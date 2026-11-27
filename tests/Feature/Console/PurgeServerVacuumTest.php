<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PhysicsCalls;
use App\Console\Commands\PurgeServer;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/Support/PurgePhysicsShim.php';

/**
 * `noc:purge-server --vacuum` against a real SQLite file.
 *
 * SQLite refuses `VACUUM` inside a transaction, so `RefreshDatabase` cannot be
 * used here: the test runs migrations against a file-backed database and
 * points `database.connections.sqlite.database` at it, exactly the way an
 * operator's install looks. The vacuum must run after the purge, never inside
 * it, and report the real file sizes before and after.
 */
class PurgeServerVacuumTest extends TestCase
{
    use DatabaseMigrations;

    private string $databasePath;

    private string $capturedOutput = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/noc-purge-vacuum-'.Str::lower(Str::random(10));

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->databasePath = $directory.'/noc.sqlite';
        touch($this->databasePath);

        parent::setUp();
    }

    /**
     * Migrate the temp database file instead of the in-memory connection.
     *
     * `DB_DATABASE` is what PHPUnit's `<env>` sets for the whole process, so
     * it has to be swapped (and restored) around `migrate:fresh`; setting only
     * the connection config leaks to the next test once `refreshDatabase()`
     * replays the configuration snapshot. The cached "already migrated" flag
     * is cleared so this file is always migrated fresh.
     */
    protected function refreshTestDatabase()
    {
        // Overriding the connection config is not enough: `refreshDatabase()`
        // records the configuration it saw and re-applies it on the next test
        // in the process. The environment variable is read when the connection
        // boots, is restored below, and keeps other tests on `:memory:`.
        $previous = getenv('DB_DATABASE');
        putenv('DB_DATABASE='.$this->databasePath);
        $_ENV['DB_DATABASE'] = $this->databasePath;
        $_SERVER['DB_DATABASE'] = $this->databasePath;
        $this->app['config']->set('database.connections.sqlite.database', $this->databasePath);

        try {
            RefreshDatabaseState::$migrated = false;

            $this->artisan('migrate:fresh', ['--force' => true]);
        } finally {
            RefreshDatabaseState::$migrated = false;

            if ($previous === false) {
                putenv('DB_DATABASE');
                unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);
            } else {
                putenv('DB_DATABASE='.$previous);
                $_ENV['DB_DATABASE'] = $previous;
                $_SERVER['DB_DATABASE'] = $previous;
            }
        }
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        // The scratch test retargets the connection; restore the migrated file
        // (and forget the forged stat entry) so the pending `migrate:rollback`
        // in tearDown hits a real database.
        clearstatcache();
        $this->app['config']->set('database.connections.sqlite.database', $this->databasePath);

        parent::tearDown();

        if (is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }

        foreach (glob(dirname($this->databasePath).'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        @rmdir(dirname($this->databasePath));
    }

    public function test_vacuum_compacts_the_database_after_the_purge(): void
    {
        $server = $this->server();
        $this->bloat($server);
        $this->sample($server);
        $server->delete();

        $baselinePages = $this->pageCount();
        $this->assertGreaterThan(0, $baselinePages);

        $exitCode = Artisan::call('noc:purge-server', [
            'name' => $server->name,
            '--force' => true,
            '--vacuum' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->capturedOutput = Artisan::output();

        $this->assertNull(Server::withTrashed()->find((string) $server->getKey()));
        $this->assertSame(0, (int) DB::table('server_tokens')->where('server_id', $server->getKey())->count());
        $this->assertFileExists($this->databasePath);

        // Freed pages leave the file and the free list is emptied.
        $this->assertLessThan($baselinePages, $this->pageCount(), 'VACUUM must compact the database file.');
        $this->assertSame(0, (int) DB::scalar('PRAGMA freelist_count'), 'VACUUM must leave no free pages behind.');

        // The reported "after" size is the real file size. The main file keeps
        // its WAL high-water mark, so it need not shrink below it here; what
        // must hold is that the number is not a stale cached `stat` entry.
        [$reportedBefore, $reportedAfter] = $this->reportedVacuumSizes();

        $this->assertGreaterThan(0, $reportedBefore);
        $this->assertSame($this->realFileSize(), $reportedAfter);
        $this->assertLessThanOrEqual($reportedBefore, $reportedAfter);
    }

    /**
     * Prove `databaseSize()` clears the stat cache before reading a size.
     *
     * PHP refreshes the stat cache on its own writes, so a stale entry cannot
     * be reproduced from the outside (every `touch`/`truncate`/`fwrite`
     * refreshes it). The shim in this test's namespace records the order of
     * `clearstatcache()` and `filesize()` calls made by the command, which is
     * exactly the contract `VACUUM` relies on.
     */
    public function test_database_size_clears_the_stat_cache_before_reading(): void
    {
        $scratch = $this->databasePath.'-scratch.sqlite';
        file_put_contents($scratch, str_repeat('a', 2048));

        config(['database.connections.sqlite.database' => $scratch]);
        DB::purge('sqlite');

        PhysicsCalls::reset();

        $command = $this->app->make(PurgeServer::class);
        $size = (fn () => $this->databaseSize())->call($command);

        $this->assertSame(2048, $size);
        $this->assertSame($scratch, PhysicsCalls::statCacheTarget());
        $this->assertTrue(
            PhysicsCalls::clearedBeforeReading(),
            'databaseSize() must call clearstatcache(true, $database) before filesize().',
        );

        DB::purge('sqlite');
        @unlink($scratch);
    }

    public function test_without_the_vacuum_flag_the_file_is_left_alone(): void
    {
        $server = $this->server();
        $server->delete();

        $this->artisan('noc:purge-server', ['name' => $server->name, '--force' => true])
            ->doesntExpectOutputToContain('VACUUM complete')
            ->assertSuccessful();
    }

    /**
     * An uncached `filesize()`, i.e. the truth on disk.
     */
    private function realFileSize(): int
    {
        clearstatcache(true, $this->databasePath);

        return (int) filesize($this->databasePath);
    }

    /**
     * Page count of the database file, read back from SQLite itself.
     */
    private function pageCount(): int
    {
        return (int) DB::scalar('PRAGMA page_count');
    }

    /**
     * Parse `VACUUM complete: X bytes -> Y bytes.` and the purge summary from
     * the last command run.
     *
     * @return array{0: int, 1: int}
     */
    private function reportedVacuumSizes(): array
    {
        $output = $this->capturedOutput;

        $this->assertMatchesRegularExpression('/VACUUM complete: (\d+) bytes -> (\d+) bytes\./', $output);
        $this->assertMatchesRegularExpression('/Purged server \[purge-[a-z0-9]+\]: \d+ samples, 0 details, 0 rollups, 0 alerts, 0 deliveries, 500 tokens\./', $output);

        preg_match('/VACUUM complete: (\d+) bytes -> (\d+) bytes\./', $output, $matches);

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function server(): Server
    {
        $name = 'purge-'.Str::lower(Str::random(8));

        return Server::query()->create([
            'name' => $name,
            'hostname' => $name.'.example.test',
            'is_active' => true,
        ]);
    }

    /**
     * Give a server enough token rows that its purge frees many pages.
     */
    private function bloat(Server $server): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->token($server);
        }
    }

    private function token(Server $server): void
    {
        ServerToken::query()->create([
            'server_id' => $server->getKey(),
            'token_hash' => hash('sha256', Str::random(32)),
            'token_prefix' => Str::random(8),
        ]);
    }

    private function sample(Server $server): void
    {
        MetricSample::query()->create([
            'server_id' => $server->getKey(),
            'collected_at' => Carbon::parse('2026-11-27 00:00:00'),
            'received_at' => Carbon::parse('2026-11-27 00:00:00'),
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
