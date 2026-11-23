<?php

namespace Tests\Feature\Acceptance;

use App\Alerts\AlertEngine;
use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\ServerHealth;
use App\Enums\UserRole;
use App\Livewire\Alerts\Show as AlertShow;
use App\Livewire\Servers\Tokens as ServerTokens;
use App\Livewire\Settings\UserForm;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Notifications\AlertOpened;
use App\Notifications\AlertResolved;
use App\Services\Servers\ServerService;
use App\Services\SettingsRepository;
use App\Services\Tokens\IssuedToken;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

/**
 * Task 23 MVP acceptance (spec §17.4).
 *
 * These tests drive the real HTTP contracts an operator and an agent use:
 * `POST /api/v1/ingest` stores samples, the dashboard/table/detail/series
 * endpoints render them for every role, an ingest rule opens an alert, the
 * queued email/Telegram deliveries are recorded, acknowledge pauses reminders,
 * resolve sends exactly one recovery per channel that opened the alert, and
 * administrative closes send none. Token rotation, grace/revocation, roles,
 * retention, backup/restore, and the malformed-input status codes are covered
 * here too.
 *
 * `DatabaseMigrations` is intentional: `noc:backup` uses SQLite
 * `VACUUM INTO`, which cannot run inside the transaction `RefreshDatabase`
 * keeps open. It also makes the `afterCommit` delivery jobs run like a real
 * request instead of keeping them pending until the test ends.
 */
class MvpAcceptanceTest extends TestCase
{
    use DatabaseMigrations;

    private const INGEST_URI = '/api/v1/ingest';

    private const NOW = '2026-11-23 12:00:00';

    private const BOT_TOKEN = '123456:AAH-acceptance-test-bot-token';

    private const CHAT_ID = '-1001234567890';

    private const THREAD_ID = '42';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        $this->tmpDir = sys_get_temp_dir().'/noc-acceptance-'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($this->tmpDir);

        Notification::fake();

        // The Telegram channel reads its credentials through SettingsRepository
        // (encrypted at rest); keeping them here exercises that path in every
        // alert test. Any request outside api.telegram.org is a test bug.
        $settings = $this->app->make(SettingsRepository::class);
        $settings->set('alerts.channels.telegram.enabled', true, 'bool');
        $settings->set('alerts.channels.telegram.bot_token', self::BOT_TOKEN, 'secret', true);
        $settings->set('alerts.channels.telegram.chat_id', self::CHAT_ID, 'string');
        $settings->set('alerts.channels.telegram.message_thread_id', self::THREAD_ID, 'string');

        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Agent -> ingest -> dashboard / detail / series / roles
    // ------------------------------------------------------------------

