<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Create a consistent, verified SQLite backup (spec §15.1/§15.2).
 *
 * `VACUUM INTO` makes a single-file copy without blocking writers for long.
 * The command refuses non-SQLite connections, relative paths, symlinks,
 * directories, and any existing destination, so an operator cannot silently
 * overwrite the previous good backup. After copying, it runs
 * `PRAGMA integrity_check` against the copy and prints the row count of every
 * table. Nothing secret is printed.
 */
class BackupDatabase extends Command
{
    protected $signature = 'noc:backup
        {--path= : Absolute destination path for the SQLite backup file}';

    protected $description = 'Create and verify a consistent SQLite backup with VACUUM INTO';

    public function handle(): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'sqlite') {
            $this->error(sprintf(
                'noc:backup only supports SQLite connections; the current driver is [%s].',
                $driver,
            ));

            return self::FAILURE;
        }

        $path = $this->option('path');

        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            $this->error('--path is required; pass an absolute path for the backup file.');

            return self::FAILURE;
        }

        if (! $this->isAbsolute($path)) {
            $this->error(sprintf('--path must be an absolute path: %s', $this->safe($path)));

            return self::FAILURE;
        }

        if (is_link($path)) {
            $this->error(sprintf('Backup destination is a symlink: %s', $this->safe($path)));

            return self::FAILURE;
        }

        if (is_dir($path)) {
            $this->error(sprintf('Backup destination is a directory: %s', $this->safe($path)));

            return self::FAILURE;
        }

        if (file_exists($path)) {
            $this->error(sprintf('Backup destination already exists: %s', $this->safe($path)));

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            $this->error(sprintf('Backup destination directory does not exist: %s', $this->safe($directory)));

            return self::FAILURE;
        }

        if (! is_writable($directory)) {
            $this->error(sprintf('Backup destination directory is not writable: %s', $this->safe($directory)));

            return self::FAILURE;
        }

        if ($connection->transactionLevel() > 0) {
            $this->error('noc:backup cannot run inside a database transaction; run it as a standalone command.');

            return self::FAILURE;
        }

        try {
            $quoted = $connection->getPdo()->quote($path);
            $connection->statement('VACUUM INTO '.$quoted);
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$this->safe($exception->getMessage()));

            return self::FAILURE;
        }

        try {
            $backup = new PDO('sqlite:'.$path);
            $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $integrity = $backup->query('PRAGMA integrity_check')->fetchColumn();

            if ($integrity !== 'ok') {
                @unlink($path);
                $this->error('Backup integrity_check failed: '.$this->safe((string) $integrity));

                return self::FAILURE;
            }

            $tables = $backup
                ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                ->fetchAll(PDO::FETCH_COLUMN);

            $counts = [];

            foreach ($tables as $table) {
                $identifier = '"'.str_replace('"', '""', (string) $table).'"';
                $counts[(string) $table] = (int) $backup->query('SELECT COUNT(*) FROM '.$identifier)->fetchColumn();
            }
        } catch (Throwable $exception) {
            $this->removeFailedCopy($path);
            $this->error('Backup verification failed: '.$this->safe($exception->getMessage()));

            return self::FAILURE;
        }

        $this->line(sprintf('Backup written to %s (%d bytes)', $path, (int) filesize($path)));
        $this->line('integrity_check: ok');

        foreach ($counts as $table => $count) {
            $this->line(sprintf('%s: %d', $table, $count));
        }

        return self::SUCCESS;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function removeFailedCopy(string $path): void
    {
        if (is_file($path) && ! is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * Keep exception output to a single bounded line; it can echo the path but
     * never a secret (the command touches no credentials).
     */
    private function safe(string $value): string
    {
        $clean = preg_replace('/[^\P{C}\n]+/u', '?', $value) ?? '';
        $clean = str_replace(["\r", "\n"], ' ', $clean);

        return mb_strlen($clean) > 300 ? mb_substr($clean, 0, 300).'…' : $clean;
    }
}
