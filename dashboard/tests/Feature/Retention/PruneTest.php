<?php

namespace Tests\Feature\Retention;

use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\Metrics\RetentionService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 12: batched retention pruning with a dry run (spec §7.11).
 *
 * A row exactly at its cutoff survives; only strictly older rows are deleted.
 * `metric_details` is pruned before `metric_samples` (samples cascade their
 * details), rollups are pruned by `bucket_start` per resolution, and open
 * alerts, active/grace tokens, and users are never touched. Real pruning is
 * capped by `batch_size`/`max_batches`; a dry run counts the whole eligible
 * set and deletes nothing.
 */
class PruneTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-23 12:00:00';

    /** @var list<string> */
    private const ORDER = [
        'metric_details',
        'metric_samples',
        'metric_rollups_5m',
        'metric_rollups_1h',
        'metric_rollups_1d',
        'resolved_alerts',
        'alert_deliveries',
        'audit_events',
        'failed_jobs',
        'server_tokens',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Boundaries
    // ------------------------------------------------------------------

    public function test_metric_samples_at_the_cutoff_survive_and_older_samples_are_deleted(): void
    {
        $server = $this->server();

        $boundary = $this->sample($server, $this->daysAgo(14));
        $older = $this->sample($server, $this->daysAgo(14)->subSecond());

        $counts = $this->prune();

        $this->assertSame(1, $counts['metric_samples']);
        $this->assertSame($boundary->getKey(), MetricSample::query()->sole()->getKey());
        $this->assertNull(MetricSample::query()->find($older->getKey()));
    }

    public function test_metric_details_are_pruned_by_their_own_created_at_even_when_the_sample_is_young(): void
    {
        $server = $this->server();

        // The sample is well inside the 14-day sample window.
        $sample = $this->sample($server, $this->daysAgo(1));

        $this->detail($sample->getKey(), $this->daysAgo(7));

        $counts = $this->prune();

        $this->assertSame(0, $counts['metric_samples']);
        $this->assertSame(0, $counts['metric_details']);
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, DB::table('metric_details')->count());

        $old = $this->sample($server, $this->daysAgo(2)->subMinute());
        $this->detail($old->getKey(), $this->daysAgo(7)->subSecond());

        $counts = $this->prune();

        $this->assertSame(1, $counts['metric_details']);
        $this->assertSame(0, $counts['metric_samples']);
        $this->assertSame(1, DB::table('metric_details')->count());
        $this->assertSame(2, MetricSample::query()->count());
    }

    public function test_metric_details_are_deleted_before_metric_samples(): void
    {
        $server = $this->server();

        $sample = $this->sample($server, $this->daysAgo(15));
        $this->detail($sample->getKey(), $this->daysAgo(15));

        $counts = $this->prune();

        $this->assertSame(1, $counts['metric_details']);
        $this->assertSame(1, $counts['metric_samples']);
        $this->assertSame(0, DB::table('metric_details')->count());
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_metric_rollups_are_pruned_per_resolution_and_other_resolutions_are_untouched(): void
    {
        $server = $this->server();

        $this->rollup($server, 300, $this->daysAgo(30));
        $this->rollup($server, 300, $this->daysAgo(30)->subSecond());
        $this->rollup($server, 300, $this->daysAgo(100));

        $this->rollup($server, 3600, $this->daysAgo(365));
        $this->rollup($server, 3600, $this->daysAgo(365)->subSecond());
        $this->rollup($server, 3600, $this->daysAgo(100));

        $this->rollup($server, 86400, $this->daysAgo(730));
        $this->rollup($server, 86400, $this->daysAgo(730)->subSecond());
        $this->rollup($server, 86400, $this->daysAgo(100));

        $counts = $this->prune();

        $this->assertSame(2, $counts['metric_rollups_5m']);
        $this->assertSame(1, $counts['metric_rollups_1h']);
        $this->assertSame(1, $counts['metric_rollups_1d']);

        $this->assertSame(1, $this->rollupCount(300));
        $this->assertSame(2, $this->rollupCount(3600));
        $this->assertSame(2, $this->rollupCount(86400));
    }

    public function test_resolved_alerts_past_the_window_are_pruned_but_open_and_acknowledged_alerts_are_never_touched(): void
    {
        $server = $this->server();

        $boundary = $this->alert($server, 'resolved', $this->daysAgo(365));
        $older = $this->alert($server, 'resolved', $this->daysAgo(365)->subSecond());
        $open = $this->alert($server, 'open');
        $acknowledged = $this->alert($server, 'acknowledged', acknowledgedAt: $this->daysAgo(500));
        $justResolved = $this->alert($server, 'resolved', $this->daysAgo(364));

        $counts = $this->prune();

        $this->assertSame(1, $counts['resolved_alerts']);
        $this->assertNull(Alert::query()->find($older->getKey()));
        $this->assertNotNull(Alert::query()->find($boundary->getKey()));
        $this->assertNotNull(Alert::query()->find($open->getKey()));
        $this->assertNotNull(Alert::query()->find($acknowledged->getKey()));
        $this->assertNotNull(Alert::query()->find($justResolved->getKey()));
    }

    public function test_users_are_never_pruned(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['created_at' => $this->daysAgo(2000)]);

        $counts = $this->prune();

        $this->assertSame(0, $counts['resolved_alerts']);
        $this->assertTrue(User::query()->whereKey($user->id)->exists());
    }

    public function test_alert_deliveries_audit_events_and_failed_jobs_use_their_own_cutoffs(): void
    {
        $server = $this->server();
        $alert = $this->alert($server, 'open');

        $deliveryBoundary = $this->delivery($alert, $this->daysAgo(90), 'email');
        $deliveryOlder = $this->delivery($alert, $this->daysAgo(90)->subSecond(), 'telegram');

        $this->auditEvent($this->daysAgo(365));
        $auditOlder = $this->auditEvent($this->daysAgo(365)->subSecond());

        $this->failedJob($this->daysAgo(30));
        $failedOlder = $this->failedJob($this->daysAgo(30)->subSecond());

        $counts = $this->prune();

        $this->assertSame(1, $counts['alert_deliveries']);
        $this->assertSame(1, $counts['audit_events']);
        $this->assertSame(1, $counts['failed_jobs']);

        $this->assertNotNull(AlertDelivery::query()->find($deliveryBoundary->getKey()));
        $this->assertNull(AlertDelivery::query()->find($deliveryOlder->getKey()));
        $this->assertSame(1, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('audit_events')->where('id', $auditOlder)->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->where('id', $failedOlder)->count());
    }

    public function test_revoked_and_expired_tokens_old_enough_are_pruned_but_active_and_grace_tokens_survive(): void
    {
        $server = $this->server();

        $revokedBoundary = $this->token($server, revokedAt: $this->daysAgo(90));
        $revokedOlder = $this->token($server, revokedAt: $this->daysAgo(90)->subSecond());

        $expiredBoundary = $this->token($server, expiresAt: $this->daysAgo(90));
        $expiredOlder = $this->token($server, expiresAt: $this->daysAgo(90)->subSecond());

        $active = $this->token($server);
        $grace = $this->token($server, expiresAt: Carbon::now()->addDay());
        $recentlyRevoked = $this->token($server, revokedAt: $this->daysAgo(1));

        $counts = $this->prune();

        $this->assertSame(2, $counts['server_tokens']);

        foreach ([$revokedBoundary, $expiredBoundary, $active, $grace, $recentlyRevoked] as $survivor) {
            $this->assertNotNull(ServerToken::query()->find($survivor->getKey()));
        }

        $this->assertNull(ServerToken::query()->find($revokedOlder->getKey()));
        $this->assertNull(ServerToken::query()->find($expiredOlder->getKey()));
    }

    // ------------------------------------------------------------------
    // Dry run, batching, command, schedule
    // ------------------------------------------------------------------

    public function test_dry_run_deletes_nothing_and_reports_the_full_eligible_count_above_the_batch_cap(): void
    {
        config(['retention.batch_size' => 2, 'retention.max_batches' => 1]);

        $server = $this->server();

        for ($i = 0; $i < 10; $i++) {
            $this->sample($server, $this->daysAgo(15)->subSeconds($i));
        }

        $counts = $this->prune(true);

        $this->assertSame(self::ORDER, array_keys($counts));
        $this->assertSame(10, $counts['metric_samples']);
        $this->assertSame(10, MetricSample::query()->count());
    }

    public function test_pruning_is_capped_by_batch_size_and_max_batches_per_run(): void
    {
        config(['retention.batch_size' => 2, 'retention.max_batches' => 2]);

        $server = $this->server();

        for ($i = 0; $i < 10; $i++) {
            $this->sample($server, $this->daysAgo(15)->subSeconds($i));
        }

        $counts = $this->prune();

        $this->assertSame(4, $counts['metric_samples']);
        $this->assertSame(6, MetricSample::query()->count());

        // The remainder is left for the next runs, four at a time.
        $this->assertSame(4, $this->prune()['metric_samples']);
        $this->assertSame(2, MetricSample::query()->count());
        $this->assertSame(2, $this->prune()['metric_samples']);
        $this->assertSame(0, MetricSample::query()->count());
        $this->assertSame(0, $this->prune()['metric_samples']);
    }

    public function test_the_command_prints_every_key_in_fixed_order(): void
    {
        Artisan::call('noc:prune', ['--dry-run' => true]);

        $lines = array_values(array_filter(
            preg_split('/\R/', trim(Artisan::output())) ?: [],
            static fn (string $line): bool => $line !== '',
        ));

        $this->assertSame(
            array_map(static fn (string $key): string => $key.': 0', self::ORDER),
            $lines,
        );
        $this->assertSame(0, Artisan::call('noc:prune', ['--dry-run' => true]));
    }

    public function test_the_command_dry_run_deletes_nothing_and_a_real_run_deletes(): void
    {
        $server = $this->server();
        $old = $this->sample($server, $this->daysAgo(15));
        $this->detail($old->getKey(), $this->daysAgo(8));
        $boundary = $this->sample($server, $this->daysAgo(14));

        $this->artisan('noc:prune', ['--dry-run' => true])
            ->expectsOutput('metric_details: 1')
            ->expectsOutput('metric_samples: 1')
            ->assertSuccessful();

        $this->assertSame(2, MetricSample::query()->count());
        $this->assertSame(1, DB::table('metric_details')->count());

        $this->artisan('noc:prune')
            ->expectsOutput('metric_details: 1')
            ->expectsOutput('metric_samples: 1')
            ->assertSuccessful();

        $this->assertSame($boundary->getKey(), MetricSample::query()->sole()->getKey());
        $this->assertSame(0, DB::table('metric_details')->count());
    }

    public function test_the_command_accepts_no_positional_arguments(): void
    {
        $command = $this->app[Kernel::class]->all()['noc:prune'];

        $this->assertSame([], $command->getDefinition()->getArguments());
        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
    }

    public function test_the_schedule_prunes_daily_at_03_30_without_overlapping(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => preg_match('/[\'"]?noc:prune[\'"]?(?![[:alnum:]_:.-])/', (string) $event->command) === 1,
        ));

        $this->assertCount(1, $events);
        $this->assertSame('30 3 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        // Shorter than a day so a killed run cannot strand the lock.
        $this->assertSame(60, $events[0]->expiresAt);
        $this->assertFalse($events[0]->onOneServer);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function prune(bool $dryRun = false): array
    {
        return $this->app->make(RetentionService::class)->prune($dryRun);
    }

    private function daysAgo(int $days): Carbon
    {
        return Carbon::now()->utc()->subDays($days);
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'prune-'.Str::random(8),
            'hostname' => 'prune-'.Str::random(8).'.example.test',
            'is_active' => true,
        ]);
    }

    private function sample(Server $server, Carbon $collectedAt): MetricSample
    {
        return MetricSample::query()->create([
            'server_id' => $server->getKey(),
            'collected_at' => $collectedAt,
            'received_at' => $collectedAt,
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

    private function detail(int $sampleId, Carbon $createdAt): void
    {
        DB::table('metric_details')->insert([
            'sample_id' => $sampleId,
            'panel_metrics' => null,
            'extensions' => null,
            'size_bytes' => 100,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function rollup(Server $server, int $resolution, Carbon $bucketStart): MetricRollup
    {
        return MetricRollup::query()->create([
            'server_id' => $server->getKey(),
            'resolution_seconds' => $resolution,
            'bucket_start' => $bucketStart,
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
    }

    private function rollupCount(int $resolution): int
    {
        return MetricRollup::query()->where('resolution_seconds', $resolution)->count();
    }

    private function alert(
        Server $server,
        string $status,
        ?Carbon $resolvedAt = null,
        ?Carbon $acknowledgedAt = null,
    ): Alert {
        return Alert::query()->create([
            'server_id' => $server->getKey(),
            'rule_key' => 'memory_high',
            'severity' => 'warning',
            'status' => $status,
            'active_key' => $status === 'open' ? 'memory_high:'.Str::random(12) : null,
            'title' => 'Memory high',
            'message' => 'Memory is high.',
            'first_seen_at' => $this->daysAgo(400),
            'last_seen_at' => $this->daysAgo(400),
            'acknowledged_at' => $acknowledgedAt,
            'resolved_at' => $resolvedAt,
        ]);
    }

    private function delivery(Alert $alert, Carbon $createdAt, string $channel = 'email'): AlertDelivery
    {
        $delivery = AlertDelivery::query()->create([
            'alert_id' => $alert->getKey(),
            'channel' => $channel,
            'event' => 'opened',
            'status' => 'sent',
            'recipient_count' => 1,
        ]);

        $delivery->forceFill(['created_at' => $createdAt])->save();

        return $delivery;
    }

    private function auditEvent(Carbon $createdAt): int
    {
        return DB::table('audit_events')->insertGetId([
            'action' => 'test.prune',
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function failedJob(Carbon $failedAt): int
    {
        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => $failedAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function token(
        Server $server,
        ?Carbon $revokedAt = null,
        ?Carbon $expiresAt = null,
    ): ServerToken {
        return ServerToken::query()->create([
            'server_id' => $server->getKey(),
            'token_hash' => hash('sha256', Str::random(32)),
            'token_prefix' => Str::random(8),
            'revoked_at' => $revokedAt,
            'expires_at' => $expiresAt,
        ]);
    }
}