    public function test_ingested_samples_are_visible_on_the_dashboard_detail_and_series_to_every_role(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $operator = $this->makeUser('operator', UserRole::Operator);
        $viewer = $this->makeUser('viewer', UserRole::Viewer);

        $fleet = $this->makeFleet(3);

        // Three samples on the first server so the 1h series has real points;
        // one sample each on the other two.
        foreach ([120, 60, 0] as $offset) {
            $this->ingest($fleet[0]['plain'], $this->payload(
                $fleet[0]['server']->hostname,
                collectedAt: now()->subSeconds($offset)->toIso8601ZuluString(),
            ))->assertStatus(202);
        }

        $this->ingest($fleet[1]['plain'], $this->payload($fleet[1]['server']->hostname))->assertStatus(202);
        $this->ingest($fleet[2]['plain'], $this->payload($fleet[2]['server']->hostname))->assertStatus(202);

        $this->assertSame(5, MetricSample::query()->count());
        $this->assertSame(5, DB::table('metric_details')->count());

        foreach ($fleet as $entry) {
            $entry['server']->refresh();
            $this->assertSame(ServerHealth::Ok, $entry['server']->health);
            $this->assertSame([], $entry['server']->issue_codes);
        }

        // A real Fortify login, not actingAs(), then the read surfaces.
        $this->post('/login', [
            'email' => $viewer->email,
            'password' => 'acceptance-password',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($viewer);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Fleet')
            ->assertSee($fleet[0]['server']->name)
            ->assertSee($fleet[1]['server']->name)
            ->assertSee($fleet[2]['server']->name);

        $this->get(route('servers.show', $fleet[0]['server']))
            ->assertOk()
            ->assertSee($fleet[0]['server']->name);

        $this->get(route('servers.series', [
            'server' => $fleet[0]['server'],
            'metric' => 'cpu',
            'range' => '1h',
        ]))
            ->assertOk()
            ->assertJsonPath('metric', 'cpu')
            ->assertJsonPath('range', '1h')
            ->assertJsonStructure(['metric', 'range', 'resolution', 'points']);

        $this->assertNotEmpty(
            $this->get(route('servers.series', [
                'server' => $fleet[0]['server'],
                'metric' => 'cpu',
                'range' => '1h',
            ]))->json('points'),
        );

        $this->get('/alerts')->assertOk();

        // All three roles can read the fleet, one server, its series, and alerts.
        foreach ([$admin, $operator, $viewer] as $user) {
            $this->actingAs($user)
                ->get('/dashboard')
                ->assertOk()
                ->assertSee($fleet[0]['server']->name);

            $this->actingAs($user)
                ->get(route('servers.show', $fleet[0]['server']))
                ->assertOk()
                ->assertSee($fleet[0]['server']->name);

            $this->actingAs($user)
                ->get(route('servers.series', [
                    'server' => $fleet[0]['server'],
                    'metric' => 'memory',
                    'range' => '1h',
                ]))
                ->assertOk()
                ->assertJsonPath('metric', 'memory');

            $this->actingAs($user)->get('/alerts')->assertOk();
        }
    }

    public function test_an_ingest_rule_opens_an_in_app_alert_with_email_and_telegram_opened_deliveries(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $operator = $this->makeUser('operator', UserRole::Operator);
        $viewer = $this->makeUser('viewer', UserRole::Viewer);

        $server = $this->makeFleet(1)[0];

        $this->ingest($server['plain'], $this->payload($server['server']->hostname, diskPct: 92.5))
            ->assertStatus(202);

        $server['server']->refresh();
        $this->assertSame(ServerHealth::Warning, $server['server']->health);
        $this->assertSame([AlertRule::DiskHigh->value], $server['server']->issue_codes);

        $alert = Alert::query()->sole();
        $this->assertSame($server['server']->id, $alert->server_id);
        $this->assertSame(AlertRule::DiskHigh, $alert->rule_key);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame('Disk usage high', $alert->title);

        // The in-app channel is the alert row itself and is visible to a viewer.
        $this->actingAs($viewer)
            ->get('/alerts')
            ->assertOk()
            ->assertSee('Disk usage high')
            ->assertSee($server['server']->name);

        $this->actingAs($operator)
            ->get(route('alerts.show', $alert))
            ->assertOk()
            ->assertSee('Disk usage high')
            ->assertSee('Telegram')
            ->assertSee('Sent');

        $email = AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('channel', 'email')
            ->where('event', 'opened')
            ->sole();
        $telegram = AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('channel', 'telegram')
            ->where('event', 'opened')
            ->sole();

        $this->assertSame('sent', $email->status);
        $this->assertSame(2, $email->recipient_count);
        $this->assertSame('sent', $telegram->status);
        $this->assertSame(1, $telegram->recipient_count);
        $this->assertNotNull($email->sent_at);
        $this->assertNotNull($telegram->sent_at);
        $this->assertSame(2, $alert->fresh()->notification_count);

        // Email goes only to active admin/operator users with notify_email.
        Notification::assertSentTo([$admin, $operator], AlertOpened::class);
        Notification::assertSentTimes(AlertOpened::class, 2);
        Notification::assertNotSentTo($viewer, AlertOpened::class);

        // Telegram carries the configured chat and forum topic exactly once.
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) use ($server): bool {
            $this->assertSame(
                'https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage',
                $request->url(),
            );
            $this->assertSame(self::CHAT_ID, $request['chat_id']);
            $this->assertSame((int) self::THREAD_ID, $request['message_thread_id']);
            $this->assertSame('HTML', $request['parse_mode']);
            $this->assertStringContainsString($server['server']->name, (string) $request['text']);
            $this->assertStringContainsString('Disk usage high', (string) $request['text']);
            $this->assertStringNotContainsString($server['plain'], (string) $request['text']);
            $this->assertStringNotContainsString(self::BOT_TOKEN, (string) $request['text']);

            return true;
        });
    }

