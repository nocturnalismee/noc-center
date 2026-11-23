<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertEngine;
use App\Alerts\AlertNotifier;
use App\Enums\AlertStatus;
use App\Enums\UserRole;
use App\Issues\IssueSet;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
use App\Notifications\AlertOpened;
use App\Notifications\AlertResolved;
use App\Services\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_BOT_TOKEN = '123456:AAH-super-secret-bot-token';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 10:00:00');

        config([
            'mail.default' => 'array',
            'alerts.channels.email.enabled' => true,
            'alerts.channels.telegram.enabled' => false,
        ]);

        // No catch-all stub: each test registers the responses it expects, and
        // any stray request fails the test.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_active_admin_and_operator_users_with_notify_email_receive_email(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);
        $operator = $this->user('operator', UserRole::Operator, notify: true);
        $this->user('viewer', UserRole::Viewer, notify: true);
        $this->user('quiet-operator', UserRole::Operator, notify: false);
        $this->user('inactive-admin', UserRole::Admin, notify: true, active: false);

        Notification::fake();

        $alert = $this->openAlert('memory_high', 'critical');

        Notification::assertSentTo([$admin, $operator], AlertOpened::class);
        Notification::assertSentTimes(AlertOpened::class, 2);
        Notification::assertNotSentTo(User::query()->where('email', 'viewer@noc.example.test')->sole(), AlertOpened::class);
    }

    public function test_the_email_excludes_tokens_and_mysql_query_text(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);

        Notification::fake();

        $alert = $this->openAlert('service_down', 'critical', context: [
            'agent_token' => 'tok_ABCDEFGHIJKLMNOP',
            'bot_token' => self::TEST_BOT_TOKEN,
            'query' => 'SELECT password FROM mysql.user',
        ]);

        Notification::assertSentTo($admin, AlertOpened::class, function (AlertOpened $notification) use ($alert): bool {
            $text = $notification->toMail($this->app->make(User::class))->render();
            $plain = implode("\n", $notification->lines());

            foreach (['tok_ABCDEFGHIJKLMNOP', self::TEST_BOT_TOKEN, 'SELECT password FROM mysql.user'] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
                $this->assertStringNotContainsString($secret, $plain);
            }

            $this->assertStringContainsString($alert->server->name, $plain);
            $this->assertStringContainsString('Service down', $plain);

            return true;
        });
    }

    public function test_the_recovery_message_excludes_tokens_and_mysql_query_text(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);

        Notification::fake();

        $alert = $this->openAlert('service_down', 'critical', context: [
            'agent_token' => 'tok_ABCDEFGHIJKLMNOP',
            'query' => 'SELECT password FROM mysql.user',
        ]);

        $this->engine()->resolve($alert);

        // The recovery job is queued after the resolve; run it explicitly so
        // the test does not depend on the test-level transaction committing.
        $this->runJob($alert, 'email', 'resolved');

        Notification::assertSentTo($admin, AlertResolved::class, function (AlertResolved $notification) use ($alert): bool {
            $text = $notification->toMail($this->app->make(User::class))->render();
            $plain = implode("\n", $notification->lines());

            foreach (['tok_ABCDEFGHIJKLMNOP', self::TEST_BOT_TOKEN, 'SELECT password FROM mysql.user'] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
                $this->assertStringNotContainsString($secret, $plain);
            }

            $this->assertStringContainsString($alert->server->name, $plain);

            return true;
        });
    }

    public function test_dispatch_creates_one_delivery_row_per_channel_and_event(): void
    {
        $this->enableTelegram();
        $this->user('admin', UserRole::Admin, notify: true);
        Queue::fake();

        $alert = $this->openAlert('memory_high', 'critical');

        $rows = AlertDelivery::query()->where('alert_id', $alert->id)->orderBy('channel')->get();
        $this->assertSame(['email', 'telegram'], $rows->pluck('channel')->all());
        $this->assertSame(['opened', 'opened'], $rows->pluck('event')->all());

        Queue::assertPushed(SendAlertNotification::class, 2);

        $this->engine()->resolve($alert);

        $events = AlertDelivery::query()->where('alert_id', $alert->id)->orderBy('id')->get()
            ->map(fn (AlertDelivery $row): string => $row->channel.':'.$row->event)->all();
        $this->assertSame(['email:opened', 'telegram:opened', 'email:resolved', 'telegram:resolved'], $events);

        Queue::assertPushed(SendAlertNotification::class, 4);
    }

    public function test_the_delivery_row_is_the_idempotency_key(): void
    {
        // Fix round: `ShouldBeUnique` held a 300 s lock past completion and
        // suppressed real retries. The unique `(alert_id, channel, event)` row
        // is the idempotency key instead, and the job carries no lock.
        $alert = $this->openAlert('memory_high', 'critical');
        $job = new SendAlertNotification($alert->id, 'email', 'opened');

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff());
        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'email')->where('event', 'opened')->count());
    }

    public function test_attempts_increment_and_sent_sets_sent_at(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert('memory_high', 'critical');
        $delivery = $this->delivery($alert, 'email');

        $this->runJob($alert, 'email', 'opened');

        $delivery = $delivery->fresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(1, $delivery->recipient_count);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->next_retry_at);
        $this->assertEquals(now(), $alert->fresh()->last_notified_at);
        $this->assertSame(1, $alert->fresh()->notification_count);
    }

    public function test_a_failed_delivery_records_a_redacted_error_and_backoff(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);

        // A real HTTP failure on the Telegram side: the message mentions the
        // full bot URL, which must be redacted before the row is written.
        $this->enableTelegram(self::TEST_BOT_TOKEN, '-1001234567890');
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: timed out at https://api.telegram.org/bot'.self::TEST_BOT_TOKEN.'/sendMessage',
        ));

        $alert = $this->openAlert('memory_high', 'critical');

        // Opening already made one failed attempt; a second keeps the backoff
        // climbing (attempt 2 → 120 s).
        $this->runJob($alert, 'telegram', 'opened');

        $delivery = $this->delivery($alert, 'telegram')->fresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertNull($delivery->sent_at);
        $this->assertNotNull($delivery->error);
        $this->assertStringNotContainsString(self::TEST_BOT_TOKEN, $delivery->error);
        $this->assertStringNotContainsString('api.telegram.org', $delivery->error);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertEquals(now()->addSeconds(120), $delivery->next_retry_at);
    }

    public function test_one_failing_channel_still_lets_the_other_send(): void
    {
        $this->enableTelegram(self::TEST_BOT_TOKEN, '-1001234567890');
        $this->user('admin', UserRole::Admin, notify: true);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400),
        ]);

        $alert = $this->openAlert('memory_high', 'critical');

        // The sync queue already attempted both channels at open time.
        $this->assertSame('sent', AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'email')->where('event', 'opened')->sole()->status);
        $this->assertSame('failed', AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->where('event', 'opened')->sole()->status);
        // In-app: the alert row itself is the notification, always present.
        $this->assertTrue($alert->fresh()->status === AlertStatus::Open);
    }

    public function test_resolving_creates_a_resolved_row_while_the_opened_row_stays(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert('memory_high', 'critical');
        $opened = $this->delivery($alert, 'email');

        $this->engine()->resolve($alert);

        $this->assertSame('opened', $opened->fresh()->event);
        $this->assertSame('sent', $opened->fresh()->status);
        $this->assertSame(1, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->count());
    }

    public function test_recovery_is_only_sent_to_channels_that_had_an_opened_delivery(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert('memory_high', 'critical');

        // Telegram was enabled after the alert opened: no opened row exists.
        $this->enableTelegram();

        $this->engine()->resolve($alert);

        $this->assertSame(
            ['email'],
            AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->pluck('channel')->all(),
        );
    }

    public function test_a_disabled_telegram_channel_creates_no_row_and_makes_no_http_call(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        config(['alerts.channels.telegram.enabled' => false, 'alerts.channels.telegram.bot_token' => self::TEST_BOT_TOKEN, 'alerts.channels.telegram.chat_id' => '-1001234567890']);

        $alert = $this->openAlert('memory_high', 'critical');
        $this->runJob($alert, 'telegram', 'opened');

        $this->assertSame(0, AlertDelivery::query()->where('channel', 'telegram')->count());
        Http::assertNothingSent();
    }

    public function test_resolving_an_alert_whose_server_had_alerts_disabled_sends_no_recovery(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);
        Notification::fake();
        $alert = $this->openAlert('memory_high', 'critical');

        $closed = $this->engine()->closeForDisabledAlerts($alert->server, $admin);

        $this->assertSame(1, $closed);
        Notification::assertNotSentTo($admin, AlertResolved::class);
    }

    public function test_reminders_are_sent_once_after_the_reminder_window_and_not_before(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert('memory_high', 'critical');
        $this->runJob($alert, 'email', 'opened');

        Carbon::setTestNow('2026-11-23 15:59:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();
        $this->assertSame('sent', $this->delivery($alert, 'email')->fresh()->status);
        $this->assertSame(1, $this->delivery($alert, 'email')->fresh()->attempts);
        $this->assertSame(1, $alert->fresh()->notification_count);

        // Before 6 hours: the delivery stays sent.
        Carbon::setTestNow('2026-11-23 15:59:30');
        $this->artisan('noc:alert-reminders')->assertSuccessful();
        $this->assertSame(1, $this->delivery($alert, 'email')->fresh()->attempts);

        Carbon::setTestNow('2026-11-23 16:00:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $delivery = $this->delivery($alert, 'email')->fresh();
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(2, $alert->fresh()->notification_count);

        // Exactly once per window: an immediate re-run sends nothing new.
        $this->artisan('noc:alert-reminders')->assertSuccessful();
        $this->assertSame(2, $this->delivery($alert, 'email')->fresh()->attempts);
    }

    public function test_acknowledging_stops_further_reminders(): void
    {
        $operator = $this->user('operator', UserRole::Operator, notify: true);
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert('memory_high', 'critical');
        $this->runJob($alert, 'email', 'opened');

        $this->engine()->acknowledge($alert, $operator);

        Carbon::setTestNow('2026-11-23 20:00:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $this->assertSame(1, $this->delivery($alert, 'email')->fresh()->attempts);
        $this->assertSame(1, $alert->fresh()->notification_count);
    }

    public function test_a_reminder_queued_before_acknowledgement_does_not_send_after_it(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);
        $operator = $this->user('operator', UserRole::Operator, notify: true);

        Notification::fake();

        $alert = $this->openAlert('memory_high', 'critical');
        $this->runJob($alert, 'email', 'opened');

        // A real reminder requeue writes `pending` but keeps the previous
        // successful `sent_at`. Ack before the queued job is handled.
        Carbon::setTestNow('2026-11-23 17:00:00');
        Queue::fake();
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $queued = $this->delivery($alert, 'email')->fresh();
        $this->assertSame('pending', $queued->status);
        $this->assertSame(1, $queued->attempts);
        $this->assertNotNull($queued->sent_at);
        Queue::assertPushed(
            SendAlertNotification::class,
            fn (SendAlertNotification $job): bool => $job->alertId === $alert->id
                && $job->channel === 'email'
                && $job->event === 'opened',
        );

        $this->engine()->acknowledge($alert, $operator);
        $this->runJob($alert, 'email', 'opened');

        $delivery = $this->delivery($alert, 'email')->fresh();

        $this->assertSame('sent', $delivery->status);
        $this->assertNull($delivery->next_retry_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(1, $alert->fresh()->notification_count);
        Notification::assertSentToTimes($admin, AlertOpened::class, 1);
    }

    public function test_an_acknowledged_alert_still_delivers_its_original_opened_notification(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);
        $operator = $this->user('operator', UserRole::Operator, notify: true);

        Queue::fake();
        Notification::fake();

        $alert = $this->openAlert('memory_high', 'critical');

        // The operator acknowledges before the queued opening job is handled.
        $this->engine()->acknowledge($alert, $operator);

        $this->assertSame('pending', $this->delivery($alert, 'email')->status);

        $this->runJob($alert, 'email', 'opened');

        $delivery = $this->delivery($alert, 'email')->fresh();

        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        Notification::assertSentTo($admin, AlertOpened::class);
    }

    public function test_reminders_skip_resolved_alerts_and_honor_next_retry_at(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);

        $resolved = $this->openAlert('memory_high', 'critical', server: $this->server('resolved'));
        $this->engine()->resolve($resolved);

        $waiting = $this->openAlert('disk_high', 'warning', server: $this->server('waiting'));
        AlertDelivery::query()->where('alert_id', $waiting->id)->where('channel', 'email')->update([
            'status' => 'failed',
            'attempts' => 1,
            'next_retry_at' => now()->addHours(30),
        ]);

        Carbon::setTestNow('2026-11-23 20:00:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        // Resolved: never reminded. Waiting: before its next_retry_at.
        $this->assertSame(1, $this->deliveryFor($resolved, 'email')->attempts);
        $this->assertSame(1, $this->deliveryFor($waiting, 'email')->attempts);

        Carbon::setTestNow('2026-11-25 09:00:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $waitingDelivery = $this->deliveryFor($waiting, 'email');
        $this->assertSame(2, $waitingDelivery->attempts);
        $this->assertSame('sent', $waitingDelivery->status);
        // The resolved alert's opened row is still untouched.
        $this->assertSame(1, $this->deliveryFor($resolved, 'email')->attempts);
    }

    public function test_sending_a_test_writes_the_audit_and_reports_redacted_failures(): void
    {
        $admin = $this->user('admin', UserRole::Admin, notify: true);

        $notifier = $this->app->make(AlertNotifier::class);
        $notifier->sendTest('email', $admin);

        $audit = AuditEvent::query()->where('action', 'alert_channel.tested')->sole();
        $this->assertSame('email', $audit->meta_json['channel']);
        $this->assertSame($admin->id, $audit->user_id);

        $this->enableTelegram(self::TEST_BOT_TOKEN, '-1001234567890');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        try {
            $notifier->sendTest('telegram', $admin);
            $this->fail('A failed test send must throw.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['channel'] ?? []);
            $this->assertStringNotContainsString(self::TEST_BOT_TOKEN, $message);
            $this->assertStringNotContainsString('api.telegram.org', $message);
        }
    }

    public function test_the_bot_token_is_stored_encrypted_and_never_audited(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);

        $this->app->make(SettingsRepository::class)
            ->set('alerts.channels.telegram.bot_token', self::TEST_BOT_TOKEN, 'secret', true);

        $this->openAlert('memory_high', 'critical');

        $raw = DB::table('settings')->where('key', 'alerts.channels.telegram.bot_token')->value('value');
        $this->assertNotSame(self::TEST_BOT_TOKEN, $raw);

        $auditJson = AuditEvent::query()->get()->pluck('meta_json')->map(fn ($meta) => json_encode($meta))->implode(' ');
        $this->assertStringNotContainsString(self::TEST_BOT_TOKEN, $auditJson);
    }

    private function runJob(Alert $alert, string $channel, string $event): void
    {
        (new SendAlertNotification($alert->id, $channel, $event))->handle(
            $this->app->make(AlertNotifier::class),
        );
    }

    private function delivery(Alert $alert, string $channel): AlertDelivery
    {
        return AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', $channel)->where('event', 'opened')->sole();
    }

    /**
     * Any delivery row for an alert and channel (regardless of event).
     */
    private function deliveryFor(Alert $alert, string $channel): AlertDelivery
    {
        return AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('channel', $channel)
            ->where('event', 'opened')
            ->sole();
    }

    private function engine(): AlertEngine
    {
        return $this->app->make(AlertEngine::class);
    }

    private function openAlert(string $code, string $severity, ?Server $server = null, array $context = []): Alert
    {
        $server ??= $this->server($code);

        $this->engine()->evaluate($server, new IssueSet([$code], [
            $code => [
                'value' => 97.0,
                'threshold' => 95,
                'severity' => $severity,
                ...$context,
            ],
        ]));

        return Alert::query()->where('server_id', $server->id)->where('status', AlertStatus::Open->value)->sole();
    }

    private function server(string $suffix): Server
    {
        return Server::query()->firstOrCreate(
            ['name' => 'server-'.$suffix],
            ['hostname' => $suffix.'.example.test', 'panel' => 'none', 'alerts_enabled' => true],
        );
    }

    private function user(string $suffix, UserRole $role, bool $notify, bool $active = true): User
    {
        return User::factory()->create([
            'email' => $suffix.'@noc.example.test',
            'role' => $role,
            'is_active' => $active,
            'notify_email' => $notify,
        ]);
    }

    private function enableTelegram(?string $token = null, ?string $chat = '-1001234567890'): void
    {
        config([
            'alerts.channels.telegram.enabled' => true,
            'alerts.channels.telegram.bot_token' => $token ?? self::TEST_BOT_TOKEN,
            'alerts.channels.telegram.chat_id' => $chat,
        ]);
    }
}
