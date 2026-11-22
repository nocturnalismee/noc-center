<?php

namespace Tests\Feature\Database;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\ServerStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\MetricDetail;
use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_server_ids_are_ulids(): void
    {
        $server = $this->createServer();

        $this->assertSame(26, strlen($server->id));
        $this->assertTrue(str()->isUlid($server->id));
    }

    public function test_token_hashes_are_unique(): void
    {
        $server = $this->createServer();

        $this->createToken($server, str_repeat('a', 64));

        $this->expectException(QueryException::class);

        $this->createToken($server, str_repeat('a', 64), 'noc_bbbb');
    }

    public function test_duplicate_server_and_collected_at_is_rejected(): void
    {
        $server = $this->createServer();
        $collectedAt = now()->subMinute();

        $this->createSample($server, $collectedAt);

        $this->expectException(QueryException::class);

        $this->createSample($server, $collectedAt);
    }

    public function test_multiple_resolved_alerts_may_have_a_null_active_key(): void
    {
        $server = $this->createServer();

        $first = $this->createAlert($server, AlertStatus::Resolved, null, 'cpu_high');
        $second = $this->createAlert($server, AlertStatus::Resolved, null, 'cpu_high');

        $this->assertNull($first->active_key);
        $this->assertNull($second->active_key);
        $this->assertSame(2, Alert::query()->count());
    }

    public function test_active_alert_keys_are_unique(): void
    {
        $server = $this->createServer();
        $activeKey = $server->id.':cpu_high';

        $this->createAlert($server, AlertStatus::Open, $activeKey, 'cpu_high');

        $this->expectException(QueryException::class);

        $this->createAlert($server, AlertStatus::Open, $activeKey, 'cpu_high');
    }

    public function test_deleting_a_server_cascades_all_owned_rows(): void
    {
        $server = $this->createServer();
        $this->createToken($server);
        $sample = $this->createSample($server, now()->subMinute());
        $this->createDetail($sample);
        $this->createRollup($server);
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', 'cpu_high');
        $this->createDelivery($alert);

        $server->forceDelete();

        $this->assertSame(0, ServerToken::query()->count());
        $this->assertSame(0, MetricSample::query()->count());
        $this->assertSame(0, MetricDetail::query()->count());
        $this->assertSame(0, MetricRollup::query()->count());
        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(0, AlertDelivery::query()->count());
    }

    public function test_deleting_a_sample_cascades_its_detail(): void
    {
        $server = $this->createServer();
        $sample = $this->createSample($server, now()->subMinute());
        $this->createDetail($sample);

        $sample->delete();

        $this->assertSame(0, MetricDetail::query()->count());
    }

    public function test_deleting_an_alert_cascades_its_deliveries(): void
    {
        $server = $this->createServer();
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', 'cpu_high');
        $this->createDelivery($alert);

        $alert->delete();

        $this->assertSame(0, AlertDelivery::query()->count());
    }

    public function test_delivery_channel_event_combination_is_unique(): void
    {
        $server = $this->createServer();
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', 'cpu_high');

        $this->createDelivery($alert);

        $this->expectException(QueryException::class);

        $this->createDelivery($alert);
    }

    public function test_decimal_columns_accept_two_decimal_values(): void
    {
        $server = $this->createServer([
            'cpu_usage_pct' => 12.34,
            'mem_used_pct' => 56.78,
            'load1' => 1.234,
        ]);
        $sample = $this->createSample($server, now()->subMinute(), [
            'cpu_usage_pct' => 98.76,
            'mem_used_pct' => 54.32,
            'load1' => 2.345,
        ]);

        $server->refresh();
        $sample->refresh();

        $this->assertSame(12.34, $server->cpu_usage_pct);
        $this->assertSame(56.78, $server->mem_used_pct);
        $this->assertSame(1.234, $server->load1);
        $this->assertSame(98.76, $sample->cpu_usage_pct);
        $this->assertSame(54.32, $sample->mem_used_pct);
        $this->assertSame(2.345, $sample->load1);
    }

    public function test_server_status_uses_absolute_thresholds(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $server = $this->createServer([
            'last_seen_at' => now(),
            'stale_after' => now()->addMinutes(2),
            'offline_after' => now()->addMinutes(5),
        ]);

        $this->assertSame(ServerStatus::Online, $server->status());

        Carbon::setTestNow('2026-11-22 12:02:00');
        $this->assertSame(ServerStatus::Online, $server->status());

        Carbon::setTestNow('2026-11-22 12:02:01');
        $this->assertSame(ServerStatus::Stale, $server->status());

        Carbon::setTestNow('2026-11-22 12:05:00');
        $this->assertSame(ServerStatus::Stale, $server->status());

        Carbon::setTestNow('2026-11-22 12:05:01');
        $this->assertSame(ServerStatus::Offline, $server->status());
    }

    public function test_server_status_is_never_seen_without_last_seen_at(): void
    {
        $server = $this->createServer([
            'last_seen_at' => null,
            'stale_after' => now()->addMinutes(2),
            'offline_after' => now()->addMinutes(5),
        ]);

        $this->assertSame(ServerStatus::NeverSeen, $server->status());
    }

    public function test_server_relations_return_owned_rows(): void
    {
        $server = $this->createServer();
        $token = $this->createToken($server);
        $older = $this->createSample($server, now()->subMinutes(2));
        $newer = $this->createSample($server, now()->subMinute());
        $rollup = $this->createRollup($server);
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', 'cpu_high');

        $this->assertTrue($server->tokens()->first()->is($token));
        $this->assertTrue($server->samples()->first()->is($older));
        $this->assertTrue($server->rollups()->first()->is($rollup));
        $this->assertTrue($server->alerts()->first()->is($alert));
        $this->assertTrue($server->latestSample()->first()->is($newer));
    }

    public function test_settings_use_the_key_as_primary_key(): void
    {
        $setting = Setting::create([
            'key' => 'alerts.cpu_high.warning',
            'value' => '90',
            'type' => 'int',
            'is_secret' => false,
        ]);

        $this->assertSame('alerts.cpu_high.warning', $setting->getKey());
        $this->assertFalse($setting->is_secret);
        $this->assertSame('90', Setting::find('alerts.cpu_high.warning')->value);
    }

    public function test_audit_events_are_append_only(): void
    {
        $event = AuditEvent::create([
            'action' => 'server.created',
            'target_type' => Server::class,
            'target_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'meta_json' => ['name' => 'web-1'],
        ]);

        $this->assertSame('server.created', $event->action);
        $this->assertSame(['name' => 'web-1'], $event->meta_json);
        $this->assertNotNull($event->created_at);

        try {
            $event->update(['action' => 'server.deleted']);
            $this->fail('AuditEvent::update() should throw.');
        } catch (LogicException) {
            // Expected.
        }

        try {
            $event->delete();
            $this->fail('AuditEvent::delete() should throw.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_alert_enum_casts_round_trip(): void
    {
        $server = $this->createServer();
        $alert = $this->createAlert($server, AlertStatus::Acknowledged, $server->id.':cpu_high', 'cpu_high');

        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertSame('acknowledged', DB::table('alerts')->where('id', $alert->id)->value('status'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createServer(array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => 'web-1',
            'hostname' => 'web-1.example.test',
        ], $attributes));
    }

    private function createToken(Server $server, ?string $hash = null, string $prefix = 'noc_aaaa'): ServerToken
    {
        return ServerToken::create([
            'server_id' => $server->id,
            'token_hash' => $hash ?? str_repeat('0', 64),
            'token_prefix' => $prefix,
            'label' => 'default',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSample(Server $server, Carbon $collectedAt, array $attributes = []): MetricSample
    {
        return MetricSample::create(array_merge([
            'server_id' => $server->id,
            'collected_at' => $collectedAt,
            'received_at' => $collectedAt->copy()->addSeconds(2),
            'schema_version' => 1,
            'agent_version' => '1.0.0',
            'interval_seconds' => 60,
            'cpu_usage_pct' => 12.34,
            'cpu_iowait_pct' => 1.23,
            'cpu_cores' => 4,
            'load1' => 0.5,
            'load5' => 0.4,
            'load15' => 0.3,
            'mem_total_bytes' => 8_000_000_000,
            'mem_used_bytes' => 4_000_000_000,
            'mem_used_pct' => 50.0,
            'swap_total_bytes' => 1_000_000_000,
            'swap_used_bytes' => 100_000_000,
            'swap_used_pct' => 10.0,
            'disk_total_bytes' => 100_000_000_000,
            'disk_used_bytes' => 40_000_000_000,
            'disk_used_pct' => 40.0,
            'disk_inode_pct' => 20.0,
            'net_rx_bps' => 1000,
            'net_tx_bps' => 2000,
            'processes_running' => 120,
            'processes_blocked' => 2,
            'uptime_seconds' => 86400,
            'payload_bytes' => 1024,
            'payload_hash' => str_repeat('b', 64),
        ], $attributes));
    }

    private function createDetail(MetricSample $sample): MetricDetail
    {
        return MetricDetail::create([
            'sample_id' => $sample->id,
            'panel_metrics' => ['mysql' => ['available' => 1]],
            'extensions' => ['smart' => ['status' => 'ok']],
            'size_bytes' => 2048,
        ]);
    }

    private function createRollup(Server $server): MetricRollup
    {
        return MetricRollup::create([
            'server_id' => $server->id,
            'resolution_seconds' => 300,
            'bucket_start' => now()->startOfHour(),
            'samples' => 10,
            'cpu_usage_sum' => 123.45,
            'cpu_iowait_sum' => 12.34,
            'mem_used_pct_sum' => 500.0,
            'disk_used_pct_sum' => 400.0,
            'load1_sum' => 5.5,
            'net_rx_bps_sum' => 10000,
            'net_tx_bps_sum' => 20000,
            'cpu_usage_min' => 10.0,
            'cpu_usage_max' => 20.0,
            'mem_used_pct_min' => 45.0,
            'mem_used_pct_max' => 55.0,
            'disk_used_pct_min' => 38.0,
            'disk_used_pct_max' => 42.0,
            'load1_max' => 0.9,
        ]);
    }

    private function createAlert(Server $server, AlertStatus $status, ?string $activeKey, string $ruleKey): Alert
    {
        return Alert::create([
            'server_id' => $server->id,
            'rule_key' => $ruleKey,
            'severity' => AlertSeverity::Warning,
            'status' => $status,
            'active_key' => $activeKey,
            'title' => 'CPU usage high',
            'message' => 'CPU usage is above the warning threshold.',
            'context_json' => ['value' => 91.5, 'threshold' => 90],
            'first_seen_at' => now()->subMinute(),
            'last_seen_at' => now(),
        ]);
    }

    private function createDelivery(Alert $alert): AlertDelivery
    {
        return AlertDelivery::create([
            'alert_id' => $alert->id,
            'channel' => 'email',
            'event' => 'opened',
            'status' => 'pending',
            'recipient_count' => 2,
            'attempts' => 0,
        ]);
    }
}
