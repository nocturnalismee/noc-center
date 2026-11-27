<?php

namespace Tests\Feature\Console;

use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Servers\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * `noc:purge-server` (purge-server design).
 *
 * The command permanently deletes one soft-deleted server. It refuses unknown
 * names and active servers, previews counts without writing on `--dry-run`,
 * asks for confirmation unless `--force` is given, prints a backup reminder,
 * and optionally runs SQLite `VACUUM` outside the deletion transaction.
 */
class PurgeServerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_name_fails(): void
    {
        $this->artisan('noc:purge-server', ['name' => 'missing-01'])
            ->expectsOutputToContain('Server not found')
            ->assertFailed();
    }

    public function test_a_missing_name_fails_without_prompting_or_purging(): void
    {
        $server = $this->server(deleted: true);

        // `--force` must never be consumed as the positional name; a missing
        // name fails before any lookup or confirmation prompt.
        $this->artisan('noc:purge-server', ['name' => null, '--force' => true])
            ->expectsOutputToContain('A server name is required')
            ->doesntExpectOutputToContain('Purged server')
            ->assertFailed();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
    }

    public function test_a_blank_name_fails_without_prompting_or_purging(): void
    {
        $server = $this->server(deleted: true);

        $this->artisan('noc:purge-server', ['name' => '   ', '--force' => true])
            ->doesntExpectOutputToContain('Purged server')
            ->assertFailed();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
    }

    public function test_a_failing_purge_reports_cleanly_and_keeps_the_server(): void
    {
        $server = $this->server(deleted: true);

        $this->mock(ServerService::class, function ($mock) use ($server): void {
            $mock->shouldReceive('purge')
                ->once()
                ->withArgs(fn (Server $target): bool => $target->getKey() === $server->getKey())
                ->andThrow(new RuntimeException('database is locked'));
        });

        $this->artisan('noc:purge-server', ['name' => $server->name, '--force' => true])
            ->expectsOutputToContain('Purge failed: database is locked')
            ->doesntExpectOutputToContain('Purged server')
            ->assertFailed();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_an_active_server_is_refused_and_kept(): void
    {
        $server = $this->server(deleted: false);

        $this->artisan('noc:purge-server', ['name' => $server->name, '--force' => true])
            ->expectsOutputToContain('soft-delete')
            ->assertFailed();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_dry_run_reports_counts_and_changes_nothing(): void
    {
        $server = $this->server(deleted: true);
        $this->token($server);
        $this->sample($server);

        $this->artisan('noc:purge-server', ['name' => $server->name, '--dry-run' => true])
            ->expectsOutputToContain('Before purging, create a backup: php artisan noc:backup --path=')
            ->expectsOutput('Would purge server ['.$server->name.']: 1 samples, 0 details, 0 rollups, 0 alerts, 0 deliveries, 1 tokens.')
            ->doesntExpectOutputToContain('Purged server')
            ->assertSuccessful();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, ServerToken::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_force_purges_the_server_and_cascading_rows(): void
    {
        $server = $this->server(deleted: true);
        $serverId = (string) $server->getKey();
        $this->token($server);
        $this->sample($server);

        $this->artisan('noc:purge-server', ['name' => $server->name, '--force' => true])
            ->expectsOutput('Purged server ['.$server->name.']: 1 samples, 0 details, 0 rollups, 0 alerts, 0 deliveries, 1 tokens.')
            ->assertSuccessful();

        $this->assertNull(Server::withTrashed()->find($serverId));
        $this->assertSame(0, MetricSample::query()->count());
        $this->assertSame(0, ServerToken::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_a_declined_confirmation_aborts_without_deleting(): void
    {
        $server = $this->server(deleted: true);

        $this->artisan('noc:purge-server', ['name' => $server->name])
            ->expectsConfirmation(
                'Permanently purge server ['.$server->name.'] and all of its data? This cannot be undone.',
                'no',
            )
            ->assertFailed();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_an_accepted_confirmation_purges_the_server(): void
    {
        $server = $this->server(deleted: true);

        $this->artisan('noc:purge-server', ['name' => $server->name])
            ->expectsConfirmation(
                'Permanently purge server ['.$server->name.'] and all of its data? This cannot be undone.',
                'yes',
            )
            ->assertSuccessful();

        $this->assertNull(Server::withTrashed()->find((string) $server->getKey()));
    }

    public function test_the_backup_reminder_is_printed_before_purging(): void
    {
        $server = $this->server(deleted: true);

        $this->artisan('noc:purge-server', ['name' => $server->name, '--force' => true])
            ->expectsOutputToContain('Before purging, create a backup: php artisan noc:backup --path=')
            ->assertSuccessful();
    }

    /**
     * `RefreshDatabase` wraps each test in a transaction, so the purge succeeds
     * and VACUUM is skipped with a warning rather than failing.
     */
    public function test_vacuum_does_not_run_inside_the_refresh_database_transaction(): void
    {
        $server = $this->server(deleted: true);

        $this->artisan('noc:purge-server', ['name' => $server->name, '--force' => true, '--vacuum' => true])
            ->expectsOutputToContain('Skipping VACUUM')
            ->assertSuccessful();

        $this->assertNull(Server::withTrashed()->find((string) $server->getKey()));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function server(bool $deleted): Server
    {
        $name = 'purge-'.Str::lower(Str::random(8));

        $server = Server::query()->create([
            'name' => $name,
            'hostname' => $name.'.example.test',
            'is_active' => true,
        ]);

        if ($deleted) {
            $server->delete();
        }

        return $server;
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
