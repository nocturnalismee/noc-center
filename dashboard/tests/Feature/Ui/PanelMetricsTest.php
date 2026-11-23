<?php

namespace Tests\Feature\Ui;

use App\Models\MetricDetail;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PanelMetricsTest extends TestCase
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

    public function test_panel_tab_renders_cpanel_security_services_and_masked_mysql_queries(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $sample = $this->sample($server);

        $this->detail($sample, [
            'cpanel.version' => '110.0.32',
            'cpanel.accounts' => 42,
            'cpanel.mysql_ping' => true,
            'security.csf_enabled' => true,
            'security.modsec' => 'On',
            'service.mysql.installed' => true,
            'service.mysql.enabled' => true,
            'service.mysql.running' => true,
            'service.nginx.installed' => true,
            'service.nginx.enabled' => true,
            'service.nginx.running' => false,
            'mysql.available' => true,
            'mysql.threads_running' => 3,
            'mysql.query.0.user' => 'app',
            'mysql.query.0.db' => 'production',
            'mysql.query.0.state' => 'Query',
            'mysql.query.0.time' => 4,
            'mysql.query.0.info' => "SELECT * FROM users WHERE id = 1 AND email = 'a@example.test'",
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'panel']))
            ->assertOk()
            ->assertSee('110.0.32')
            ->assertSee('42')
            ->assertSee('Yes')
            ->assertSee('On')
            ->assertSee('mysql')
            ->assertSee('nginx')
            ->assertSee('Critical')
            ->assertSee('SELECT * FROM users WHERE id = ? AND email = ?')
            ->assertDontSee("SELECT * FROM users WHERE id = 1 AND email = 'a@example.test'");
    }

    public function test_panel_values_and_unknown_keys_are_escaped(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $sample = $this->sample($server);

        $this->detail($sample, [
            'cpanel.<script>alert(1)</script>' => '<script>alert(2)</script>',
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'panel']))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<script>alert(2)</script>', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false);
    }

    public function test_panel_metrics_are_capped_and_mark_truncation(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $sample = $this->sample($server);

        $flat = [];
        for ($i = 0; $i < 250; $i++) {
            $flat['other.key_'.$i] = $i;
        }

        $this->detail($sample, $flat);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'panel']))
            ->assertOk()
            ->assertSee('Showing the first 200')
            ->assertSee('250');
    }

    public function test_modules_tab_renders_smart_quota_and_imunify_state(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $sample = $this->sample($server);

        $this->detail($sample, [
            'smart.status' => 'warning',
            'smart.detail' => 'One disk needs attention',
            'smart.disks' => 2,
            'smart.cache_age_seconds' => 7200,
            'smart.disk.sda.health' => 'warning',
            'smart.disk.sda.realloc' => 8,
            'smart.disk.sda.pending' => 0,
            'smart.disk.sda.power_on_hours' => 12000,
            'smart.disk.sda.written_gb' => 2048,
            'diskquota.accounts' => 42,
            'diskquota.over' => 3,
            'diskquota.cache_age_seconds' => 3600,
            'diskquota.top.alice.used_gb' => 12.5,
            'diskquota.top.bob.used_gb' => 7.25,
            'imunify.scanning' => false,
            'imunify.age_s' => 240,
            'imunify.files' => 1024,
            'imunify.target' => 'public_html',
            'imunify.cache_age_seconds' => 300,
        ]);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'modules']))
            ->assertOk()
            ->assertSee('One disk needs attention')
            ->assertSee('sda')
            ->assertSee('alice')
            ->assertSee('12.5')
            ->assertSee('public_html')
            ->assertSee('1024')
            ->assertSee('3');
    }

    public function test_panel_and_modules_show_empty_states_without_details(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $this->sample($server);

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'panel']))
            ->assertOk()
            ->assertSee('No panel metrics were recorded for this sample.');

        $this->actingAs($viewer)
            ->get(route('servers.show', ['server' => $server, 'tab' => 'modules']))
            ->assertOk()
            ->assertSee('No module data was reported for this sample.');
    }

    public function test_views_do_not_use_unescaped_output(): void
    {
        foreach ([
            resource_path('views/livewire/servers/tabs/panel.blade.php'),
            resource_path('views/livewire/servers/tabs/modules.blade.php'),
            resource_path('views/servers/snapshot.blade.php'),
        ] as $path) {
            $this->assertStringNotContainsString('{!!', file_get_contents($path), $path);
        }
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

    private function sample(Server $server): MetricSample
    {
        return MetricSample::query()->create([
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
