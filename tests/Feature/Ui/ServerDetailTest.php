<?php

namespace Tests\Feature\Ui;

use App\Livewire\Servers\Show;
use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ServerDetailTest extends TestCase
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

        $this->get(route('servers.show', $server))->assertRedirect('/login');
    }

    public function test_a_viewer_can_view_the_page_without_management_actions(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee($server->name)
            ->assertSee('web-01.nusantara.id')
            ->assertDontSee('Manage tokens')
            ->assertDontSee('Edit server')
            ->assertDontSee('Stored snapshot')
            ->assertDontSee('tab=history', false);
    }

    public function test_operators_see_token_and_edit_actions_and_admins_see_the_snapshot_action(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();

        $this->actingAs($operator)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Manage tokens')
            ->assertSee('Edit server')
            ->assertDontSee('Stored snapshot');

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Stored snapshot');
    }

    public function test_server_facts_show_asset_metadata(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server([
            'provider' => 'Nusantara Cloud',
            'location' => 'Jakarta',
            'ip_address' => '203.0.113.10',
            'label' => 'asset-042',
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Server facts')
            ->assertSee('Primary IP')
            ->assertSee('203.0.113.10')
            ->assertSee('Asset label')
            ->assertSee('asset-042')
            ->assertSee('Provider')
            ->assertSee('Nusantara Cloud')
            ->assertSee('Location')
            ->assertSee('Jakarta');
    }

    public function test_soft_deleted_servers_return_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server();
        $server->delete();

        $this->actingAs($admin)
            ->get('/servers/'.$server->id)
            ->assertNotFound();
    }

    public function test_header_and_never_seen_state_use_the_expected_copy(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server([
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Never seen')
            ->assertSee('Waiting for first check-in')
            ->assertSee('No sample yet')
            ->assertSee('No samples yet')
            ->assertSee('overflow-x-auto overflow-y-hidden', false);
    }

    public function test_offline_status_tells_the_operator_when_data_ends(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server([
            'last_seen_at' => now()->subHours(2),
            'stale_after' => now()->subMinutes(90),
            'offline_after' => now()->subMinutes(30),
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Offline')
            ->assertSee('Data ends')
            ->assertSee('2h ago');
    }

    public function test_the_metric_strip_uses_the_latest_sample(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $this->sample($server, [
            'cpu_usage_pct' => 42,
            'cpu_iowait_pct' => 0.4,
            'cpu_cores' => 8,
            'mem_used_pct' => 68,
            'mem_used_bytes' => 10.9 * 1024 ** 3,
            'mem_total_bytes' => 16 * 1024 ** 3,
            'disk_used_pct' => 71,
            'disk_used_bytes' => 142 * 1024 ** 3,
            'disk_total_bytes' => 200 * 1024 ** 3,
            'load1' => 1.82,
            'load5' => 1.64,
            'load15' => 1.51,
            'net_rx_bps' => 4_400_000,
            'net_tx_bps' => 1_150_000,
            'uptime_seconds' => 38 * 86400 + 4 * 3600,
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('42%')
            ->assertSee('8 cores')
            ->assertSee('68%')
            ->assertSee('71%')
            ->assertSee('1.82')
            ->assertSee('4.20 / 1.10')
            ->assertSee('38d 4h')
            ->assertSee('Charts require JavaScript.');
    }

    public function test_missing_metric_values_are_em_dashes_not_zeroes(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('No sample yet')
            ->assertSee('—');
    }

    public function test_the_chart_subtexts_render_wib_and_not_utc(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('collector samples · WIB')
            ->assertDontSee('collector samples · UTC');

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'performance']))
            ->assertOk()
            ->assertSee('downsampled for display · WIB')
            ->assertDontSee('downsampled for display · UTC');
    }

    public function test_each_tab_renders_its_section_and_invalid_tabs_fall_back_to_overview(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $this->sample($server);

        Alert::query()->create([
            'server_id' => $server->id,
            'rule_key' => 'cpu_high',
            'severity' => 'warning',
            'status' => 'open',
            'title' => 'CPU high',
            'message' => 'CPU is high',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        AuditEvent::query()->create([
            'user_id' => $viewer->id,
            'action' => 'server.updated',
            'target_type' => $server->getMorphClass(),
            'target_id' => $server->id,
            'ip' => '127.0.0.1',
            'user_agent' => 'test',
            'meta_json' => ['field' => 'name'],
        ]);

        $expectations = [
            'overview' => 'Performance',
            'performance' => 'Resource history',
            'panel' => 'Panel metrics',
            'modules' => 'Module freshness',
            'alerts' => 'CPU high',
        ];

        foreach ($expectations as $tab => $needle) {
            $this->actingAs($viewer)
                ->get(route('servers.show', ['server' => $server, 'tab' => $tab]))
                ->assertOk()
                ->assertSee($needle);
        }

        // Viewers cannot read the audit history even by asking for the tab.
        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'history']))
            ->assertOk()
            ->assertSee('History is restricted to operators and administrators.')
            ->assertDontSee('server.updated');

        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'history']))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('server.updated');

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'bogus']))
            ->assertOk()
            ->assertSee('Performance');
    }

    public function test_server_detail_renders_realtime_hooks_and_fallback_poll(): void
    {
        config(['noc.realtime.enabled' => true]);
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'performance', 'range' => '7d']))
            ->assertOk()
            ->assertSee('data-realtime', false)
            ->assertSee('server.'.$server->id, false)
            ->assertSee('SampleStored', false)
            ->assertSee('ServerChanged', false)
            ->assertSee('AlertChanged', false)
            ->assertSee('range=7d', false)
            ->assertSee('wire:poll.60s', false);
    }

    public function test_server_detail_keeps_polling_without_realtime(): void
    {
        config(['noc.realtime.enabled' => false]);
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertDontSee('data-realtime', false)
            ->assertSee('wire:poll.30s', false);
    }

    public function test_performance_tab_renders_five_unit_aware_charts(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $this->sample($server);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'performance', 'range' => '7d']))
            ->assertOk()
            ->assertSee('data-chart-key="cpu"', false)
            ->assertSee('data-chart-unit="percent"', false)
            ->assertSee('data-chart-key="memory"', false)
            ->assertSee('data-chart-unit="bytes"', false)
            ->assertSee('data-chart-key="disk"', false)
            ->assertSee('data-chart-key="load"', false)
            ->assertSee('data-chart-key="network"', false)
            ->assertSee('metric=memory_bytes', false)
            ->assertSee('metric=disk_bytes', false)
            ->assertSee('metric=rx', false)
            ->assertSee('metric=tx', false)
            ->assertSee('range=7d', false)
            ->assertSee('data-interval="60"', false);
    }

    public function test_overview_adds_a_combined_load_and_network_chart(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $this->sample($server);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('data-chart-key="resources"', false)
            ->assertSee('data-chart-key="load-network"', false)
            ->assertSee('data-chart-unit="mixed"', false)
            ->assertSee('metric=load', false)
            ->assertSee('metric=rx', false)
            ->assertSee('metric=tx', false);
    }

    public function test_livewire_normalises_an_unknown_tab_and_range(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer);

        Livewire::test(Show::class, ['server' => $server])
            ->set('tab', 'bogus')
            ->assertOk()
            ->assertSee('Performance');

        Livewire::test(Show::class, ['server' => $server])
            ->set('tab', 'performance')
            ->set('range', 'bogus')
            ->assertOk()
            ->assertSee('range=24h', false);
    }

    public function test_the_page_has_no_stat_card_or_sparkline_markup(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $response = $this->actingAs($viewer)->get(route('servers.show', $server));

        $response->assertOk()
            ->assertDontSee('<x-sparkline', false)
            ->assertDontSee('<x-stat-card', false)
            ->assertDontSee('rounded-full bg-surface', false);
    }

    private function server(array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
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
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sample(Server $server, array $attributes = []): MetricSample
    {
        return MetricSample::query()->create(array_merge([
            'server_id' => $server->id,
            'collected_at' => now(),
            'received_at' => now(),
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
        ], $attributes));
    }
}
