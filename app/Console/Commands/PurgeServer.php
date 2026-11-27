<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\ServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        {name : Unique server name, including soft-deleted servers}
        {--force : Skip the confirmation prompt}
        {--dry-run : Report the rows that would be deleted without changing anything}
        {--vacuum : Run SQLite VACUUM after purging to reclaim file space}';

    protected $description = 'Permanently delete a soft-deleted server and all of its history';

    public function handle(ServerService $servers): int
    {
        $name = (string) $this->argument('name');
        $server = Server::withTrashed()->where('name', $name)->first();

        if (! $server instanceof Server) {
            $this->error(sprintf('Server not found: %s', $name));

            return self::FAILURE;
        }

        if (! $server->trashed()) {
            $this->error(sprintf(
                'Server [%s] is active; soft-delete it first and re-run the purge.',
                (string) $server->name,
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $counts = $servers->purgeCounts($server);

            $this->line(sprintf('Would purge server [%s]: %s', (string) $server->name, $this->summary($counts)));
            $this->line('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        $this->line('Before purging, create a backup: php artisan noc:backup --path=/absolute/path.sqlite');

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

        $counts = $servers->purge($server);

        $this->line(sprintf('Purged server [%s]: %s', $name, $this->summary($counts)));

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

        $connection->statement('VACUUM');

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

        $size = @filesize($database);

        return $size === false ? null : $size;
    }
}