    // ------------------------------------------------------------------
    // Acknowledge / resolve / administrative close (R44/R54)
    // ------------------------------------------------------------------

    public function test_acknowledge_pauses_reminders_and_resolve_sends_exactly_one_recovery_per_opened_channel(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $operator = $this->makeUser('operator', UserRole::Operator);
        $server = $this->makeFleet(1)[0];

        $this->ingest($server['plain'], $this->payload($server['server']->hostname, diskPct: 92.5))
            ->assertStatus(202);

        $alert = Alert::query()->sole();

        $this->actingAs($operator);

        Livewire::test(AlertShow::class, ['alert' => $alert])
            ->call('acknowledge')
            ->assertSet('statusMessage', 'Alert acknowledged. Reminders are paused until it resolves.');

        $alert->refresh();
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertSame($operator->id, $alert->acknowledged_by);
        $this->assertNotNull($alert->acknowledged_at);

        // Seven hours later an open alert would be reminded; an acknowledged one
        // must not attempt either channel again.
        Carbon::setTestNow('2026-11-23 19:00:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $this->assertSame(1, $this->delivery($alert, 'email')->attempts);
        $this->assertSame(1, $this->delivery($alert, 'telegram')->attempts);
        $this->assertSame(2, $alert->fresh()->notification_count);

        // Manual resolve asserts recovery and sends exactly one per channel
        // that actually had an opened delivery.
        Livewire::test(AlertShow::class, ['alert' => $alert->fresh()])
            ->set('note', 'Disk cleaned up by the operator.')
            ->call('resolve')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Alert resolved. A recovery message was queued for each channel that actually opened this alert.');

        $alert->refresh();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertSame($operator->id, $alert->resolved_by);
        $this->assertSame('Disk cleaned up by the operator.', $alert->resolution_note);
        $this->assertNull($alert->active_key);

        $recoveries = AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('event', 'resolved')
            ->orderBy('channel')
            ->get();

        $this->assertSame(['email', 'telegram'], $recoveries->pluck('channel')->all());
        $this->assertSame(['sent', 'sent'], $recoveries->pluck('status')->all());
        $this->assertSame([2, 1], $recoveries->pluck('recipient_count')->all());

        Notification::assertSentTo([$admin, $operator], AlertResolved::class);
        Notification::assertSentTimes(AlertResolved::class, 2);

        // One opening Telegram message plus one recovery.
        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request) use ($server): bool {
            if (! str_contains((string) $request['text'], 'Recovered')) {
                return false;
            }

            $this->assertSame((int) self::THREAD_ID, $request['message_thread_id']);
            $this->assertSame(self::CHAT_ID, $request['chat_id']);
            $this->assertStringContainsString($server['server']->name, (string) $request['text']);

            return true;
        });

