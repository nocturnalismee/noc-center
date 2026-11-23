<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\ServerService;
use App\Services\Tokens\TokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Build a repeatable ingest load fixture (spec §17.3).
 *
 * The command registers synthetic servers and issues one agent token per
 * server, then writes `{name, token, payload}` entries. `tools/load-ingest.php`
 * reads that file and posts every payload to `/api/v1/ingest`.
 *
 * The fixture contains agent tokens in plaintext, so it is written mode 0600
 * and must never be committed. It is also **not** a production tool: the
 * command refuses to run when `APP_ENV=production`, because it would create
 * permanent servers and tokens in the live database.
 *
 * Use a throwaway SQLite database for a load run. The dashboard and the
 * fixture command must share the same database **and** `APP_KEY` (tokens are
 * keyed HMACs), for example:
 *
 *     touch /tmp/noc-load.sqlite
 *     DB_DATABASE=/tmp/noc-load.sqlite php artisan migrate --force
 *     DB_DATABASE=/tmp/noc-load.sqlite APP_ENV=local \
 *         php artisan noc:make-load-fixture --servers=100 --path=/tmp/noc-load.json
 *     DB_DATABASE=/tmp/noc-load.sqlite APP_ENV=local \
 *         php artisan serve --host=127.0.0.1 --port=8000
 */
class MakeLoadFixture extends Command
{
    protected $signature = 'noc:make-load-fixture
        {--servers=100 : Number of synthetic servers to create (1–1000)}
        {--path= : Absolute path for the JSON fixture file}
        {--force : Overwrite an existing fixture file}';

    protected $description = 'Create synthetic servers/tokens and write a load-ingest fixture (never for production)';

