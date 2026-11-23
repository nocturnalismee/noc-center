<?php

namespace Tests\Feature\Ui;

use App\Models\AuditEvent;
use App\Models\MetricDetail;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoredSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_sign_in_screen(): void
    {
        $server = $this->server();

        $this->get(route('servers.snapshot', $server))->assertRedirect('/login');
    }

    public function test_viewers_and_operators_are_forbidden(): void
    {
        $server = $this->server();

        foreach (['viewer', 'operator'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('servers.snapshot', $server))
                ->assertForbidden();
        }
    }

    public function test_an_admin_sees_the_latest_snapshot_with_masked_queries_and_no_store(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server();
        $sample = $this->sample($server, now()->subMinute());
        $this->detail($sample, [
            'cpanel.version' => '110.0.32',
            'mysql.query.0.info' => "SELECT * FROM users WHERE id = 1 AND email = 'a@example.test'",
        ]);

        $newer = $this->sample($server, now());
        $this->detail($newer, [
            'cpanel.version' => '110.0.33',
            'mysql.query.0.info' => 'SELECT * FROM invoices WHERE total > 1000',
        ]);

        $response = $this->actingAs($admin)->get(route('servers.snapshot', $server));

        $response->assertOk()
            ->assertSee('110.0.33')
            ->assertSee('SELECT * FROM invoices WHERE total > ?')
            ->assertDontSee('SELECT * FROM invoices WHERE total > 1000')
            ->assertDontSee('110.0.32');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'server.snapshot_viewed',
            'target_type' => $server->getMorphClass(),
            'target_id' => $server->id,
        ]);

        $event = AuditEvent::query()->where('action', 'server.snapshot_viewed')->firstOrFail();
        $this->assertSame($newer->id, $event->meta_json['sample_id']);
    }

    public function test_an_admin_without_samples_sees_an_empty_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server();

        $this->actingAs($admin)
            ->get(route('servers.snapshot', $server))
            ->assertOk()
            ->assertSee('No stored snapshot is available for this server.');
    }

    public function test_soft_deleted_servers_return_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server();
        $server->delete();

        $this->actingAs($admin)
            ->get('/servers/'.$server->id.'/snapshot')
            ->assertNotFound();
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'web-01',
            'hostname' => 'web-01.nusantara.id',
            'server_type' => 'Web',
            'panel' => 'cPanel 110.0.32',
            'agent_version' => '1.4.2',
            'interval_seconds' => 60,
            'health' => 'ok',
            'issue_codes' => [],
            'is_active' => true,
            'last_seen_at' => now(),
            'last_sample_at' => now(),
            'stale_after' => now()->addMinutes(5),
            'offline_after' => now()->addMinutes(10),
        ]);
    }

    private function sample(Server $server, Carbon $collectedAt): MetricSample
    {
        return MetricSample::query()->create([
            'server_id' => $server->id,
            'collected_at' => $collectedAt,
            'received_at' => $collectedAt,
            'interval_seconds' => 60,
            'cpu_usage_pct' => 42,
            'cpu_iowait_pct' => 0.4,
            'cpu_cores' => 8,
            'load1' => 1.82,
            'load5' => 1.64,
            'load15' => 1.51,
            'mem_total_bytes' => 16 * 1024 ** 3,
            'mem_used_bytes' => 10.9 * 1024 ** 3,
            'mem_used_pct' => 68,
            'swap_total_bytes' => 2 * 1024 ** 3,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 200 * 1024 ** 3,
            'disk_used_bytes' => 142 * 1024 ** 3,
            'disk_used_pct' => 71,
            'disk_inode_pct' => 41,
            'net_rx_bps' => 4_400_000,
            'net_tx_bps' => 1_150_000,
            'processes_running' => 184,
            'processes_blocked' => 2,
            'uptime_seconds' => 38 * 86400 + 4 * 3600,
            'payload_bytes' => 1024,
        ]);
    }

    /**
     * @param  array<string, mixed>  $flat
     */
    private function detail(MetricSample $sample, array $flat): MetricDetail
    {
        return MetricDetail::query()->create([
            'sample_id' => $sample->id,
            'panel_metrics' => $flat,
            'extensions' => [],
            'size_bytes' => 512,
        ]);
    }
}
