<?php

namespace Tests\Feature\Database;

use App\Enums\AlertRule;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_required_indexes_exist_in_the_database(): void
    {
        $this->assertTrue(
            $this->indexExists('server_tokens', ['server_id'], false),
            'server_tokens.server_id must have a non-unique index.',
        );

        $this->assertTrue(
            $this->indexExists('metric_samples', ['server_id', 'collected_at'], true),
            'metric_samples must keep its unique sample idempotency index.',
        );

        $this->assertTrue(
            $this->indexExists('metric_samples', ['server_id', 'collected_at'], false),
            'metric_samples must keep its non-unique lookup index.',
        );

        $this->assertTrue(
            $this->indexExists('metric_rollups', ['server_id', 'resolution_seconds', 'bucket_start'], true),
            'metric_rollups must keep its unique rollup-bucket index.',
        );

        $this->assertTrue(
            $this->indexExists('metric_rollups', ['server_id', 'resolution_seconds', 'bucket_start'], false),
            'metric_rollups must keep its non-unique lookup index.',
        );

        $this->assertTrue(
            $this->indexExists('alert_deliveries', ['alert_id', 'channel', 'event'], true),
            'alert_deliveries must keep its unique delivery index.',
        );

        $this->assertTrue(
            $this->indexExists('alerts', ['active_key'], true),
            'alerts.active_key must be unique.',
        );
    }

    public function test_server_metadata_columns_are_indexed(): void
    {
        foreach (['server_type', 'provider', 'location', 'alerts_enabled'] as $column) {
            $this->assertTrue(
                $this->indexExists('servers', [$column], false),
                "servers.{$column} must have a non-unique index.",
            );
        }
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

    public function test_server_names_are_unique_but_agent_uuids_may_repeat(): void
    {
        $agentUuid = (string) Str::uuid();

        $first = $this->createServer(['name' => 'web-1', 'agent_uuid' => $agentUuid]);
        $second = $this->createServer(['name' => 'web-2', 'agent_uuid' => $agentUuid]);

        $this->assertSame($agentUuid, $first->agent_uuid);
        $this->assertSame($agentUuid, $second->agent_uuid);

        $this->expectException(QueryException::class);

        $this->createServer(['name' => 'web-1', 'agent_uuid' => (string) Str::uuid()]);
    }

    public function test_duplicate_server_and_collected_at_is_rejected(): void
    {
        $server = $this->createServer();
        $collectedAt = now()->subMinute();

        $this->createSample($server, $collectedAt);

        $this->expectException(QueryException::class);

        $this->createSample($server, $collectedAt);
    }

    public function test_duplicate_rollup_bucket_is_rejected(): void
    {
        $server = $this->createServer();

        $this->createRollup($server);

        $this->expectException(QueryException::class);

        $this->createRollup($server);
    }

    public function test_multiple_resolved_alerts_may_have_a_null_active_key(): void
    {
        $server = $this->createServer();

        $first = $this->createAlert($server, AlertStatus::Resolved, null, AlertRule::CpuHigh);
        $second = $this->createAlert($server, AlertStatus::Resolved, null, AlertRule::CpuHigh);

        $this->assertNull($first->active_key);
        $this->assertNull($second->active_key);
        $this->assertSame(2, Alert::query()->count());
    }

    public function test_active_alert_keys_are_unique(): void
    {
        $server = $this->createServer();
        $activeKey = $server->id.':cpu_high';

        $this->createAlert($server, AlertStatus::Open, $activeKey, AlertRule::CpuHigh);

        $this->expectException(QueryException::class);

        $this->createAlert($server, AlertStatus::Open, $activeKey, AlertRule::CpuHigh);
    }

    public function test_deleting_a_server_cascades_all_owned_rows(): void
    {
        $server = $this->createServer();
        $this->createToken($server);
        $sample = $this->createSample($server, now()->subMinute());
        $this->createDetail($sample);
        $this->createRollup($server);
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', AlertRule::CpuHigh);
        $this->createDelivery($alert);

        $server->forceDelete();

        $this->assertSame(0, ServerToken::query()->count());
        $this->assertSame(0, MetricSample::query()->count());
        $this->assertSame(0, MetricDetail::query()->count());
        $this->assertSame(0, MetricRollup::query()->count());
        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(0, AlertDelivery::query()->count());
    }

    public function test_soft_deleting_a_server_keeps_rows_until_force_delete(): void
    {
        $server = $this->createServer();
        $this->createToken($server);
        $sample = $this->createSample($server, now()->subMinute());
        $this->createDetail($sample);
        $this->createRollup($server);
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', AlertRule::CpuHigh);
        $this->createDelivery($alert);

        $server->delete();

        $this->assertSoftDeleted('servers', ['id' => $server->id]);
        $this->assertNotNull(DB::table('servers')->where('id', $server->id)->value('deleted_at'));
        $this->assertSame(1, ServerToken::query()->count());
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, MetricDetail::query()->count());
        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame(1, Alert::query()->count());
        $this->assertSame(1, AlertDelivery::query()->count());

        $server->forceDelete();

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
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
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', AlertRule::CpuHigh);
        $this->createDelivery($alert);

        $alert->delete();

        $this->assertSame(0, AlertDelivery::query()->count());
    }

    public function test_delivery_channel_event_combination_is_unique(): void
    {
        $server = $this->createServer();
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', AlertRule::CpuHigh);

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

        $this->assertEqualsWithDelta(12.34, $server->cpu_usage_pct, 0.0001);
        $this->assertEqualsWithDelta(56.78, $server->mem_used_pct, 0.0001);
        $this->assertEqualsWithDelta(1.234, $server->load1, 0.0001);
        $this->assertEqualsWithDelta(98.76, $sample->cpu_usage_pct, 0.0001);
        $this->assertEqualsWithDelta(54.32, $sample->mem_used_pct, 0.0001);
        $this->assertEqualsWithDelta(2.345, $sample->load1, 0.0001);
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

    public function test_server_status_is_offline_when_either_threshold_is_missing(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $missingStaleAfter = $this->createServer([
            'name' => 'web-stale-null',
            'last_seen_at' => now(),
            'stale_after' => null,
            'offline_after' => now()->addMinutes(5),
        ]);

        $missingOfflineAfter = $this->createServer([
            'name' => 'web-offline-null',
            'last_seen_at' => now(),
            'stale_after' => now()->addMinutes(2),
            'offline_after' => null,
        ]);

        $this->assertSame(ServerStatus::Offline, $missingStaleAfter->status());
        $this->assertSame(ServerStatus::Offline, $missingOfflineAfter->status());
    }

    public function test_server_relations_return_owned_rows(): void
    {
        $server = $this->createServer();
        $token = $this->createToken($server);
        $newer = $this->createSample($server, now()->subMinute());
        $older = $this->createSample($server, now()->subMinutes(2));
        $rollup = $this->createRollup($server);
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', AlertRule::CpuHigh);

        // Insertion order and collected_at order deliberately disagree.
        $this->assertLessThan($older->id, $newer->id);
        $this->assertTrue($newer->collected_at->greaterThan($older->collected_at));

        $this->assertTrue($server->tokens()->first()->is($token));
        $this->assertTrue($server->samples()->orderBy('collected_at')->first()->is($older));
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

    public function test_json_casts_round_trip(): void
    {
        $server = $this->createServer([
            'issue_codes' => ['cpu_high', 'disk_high'],
        ]);
        $sample = $this->createSample($server, now()->subMinute());
        $detail = $this->createDetail($sample);
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':cpu_high', AlertRule::CpuHigh);
        $event = AuditEvent::create([
            'action' => 'server.created',
            'target_type' => Server::class,
            'target_id' => $server->id,
            'meta_json' => ['name' => 'web-1'],
        ]);

        $server = Server::findOrFail($server->id);
        $detail = MetricDetail::findOrFail($detail->getKey());
        $alert = Alert::findOrFail($alert->id);
        $event = AuditEvent::findOrFail($event->id);

        $this->assertSame(['cpu_high', 'disk_high'], $server->issue_codes);
        $this->assertSame(['mysql' => ['available' => 1]], $detail->panel_metrics);
        $this->assertSame(['smart' => ['status' => 'ok']], $detail->extensions);
        $this->assertSame(['value' => 91.5, 'threshold' => 90], $alert->context_json);
        $this->assertSame(['name' => 'web-1'], $event->meta_json);
    }

    public function test_sensitive_attributes_are_hidden_from_serialization(): void
    {
        $server = $this->createServer();
        $token = $this->createToken($server);
        $setting = Setting::create([
            'key' => 'mail.password',
            'value' => 'super-secret',
            'type' => 'secret',
            'is_secret' => true,
        ]);

        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertArrayNotHasKey('value', $setting->toArray());
    }

    public function test_alert_rule_enum_matches_the_specification(): void
    {
        $this->assertCount(14, AlertRule::cases());
        $this->assertSame([
            'server_offline',
            'server_stale',
            'cpu_high',
            'memory_high',
            'disk_high',
            'inode_high',
            'load_high',
            'smart_failed',
            'smart_warning',
            'service_down',
            'mysql_unreachable',
            'quota_over',
            'module_cache_stale',
            'security_disabled',
        ], array_map(static fn (AlertRule $rule): string => $rule->value, AlertRule::cases()));
    }

    public function test_alert_rule_casts_round_trip(): void
    {
        $server = $this->createServer();
        $alert = $this->createAlert($server, AlertStatus::Open, $server->id.':disk_high', AlertRule::DiskHigh);

        $this->assertSame(AlertRule::DiskHigh, $alert->rule_key);
        $this->assertSame('disk_high', DB::table('alerts')->where('id', $alert->id)->value('rule_key'));
        $this->assertSame(AlertRule::DiskHigh, Alert::findOrFail($alert->id)->rule_key);
    }

    public function test_alert_enum_casts_round_trip(): void
    {
        $server = $this->createServer();
        $alert = $this->createAlert($server, AlertStatus::Acknowledged, $server->id.':cpu_high', AlertRule::CpuHigh);

        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertSame('acknowledged', DB::table('alerts')->where('id', $alert->id)->value('status'));
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexExists(string $table, array $columns, bool $unique): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => $index['columns'] === $columns && $index['unique'] === $unique,
        );
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

    private function createAlert(Server $server, AlertStatus $status, ?string $activeKey, AlertRule $ruleKey): Alert
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
