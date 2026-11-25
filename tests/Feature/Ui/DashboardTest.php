<?php

namespace Tests\Feature\Ui;

use App\Livewire\Dashboard;
use App\Livewire\Servers\ServerTable;
use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
use App\Services\FleetSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_fleet_summary_uses_the_documented_boundaries(): void
    {
        $alertServer = $this->online('web-01');
        $this->online('db-01', ['health' => 'warning', 'cpu_usage_pct' => 91]);
        $this->online('web-02', ['health' => 'critical', 'mem_used_pct' => 90]);
        $this->stale('cache-01');
        $this->offline('backup-01');
        $this->neverSeen('node-07');
        $this->online('null-threshold', ['stale_after' => null, 'offline_after' => null]);
        $this->online('hot-disk', ['disk_used_pct' => 95, 'interval_seconds' => 30]);

        Alert::query()->create($this->alertAttributes($alertServer, 'open'));
        Alert::query()->create($this->alertAttributes($alertServer, 'open'));
        Alert::query()->create($this->alertAttributes($alertServer, 'acknowledged'));

        $summary = $this->app->make(FleetSummary::class)->summary();

        $this->assertSame(8, $summary['total']);
        $this->assertSame(4, $summary['online']);
        $this->assertSame(2, $summary['attention']);
        $this->assertSame(3, $summary['hot']);
        $this->assertSame(4, $summary['gaps']);
        $this->assertSame(2, $summary['open_alerts']);
        // Four online servers: three at 60s and hot-disk at 30s = 5/min.
        $this->assertSame(5, $summary['samples_per_minute']);
    }

    public function test_the_fleet_summary_is_cached_for_twenty_seconds(): void
    {
        $this->online('web-01');

        $summary = $this->app->make(FleetSummary::class);

        $this->assertSame(1, $summary->summary()['online']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $summary->summary();

        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_soft_deleted_servers_are_outside_the_fleet_summary(): void
    {
        $this->online('web-01')->delete();
        $this->online('web-02');

        $summary = $this->app->make(FleetSummary::class)->summary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['online']);
    }

    public function test_an_online_server_with_a_warning_health_renders_the_warning_state(): void
    {
        $this->online('db-01', ['health' => 'warning']);

        Livewire::test(ServerTable::class)
            ->assertSee('data-state="warning"', false)
            ->assertSee('Warning');
    }

    public function test_the_dashboard_renders_the_summary_and_server_table(): void
    {
        $this->online('web-01');
        $this->neverSeen('node-07');

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Fleet');
        $response->assertSee('1/2');
        $response->assertSee('Add server');
        $response->assertSee('web-01');
        $response->assertSee('node-07');
        $response->assertDontSee('<x-sparkline', false);
        $response->assertDontSee('stat-card', false);
    }

    public function test_the_dashboard_renders_open_alerts_collection_and_activity(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Andi Pratama']);
        $server = $this->online('web-01');

        Alert::query()->create(array_merge($this->alertAttributes($server, 'open'), [
            'severity' => 'critical',
            'title' => 'Disk almost full',
            'message' => 'Disk almost full on web-01: 93% (threshold 85%)',
        ]));

        AuditEvent::query()->create([
            'user_id' => $admin->id,
            'action' => 'server.created',
            'target_type' => Server::class,
            'target_id' => $server->id,
            'ip' => '10.0.0.1',
            'meta_json' => ['server_id' => $server->id],
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Open alerts')
            ->assertSee('View all')
            ->assertSee('Disk almost full')
            ->assertSee('web-01')
            ->assertSee('Collection')
            ->assertSee('Agents reporting')
            ->assertSee('1 / 1')
            ->assertSee('Last rollup')
            ->assertSee('Next retention')
            ->assertSee('Failed jobs')
            ->assertSee('Activity')
            ->assertSee('Open audit log')
            ->assertSee('Andi Pratama')
            ->assertSee('Created server');
    }

    public function test_dashboard_caches_survive_a_serialized_cache_store(): void
    {
        config(['cache.default' => 'database']);
        Cache::setDefaultDriver('database');
        Cache::flush();

        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Andi Pratama']);
        $server = $this->online('web-01');

        Alert::query()->create(array_merge($this->alertAttributes($server, 'open'), [
            'severity' => 'critical',
            'title' => 'Disk almost full',
        ]));

        AuditEvent::query()->create([
            'user_id' => $admin->id,
            'action' => 'server.created',
            'target_type' => Server::class,
            'target_id' => $server->id,
            'meta_json' => ['server_id' => $server->id],
        ]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Disk almost full')
            ->assertSee('Created server');
    }

    public function test_open_alerts_put_critical_before_warning(): void
    {
        $server = $this->online('web-01');

        Alert::query()->create(array_merge($this->alertAttributes($server, 'open'), [
            'severity' => 'warning',
            'title' => 'CPU sustained high',
            'last_seen_at' => now()->subMinutes(2),
        ]));
        Alert::query()->create(array_merge($this->alertAttributes($server, 'open'), [
            'severity' => 'critical',
            'title' => 'Disk almost full',
            'last_seen_at' => now()->subMinutes(9),
        ]));

        $alerts = Livewire::test(Dashboard::class)->viewData('openAlerts');

        $this->assertSame('Disk almost full', $alerts->first()->title);
        $this->assertSame('critical', $alerts->first()->severity->value);
    }

    public function test_viewers_do_not_see_the_audit_activity_section(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->online('web-01');

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Open alerts')
            ->assertDontSee('Open audit log')
            ->assertDontSee('Created server');
    }

    public function test_guests_are_redirected_to_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_status_filters_follow_the_absolute_liveness_boundaries(): void
    {
        $this->online('online-01');
        $this->stale('stale-01');
        $this->offline('offline-01');
        $this->neverSeen('never-01');
        $this->online('null-threshold', ['stale_after' => null, 'offline_after' => null]);

        Livewire::test(ServerTable::class)
            ->set('status', 'online')
            ->assertSee('online-01')
            ->assertDontSee('stale-01')
            ->assertDontSee('null-threshold')
            ->set('status', 'stale')
            ->assertSee('stale-01')
            ->assertDontSee('online-01')
            ->set('status', 'offline')
            ->assertSee('offline-01')
            ->assertSee('null-threshold')
            ->assertDontSee('never-01')
            ->set('status', 'never')
            ->assertSee('never-01')
            ->assertDontSee('offline-01');
    }

    public function test_the_active_status_tab_uses_a_focus_colour_underline(): void
    {
        Livewire::test(ServerTable::class)
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('bg-focus', false)
            ->call('setStatus', 'online')
            ->assertSee('bg-focus', false);
    }

    public function test_the_attention_tab_filters_by_server_health(): void
    {
        $this->online('web-01');
        $this->online('db-01', ['health' => 'warning']);
        $this->offline('backup-01', ['health' => 'critical']);

        Livewire::test(ServerTable::class)
            ->set('status', 'attention')
            ->assertSee('db-01')
            ->assertSee('backup-01')
            ->assertDontSee('web-01');
    }

    public function test_search_matches_name_hostname_ip_and_label(): void
    {
        $this->online('web-01', ['hostname' => 'web-01.nusantara.id', 'ip_address' => '10.0.0.1', 'label' => 'Primary']);
        $this->online('db-01', ['hostname' => 'db-01.nusantara.id', 'ip_address' => '10.0.0.2', 'label' => 'Database']);

        foreach (['web-01', 'nusantara', '10.0.0.2', 'Database'] as $term) {
            $html = Livewire::test(ServerTable::class)
                ->set('q', $term)
                ->html();

            $this->assertStringContainsString($term, $html);
        }

        Livewire::test(ServerTable::class)
            ->set('q', '10.0.0.2')
            ->assertSee('db-01')
            ->assertDontSee('web-01');
    }

    public function test_the_provider_filter_and_tab_counts_respect_the_search(): void
    {
        $this->online('web-01', ['provider' => 'Nusantara Cloud', 'server_type' => 'Web']);
        $this->online('web-02', ['provider' => 'Nusantara Cloud', 'server_type' => 'Web']);
        $this->online('db-01', ['provider' => 'Contabo', 'server_type' => 'Database']);

        $component = Livewire::test(ServerTable::class)
            ->assertSee('Provider')
            ->assertDontSee('All groups')
            ->set('provider', 'Nusantara Cloud')
            ->assertSee('web-01')
            ->assertSee('web-02')
            ->assertDontSee('db-01');

        $counts = $component->viewData('counts');

        $this->assertSame(2, $counts['all']);
        $this->assertSame(2, $counts['online']);

        $component->set('q', 'web-02');

        $counts = $component->viewData('counts');

        $this->assertSame(1, $counts['all']);
    }

    public function test_memory_and_disk_bars_follow_alert_thresholds(): void
    {
        $this->online('low-01', ['mem_used_pct' => 10, 'disk_used_pct' => 10]);
        $this->online('warn-01', ['mem_used_pct' => 90, 'disk_used_pct' => 85]);
        $this->online('crit-01', ['mem_used_pct' => 95, 'disk_used_pct' => 95]);
        $this->online('null-01', ['mem_used_pct' => null, 'disk_used_pct' => null]);

        $html = Livewire::test(ServerTable::class)->html();

        $this->assertSame(2, substr_count($html, 'data-tone="ok"'));
        $this->assertSame(2, substr_count($html, 'data-tone="warning"'));
        $this->assertSame(2, substr_count($html, 'data-tone="critical"'));
        $this->assertSame(3, substr_count($html, 'data-usage="memory"'));
        $this->assertSame(3, substr_count($html, 'data-usage="disk"'));
        $this->assertStringContainsString('style="width: 95%"', $html);
    }

    public function test_default_and_explicit_sorting(): void
    {
        $this->online('ok-01', ['health' => 'ok', 'cpu_usage_pct' => 10]);
        $this->online('critical-01', ['health' => 'critical', 'cpu_usage_pct' => 99]);
        $this->online('warning-01', ['health' => 'warning', 'cpu_usage_pct' => 50]);

        Livewire::test(ServerTable::class)
            ->assertSeeInOrder(['critical-01', 'warning-01', 'ok-01'])
            ->set('sort', 'cpu')
            ->set('dir', 'desc')
            ->assertSeeInOrder(['critical-01', 'warning-01', 'ok-01'])
            ->set('dir', 'asc')
            ->assertSeeInOrder(['ok-01', 'warning-01', 'critical-01']);
    }

    public function test_thirty_servers_paginate_twenty_five_at_a_time(): void
    {
        foreach (range(1, 30) as $i) {
            $this->online(sprintf('srv-%02d', $i));
        }

        $component = Livewire::test(ServerTable::class);

        $servers = $component->viewData('servers');

        $this->assertSame(25, $servers->count());
        $this->assertSame(30, $servers->total());
        $this->assertSame(2, $servers->lastPage());

        $component->call('setPage', 2);

        $this->assertSame(5, $component->viewData('servers')->count());
    }

    public function test_a_twenty_five_row_page_stays_within_the_query_budget(): void
    {
        foreach (range(1, 25) as $i) {
            $this->online(sprintf('srv-%02d', $i), ['issue_codes' => ['cpu_high', 'memory_high']]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::test(ServerTable::class)->assertOk();

        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
    }

    public function test_missing_metrics_render_an_em_dash_and_never_seen_servers_say_so(): void
    {
        $this->online('web-01', [
            'cpu_usage_pct' => null,
            'mem_used_pct' => null,
            'disk_used_pct' => null,
            'load1' => null,
            'last_sample_at' => null,
        ]);
        $this->neverSeen('node-07');

        $html = Livewire::test(ServerTable::class)->html();

        $this->assertStringContainsString('—', $html);
        $this->assertStringContainsString('No sample', $html);
        $this->assertStringContainsString('Never seen', $html);
    }

    public function test_issue_codes_render_as_compact_labels(): void
    {
        $this->online('web-01', ['issue_codes' => ['cpu_high', 'service_down', 'module_cache_stale']]);

        Livewire::test(ServerTable::class)
            ->assertSee('CPU')
            ->assertSee('SERVICE')
            ->assertSee('STALE');
    }

    public function test_an_empty_database_renders_the_add_server_cta(): void
    {
        Livewire::test(ServerTable::class)
            ->assertSee('No servers yet. Register the first server to start collecting metrics.')
            ->assertSee('href="'.route('servers.create').'"', false);
    }

    public function test_a_filter_with_no_matches_offers_a_clear_action_without_the_empty_fleet_cta(): void
    {
        $this->online('web-01');

        Livewire::test(ServerTable::class)
            ->set('q', 'does-not-exist')
            ->assertSee('No servers match these filters.')
            ->assertSee('Clear filters')
            ->assertDontSee('No servers yet.');
    }

    public function test_dashboard_renders_realtime_hooks_when_enabled(): void
    {
        config(['noc.realtime.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-realtime', false)
            ->assertSee('SampleStored', false)
            ->assertSee('AlertChanged', false)
            ->assertSee('OperationsChanged', false)
            ->assertSee('wire:poll.60s', false);
    }

    public function test_dashboard_keeps_polling_without_realtime(): void
    {
        config(['noc.realtime.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-realtime', false)
            ->assertSee('wire:poll.30s', false);
    }

    public function test_the_table_poll_refreshes_the_fleet_summary(): void
    {
        Livewire::test(ServerTable::class)
            ->assertSeeHtml('wire:poll.30s.visible="pollRefresh"')
            ->call('pollRefresh')
            ->assertDispatched('fleet-summary-refresh');

        $dashboard = Livewire::test(Dashboard::class)
            ->assertSee('0/0');

        $this->online('web-01');
        Cache::forget(FleetSummary::CACHE_KEY);

        $dashboard
            ->dispatch('fleet-summary-refresh')
            ->assertSee('1/1');
    }

    public function test_inconsistent_liveness_thresholds_follow_the_status_order(): void
    {
        $this->online('inconsistent', [
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->addMinutes(5),
            'offline_after' => now()->subMinutes(5),
        ]);

        $component = Livewire::test(ServerTable::class)
            ->set('status', 'online')
            ->assertSee('inconsistent')
            ->set('status', 'offline')
            ->assertDontSee('inconsistent');

        $counts = $component->viewData('counts');

        $this->assertSame(1, $counts['online']);
        $this->assertSame(0, $counts['offline']);
        $this->assertSame(1, $counts['all']);
    }

    private function online(string $name, array $attributes = []): Server
    {
        return $this->server($name, array_merge([
            'last_seen_at' => now(),
            'last_sample_at' => now()->subSeconds(8),
            'stale_after' => now()->addMinutes(5),
            'offline_after' => now()->addMinutes(10),
            'cpu_usage_pct' => 42,
            'mem_used_pct' => 68,
            'disk_used_pct' => 71,
            'load1' => 1.82,
        ], $attributes));
    }

    private function stale(string $name, array $attributes = []): Server
    {
        return $this->server($name, array_merge([
            'last_seen_at' => now()->subMinutes(10),
            'stale_after' => now()->subMinutes(5),
            'offline_after' => now()->addMinutes(5),
        ], $attributes));
    }

    private function offline(string $name, array $attributes = []): Server
    {
        return $this->server($name, array_merge([
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ], $attributes));
    }

    private function neverSeen(string $name, array $attributes = []): Server
    {
        return $this->server($name, array_merge([
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(string $name, array $attributes): Server
    {
        return Server::query()->create(array_merge([
            'name' => $name,
            'hostname' => $name.'.nusantara.id',
            'health' => 'ok',
            'issue_codes' => [],
            'is_active' => true,
            'interval_seconds' => 60,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function alertAttributes(Server $server, string $status): array
    {
        return [
            'server_id' => $server->id,
            'rule_key' => 'cpu_high',
            'severity' => 'warning',
            'status' => $status,
            'title' => 'CPU high',
            'message' => 'CPU is high',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
