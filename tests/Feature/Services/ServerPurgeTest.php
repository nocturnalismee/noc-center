<?php

namespace Tests\Feature\Services;

use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\Servers\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Permanent server purge (spec: purge-server design).
 *
 * A soft-deleted server may be force-deleted with all of its history. Counts
 * cover samples, sample details, rollups, alerts, alert deliveries, and
 * tokens. The purge writes an append-only `server.purged` audit event, and an
 * active server is refused before anything is counted or deleted.
 */
class ServerPurgeTest extends TestCase
{
    use RefreshDatabase;

    private ServerService $servers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servers = $this->app->make(ServerService::class);
    }

    public function test_purge_counts_reports_every_cascading_row(): void
    {
        $server = $this->deletedServerWithHistory();

        $counts = $this->servers->purgeCounts($server);

        $this->assertSame([
            'samples' => 1,
            'details' => 1,
            'rollups' => 1,
            'alerts' => 1,
            'deliveries' => 1,
            'tokens' => 1,
        ], $counts);
    }

    public function test_purge_counts_changes_nothing(): void
    {
        $server = $this->deletedServerWithHistory();

        $this->servers->purgeCounts($server);

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, DB::table('metric_details')->count());
        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame(1, Alert::query()->count());
        $this->assertSame(1, AlertDelivery::query()->count());
        $this->assertSame(1, ServerToken::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_purge_force_deletes_the_server_and_all_related_rows(): void
    {
        $server = $this->deletedServerWithHistory();
        $serverId = (string) $server->getKey();

        $counts = $this->servers->purge($server);

        $this->assertSame([
            'samples' => 1,
            'details' => 1,
            'rollups' => 1,
            'alerts' => 1,
            'deliveries' => 1,
            'tokens' => 1,
        ], $counts);

        $this->assertNull(Server::withTrashed()->find($serverId));
        $this->assertSame(0, MetricSample::query()->count());
        $this->assertSame(0, DB::table('metric_details')->count());
        $this->assertSame(0, MetricRollup::query()->count());
        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(0, AlertDelivery::query()->count());
        $this->assertSame(0, ServerToken::query()->count());
    }

    public function test_purge_writes_an_append_only_audit_event(): void
    {
        $server = $this->deletedServerWithHistory();
        $serverId = (string) $server->getKey();

        $this->servers->purge($server);

        $event = AuditEvent::query()->where('action', 'server.purged')->sole();

        $this->assertSame(Server::class, $event->target_type);
        $this->assertSame($serverId, $event->target_id);
        $this->assertSame($serverId, $event->meta_json['server_id']);
        $this->assertSame('purge-01', $event->meta_json['name']);
        $this->assertSame([
            'samples' => 1,
            'details' => 1,
            'rollups' => 1,
            'alerts' => 1,
            'deliveries' => 1,
            // A `tokens` count is not a secret; `AuditLogger` keeps integers
            // under that key and redacts only non-integer values.
            'tokens' => 1,
        ], $event->meta_json['counts']);
        // Cascade deletion must not remove the audit trail.
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_purge_records_the_actor_when_given(): void
    {
        $actor = User::factory()->create();
        $server = $this->deletedServerWithHistory();

        $this->servers->purge($server, $actor);

        $event = AuditEvent::query()->where('action', 'server.purged')->sole();

        $this->assertSame($actor->getKey(), $event->user_id);
    }

    public function test_purge_records_the_manual_trigger_by_default(): void
    {
        $server = $this->deletedServerWithHistory();

        $this->servers->purge($server);

        $event = AuditEvent::query()->where('action', 'server.purged')->sole();

        $this->assertSame('manual', $event->meta_json['trigger']);
    }

    public function test_purge_records_the_auto_trigger_when_given(): void
    {
        $server = $this->deletedServerWithHistory();

        $this->servers->purge($server, null, 'auto');

        $event = AuditEvent::query()->where('action', 'server.purged')->sole();

        $this->assertSame('auto', $event->meta_json['trigger']);
        $this->assertNull($event->user_id);
    }

    public function test_purge_refuses_an_active_server_and_deletes_nothing(): void
    {
        $server = $this->activeServerWithHistory();

        try {
            $this->servers->purge($server);
            $this->fail('Purging an active server must throw a LogicException.');
        } catch (LogicException) {
        }

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, DB::table('metric_details')->count());
        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame(1, Alert::query()->count());
        $this->assertSame(1, AlertDelivery::query()->count());
        $this->assertSame(1, ServerToken::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function deletedServerWithHistory(): Server
    {
        return $this->serverWithHistory(deleted: true);
    }

    private function activeServerWithHistory(): Server
    {
        return $this->serverWithHistory(deleted: false);
    }

    private function serverWithHistory(bool $deleted): Server
    {
        $server = Server::query()->create([
            'name' => 'purge-01',
            'hostname' => 'purge-01.example.test',
            'is_active' => true,
        ]);

        $sample = MetricSample::query()->create([
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

        DB::table('metric_details')->insert([
            'sample_id' => $sample->getKey(),
            'panel_metrics' => null,
            'extensions' => null,
            'size_bytes' => 100,
            'created_at' => '2026-11-27 00:00:00',
        ]);

        MetricRollup::query()->create([
            'server_id' => $server->getKey(),
            'resolution_seconds' => 300,
            'bucket_start' => Carbon::parse('2026-11-27 00:00:00'),
            'samples' => 1,
            'cpu_usage_sum' => 1,
            'cpu_iowait_sum' => 0,
            'mem_used_pct_sum' => 10,
            'disk_used_pct_sum' => 10,
            'load1_sum' => 0,
            'net_rx_bps_sum' => 0,
            'net_tx_bps_sum' => 0,
            'cpu_usage_min' => 1,
            'cpu_usage_max' => 1,
            'mem_used_pct_min' => 10,
            'mem_used_pct_max' => 10,
            'disk_used_pct_min' => 10,
            'disk_used_pct_max' => 10,
            'load1_max' => 0,
        ]);

        $alert = Alert::query()->create([
            'server_id' => $server->getKey(),
            'rule_key' => 'memory_high',
            'severity' => 'warning',
            'status' => 'resolved',
            'active_key' => null,
            'title' => 'Memory high',
            'message' => 'Memory is high.',
            'first_seen_at' => Carbon::parse('2026-11-27 00:00:00'),
            'last_seen_at' => Carbon::parse('2026-11-27 00:00:00'),
            'resolved_at' => Carbon::parse('2026-11-27 01:00:00'),
        ]);

        AlertDelivery::query()->create([
            'alert_id' => $alert->getKey(),
            'channel' => 'email',
            'event' => 'opened',
            'status' => 'sent',
            'recipient_count' => 1,
        ]);

        ServerToken::query()->create([
            'server_id' => $server->getKey(),
            'token_hash' => hash('sha256', Str::random(32)),
            'token_prefix' => Str::random(8),
        ]);

        if ($deleted) {
            $server->delete();
        }

        return $server;
    }
}