    public function handle(ServerService $servers, TokenService $tokens): int
    {
        if (app()->isProduction()) {
            $this->error('noc:make-load-fixture refuses to run with APP_ENV=production.');

            return self::FAILURE;
        }

        $count = filter_var($this->option('servers'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);

        if ($count === false) {
            $this->error('--servers must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $path = $this->option('path');

        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            $this->error('--path is required; pass an absolute path for the fixture file.');

            return self::FAILURE;
        }

        if (! $this->isAbsolute($path)) {
            $this->error('--path must be an absolute path.');

            return self::FAILURE;
        }

        if (is_link($path)) {
            $this->error('The fixture destination is a symlink; choose a regular file path.');

            return self::FAILURE;
        }

        if (is_dir($path)) {
            $this->error('The fixture destination is a directory.');

            return self::FAILURE;
        }

        if (file_exists($path) && ! $this->option('force')) {
            $this->error(sprintf('The fixture already exists: %s (pass --force to overwrite).', $path));

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->error(sprintf('The fixture directory does not exist or is not writable: %s', $directory));

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Database: %s (%s)',
            (string) config('database.default'),
            (string) DB::connection()->getDatabaseName(),
        ));

        $entries = [];
        $created = 0;

        for ($index = 1; $index <= $count; $index++) {
            $name = sprintf('load-%03d', $index);
            $hostname = $name.'.load.example.test';

            $server = Server::withTrashed()->where('name', $name)->first();

            if ($server !== null && $server->trashed()) {
                $this->error(sprintf(
                    'Server [%s] exists but is soft-deleted; use a scratch DB_DATABASE for load fixtures.',
                    $name,
                ));

                return self::FAILURE;
            }

            if ($server === null) {
                $server = $servers->create([
                    'name' => $name,
                    'hostname' => $hostname,
                    'panel' => 'cpanel',
                    'server_type' => 'load-test',
                    'provider' => 'synthetic',
                    'location' => 'fixture',
                    'interval_seconds' => 60,
                    'notes' => 'Synthetic server created by noc:make-load-fixture.',
                ]);
                $created++;
            }

            $issued = $tokens->issue($server, null, 'load fixture');

            $entries[] = [
                'name' => $name,
                'token' => $issued->plain,
                'payload' => $this->payload($name, $hostname, $index),
            ];
        }

        $json = json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $temporary = $path.'.tmp.'.getmypid();

        if (file_put_contents($temporary, $json."\n") === false) {
            $this->error('Could not write the fixture file.');

            return self::FAILURE;
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            $this->error('Could not move the fixture into place.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Wrote %d fixture entries to %s (%d new servers, %d existing reused).',
            count($entries),
            $path,
            $created,
            $count - $created,
        ));
        $this->line('The fixture contains plaintext agent tokens (mode 0600). Do not commit it; delete it after the load run.');

        return self::SUCCESS;
    }

    /**
     * One realistic schema-v1 payload, below every alert threshold so the
     * load test measures ingest/storage rather than the alerting path.
     *
     * @return array<string, mixed>
     */
    private function payload(string $name, string $hostname, int $index): array
    {
        $cores = 8;
        $memoryTotal = 8 * 1024 * 1024 * 1024;
        $swapTotal = 2 * 1024 * 1024 * 1024;
        $diskTotal = 100 * 1024 * 1024 * 1024;

        $cpu = 10.0 + fmod($index * 7.3, 30.0);
        $memoryPct = 20.0 + fmod($index * 5.1, 35.0);
        $diskPct = 30.0 + fmod($index * 3.7, 35.0);
        $inodePct = 10.0 + fmod($index * 1.9, 25.0);
        $load1 = 0.5 + fmod($index * 0.13, 3.0);

        $memoryUsed = (int) round($memoryTotal * $memoryPct / 100);
        $diskUsed = (int) round($diskTotal * $diskPct / 100);

        return [
            'schema_version' => 1,
            'agent' => [
                'version' => '0.1.0',
                'id' => (string) Str::uuid(),
                'hostname' => $hostname,
                'panel' => 'cpanel',
                'collected_at' => now()->utc()->toIso8601ZuluString(),
                'interval_seconds' => 60,
            ],
            'system' => [
                'os' => [
                    'name' => 'AlmaLinux',
                    'version' => '9.4',
                    'kernel' => '5.14.0-362.8.1.el9_3.x86_64',
                ],
                'uptime_seconds' => 86_400 + ($index * 137),
            ],
            'metrics' => [
                'cpu' => [
                    'usage_pct' => round($cpu, 2),
                    'iowait_pct' => 0.2,
                    'load1' => round($load1, 2),
                    'load5' => round($load1 * 0.9, 2),
                    'load15' => round($load1 * 0.8, 2),
                    'cores' => $cores,
                ],
                'memory' => [
                    'total_bytes' => $memoryTotal,
                    'used_bytes' => $memoryUsed,
                    'used_pct' => round($memoryPct, 2),
                ],
                'swap' => [
                    'total_bytes' => $swapTotal,
                    'used_bytes' => 0,
                    'used_pct' => 0.0,
                ],
                'disk' => [
                    'root' => [
                        'total_bytes' => $diskTotal,
                        'used_bytes' => $diskUsed,
                        'used_pct' => round($diskPct, 2),
                        'inode_pct' => round($inodePct, 2),
                    ],
                ],
                'network' => [
                    'rx_bytes_per_sec' => 1_000_000 + ($index * 10_000),
                    'tx_bytes_per_sec' => 500_000 + ($index * 5_000),
                ],
                'processes' => [
                    'running' => 100 + $index,
                    'blocked' => 0,
                ],
            ],
            'panel_metrics' => [
                'cpanel.version' => '120.0.1',
                'cpanel.accounts' => 25,
                'cpanel.mailq' => 0,
                'cpanel.mysql_ping' => true,
                'cpanel.webserver' => 'apache',
                'cpanel.cagefs' => true,
                'service.apache.installed' => true,
                'service.apache.enabled' => true,
                'service.apache.running' => true,
                'service.mysql.installed' => true,
                'service.mysql.enabled' => true,
                'service.mysql.running' => true,
            ],
            'extensions' => [
                'mysql.available' => true,
                'mysql.threads_running' => 2,
                'mysql.long_queries' => 0,
                'smart.status' => 'ok',
                'smart.disks' => 2,
                'smart.bad_count' => 0,
                'smart.cache_age_seconds' => 3_600,
                'diskquota.accounts' => 25,
                'diskquota.over' => 0,
                'diskquota.cache_age_seconds' => 3_600,
                'imunify.scanning' => false,
                'imunify.age_s' => 300,
                'imunify.files' => 10_000,
                'imunify.cache_age_seconds' => 300,
            ],
        ];
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
