<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertEngine;
use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\ServerHealth;
use App\Enums\UserRole;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Services\SettingsRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 10: the liveness scheduler (spec §9.1 liveness rules, §10 boundaries).
 *
 * The stale/offline rules are owned by `noc:evaluate-liveness`, not by ingest.
 * The status comes from the absolute `stale_after`/`offline_after`
 * timestamps through `Server::status()` (R21), never from a duration.
 *
 * Tests that assert on deliveries run the command through `runLiveness()`: the
 * `RefreshDatabase` transaction is level 1 and notification jobs are
 * dispatched `afterCommit()`. `syncRule()` nests its own transaction, so the
 * savepoint commits at tracked level 3 and `DatabaseTransactionsManager` runs
 * the callbacks right there — the same code path as a production commit —
 * while `RefreshDatabase` still rolls the outer transaction back.
 */
class LivenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models the test holds across command runs; see `runLiveness()`.
     *
     * @var list<Model>
     */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 10:00:00');

        config([
            'mail.default' => 'array',
            'alerts.channels.email.enabled' => true,
            'alerts.channels.telegram.enabled' => false,
        ]);

        Http::preventStrayRequests();

        // Telegram is enabled in one test; every other request is blocked.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_online_server_gets_no_liveness_alert(): void
    {
        [$server, $sample] = $this->seenServer();

        $this->runLiveness();

        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(ServerHealth::Ok, $server->fresh()->health);
        $this->assertNotNull($sample->fresh());
    }

    public function test_a_server_exactly_at_stale_after_is_still_online(): void
    {
        // R21: online is `now <= stale_after`, so the boundary itself is online.
        [$server] = $this->seenServer(staleAfter: '2026-11-23 10:00:00');

        $this->runLiveness();

        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(ServerHealth::Ok, $server->fresh()->health);
    }

    public function test_just_past_stale_after_opens_a_stale_warning(): void
    {
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59');

        $this->runLiveness();

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->rule_key === AlertRule::ServerStale);
        $this->assertTrue($alert->severity === AlertSeverity::Warning);
        $this->assertTrue($alert->status === AlertStatus::Open);
        $this->assertSame("{$server->id}:server_stale", $alert->active_key);
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'opened')->count());

        $server = $server->fresh();
        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame(['server_stale'], $server->issue_codes);
    }

    public function test_stale_escalating_to_offline_resolves_stale_without_recovery_and_opens_offline(): void
    {
        $this->adminWithEmail();
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:10:00');

        $this->runLiveness();

        $stale = Alert::query()->sole();
        $this->assertTrue($stale->rule_key === AlertRule::ServerStale);
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $stale->id)->where('event', 'opened')->where('status', 'sent')->count());

        // Just past `offline_after` (R21: stale is `stale_after < now <= offline_after`).
        Carbon::setTestNow('2026-11-23 10:10:01');

        $this->runLiveness();

        $stale = $stale->fresh();
        $this->assertTrue($stale->status === AlertStatus::Resolved);
        $this->assertNull($stale->active_key);
        $this->assertSame('Server offline', $stale->resolution_note);
        // The stale→offline close is administrative: no recovery delivery row.
        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $stale->id)->where('event', 'resolved')->count());

        $offline = Alert::query()->where('rule_key', AlertRule::ServerOffline->value)->sole();
        $this->assertTrue($offline->status === AlertStatus::Open);
        $this->assertTrue($offline->severity === AlertSeverity::Critical);
        $this->assertSame("{$server->id}:server_offline", $offline->active_key);
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $offline->id)->where('event', 'opened')->count());

        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);
    }

    public function test_a_returning_server_resolves_offline_with_recovery_and_keeps_ingest_health(): void
    {
        $this->adminWithEmail();
        $this->telegramEnabled();
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:30:00', offlineAfter: '2026-11-23 09:45:00');

        $this->runLiveness();

        $offline = Alert::query()->where('rule_key', AlertRule::ServerOffline->value)->sole();
        $this->assertTrue($offline->status === AlertStatus::Open);
        $this->assertSame(2, AlertDelivery::query()->where('alert_id', $offline->id)->where('event', 'opened')->count());
        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);

        // A sample arrives: the server is online again.
        $this->markSeen($server);

        $this->runLiveness();

        $offline = $offline->fresh();
        $this->assertTrue($offline->status === AlertStatus::Resolved);
        $this->assertNull($offline->active_key);

        // One recovery per channel that had an `opened` row (email + telegram).
        $recoveries = AlertDelivery::query()->where('alert_id', $offline->id)->where('event', 'resolved');
        $this->assertSame(2, (clone $recoveries)->count());
        $this->assertEqualsCanonicalizing(['email', 'telegram'], (clone $recoveries)->pluck('channel')->all());
        $this->assertSame(2, (clone $recoveries)->where('status', 'sent')->count());

        // The returning sample wrote its own health; liveness must not
        // re-derive it (the fixture sample is clean: `ok`, no codes).
        $server = $server->fresh();
        $this->assertSame(ServerHealth::Ok, $server->health);
        $this->assertSame([], $server->issue_codes);
    }

    public function test_a_returning_server_derives_health_from_its_remaining_open_alerts(): void
    {
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:30:00', offlineAfter: '2026-11-23 09:45:00');

        $this->runLiveness();

        // Another rule is still open when the server comes back.
        $this->engine()->syncRule($server, AlertRule::DiskHigh, true, [
            'value' => 92.0,
            'threshold' => 85,
            'severity' => 'warning',
        ]);

        $this->markSeen($server);
        // The returning sample's `StoreSample` health for the open disk rule.
        $server->forceFill([
            'health' => ServerHealth::Warning,
            'issue_codes' => [AlertRule::DiskHigh->value],
        ])->save();

        $this->runLiveness();

        $server = $server->fresh();
        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame(['disk_high'], $server->issue_codes);
        $this->assertSame(1, Alert::query()->where('status', AlertStatus::Open->value)->count());
        $this->assertSame(1, AlertDelivery::query()->where('event', 'resolved')->count());
    }

    public function test_a_critical_server_is_never_downgraded_to_ok_while_other_open_alerts_remain(): void
    {
        $server = $this->seenServer()[0];

        $this->engine()->syncRule($server, AlertRule::SmartFailed, true, [
            'value' => 'failed',
            'threshold' => 'healthy',
            'severity' => 'critical',
        ]);
        // Derive the health of the current alert state once, as ingest would.
        $this->engine()->refreshServerHealth($server);

        $this->runLiveness();

        $server = $server->fresh();
        $this->assertSame(ServerHealth::Critical, $server->health);
        $this->assertSame(['smart_failed'], $server->issue_codes);
    }

    public function test_a_soft_deleted_server_is_skipped_entirely(): void
    {
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:00:00', offlineAfter: '2026-11-23 09:30:00');

        $server->forceFill(['health' => ServerHealth::Unknown])->save();
        $server->delete();

        $this->runLiveness();

        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(ServerHealth::Unknown, $server->fresh()->health);
    }

    public function test_an_inactive_server_is_skipped_entirely(): void
    {
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:30:00');

        $this->runLiveness();

        $existing = Alert::query()->sole();
        $this->assertTrue($existing->rule_key === AlertRule::ServerStale);
        $this->assertTrue($existing->status === AlertStatus::Open);
        $lastSeen = $existing->last_seen_at;

        $server->forceFill(['is_active' => false, 'health' => ServerHealth::Unknown])->save();

        $this->runLiveness();

        // R39: no new alert and no resolution, but the existing one untouched.
        $this->assertSame(1, Alert::query()->count());
        $existing = $existing->fresh();
        $this->assertTrue($existing->status === AlertStatus::Open);
        $this->assertSame("{$server->id}:server_stale", $existing->active_key);
        $this->assertSame($lastSeen->format('Y-m-d H:i:s'), $existing->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $existing->id)->where('event', 'resolved')->count());
        $this->assertSame(ServerHealth::Unknown, $server->fresh()->health);
    }

    public function test_a_server_exactly_at_offline_after_is_still_stale(): void
    {
        // R21: stale is `stale_after < now <= offline_after`.
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:00:00');

        $this->runLiveness();

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->rule_key === AlertRule::ServerStale);
        $this->assertTrue($alert->severity === AlertSeverity::Warning);
        $this->assertSame(ServerHealth::Warning, $server->fresh()->health);
    }

    public function test_a_server_with_a_null_threshold_is_offline(): void
    {
        // R26: a missing interval cannot make a seen server online; it is
        // offline and gets the critical alert.
        [$server] = $this->seenServer(staleAfter: null, offlineAfter: null);

        $this->runLiveness();

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->rule_key === AlertRule::ServerOffline);
        $this->assertTrue($alert->severity === AlertSeverity::Critical);
        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);
    }

    public function test_a_return_with_a_remaining_critical_ingest_alert_keeps_the_ingest_health(): void
    {
        $this->adminWithEmail();
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:30:00', offlineAfter: '2026-11-23 09:45:00');

        $this->runLiveness();

        $offline = Alert::query()->where('rule_key', AlertRule::ServerOffline->value)->sole();
        $this->assertTrue($offline->status === AlertStatus::Open);

        // The returning sample also trips a critical ingest rule: ingest
        // inserts the alert inside its own transaction, writes the derived
        // health and only then commits, so the liveness run can see the alert.
        $this->engine()->syncRule($server, AlertRule::SmartFailed, true, [
            'value' => 'failed',
            'threshold' => 'healthy',
            'severity' => 'critical',
        ]);

        $this->markSeen($server);
        $server->forceFill([
            'health' => ServerHealth::Critical,
            'issue_codes' => [AlertRule::SmartFailed->value],
        ])->save();

        $this->runLiveness();

        $this->assertTrue($offline->fresh()->status === AlertStatus::Resolved);
        $server = $server->fresh();
        // A liveness recovery must not overwrite what ingest just wrote.
        $this->assertSame(ServerHealth::Critical, $server->health);
        $this->assertSame(['smart_failed'], $server->issue_codes);
    }

    public function test_a_liveness_recovery_does_not_overwrite_the_health_ingest_just_wrote(): void
    {
        // The scheduler fires between `IngestController::store()` and
        // `AlertEngine::evaluate()`: the sample that made the server online is
        // committed, ingest already wrote its health, but the alerts the
        // ingest run opens are not committed yet, so liveness cannot see them.
        // Resolving the offline alert here must therefore leave the health
        // `StoreSample` wrote alone.
        $this->adminWithEmail();
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:30:00', offlineAfter: '2026-11-23 09:45:00');

        $this->runLiveness();

        $offline = Alert::query()->where('rule_key', AlertRule::ServerOffline->value)->sole();
        $this->assertTrue($offline->status === AlertStatus::Open);
        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);

        // What `StoreSample` commits: the sample, the thresholds, the health.
        $this->markSeen($server);
        $server->forceFill([
            'health' => ServerHealth::Warning,
            'issue_codes' => [AlertRule::DiskHigh->value],
        ])->save();

        $this->runLiveness();

        $this->assertTrue($offline->fresh()->status === AlertStatus::Resolved);
        $server = $server->fresh();
        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame(['disk_high'], $server->issue_codes);
    }

    public function test_a_healthy_return_keeps_ingest_health_and_issue_codes(): void
    {
        [$server, $sample] = $this->seenServer(staleAfter: '2026-11-23 09:30:00', offlineAfter: '2026-11-23 09:45:00');

        $this->runLiveness();

        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);

        // `hasSample()` has to fall back to the `metric_samples` table: the
        // server carries no `last_sample_at`.
        $this->markSeen($server);
        $server->forceFill(['last_sample_at' => null])->save();
        $this->assertNotNull($sample->fresh());

        $this->runLiveness();

        $server = $server->fresh();
        $this->assertSame(ServerHealth::Ok, $server->health);
        $this->assertSame([], $server->issue_codes);
    }

    public function test_a_server_with_alerts_disabled_opens_and_resolves_nothing(): void
    {
        // R39: alerts_enabled=false skips liveness entirely, including resolutions.
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:30:00');

        $this->runLiveness();

        $existing = Alert::query()->sole();
        $this->assertTrue($existing->rule_key === AlertRule::ServerStale);
        $lastSeen = $existing->last_seen_at;

        $server->forceFill(['alerts_enabled' => false])->save();

        Carbon::setTestNow('2026-11-23 10:20:00');
        $this->runLiveness();

        $this->assertSame(1, Alert::query()->count());
        $existing = $existing->fresh();
        $this->assertTrue($existing->status === AlertStatus::Open);
        $this->assertSame($lastSeen->format('Y-m-d H:i:s'), $existing->last_seen_at->format('Y-m-d H:i:s'));

        // Even when the server comes back, a disabled server is not resolved by liveness.
        $this->markSeen($server);
        Carbon::setTestNow('2026-11-23 10:21:00');
        $this->runLiveness();

        $this->assertTrue($existing->fresh()->status === AlertStatus::Open);
        $this->assertSame(0, AlertDelivery::query()->where('event', 'resolved')->count());
    }

    public function test_a_never_seen_server_gets_no_offline_alert(): void
    {
        // R21: `Server::status()` is NeverSeen without `last_seen_at`; there is
        // no interval to derive a threshold from, so nothing is opened.
        $server = Server::query()->create([
            'name' => 'never-seen',
            'hostname' => 'never.example.test',
            'panel' => 'none',
        ]);

        $this->runLiveness();

        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(ServerHealth::Unknown, $server->fresh()->health);
    }

    public function test_refresh_server_health_reflects_open_alerts_and_samples(): void
    {
        $engine = $this->engine();

        $neverSeen = Server::query()->create([
            'name' => 'health-never',
            'hostname' => 'health-never.example.test',
            'panel' => 'none',
        ]);

        $engine->refreshServerHealth($neverSeen);
        $this->assertSame(ServerHealth::Unknown, $neverSeen->fresh()->health);

        $seen = $this->seenServer()[0];

        $engine->refreshServerHealth($seen);
        $this->assertSame(ServerHealth::Ok, $seen->fresh()->health);

        $engine->syncRule($seen, AlertRule::LoadHigh, true, ['value' => 9.0, 'threshold' => 4, 'severity' => 'warning']);
        $engine->refreshServerHealth($seen);
        $this->assertSame(ServerHealth::Warning, $seen->fresh()->health);
        $this->assertSame(['load_high'], $seen->fresh()->issue_codes);

        $engine->syncRule($seen, AlertRule::ServiceDown, true, ['value' => 'nginx', 'threshold' => 'running', 'severity' => 'critical']);
        $engine->refreshServerHealth($seen);
        $this->assertSame(ServerHealth::Critical, $seen->fresh()->health);

        // Resolving the critical one leaves the warning, never `ok`.
        $engine->syncRule($seen, AlertRule::ServiceDown, false);
        $engine->refreshServerHealth($seen);
        $this->assertSame(ServerHealth::Warning, $seen->fresh()->health);

        $engine->syncRule($seen, AlertRule::LoadHigh, false);
        $engine->refreshServerHealth($seen);
        $this->assertSame(ServerHealth::Ok, $seen->fresh()->health);
        $this->assertSame([], $seen->fresh()->issue_codes);
    }

    public function test_refresh_server_health_keeps_a_critical_acknowledged_alert(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $server = $this->seenServer()[0];

        $this->engine()->syncRule($server, AlertRule::SmartFailed, true, ['severity' => 'critical']);
        $this->engine()->acknowledge(Alert::query()->sole(), $operator);

        $this->engine()->refreshServerHealth($server);

        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);
    }

    public function test_a_stale_server_returning_before_offline_resolves_with_recovery(): void
    {
        $this->adminWithEmail();
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:30:00');

        $this->runLiveness();
        $stale = Alert::query()->where('rule_key', AlertRule::ServerStale->value)->sole();

        // In production the `afterCommit()` job of the open publishes before
        // the next minute's run; here a zero-delay job does the same.
        SendAlertNotification::dispatchSync($stale->id, 'email', 'opened');

        // A later scheduler run changes nothing; the server returns before
        // `offline_after` and the stale alert resolves normally, with one
        // recovery for the channel that had a successful `opened` row.
        Carbon::setTestNow('2026-11-23 10:06:00');
        $this->runLiveness();

        Carbon::setTestNow('2026-11-23 10:11:00');
        $this->markSeen($server);
        $this->runLiveness();

        $alert = $stale->fresh();
        $this->assertTrue($alert->status === AlertStatus::Resolved);
        $this->assertSame('Issue cleared', $alert->resolution_note);
        $this->assertSame(1, AuditEvent::query()->where('action', 'alert.resolved')->count());
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'opened')->where('status', 'sent')->count());
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->where('status', 'sent')->count());

        // The clean returning sample owns the health: `ok`, no issue codes.
        $server = $server->fresh();
        $this->assertSame(ServerHealth::Ok, $server->health);
        $this->assertSame([], $server->issue_codes);
    }

    public function test_an_offline_server_staying_offline_keeps_one_offline_alert(): void
    {
        $this->adminWithEmail();
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:30:00', offlineAfter: '2026-11-23 09:45:00');

        $this->runLiveness();
        $offline = Alert::query()->where('rule_key', AlertRule::ServerOffline->value)->sole();

        Carbon::setTestNow('2026-11-23 10:05:00');
        $this->runLiveness();

        $this->assertSame(1, Alert::query()->count());
        $this->assertSame($offline->id, Alert::query()->sole()->id);
        $this->assertSame(0, AlertDelivery::query()->where('event', 'resolved')->count());
        $this->assertSame(ServerHealth::Critical, $server->fresh()->health);
    }

    public function test_an_escalation_close_is_audited_as_not_notified(): void
    {
        $this->adminWithEmail();
        $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:10:00');

        $this->runLiveness();

        Carbon::setTestNow('2026-11-23 10:10:01');
        $this->runLiveness();

        $alert = Alert::query()->where('rule_key', AlertRule::ServerStale->value)->sole();
        $this->assertTrue($alert->status === AlertStatus::Resolved);
        $this->assertSame('Server offline', $alert->resolution_note);
        $this->assertNull($alert->resolved_by);

        $audit = AuditEvent::query()->where('action', 'alert.resolved')->where('target_id', $alert->id)->sole();
        $this->assertSame('server_stale', $audit->meta_json['rule_key']);
        $this->assertFalse($audit->meta_json['notified']);
        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->count());
    }

    public function test_repeated_runs_do_not_duplicate_or_reshuffle_alerts(): void
    {
        [$server] = $this->seenServer(staleAfter: '2026-11-23 09:59:59', offlineAfter: '2026-11-23 10:30:00');

        $this->runLiveness();
        $first = Alert::query()->sole();

        Carbon::setTestNow('2026-11-23 10:01:00');
        $this->runLiveness();

        $this->assertSame(1, Alert::query()->count());
        $alert = Alert::query()->sole();
        $this->assertSame($first->id, $alert->id);
        $this->assertEquals('2026-11-23 10:01:00', $alert->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame(ServerHealth::Warning, $server->fresh()->health);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function livenessRuleCases(): array
    {
        return [
            'stale' => ['server_stale', '2026-11-23 09:59:59', '2026-11-23 10:30:00'],
            'offline' => ['server_offline', '2026-11-23 09:30:00', '2026-11-23 09:45:00'],
        ];
    }

    #[DataProvider('livenessRuleCases')]
    public function test_a_disabled_liveness_rule_does_not_open_an_alert(string $rule, string $staleAfter, string $offlineAfter): void
    {
        app(SettingsRepository::class)->set('alerts.rules.'.$rule.'.enabled', false, 'bool');

        [$server] = $this->seenServer(staleAfter: $staleAfter, offlineAfter: $offlineAfter);

        $this->runLiveness();

        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(ServerHealth::Ok, $server->fresh()->health);
    }

    #[DataProvider('livenessRuleCases')]
    public function test_a_disabled_liveness_rule_closes_an_existing_alert_without_recovery(string $rule, string $staleAfter, string $offlineAfter): void
    {
        $this->adminWithEmail();
        [$server] = $this->seenServer(staleAfter: $staleAfter, offlineAfter: $offlineAfter);

        $this->runLiveness();

        $alert = Alert::query()->sole();
        $this->assertSame($rule, $alert->rule_key->value);
        $this->assertSame(AlertStatus::Open, $alert->status);

        app(SettingsRepository::class)->set('alerts.rules.'.$rule.'.enabled', false, 'bool');

        $this->runLiveness();

        $alert = $alert->fresh();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNull($alert->active_key);
        $this->assertSame('Alert rule disabled', $alert->resolution_note);
        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->count());

        $audit = AuditEvent::query()->where('action', 'alert.resolved')->where('target_id', $alert->id)->sole();
        $this->assertFalse($audit->meta_json['notified']);

        $this->assertSame(ServerHealth::Ok, $server->fresh()->health);
    }

    public function test_the_schedule_runs_liveness_every_minute_and_reminders_every_five(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $liveness = $this->scheduled($schedule, 'noc:evaluate-liveness');
        $reminders = $this->scheduled($schedule, 'noc:alert-reminders');

        $this->assertCount(1, $liveness);
        $this->assertSame('* * * * *', $liveness[0]->expression);
        $this->assertTrue($liveness[0]->withoutOverlapping);
        // Shorter than the cadence so a killed run cannot strand the lock.
        $this->assertSame(5, $liveness[0]->expiresAt);
        $this->assertFalse($liveness[0]->onOneServer);

        $this->assertCount(1, $reminders);
        $this->assertSame('*/5 * * * *', $reminders[0]->expression);
        $this->assertTrue($reminders[0]->withoutOverlapping);
        $this->assertSame(10, $reminders[0]->expiresAt);
        $this->assertFalse($reminders[0]->onOneServer);
    }

    /**
     * Run the command so its `afterCommit()` notification jobs execute (see
     * the class docblock). The outer transaction makes the tracked level 2;
     * `syncRule()` opens its own transaction at level 3, so committing that
     * savepoint passes `levelBeingCommitted: 3` to
     * `DatabaseTransactionsManager::commit()`, which stages the record and
     * runs its callbacks now.
     */
    private function runLiveness(): void
    {
        // `chunkById()` re-reads each server into the model identity map, so a
        // fixture model held by the test would be mutated by the command.
        // Snapshot them first and reload afterwards, as a fresh request would.
        $fixtures = $this->fixtures;
        $this->fixtures = [];

        DB::transaction(function (): void {
            $this->artisan('noc:evaluate-liveness')->assertSuccessful();
        });

        foreach ($fixtures as $model) {
            $model->refresh();
        }
    }

    /**
     * Track a model the test holds across a command run.
     */
    private function track(Model $model): void
    {
        $this->fixtures[] = $model;
    }

    private function engine(): AlertEngine
    {
        return $this->app->make(AlertEngine::class);
    }

    /**
     * @return list<Event>
     */
    private function scheduled(Schedule $schedule, string $command): array
    {
        return array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', $command),
        ));
    }

    /**
     * A server with one stored sample and explicit absolute thresholds.
     *
     * @return array{0: Server, 1: MetricSample}
     */
    private function seenServer(
        string $seenAt = '2026-11-23 09:59:00',
        ?string $staleAfter = '2026-11-23 10:30:00',
        ?string $offlineAfter = '2026-11-23 11:00:00',
    ): array {
        static $count = 0;
        $count++;

        // `HasUlids` derives the key from the frozen clock and a random suffix;
        // force a unique one so a server can never collide with a row another
        // test class created in the same process.
        $server = Server::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'live-'.$count,
            'hostname' => 'live'.$count.'.example.test',
            'panel' => 'none',
            'is_active' => true,
            'alerts_enabled' => true,
        ]);

        $sample = $this->storedSample($server, $seenAt);
        $this->track($sample);

        $server->forceFill([
            'last_seen_at' => $seenAt,
            'last_sample_at' => $seenAt,
            'stale_after' => $staleAfter,
            'offline_after' => $offlineAfter,
            'health' => ServerHealth::Ok,
            'issue_codes' => [],
        ])->save();

        $this->track($server);

        return [$server, $sample];
    }

    private function markSeen(Server $server): void
    {
        $now = now();
        $seenAt = $now->format('Y-m-d H:i:s');

        // `StoreSample` writes the health of the sample it stores in the same
        // transaction; the clean fixture sample is `ok` with no codes.
        $server->forceFill([
            'last_seen_at' => $seenAt,
            'last_sample_at' => $seenAt,
            'stale_after' => $now->copy()->addSeconds(150)->format('Y-m-d H:i:s'),
            'offline_after' => $now->copy()->addSeconds(300)->format('Y-m-d H:i:s'),
            'health' => ServerHealth::Ok,
            'issue_codes' => [],
        ])->save();

        // Name the helper `storedSample`: `sample` would resolve to the
        // `TestCase::sample()` closure property, not to a method.
        $this->storedSample($server, $seenAt);
    }

    private function storedSample(Server $server, string $collectedAt): MetricSample
    {
        $at = Carbon::parse($collectedAt);

        return $server->samples()->create([
            'collected_at' => $at,
            'received_at' => $at,
            'schema_version' => 1,
            'agent_version' => '0.1.0',
            'interval_seconds' => 60,
            'cpu_usage_pct' => 10.0,
            'cpu_iowait_pct' => 0.0,
            'cpu_cores' => 2,
            'load1' => 0.1,
            'load5' => 0.1,
            'load15' => 0.1,
            'mem_total_bytes' => 1024,
            'mem_used_bytes' => 256,
            'mem_used_pct' => 25.0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0.0,
            'disk_total_bytes' => 1024,
            'disk_used_bytes' => 256,
            'disk_used_pct' => 25.0,
            'disk_inode_pct' => 5.0,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 60,
            'payload_bytes' => 128,
        ]);
    }

    private function adminWithEmail(): User
    {
        return User::factory()->create([
            'email' => 'admin@noc.example.test',
            'role' => UserRole::Admin,
            'is_active' => true,
            'notify_email' => true,
        ]);
    }

    private function telegramEnabled(): void
    {
        config([
            'alerts.channels.telegram.enabled' => true,
            'alerts.channels.telegram.bot_token' => '123456:AAH-super-secret',
            'alerts.channels.telegram.chat_id' => '-1001234567890',
        ]);
    }
}
