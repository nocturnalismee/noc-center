<?php

namespace Tests\Feature\Fleet;

use App\Enums\ServerStatus;
use App\Fleet\FleetState;
use App\Models\Server;
use App\Models\Setting;
use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FleetStateTest extends TestCase
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

    public function test_status_boundaries_are_absolute_to_the_second(): void
    {
        $now = now()->utc();

        $this->assertSame(
            ServerStatus::Online,
            FleetState::status($this->server('at-stale', [
                'stale_after' => $now->copy(),
                'offline_after' => $now->copy()->addMinute(),
            ]), $now),
        );

        $this->assertSame(
            ServerStatus::Stale,
            FleetState::status($this->server('past-stale', [
                'stale_after' => $now->copy()->subSecond(),
                'offline_after' => $now->copy()->addMinute(),
            ]), $now),
        );

        $this->assertSame(
            ServerStatus::Stale,
            FleetState::status($this->server('at-offline', [
                'stale_after' => $now->copy()->subMinute(),
                'offline_after' => $now->copy(),
            ]), $now),
        );

        $this->assertSame(
            ServerStatus::Offline,
            FleetState::status($this->server('past-offline', [
                'stale_after' => $now->copy()->subMinute(),
                'offline_after' => $now->copy()->subSecond(),
            ]), $now),
        );

        $this->assertSame(
            ServerStatus::NeverSeen,
            FleetState::status($this->server('never', [
                'last_seen_at' => null,
                'stale_after' => null,
                'offline_after' => null,
            ]), $now),
        );

        $this->assertSame(
            ServerStatus::Offline,
            FleetState::status($this->server('null-stale', [
                'stale_after' => null,
                'offline_after' => $now->copy()->addMinute(),
            ]), $now),
        );

        $this->assertSame(
            ServerStatus::Offline,
            FleetState::status($this->server('null-offline', [
                'stale_after' => $now->copy()->addMinute(),
                'offline_after' => null,
            ]), $now),
        );
    }

    public function test_inconsistent_thresholds_follow_the_online_first_order(): void
    {
        $now = now()->utc();

        $this->assertSame(
            ServerStatus::Online,
            FleetState::status($this->server('inconsistent', [
                'stale_after' => $now->copy()->addMinutes(5),
                'offline_after' => $now->copy()->subMinutes(5),
            ]), $now),
        );
    }

    public function test_server_status_delegates_to_the_module(): void
    {
        $this->assertSame(ServerStatus::Online, $this->server('online')->status());
        $this->assertSame(ServerStatus::Stale, $this->server('stale', [
            'last_seen_at' => now()->subMinutes(10),
            'stale_after' => now()->subMinutes(5),
            'offline_after' => now()->addMinutes(5),
        ])->status());
        $this->assertSame(ServerStatus::NeverSeen, $this->server('never', [
            'last_seen_at' => null,
        ])->status());
    }

    public function test_status_filter_sql_agrees_with_the_module_status(): void
    {
        $now = now()->utc();

        $online = $this->server('online');
        $stale = $this->server('stale', [
            'last_seen_at' => now()->subMinutes(10),
            'stale_after' => now()->subMinutes(5),
            'offline_after' => now()->addMinutes(5),
        ]);
        $offline = $this->server('offline', [
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ]);
        $never = $this->server('never', [
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);
        $nullThreshold = $this->server('null-threshold', [
            'stale_after' => null,
            'offline_after' => null,
        ]);
        $attention = $this->server('attention', [
            'health' => 'critical',
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ]);

        $this->assertSame([$online->name], $this->namesFor('online', $now));
        $this->assertSame([$stale->name], $this->namesFor('stale', $now));
        $this->assertEqualsCanonicalizing(
            [$offline->name, $nullThreshold->name, $attention->name],
            $this->namesFor('offline', $now),
        );
        $this->assertSame([$never->name], $this->namesFor('never', $now));
        $this->assertSame([$attention->name], $this->namesFor('attention', $now));

        foreach ([$online, $stale, $offline, $never, $nullThreshold, $attention] as $server) {
            $this->assertSame(
                $server->status(),
                FleetState::status($server, $now),
            );
        }
    }

    public function test_counts_match_the_fixture_matrix(): void
    {
        $this->server('online');
        $this->server('stale', [
            'last_seen_at' => now()->subMinutes(10),
            'stale_after' => now()->subMinutes(5),
            'offline_after' => now()->addMinutes(5),
        ]);
        $this->server('offline', [
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ]);
        $this->server('null-threshold', [
            'stale_after' => null,
            'offline_after' => null,
        ]);
        $this->server('never', [
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);
        $this->server('warning', ['health' => 'warning']);

        $this->server('deleted')->delete();

        $this->assertSame([
            'all' => 6,
            'online' => 2,
            'attention' => 1,
            'stale' => 1,
            'offline' => 2,
            'never' => 1,
        ], FleetState::counts(Server::query(), now()->utc()));
    }

    public function test_apply_status_sort_orders_online_stale_offline_never(): void
    {
        $this->server('never', [
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);
        $this->server('offline', [
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ]);
        $this->server('online');
        $this->server('stale', [
            'last_seen_at' => now()->subMinutes(10),
            'stale_after' => now()->subMinutes(5),
            'offline_after' => now()->addMinutes(5),
        ]);

        $query = Server::query();
        FleetState::applyStatusSort($query, 'asc', now()->utc());

        $this->assertSame(
            ['online', 'stale', 'offline', 'never'],
            $query->pluck('name')->all(),
        );
    }

    public function test_hot_uses_configured_warning_thresholds_and_only_online_servers(): void
    {
        $this->setSetting('alerts.rules.memory_high.warning', '95');

        $this->server('mem-91', ['mem_used_pct' => 91]);
        $this->server('mem-96', ['mem_used_pct' => 96]);
        $this->server('mem-99-offline', [
            'mem_used_pct' => 99,
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ]);
        $this->server('disk-86', ['disk_used_pct' => 86]);
        $this->server('disk-70', ['disk_used_pct' => 70]);

        $settings = app(SettingsRepository::class);

        $this->assertSame(95, FleetState::usageThresholds($settings)['memory']['warning']);

        $summary = FleetState::summary($settings, now()->utc());

        // mem-96 and disk-86 are hot; 91% memory is below the 95 warning,
        // the offline server is excluded, and the default disk warning of 85
        // still catches 86%.
        $this->assertSame(2, $summary['hot']);
    }

    public function test_usage_thresholds_default_to_config(): void
    {
        $thresholds = FleetState::usageThresholds(app(SettingsRepository::class));

        $this->assertSame([
            'cpu' => ['warning' => 90, 'critical' => 95],
            'memory' => ['warning' => 90, 'critical' => 95],
            'disk' => ['warning' => 85, 'critical' => 95],
        ], $thresholds);
    }

    public function test_summary_computes_attention_gaps_and_samples_from_the_same_rows(): void
    {
        $this->server('online-1', ['health' => 'warning', 'interval_seconds' => 60]);
        $this->server('online-2', ['health' => 'ok', 'interval_seconds' => 120]);
        $this->server('stale-1', [
            'health' => 'ok',
            'interval_seconds' => 60,
            'last_seen_at' => now()->subMinutes(10),
            'stale_after' => now()->subMinutes(5),
            'offline_after' => now()->addMinutes(5),
        ]);
        $this->server('offline-critical', [
            'health' => 'critical',
            'interval_seconds' => 60,
            'last_seen_at' => now()->subMinutes(20),
            'stale_after' => now()->subMinutes(10),
            'offline_after' => now()->subMinutes(5),
        ]);
        $this->server('never', [
            'health' => 'unknown',
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);
        $this->server('null-threshold', ['health' => 'ok', 'stale_after' => null, 'offline_after' => null]);

        $summary = FleetState::summary(app(SettingsRepository::class), now()->utc());

        $this->assertSame(6, $summary['total']);
        $this->assertSame(2, $summary['online']);
        $this->assertSame(2, $summary['attention']);
        $this->assertSame(4, $summary['gaps']);
        // 60s interval = 1/min, 120s interval = 0.5/min, rounded to 2.
        $this->assertSame(2, $summary['samples_per_minute']);
    }

    public function test_samples_per_minute_doubles_for_thirty_second_intervals(): void
    {
        $this->server('fast', ['interval_seconds' => 30]);
        $this->server('default');

        $summary = FleetState::summary(app(SettingsRepository::class), now()->utc());

        $this->assertSame(3, $summary['samples_per_minute']);
    }

    public function test_soft_deleted_servers_are_outside_counts_and_summary(): void
    {
        $this->server('gone')->delete();
        $this->server('kept');

        $this->assertSame(1, FleetState::counts(Server::query(), now()->utc())['all']);
        $this->assertSame(1, FleetState::summary(app(SettingsRepository::class), now()->utc())['total']);
    }

    /**
     * @return list<string>
     */
    private function namesFor(string $status, CarbonInterface $now): array
    {
        $query = Server::query();
        FleetState::applyStatus($query, $status, $now);

        return $query->orderBy('name')->pluck('name')->all();
    }

    private function setSetting(string $key, string $value): void
    {
        Setting::query()->create([
            'key' => $key,
            'value' => $value,
            'type' => 'int',
            'is_secret' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(string $name, array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => $name,
            'hostname' => $name.'.nusantara.id',
            'health' => 'ok',
            'issue_codes' => [],
            'is_active' => true,
            'interval_seconds' => 60,
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
}
