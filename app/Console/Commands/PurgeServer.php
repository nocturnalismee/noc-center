<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\ServerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Permanently delete one soft-deleted server and every row that cascades.
 *
 * Soft delete keeps the server row and its history; this command is the
 * deliberate, previewable, auditable way to remove it for good. Only
 * soft-deleted servers are accepted, no backup is taken automatically, and
 * `--vacuum` runs outside the deletion transaction so SQLite can reclaim
 * space. `--dry-run` counts without writing or deleting anything.
 */
class PurgeServer extends Command
{
    protected $signature = 'noc:purge-server
        {name? : Unique server name, including soft-deleted servers}
        {--force : Skip the confirmation prompt}
        {--dry-run : Report the rows that would be deleted without changing anything}
        {--vacuum : Run SQLite VACUUM after purging to reclaim file space}';

    protected $description = 'Permanently delete a soft-deleted server and all of its history';

    public function handle(ServerService $servers): int
    {
        $name = is_string($this->argument('name')) ? trim($this->argument('name')) : '';

        if ($name === '') {
            $this->error('A server name is required: php artisan noc:purge-server {name}');

            return self::FAILURE;
        }

        try {
            $server = Server::withTrashed()->where('name', $name)->sole();
        } catch (ModelNotFoundException) {
            $this->error(sprintf('Server not found: %s', $name));

            return self::FAILURE;
        } catch (MultipleRecordsFoundException) {
            $this->error(sprintf('More than one server is named [%s]; refusing to guess which one to purge.', $name));

            return self::FAILURE;
        }

        if (! $server->trashed()) {
            $this->error(sprintf(
                'Server [%s] is active; soft-delete it first and re-run the purge.',
                (string) $server->name,
            ));

            return self::FAILURE;
        }

        $this->line('Before purging, create a backup: php artisan noc:backup --path=/absolute/path.sqlite');

        if ((bool) $this->option('dry-run')) {
            $counts = $servers->purgeCounts($server);

            $this->line(sprintf('Would purge server [%s]: %s', (string) $server->name, $this->summary($counts)));
            $this->line('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')) {
            $confirmed = $this->confirm(
                sprintf(
                    'Permanently purge server [%s] and all of its data? This cannot be undone.',
                    (string) $server->name,
                ),
                false,
            );

            if (! $confirmed) {
                $this->line('Purge cancelled.');

                return self::FAILURE;
            }
        }

        // VACUUM cannot run inside a transaction, so only the file size is
        // captured here; the purge itself commits on its own.
        $sizeBefore = (bool) $this->option('vacuum') ? $this->databaseSize() : null;

        try {
            $counts = $servers->purge($server);
        } catch (Throwable $exception) {
            $this->error(sprintf('Purge failed: %s', $this->safe($exception->getMessage())));

            return self::FAILURE;
        }

        $this->line(sprintf('Purged server [%s]: %s', (string) $server->name, $this->summary($counts)));

        if ((bool) $this->option('vacuum')) {
            $this->vacuum($sizeBefore);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{samples: int, details: int, rollups: int, alerts: int, deliveries: int, tokens: int}  $counts
     */
    private function summary(array $counts): string
    {
        return sprintf(
            '%d samples, %d details, %d rollups, %d alerts, %d deliveries, %d tokens.',
            $counts['samples'],
            $counts['details'],
            $counts['rollups'],
            $counts['alerts'],
            $counts['deliveries'],
            $counts['tokens'],
        );
    }

    private function vacuum(?int $sizeBefore): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'sqlite') {
            $this->warn(sprintf(
                'Skipping VACUUM: only SQLite connections are supported; the current driver is [%s].',
                $driver,
            ));

            return;
        }

        if ($connection->transactionLevel() > 0) {
            $this->warn('Skipping VACUUM: it cannot run inside a database transaction.');

            return;
        }

        try {
            $connection->statement('VACUUM');
        } catch (Throwable $exception) {
            // The purge is already committed; a failed VACUUM only means the
            // file was not compacted, so this is a warning, not a failure.
            $this->warn(sprintf('VACUUM failed: %s', $this->safe($exception->getMessage())));
            $this->warn('The purge is complete; re-run VACUUM later to reclaim space.');

            return;
        }

        $sizeAfter = $this->databaseSize();

        if ($sizeBefore !== null && $sizeAfter !== null) {
            $this->line(sprintf(
                'VACUUM complete: %d bytes -> %d bytes.',
                $sizeBefore,
                $sizeAfter,
            ));

            return;
        }

        $this->line('VACUUM complete.');
    }

    /**
     * Size of the database file when the connection is backed by a real file.
     * In-memory and URI databases have no such file.
     *
     * `clearstatcache()` is required: `VACUUM` rewrites the file in place and
     * PHP would otherwise report the size cached before the command ran.
     */
    private function databaseSize(): ?int
    {
        $database = DB::connection()->getDatabaseName();

        if (! is_string($database) || $database === '' || $database === ':memory:' || str_starts_with($database, 'file:')) {
            return null;
        }

        if (! is_file($database)) {
            return null;
        }

        clearstatcache(true, $database);

        $size = @filesize($database);

        return $size === false ? null : $size;
    }

    /**
     * Keep exception output to a single bounded line; it may echo a SQL error
     * but never a credential (the command touches none).
     */
    private function safe(string $value): string
    {
        $clean = preg_replace('/[^\P{C}\n]+/u', '?', $value) ?? '';
        $clean = str_replace(["\r", "\n"], ' ', $clean);

        return mb_strlen($clean) > 300 ? mb_substr($clean, 0, 300).'…' : $clean;
    }
}