        // Resolving again is a no-op: no extra row, no extra notification.
        $this->app->make(AlertEngine::class)->resolve($alert->fresh(), $operator, 'again');
        $this->assertSame(2, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->count());
        Notification::assertSentTimes(AlertResolved::class, 2);
        Http::assertSentCount(2);
    }

    public function test_administrative_closes_send_no_recovery(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $fleet = $this->makeFleet(2, $admin);

        foreach ($fleet as $entry) {
            $this->ingest($entry['plain'], $this->payload($entry['server']->hostname, diskPct: 95.5))
                ->assertStatus(202);
        }

        $disabledAlert = Alert::query()->where('server_id', $fleet[0]['server']->id)->sole();
        $deletedAlert = Alert::query()->where('server_id', $fleet[1]['server']->id)->sole();

        // Both alerts must have real opened deliveries before the close, or
        // this test could pass by accident.
        foreach ([$disabledAlert, $deletedAlert] as $alert) {
            $this->assertSame(2, AlertDelivery::query()
                ->where('alert_id', $alert->id)
                ->where('event', 'opened')
                ->count());
        }

        $this->app->make(ServerService::class)->update($fleet[0]['server'], [
            'name' => $fleet[0]['server']->name,
            'hostname' => $fleet[0]['server']->hostname,
            'alerts_enabled' => false,
        ], $admin);

        $disabledAlert->refresh();
        $this->assertSame(AlertStatus::Resolved, $disabledAlert->status);
        $this->assertSame('Alerts disabled for this server', $disabledAlert->resolution_note);
        $this->assertSame($admin->id, $disabledAlert->resolved_by);
        $this->assertSame(0, AlertDelivery::query()
            ->where('alert_id', $disabledAlert->id)
            ->where('event', 'resolved')
            ->count());

        $disabledAudit = AuditEvent::query()->where('action', 'server.alerts_disabled')->sole();
        $this->assertSame(1, $disabledAudit->meta_json['closed_count']);
        $this->assertFalse(
            AuditEvent::query()
                ->where('action', 'alert.resolved')
                ->where('target_id', $disabledAlert->id)
                ->sole()
                ->meta_json['notified'],
        );

        $this->app->make(ServerService::class)->delete($fleet[1]['server'], $admin);

        $deletedAlert->refresh();
        $this->assertSame(AlertStatus::Resolved, $deletedAlert->status);
        $this->assertSame('Server deleted', $deletedAlert->resolution_note);
        $this->assertSame(0, AlertDelivery::query()
            ->where('alert_id', $deletedAlert->id)
            ->where('event', 'resolved')
            ->count());
        $this->assertSoftDeleted('servers', ['id' => $fleet[1]['server']->id]);

        Notification::assertNotSentTo($admin, AlertResolved::class);
        Http::assertSentCount(2); // one opening message per server, no recovery
    }

    // ------------------------------------------------------------------
    // Token lifecycle: rotation grace, revocation, expired/inactive statuses
    // ------------------------------------------------------------------

    public function test_token_rotation_keeps_the_old_token_for_24h_then_revocation_and_statuses_are_exact(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $server = $this->makeFleet(1)[0];
        $old = $server['plain'];

        $this->ingest($old, $this->payload($server['server']->hostname))->assertStatus(202);

        $rotated = $this->app->make(TokenService::class)->rotate($server['server'], 1440, $admin);
        $new = $rotated->plain;

        $oldRow = ServerToken::query()
            ->where('token_prefix', substr($old, 0, 8))
            ->sole();

        $this->assertTrue($oldRow->expires_at->equalTo(now()->addMinutes(1440)));

        // Grace: the old token still works 23h59m later, not at 24h+1s.
        $this->ingest($old, $this->payload($server['server']->hostname))->assertStatus(202);

        Carbon::setTestNow(now()->addMinutes(1439));
        $this->ingest($old, $this->payload($server['server']->hostname))->assertStatus(202);

        Carbon::setTestNow($oldRow->expires_at->copy()->addSecond());
        $this->ingest($old, $this->payload($server['server']->hostname))->assertStatus(401);

        // The new token is valid at the same moment.
        $this->ingest($new, $this->payload($server['server']->hostname))->assertStatus(202);

        // Revocation is immediate.
        $this->app->make(TokenService::class)->revoke($rotated->token, $admin);
        $this->ingest($new, $this->payload($server['server']->hostname))->assertStatus(401);

        // An explicitly expired token and a malformed token are both 401.
        $expired = $this->app->make(TokenService::class)->issue(
            $server['server'],
            $admin,
            'expired',
            now()->subMinute(),
        );
        $this->ingest($expired->plain, $this->payload($server['server']->hostname))->assertStatus(401);
        $this->ingest('not-a-valid-agent-token', $this->payload($server['server']->hostname))->assertStatus(401);

        // A genuine token on an inactive server is 403, not 401.
        $inactive = $this->makeServer('acceptance-inactive', ['is_active' => false]);
        $inactiveToken = $this->app->make(TokenService::class)->issue($inactive);
        $this->ingest($inactiveToken->plain, $this->payload($inactive->hostname))->assertStatus(403);

        // No plaintext token is ever stored: only the HMAC hash and the
        // documented eight-character prefix.
        $this->assertFalse(DB::table('server_tokens')->where('token_hash', $old)->exists());
        $this->assertFalse(DB::table('server_tokens')->where('token_hash', $new)->exists());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $oldRow->getRawOriginal('token_hash'));
        $this->assertSame(substr($old, 0, 8), $oldRow->token_prefix);
    }

    // ------------------------------------------------------------------
    // Roles and admin user CRUD / last-admin guard
    // ------------------------------------------------------------------

    public function test_viewers_cannot_mutate_and_admin_user_crud_keeps_the_last_admin(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $viewer = $this->makeUser('viewer', UserRole::Viewer);
        $server = $this->makeFleet(1)[0];

        $alert = Alert::query()->create([
            'server_id' => $server['server']->id,
            'rule_key' => AlertRule::DiskHigh,
            'severity' => AlertSeverity::Warning,
            'status' => AlertStatus::Open,
            'active_key' => $server['server']->id.':disk_high',
            'title' => 'Disk usage high',
            'message' => 'Disk usage high on '.$server['server']->name,
            'context_json' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'notification_count' => 0,
        ]);

        // Viewer: read surfaces are allowed, every mutation/action surface is not.
        $this->actingAs($viewer)
            ->get('/settings/users')
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get('/settings/alerts')
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get('/servers/create')
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('servers.edit', $server['server']))
            ->assertForbidden();

        Livewire::test(ServerTokens::class, ['server' => $server['server']])
            ->assertStatus(403);

        Livewire::test(AlertShow::class, ['alert' => $alert])
            ->call('acknowledge')
            ->assertStatus(403);

        Livewire::test(AlertShow::class, ['alert' => $alert])
            ->set('note', 'viewer must not resolve')
            ->call('resolve')
            ->assertStatus(403);

        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
        $this->assertSame(1, ServerToken::query()->count());

        // Admin: create, change a role, deactivate.
        $this->actingAs($admin);

        Livewire::test(UserForm::class)
            ->set('name', 'Acceptance Operator')
            ->set('email', 'acceptance.operator@example.test')
            ->set('role', 'operator')
            ->set('isActive', true)
            ->set('password', 'acceptance-password-2')
            ->set('passwordConfirmation', 'acceptance-password-2')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::query()->where('email', 'acceptance.operator@example.test')->sole();

        Livewire::test(UserForm::class, ['userId' => $created->id])
            ->set('role', 'viewer')
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $created->refresh();
        $this->assertSame(UserRole::Viewer, $created->role());
        $this->assertFalse($created->is_active);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.created')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'user.role_changed')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'user.deactivated')->exists());

        // The only active administrator cannot be demoted or deactivated.
        Livewire::test(UserForm::class, ['userId' => $admin->id])
            ->set('role', 'viewer')
            ->call('save')
            ->assertHasErrors('role');

        Livewire::test(UserForm::class, ['userId' => $admin->id])
            ->set('role', 'admin')
            ->set('isActive', false)
            ->call('save')
            ->assertHasErrors('isActive');

        $admin->refresh();
        $this->assertSame(UserRole::Admin, $admin->role());
        $this->assertTrue($admin->is_active);
    }

    // ------------------------------------------------------------------
    // Retention dry run and backup/restore
    // ------------------------------------------------------------------

    public function test_retention_dry_run_counts_without_deleting_and_backup_restore_preserves_the_database(): void
    {
        $fleet = $this->makeFleet(3);

        foreach ($fleet as $entry) {
            $this->ingest($entry['plain'], $this->payload($entry['server']->hostname))->assertStatus(202);
        }

        // Backdate one sample outside the 14-day sample window.
        DB::table('metric_samples')
            ->where('server_id', $fleet[0]['server']->id)
            ->update(['collected_at' => now()->subDays(15)]);

        $this->artisan('noc:prune', ['--dry-run' => true])
            ->expectsOutputToContain('metric_samples: 1')
            ->assertSuccessful();

        $this->assertSame(3, MetricSample::query()->count());
        $this->assertSame(3, DB::table('metric_details')->count());

        $backup = $this->tmpDir.'/noc.sqlite';

        $this->artisan('noc:backup', ['--path' => $backup])
            ->expectsOutputToContain('integrity_check: ok')
            ->expectsOutputToContain('servers: 3')
            ->expectsOutputToContain('metric_samples: 3')
            ->assertSuccessful();

        $this->assertFileExists($backup);

        // A restore is the verified backup file itself; open it with a fresh
        // PDO connection, exactly like the deployment runbook.
        $restored = $this->tmpDir.'/restored.sqlite';
        $this->assertTrue(copy($backup, $restored));

        $pdo = new PDO('sqlite:'.$restored);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM metric_samples')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM metric_details')->fetchColumn());
    }

    // ------------------------------------------------------------------
    // Malformed input: documented statuses, never a 500
    // ------------------------------------------------------------------

    public function test_malformed_ingest_requests_use_the_documented_statuses_and_never_500(): void
    {
        $server = $this->makeFleet(1)[0];
        $plain = $server['plain'];

        $this->clearIngestLimits($plain);

        $unauthorized = $this->postJson(self::INGEST_URI, $this->payload($server['server']->hostname));
        $unauthorized->assertStatus(401);
        $this->assertNoStore($unauthorized);

        $this->withHeaders(['Authorization' => 'Basic abc'])
            ->postJson(self::INGEST_URI, $this->payload($server['server']->hostname))
            ->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer not-a-valid-agent-token'])
            ->postJson(self::INGEST_URI, $this->payload($server['server']->hostname))
            ->assertStatus(401);

        $notJson = $this->rawIngest($plain, json_encode($this->payload($server['server']->hostname)), 'text/plain');
        $notJson->assertStatus(415);
        $this->assertNoStore($notJson);

        $badJson = $this->rawIngest($plain, '{', 'application/json');
        $badJson->assertStatus(400);
        $this->assertNoStore($badJson);

        $this->rawIngest($plain, '[]', 'application/json')
            ->assertStatus(400);

        $this->rawIngest($plain, '', 'application/json')
            ->assertStatus(400);

        $this->rawIngest($plain, '{"schema_version":2}', 'application/json')
            ->assertStatus(422);

        $invalid = $this->payload($server['server']->hostname);
        $invalid['metrics']['cpu']['usage_pct'] = 'hot';
        $this->rawIngest($plain, json_encode($invalid), 'application/json')
            ->assertStatus(422);

        $oversized = str_repeat('x', (int) config('ingest.max_bytes') + 1);
        $this->rawIngest($plain, $oversized, 'application/json')
            ->assertStatus(413);

        $inactive = $this->makeServer('malformed-inactive', ['is_active' => false]);
        $inactiveToken = $this->app->make(TokenService::class)->issue($inactive);
        $this->clearIngestLimits($inactiveToken->plain);
        $this->ingest($inactiveToken->plain, $this->payload($inactive->hostname))
            ->assertStatus(403);

        // A valid payload still succeeds after all of the above.
        $this->clearIngestLimits($plain);
        $accepted = $this->ingest($plain, $this->payload($server['server']->hostname));
        $accepted->assertStatus(202);
        $this->assertNoStore($accepted);

        // The documented per-token budget: request 31 is 429, not a 500.
        config(['ingest.per_token_per_minute' => 1]);
        $this->clearIngestLimits($plain);

        $this->ingest($plain, $this->payload($server['server']->hostname))
            ->assertStatus(202);

        $this->ingest($plain, $this->payload(
            $server['server']->hostname,
            collectedAt: now()->addSecond()->toIso8601ZuluString(),
        ))->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    // ------------------------------------------------------------------
    // Load fixture command
    // ------------------------------------------------------------------

    public function test_make_load_fixture_refuses_production_and_writes_mode_600_entries(): void
    {
        $path = $this->tmpDir.'/noc-load.json';

        $this->app['env'] = 'production';

        $this->artisan('noc:make-load-fixture', ['--servers' => 1, '--path' => $path])
            ->expectsOutputToContain('refuses to run with APP_ENV=production')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);

        $this->app['env'] = 'testing';

        $this->artisan('noc:make-load-fixture', ['--servers' => 3, '--path' => $path])
            ->expectsOutputToContain('Wrote 3 fixture entries')
            ->assertSuccessful();

        $this->assertFileExists($path);

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($path) & 0777);
        }

        $entries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(3, $entries);
        $this->assertSame(['load-001', 'load-002', 'load-003'], array_column($entries, 'name'));
        $this->assertSame(3, Server::query()->count());
        $this->assertSame(3, ServerToken::query()->count());

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/\Anoc_[A-Za-z0-9_-]{43}\z/', $entry['token']);
            $this->assertSame(1, $entry['payload']['schema_version']);
            $this->assertSame(60, $entry['payload']['agent']['interval_seconds']);
        }

        // No plaintext fixture token is stored in the token table.
        $hashes = ServerToken::query()->pluck('token_hash')->all();
        foreach ($entries as $entry) {
            $this->assertNotContains($entry['token'], $hashes);
        }

        // Refuses to clobber an existing fixture without --force.
        $this->artisan('noc:make-load-fixture', ['--servers' => 1, '--path' => $path])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    // ------------------------------------------------------------------
    // Security spot checks (the full checklist lives in README)
    // ------------------------------------------------------------------

    public function test_agent_and_telegram_secrets_are_absent_from_storage_html_audit_and_notifications(): void
    {
        $admin = $this->makeUser('admin', UserRole::Admin);
        $server = $this->makeFleet(1)[0];

        $this->ingest($server['plain'], $this->payload($server['server']->hostname, diskPct: 92.5))
            ->assertStatus(202);

        // Agent tokens are keyed hashes; the fixture bot token is ciphertext.
        $tokens = ServerToken::query()->get();

        foreach ($tokens as $token) {
            $hash = (string) $token->getRawOriginal('token_hash');

            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
            $this->assertNotSame($server['plain'], $hash);
            $this->assertStringStartsWith('noc_', $token->token_prefix);
            $this->assertSame(substr($server['plain'], 0, 8), $token->token_prefix);
        }

        $this->assertFalse(DB::table('server_tokens')->where('token_hash', $server['plain'])->exists());

        $rawBotToken = DB::table('settings')
            ->where('key', 'alerts.channels.telegram.bot_token')
            ->value('value');

        $this->assertIsString($rawBotToken);
        $this->assertNotSame(self::BOT_TOKEN, $rawBotToken);
        $this->assertSame(self::BOT_TOKEN, Crypt::decryptString($rawBotToken));

        $audit = AuditEvent::query()->get()->map(
            static fn (AuditEvent $event): string => json_encode($event->meta_json) ?: '',
        )->implode(' ');

        $this->assertStringNotContainsString($server['plain'], $audit);
        $this->assertStringNotContainsString(self::BOT_TOKEN, $audit);

        // The token page shows the 8-character prefix only.
        $this->actingAs($admin)
            ->get(route('servers.tokens', $server['server']))
            ->assertOk()
            ->assertSee(substr($server['plain'], 0, 8))
            ->assertDontSee($server['plain']);

        // Alert email/Telegram text never carries either secret.
        Notification::assertSentTo($admin, AlertOpened::class, function (AlertOpened $notification) use ($admin, $server): bool {
            $mail = $notification->toMail($admin)->render();
            $lines = implode("\n", $notification->lines());

            $this->assertStringNotContainsString($server['plain'], $mail);
            $this->assertStringNotContainsString(self::BOT_TOKEN, $mail);
            $this->assertStringNotContainsString($server['plain'], $lines);
            $this->assertStringNotContainsString(self::BOT_TOKEN, $lines);

            return true;
        });

        Http::assertSent(function (ClientRequest $request) use ($server): bool {
            $this->assertStringNotContainsString($server['plain'], (string) $request['text']);
            $this->assertStringNotContainsString($server['plain'], json_encode($request->data()) ?: '');

            return true;
        });

        // Security headers are present on authenticated HTML.
        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeUser(
        string $suffix,
        UserRole $role,
        bool $notify = true,
        bool $active = true,
    ): User {
        return User::factory()->create([
            'name' => Str::headline($suffix),
            'email' => $suffix.'@acceptance.example.test',
            'password' => 'acceptance-password',
            'role' => $role->value,
            'is_active' => $active,
            'notify_email' => $notify,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeServer(string $name, array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => $name,
            'hostname' => $name.'.acceptance.example.test',
            'panel' => 'cpanel',
            'server_type' => 'web',
            'interval_seconds' => 60,
            'is_active' => true,
            'alerts_enabled' => true,
            'health' => ServerHealth::Unknown,
            'issue_codes' => [],
        ], $attributes));
    }

    /**
     * @return list<array{server: Server, token: ServerToken, plain: string}>
     */
    private function makeFleet(int $count, ?User $actor = null): array
    {
        $tokens = $this->app->make(TokenService::class);
        $fleet = [];

        for ($index = 1; $index <= $count; $index++) {
            $server = $this->makeServer(sprintf('acceptance-web-%02d', $index));
            /** @var IssuedToken $issued */
            $issued = $tokens->issue($server, $actor, 'acceptance token');

            $fleet[] = [
                'server' => $server,
                'token' => $issued->token,
                'plain' => $issued->plain,
            ];
        }

        return $fleet;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingest(string $plain, array $payload): TestResponse
    {
        return $this->rawIngest($plain, json_encode($payload, JSON_THROW_ON_ERROR), 'application/json');
    }

    private function rawIngest(string $plain, string $body, string $contentType): TestResponse
    {
        $response = $this->call('POST', self::INGEST_URI, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
        ], $body);

        $this->assertNotSame(500, $response->getStatusCode(), 'Ingest must never answer 500 for this request.');

        return $response;
    }

    private function clearIngestLimits(string $plain): void
    {
        $token = $this->app->make(TokenService::class)->resolve($plain);

        if ($token !== null) {
            RateLimiter::clear('ingest:token:'.$token->getKey());
        }

        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');
    }

    private function assertNoStore(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
    }

    private function delivery(Alert $alert, string $channel): AlertDelivery
    {
        return AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('channel', $channel)
            ->where('event', 'opened')
            ->sole();
    }

    /**
     * A realistic schema-v1 payload. Defaults are below every threshold, so a
     * test that wants an alert must pass the offending value explicitly.
     *
     * @param  array<string, mixed>  $panel
     * @param  array<string, mixed>  $extensions
     * @return array<string, mixed>
     */
    private function payload(
        string $hostname,
        float $diskPct = 42.5,
        float $memPct = 38.0,
        float $cpuPct = 12.5,
        ?string $collectedAt = null,
        array $panel = [],
        array $extensions = [],
    ): array {
        $memoryTotal = 8 * 1024 * 1024 * 1024;
        $swapTotal = 2 * 1024 * 1024 * 1024;
        $diskTotal = 100 * 1024 * 1024 * 1024;

        return [
            'schema_version' => 1,
            'agent' => [
                'version' => '0.1.0',
                'id' => (string) Str::uuid(),
                'hostname' => $hostname,
                'panel' => 'cpanel',
                'collected_at' => $collectedAt ?? now()->utc()->toIso8601ZuluString(),
                'interval_seconds' => 60,
            ],
            'system' => [
                'os' => [
                    'name' => 'AlmaLinux',
                    'version' => '9.4',
                    'kernel' => '5.14.0-362.8.1.el9_3.x86_64',
                ],
                'uptime_seconds' => 123_456,
            ],
            'metrics' => [
                'cpu' => [
                    'usage_pct' => $cpuPct,
                    'iowait_pct' => 0.3,
                    'load1' => 0.4,
                    'load5' => 0.3,
                    'load15' => 0.2,
                    'cores' => 8,
                ],
                'memory' => [
                    'total_bytes' => $memoryTotal,
                    'used_bytes' => (int) round($memoryTotal * $memPct / 100),
                    'used_pct' => $memPct,
                ],
                'swap' => [
                    'total_bytes' => $swapTotal,
                    'used_bytes' => 0,
                    'used_pct' => 0.0,
                ],
                'disk' => [
                    'root' => [
                        'total_bytes' => $diskTotal,
                        'used_bytes' => (int) round($diskTotal * $diskPct / 100),
                        'used_pct' => $diskPct,
                        'inode_pct' => 12.75,
                    ],
                ],
                'network' => [
                    'rx_bytes_per_sec' => 12_345,
                    'tx_bytes_per_sec' => 6_789,
                ],
                'processes' => [
                    'running' => 271,
                    'blocked' => 0,
                ],
            ],
            'panel_metrics' => array_replace([
                'cpanel.version' => '120.0.1',
                'cpanel.accounts' => 25,
                'cpanel.mailq' => 0,
                'cpanel.mysql_ping' => true,
                'service.apache.installed' => true,
                'service.apache.enabled' => true,
                'service.apache.running' => true,
            ], $panel),
            'extensions' => array_replace([
                'mysql.available' => true,
                'mysql.threads_running' => 2,
                'smart.status' => 'ok',
                'smart.disks' => 2,
                'smart.bad_count' => 0,
                'smart.cache_age_seconds' => 3_600,
                'diskquota.accounts' => 25,
                'diskquota.over' => 0,
                'diskquota.cache_age_seconds' => 3_600,
                'imunify.scanning' => false,
                'imunify.cache_age_seconds' => 300,
            ], $extensions),
        ];
    }
}
