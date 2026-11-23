<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertEngine;
use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\UserRole;
use App\Issues\IssueSet;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_evaluation_opens_exactly_one_alert(): void
    {
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));

        $this->assertSame(1, Alert::query()->count());

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->rule_key === AlertRule::MemoryHigh);
        $this->assertTrue($alert->status === AlertStatus::Open);
        $this->assertTrue($alert->severity === AlertSeverity::Warning);
        $this->assertSame("{$server->id}:memory_high", $alert->active_key);
        $this->assertSame($server->id, $alert->server_id);
        $this->assertEquals(now(), $alert->first_seen_at);
        $this->assertEquals(now(), $alert->last_seen_at);
        // R24: the severity comes from the issue detail, never re-derived.
        $this->assertSame('warning', $alert->context_json['severity']);
        $this->assertSame(97.5, $alert->context_json['value']);
    }

    public function test_an_identical_second_evaluation_updates_last_seen_and_keeps_one_row(): void
    {
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));
        $first = Alert::query()->sole();

        Carbon::setTestNow('2026-11-23 10:05:00');

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));

        $this->assertSame(1, Alert::query()->count());

        $alert = Alert::query()->sole();
        $this->assertSame($first->id, $alert->id);
        $this->assertEquals('2026-11-23 10:00:00', $alert->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-23 10:05:00', $alert->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame("{$server->id}:memory_high", $alert->active_key);
    }

    public function test_two_servers_with_the_same_rule_get_separate_rows(): void
    {
        $one = $this->server('one');
        $two = $this->server('two');

        $this->engine()->evaluate($one, $this->issues('memory_high', 'warning'));
        $this->engine()->evaluate($two, $this->issues('memory_high', 'warning'));

        $this->assertSame(2, Alert::query()->count());
        $this->assertSame("{$one->id}:memory_high", Alert::query()->where('server_id', $one->id)->sole()->active_key);
        $this->assertSame("{$two->id}:memory_high", Alert::query()->where('server_id', $two->id)->sole()->active_key);
    }

    public function test_a_vanished_condition_resolves_and_clears_the_active_key(): void
    {
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));

        Carbon::setTestNow('2026-11-23 10:10:00');

        $this->engine()->evaluate($server, IssueSet::empty());

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->status === AlertStatus::Resolved);
        $this->assertNull($alert->active_key);
        $this->assertEquals('2026-11-23 10:10:00', $alert->resolved_at->format('Y-m-d H:i:s'));
        $this->assertNull($alert->resolved_by);
    }

    public function test_evaluation_only_resolves_rules_it_evaluated(): void
    {
        // R7: the ingest evaluator never touches the liveness rules.
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));
        $memory = Alert::query()->sole();

        $this->engine()->syncRule($server, AlertRule::ServerOffline, true, ['severity' => 'critical', 'value' => null, 'threshold' => null]);

        $this->engine()->evaluate($server, IssueSet::empty());

        $this->assertTrue($memory->refresh()->status === AlertStatus::Resolved);
        $offline = Alert::query()->where('rule_key', AlertRule::ServerOffline->value)->sole();
        $this->assertTrue($offline->status === AlertStatus::Open);
    }

    public function test_acknowledge_does_not_resolve(): void
    {
        $operators = User::factory()->create(['role' => UserRole::Operator]);
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'critical'));
        $alert = Alert::query()->sole();

        $this->engine()->acknowledge($alert, $operators);

        $alert = $alert->fresh();
        $this->assertTrue($alert->status === AlertStatus::Acknowledged);
        $this->assertNull($alert->resolved_at);
        $this->assertSame($operators->id, $alert->acknowledged_by);
        $this->assertSame("{$server->id}:memory_high", $alert->active_key);
        $this->assertSame(1, AuditEvent::query()->where('action', 'alert.acknowledged')->count());
    }

    public function test_evaluating_an_acknowledged_alert_keeps_it_acknowledged(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));
        $this->engine()->acknowledge(Alert::query()->sole(), $operator);

        Carbon::setTestNow('2026-11-23 10:15:00');
        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->status === AlertStatus::Acknowledged);
        $this->assertEquals('2026-11-23 10:15:00', $alert->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_severity_escalation_updates_the_existing_alert(): void
    {
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));
        $original = Alert::query()->sole();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'critical'));

        $alert = Alert::query()->sole();
        $this->assertSame($original->id, $alert->id);
        $this->assertTrue($alert->severity === AlertSeverity::Critical);
        $this->assertSame('critical', $alert->context_json['severity']);
    }

    public function test_resolving_notifies_exactly_once_and_a_second_resolve_does_not_duplicate_it(): void
    {
        $this->adminWithEmail();
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'critical'));
        $alert = Alert::query()->sole();

        $this->assertSame(['opened'], AlertDelivery::query()->where('alert_id', $alert->id)->pluck('event')->all());

        $this->engine()->resolve($alert);

        $alert = $alert->fresh();
        $this->assertSame(['opened', 'resolved'], AlertDelivery::query()->where('alert_id', $alert->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(1, AuditEvent::query()->where('action', 'alert.resolved')->count());

        // A second resolve (already resolved) must not create a second recovery row.
        $this->engine()->resolve($alert);

        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'alert.resolved')->count());
    }

    public function test_resolve_without_notify_creates_no_recovery_delivery(): void
    {
        $this->adminWithEmail();
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'critical'));

        $this->engine()->resolve(Alert::query()->sole(), null, 'manual', false);

        $alert = Alert::query()->sole();
        $this->assertTrue($alert->status === AlertStatus::Resolved);
        $this->assertSame(0, AlertDelivery::query()->where('event', 'resolved')->count());
    }

    public function test_a_disabled_server_opens_nothing_and_leaves_existing_alerts_untouched(): void
    {
        // R39: alerts_enabled=false short-circuits evaluate() entirely.
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));
        $existing = Alert::query()->sole();

        $server->forceFill(['alerts_enabled' => false])->save();

        Carbon::setTestNow('2026-11-23 11:00:00');
        $this->engine()->evaluate($server, $this->issues('disk_high', 'critical'));
        $this->engine()->evaluate($server, IssueSet::empty());

        $this->assertSame(1, Alert::query()->count());
        $existing = $existing->fresh();
        $this->assertTrue($existing->status === AlertStatus::Open);
        $this->assertEquals('2026-11-23 10:00:00', $existing->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_close_for_disabled_alerts_resolves_open_and_acknowledged_without_recovery(): void
    {
        $operator = $this->userWithEmail('operator', UserRole::Operator);
        $server = $this->server();

        $this->engine()->evaluate($server, new IssueSet(
            ['memory_high', 'disk_high'],
            [
                'memory_high' => ['value' => 97.0, 'threshold' => 95, 'severity' => 'critical'],
                'disk_high' => ['value' => 90.0, 'threshold' => 85, 'severity' => 'warning'],
            ],
        ));

        $acknowledged = Alert::query()->where('rule_key', AlertRule::DiskHigh->value)->sole();
        $this->engine()->acknowledge($acknowledged, $operator);

        $closed = $this->engine()->closeForDisabledAlerts($server, $operator);

        $this->assertSame(2, $closed);

        foreach (Alert::query()->get() as $alert) {
            $this->assertTrue($alert->status === AlertStatus::Resolved);
            $this->assertNull($alert->active_key);
            $this->assertSame('Alerts disabled for this server', $alert->resolution_note);
            $this->assertSame($operator->id, $alert->resolved_by);
        }

        $this->assertSame(0, AlertDelivery::query()->where('event', 'resolved')->count());

        $audit = AuditEvent::query()->where('action', 'server.alerts_disabled')->sole();
        $this->assertSame($server->id, $audit->target_id);
        $this->assertSame(2, $audit->meta_json['closed_count']);
        $this->assertSame($operator->id, $audit->user_id);
    }

    public function test_closing_a_server_without_alerts_returns_zero_and_writes_no_audit(): void
    {
        $server = $this->server();

        $this->assertSame(0, $this->engine()->closeForDisabledAlerts($server));

        $this->assertSame(0, AuditEvent::query()->where('action', 'server.alerts_disabled')->count());
    }

    public function test_re_enabling_then_evaluating_opens_a_fresh_alert(): void
    {
        $server = $this->server();

        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));
        $original = Alert::query()->sole();

        $this->engine()->closeForDisabledAlerts($server);

        $server->forceFill(['alerts_enabled' => true])->save();
        Carbon::setTestNow('2026-11-23 12:00:00');
        $this->engine()->evaluate($server, $this->issues('memory_high', 'warning'));

        $this->assertSame(2, Alert::query()->count());
        $fresh = Alert::query()->where('status', AlertStatus::Open->value)->sole();
        $this->assertNotSame($original->id, $fresh->id);
        $this->assertSame("{$server->id}:memory_high", $fresh->active_key);
    }

    public function test_ingest_opens_and_resolves_alerts_without_changing_the_response(): void
    {
        $server = $this->server();
        $issued = $this->app->make(TokenService::class)->issue($server);
        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');
        RateLimiter::clear('ingest:token:'.$issued->token->id);

        $hot = $this->payload();
        $hot['metrics']['memory']['used_pct'] = 97.0;

        $this->ingest($issued->plain, $hot)->assertStatus(202);

        $this->assertSame(1, Alert::query()->count());
        $this->assertTrue(Alert::query()->sole()->rule_key === AlertRule::MemoryHigh);

        $cool = $this->payload();
        $cool['agent']['collected_at'] = '2026-11-23T10:01:00Z';

        $this->ingest($issued->plain, $cool)->assertStatus(202)->assertExactJson(['status' => 'accepted']);

        $this->assertTrue(Alert::query()->sole()->status === AlertStatus::Resolved);
    }

    public function test_ingest_still_returns_202_when_alert_evaluation_throws(): void
    {
        $this->adminWithEmail();
        $server = $this->server();
        $issued = $this->app->make(TokenService::class)->issue($server);
        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');
        RateLimiter::clear('ingest:token:'.$issued->token->id);

        $this->mock(AlertEngine::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('engine exploded'));
        });

        $this->ingest($issued->plain, $this->payload())
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted']);

        $this->assertSame(1, MetricSample::query()->count());
    }

    private function engine(): AlertEngine
    {
        return $this->app->make(AlertEngine::class);
    }

    /**
     * @return array<string, array{value: mixed, threshold: mixed, severity: string}>
     */
    private function issues(string $code, string $severity): IssueSet
    {
        return new IssueSet([$code], [
            $code => [
                'value' => $code === 'memory_high' ? 97.5 : 97.0,
                'threshold' => $severity === 'critical' ? 95 : 90,
                'severity' => $severity,
            ],
        ]);
    }

    private function server(string $suffix = 'one'): Server
    {
        return Server::query()->create([
            'name' => 'server-'.$suffix,
            'hostname' => $suffix.'.example.test',
            'panel' => 'none',
        ]);
    }

    private function userWithEmail(string $suffix, UserRole $role): User
    {
        return User::factory()->create([
            'email' => $suffix.'@noc.example.test',
            'role' => $role,
            'is_active' => true,
            'notify_email' => true,
        ]);
    }

    private function adminWithEmail(): User
    {
        return $this->userWithEmail('admin', UserRole::Admin);
    }

    private function ingest(string $plain, array $body): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$plain,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/ingest', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'agent' => [
                'version' => '0.1.0',
                'id' => '11111111-2222-3333-4444-555555555555',
                'hostname' => 'web01.example.test',
                'panel' => 'cpanel',
                'collected_at' => '2026-11-23T10:00:00Z',
                'interval_seconds' => 60,
            ],
            'metrics' => [
                'cpu' => ['usage_pct' => 12.5, 'iowait_pct' => 0.3, 'load1' => 0.4, 'load5' => 0.3, 'load15' => 0.2, 'cores' => 8],
                'memory' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 20.0],
                'swap' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0],
                'disk' => ['root' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 40.0, 'inode_pct' => 10.0]],
                'network' => ['rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0],
                'processes' => ['running' => 3, 'blocked' => 0],
            ],
            'panel_metrics' => [
                'service.apache.running' => 1,
                'service.apache.installed' => 1,
                'service.apache.enabled' => 1,
            ],
            'extensions' => ['smart.status' => 'ok'],
        ];
    }
}
