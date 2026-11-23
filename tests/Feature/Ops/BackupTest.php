<?php

namespace Tests\Feature\Ops;

use App\Console\QueueWorkerSchedule;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Tokens\TokenService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Task 22: `noc:backup` and the restore/APP_KEY runbook.
 *
 * A backup is a SQLite `VACUUM INTO` copy of the live database: it must pass
 * `PRAGMA integrity_check`, be refused when the destination already exists,
 * and restore as a point-in-time snapshot (rows written after the copy do not
 * appear). The restore is a file copy; nothing in the command mutates the
 * live database.
 *
 * `DatabaseMigrations` is intentional: SQLite refuses `VACUUM` inside a
 * transaction, so `RefreshDatabase` (which wraps each test in one) cannot be
 * used here.
 */
class BackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/noc-backup-test-'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($this->tmpDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    public function test_it_creates_a_verified_backup_and_prints_row_counts(): void
    {
        $this->seedFleet(servers: 2, samplesPerServer: 3);

        $path = $this->path('noc.sqlite');

        $this->artisan('noc:backup', ['--path' => $path])
            ->expectsOutputToContain('integrity_check: ok')
            ->expectsOutputToContain('servers: 2')
            ->expectsOutputToContain('metric_samples: 6')
            ->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        $backup = $this->open($path);

        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(2, (int) $backup->query('SELECT COUNT(*) FROM servers')->fetchColumn());
        $this->assertSame(6, (int) $backup->query('SELECT COUNT(*) FROM metric_samples')->fetchColumn());
    }

    public function test_it_refuses_to_overwrite_an_existing_destination(): void
    {
        $this->seedFleet(servers: 1, samplesPerServer: 1);

        $path = $this->path('noc.sqlite');
        file_put_contents($path, 'sentinel');

        $this->artisan('noc:backup', ['--path' => $path])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame('sentinel', file_get_contents($path));
    }

    public function test_a_restored_backup_is_a_point_in_time_snapshot(): void
    {
        $this->seedFleet(servers: 2, samplesPerServer: 3);

        $backupPath = $this->path('noc.sqlite');

        $this->artisan('noc:backup', ['--path' => $backupPath])->assertSuccessful();

        // Rows written after the backup must not leak into the restored copy.
        $this->seedFleet(servers: 1, samplesPerServer: 2);

        $restorePath = $this->path('restored.sqlite');
        $this->assertTrue(copy($backupPath, $restorePath));

        $restored = $this->open($restorePath);

        $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(2, (int) $restored->query('SELECT COUNT(*) FROM servers')->fetchColumn());
        $this->assertSame(6, (int) $restored->query('SELECT COUNT(*) FROM metric_samples')->fetchColumn());
    }

    public function test_it_refuses_unsafe_destinations(): void
    {
        $this->seedFleet(servers: 1, samplesPerServer: 1);

        $directory = $this->path('backups');
        File::ensureDirectoryExists($directory);

        $this->artisan('noc:backup', ['--path' => $directory])
            ->expectsOutputToContain('is a directory')
            ->assertFailed();

        $target = $this->path('target.sqlite');
        file_put_contents($target, 'target');
        $link = $this->path('link.sqlite');
        symlink($target, $link);

        $this->artisan('noc:backup', ['--path' => $link])
            ->expectsOutputToContain('is a symlink')
            ->assertFailed();

        $this->artisan('noc:backup', ['--path' => 'relative.sqlite'])
            ->expectsOutputToContain('absolute path')
            ->assertFailed();

        $missingParent = $this->path('missing/nested/noc.sqlite');

        $this->artisan('noc:backup', ['--path' => $missingParent])
            ->expectsOutputToContain('directory does not exist')
            ->assertFailed();
    }

    public function test_it_refuses_a_non_sqlite_connection(): void
    {
        config(['database.default' => 'mysql']);

        try {
            $this->artisan('noc:backup', ['--path' => $this->path('noc.sqlite')])
                ->expectsOutputToContain('only supports SQLite')
                ->assertFailed();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    /**
     * The restore runbook states that a backup is only usable with the
     * `APP_KEY` it was taken under: agent tokens are HMAC-SHA256 hashes keyed
     * by `APP_KEY`, so rotating the key invalidates every issued token.
     */
    public function test_app_key_rotation_invalidates_issued_tokens(): void
    {
        $server = $this->server(0);

        $issued = $this->app->make(TokenService::class)->issue($server);
        $tokens = $this->app->make(TokenService::class);

        $this->assertNotNull($tokens->resolve($issued->plain));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNull($tokens->resolve($issued->plain));
        $this->assertTrue(ServerToken::query()->where('token_prefix', $issued->token->token_prefix)->exists());
    }

    public function test_the_backup_file_is_owner_readable_only(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file modes are not available on Windows.');
        }

        $this->seedFleet(servers: 1, samplesPerServer: 1);

        $path = $this->path('noc.sqlite');

        $this->artisan('noc:backup', ['--path' => $path])->assertSuccessful();

        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function test_the_queue_worker_is_scheduled_when_enabled_and_absent_when_disabled(): void
    {
        config(['queue.scheduled_worker' => true]);

        $enabled = new Schedule;
        QueueWorkerSchedule::register($enabled);

        $registered = array_values(array_filter(
            $enabled->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', 'queue:work'),
        ));

        $this->assertCount(1, $registered);
        $this->assertSame('* * * * *', $registered[0]->expression);
        $this->assertTrue($registered[0]->withoutOverlapping);

        config(['queue.scheduled_worker' => false]);

        $disabled = new Schedule;
        QueueWorkerSchedule::register($disabled);

        $this->assertCount(0, array_filter(
            $disabled->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', 'queue:work'),
        ));
    }

    public function test_the_queue_worker_is_scheduled_every_minute_without_overlapping(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', 'queue:work'),
        ));

        $this->assertCount(1, $events);

        $worker = $events[0];
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertFalse($worker->onOneServer);
        $this->assertStringContainsString('--stop-when-empty', (string) $worker->command);
        $this->assertStringContainsString('--max-time=50', (string) $worker->command);
        $this->assertStringContainsString('--tries=3', (string) $worker->command);
        $this->assertStringContainsString('--sleep=1', (string) $worker->command);
    }

    private function seedFleet(int $servers, int $samplesPerServer): void
    {
        for ($i = 0; $i < $servers; $i++) {
            $server = $this->server($i);

            for ($j = 0; $j < $samplesPerServer; $j++) {
                MetricSample::query()->create([
                    'server_id' => $server->getKey(),
                    'collected_at' => now()->subMinutes($j)->format('Y-m-d H:i:s'),
                    'received_at' => now()->subMinutes($j)->format('Y-m-d H:i:s'),
                    'schema_version' => 1,
                    'interval_seconds' => 60,
                    'cpu_usage_pct' => 1,
                    'cpu_iowait_pct' => 0,
                    'cpu_cores' => 4,
                    'load1' => 0,
                    'load5' => 0,
                    'load15' => 0,
                    'mem_total_bytes' => 1000,
                    'mem_used_bytes' => 100,
                    'mem_used_pct' => 10,
                    'swap_total_bytes' => 0,
                    'swap_used_bytes' => 0,
                    'swap_used_pct' => 0,
                    'disk_total_bytes' => 1000,
                    'disk_used_bytes' => 100,
                    'disk_used_pct' => 10,
                    'disk_inode_pct' => 1,
                    'net_rx_bps' => 0,
                    'net_tx_bps' => 0,
                    'processes_running' => 1,
                    'processes_blocked' => 0,
                    'uptime_seconds' => 100,
                    'payload_bytes' => 100,
                ]);
            }
        }
    }

    private function server(int $index): Server
    {
        return Server::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'backup-'.$index.'-'.Str::lower(Str::random(6)),
            'hostname' => 'backup'.$index.'.example.test',
            'panel' => 'none',
            'is_active' => true,
        ]);
    }

    private function path(string $name): string
    {
        return $this->tmpDir.'/'.$name;
    }

    private function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
