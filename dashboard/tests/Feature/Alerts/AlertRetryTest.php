<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertEngine;
use App\Alerts\AlertNotifier;
use App\Alerts\Channels\DeliveryResult;
use App\Alerts\Channels\EmailChannel;
use App\Enums\AlertStatus;
use App\Enums\UserRole;
use App\Issues\IssueSet;
use App\Jobs\RetryDelivery;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\Server;
use App\Models\User;
use App\Notifications\AlertOpened;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fix-round coverage for the Task 9 review: real queue retries, partial email
 * failure, skipped transitions, and the reminder query rules.
 */
class AlertRetryTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456:AAH-super-secret-bot-token';

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- 1. ShouldBeUnique is gone -----------------------------------------

    public function test_the_job_is_not_unique_because_the_row_owns_idempotency(): void
    {
        $alert = $this->openAlert();

        $job = new SendAlertNotification($alert->id, 'email', 'opened');

        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff());
        // No uniqueness lock; only the per-delivery overlap guard survives.
        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('alert:'.$alert->id.':email:opened', $middleware[0]->key);
    }

    public function test_a_requeue_inside_three_hundred_seconds_is_not_suppressed(): void
    {
        // A real queue (database) proves no uniqueness lock can swallow the
        // second push; the row's next_retry_at is what waits.
        config(['queue.default' => 'database']);
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();
        $delivery = $this->delivery($alert, 'email', 'opened');

        $before = DB::table('jobs')->count();

        $notifier = $this->app->make(AlertNotifier::class);
        $notifier->requeue($alert, $delivery);
        $notifier->requeue($alert, $delivery);

        // Both pushes survive (the open-time job was dispatched before the
        // database driver was selected, so it is counted from `$before`).
        $this->assertSame($before + 2, DB::table('jobs')->count());
    }

    // --- 2. Real queue retries ---------------------------------------------

    public function test_a_failed_attempt_in_the_worker_throws_a_retry_exception(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $this->enableTelegram();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $alert = $this->openAlert();
        $delivery = $this->delivery($alert, 'telegram', 'opened');
        $delivery->forceFill(['status' => 'pending', 'attempts' => 0, 'sent_at' => null, 'next_retry_at' => null])->save();

        // A real queue (database) proves the dispatch is not suppressed; then
        // the job is fired through a real worker `Job` (as `queue:work` does),
        // which sets `$this->job` — the job also sets `next_retry_at` during the failed attempt.
        config(['queue.default' => 'database']);
        DB::table('jobs')->delete();

        SendAlertNotification::dispatch($alert->id, 'telegram', 'opened');
        $this->assertSame(1, DB::table('jobs')->count());

        $record = (array) DB::table('jobs')->first();
        $queueJob = new DatabaseJob(
            $this->app,
            $this->app['queue']->connection('database'),
            json_decode($record['payload'], true),
            'default',
            $record['id'],
        );

        $instance = new SendAlertNotification($alert->id, 'telegram', 'opened');
        $instance->setJob($queueJob);

        try {
            $instance->handle($this->app->make(AlertNotifier::class));
            $this->fail('A failed worker attempt must throw RetryDelivery.');
        } catch (RetryDelivery) {
            // expected: the worker applies $tries / backoff()
        }

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryResult::FAILED, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->next_retry_at);
    }

    public function test_a_sync_dispatch_never_leaks_a_failure_to_its_caller(): void
    {
        // `sync` rethrows at the dispatcher, so the job must records the
        // failure in the row and stay quiet: ingest and a manual resolve keep
        // their response even when the mail or Telegram call fails.
        $this->user('admin', UserRole::Admin, notify: true);
        $this->enableTelegram();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $alert = $this->openAlert();

        $delivery = AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->sole();
        $this->assertSame(DeliveryResult::FAILED, $delivery->status);
        $this->assertNotNull($delivery->next_retry_at);
    }

    public function test_a_sent_attempt_does_not_throw(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();
        $delivery = $this->delivery($alert, 'email', 'opened');
        $delivery->forceFill(['status' => 'pending'])->save();

        (new SendAlertNotification($alert->id, 'email', 'opened'))->handle($this->app->make(AlertNotifier::class));

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_early_returns_do_not_deliver_or_throw(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $this->enableTelegram();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $alert = $this->openAlert();
        // The second alert must open before the first is resolved, or the
        // open-time attempt races the resolve.
        $other = $this->openAlert('other');

        $telegram = $this->delivery($alert, 'telegram', 'opened');
        $this->assertSame(DeliveryResult::SENT, $telegram->status);

        // An `opened` job's early return needs the alert to be resolved first;
        // nothing below may reach the network.
        $this->app->make(AlertEngine::class)->resolve($alert);

        Http::fake(fn () => throw new ConnectionException('must not be called'));

        // An `opened` job for an alert that is no longer open.
        (new SendAlertNotification($alert->id, 'telegram', 'opened'))->handle($this->app->make(AlertNotifier::class));

        // A `resolved` job for an alert that is still open.
        (new SendAlertNotification($other->id, 'telegram', 'resolved'))->handle($this->app->make(AlertNotifier::class));

        Http::assertNothingSent();
        $this->assertSame(DeliveryResult::SENT, $telegram->fresh()->status);
        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $other->id)->where('event', 'resolved')->where('channel', 'telegram')->count());
    }

    public function test_a_failed_delivery_keeps_a_next_retry_at_for_the_reminder_command(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $this->enableTelegram();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $alert = $this->openAlert();

        (new SendAlertNotification($alert->id, 'telegram', 'opened'))->handle($this->app->make(AlertNotifier::class));

        $delivery = AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->next_retry_at);
    }

    // --- 3. Reminder query rules -------------------------------------------

    public function test_a_stuck_pending_row_older_than_the_threshold_is_requeued(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();
        $delivery = $this->delivery($alert, 'email', 'opened');

        $delivery->forceFill(['status' => 'pending', 'created_at' => now()->subSeconds(301)])->save();
        DB::table('alert_deliveries')->where('id', $delivery->id)->update(['last_attempt_at' => null]);

        Queue::fake();
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        Queue::assertPushed(SendAlertNotification::class, fn (SendAlertNotification $job): bool => $job->channel === 'email');
    }

    public function test_a_pending_row_newer_than_the_threshold_is_not_requeued(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();
        $delivery = $this->delivery($alert, 'email', 'opened');

        $delivery->forceFill(['status' => 'pending', 'created_at' => now()->subSeconds(10)])->save();
        DB::table('alert_deliveries')->where('id', $delivery->id)->update(['last_attempt_at' => null]);

        Queue::fake();
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendAlertNotification::class);
    }

    public function test_a_failed_resolved_row_on_an_open_alert_is_never_requeued(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();
        $opened = $this->delivery($alert, 'email', 'opened');

        // A stray resolved row on an alert that is still open.
        AlertDelivery::query()->create([
            'alert_id' => $alert->id,
            'channel' => 'email',
            'event' => 'resolved',
            'status' => 'failed',
            'attempts' => 1,
            'next_retry_at' => now()->subMinute(),
        ]);

        Queue::fake();
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendAlertNotification::class, fn (SendAlertNotification $job): bool => $job->event === 'resolved');
        $this->assertSame('sent', $opened->fresh()->status);
    }

    public function test_a_failed_resolved_row_on_a_resolved_alert_is_retried(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();
        $this->app->make(AlertEngine::class)->resolve($alert);

        $resolved = AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->sole();
        $resolved->forceFill(['status' => 'failed', 'attempts' => 1, 'next_retry_at' => now()->subMinute()])->save();

        Queue::fake();
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        Queue::assertPushed(SendAlertNotification::class, fn (SendAlertNotification $job): bool => $job->event === 'resolved');
    }

    // --- 4. Partial email failure ------------------------------------------

    public function test_a_partial_email_failure_counts_as_sent_and_a_retry_does_not_duplicate(): void
    {
        $ok = $this->user('ok', UserRole::Admin, notify: true);
        $bad = $this->user('bad', UserRole::Operator, notify: true);

        $sent = [];
        Notification::fake();
        Notification::shouldReceive('send')->andReturnUsing(function (User $user, AlertOpened $notification) use ($bad, &$sent): void {
            if ($user->is($bad)) {
                throw new \RuntimeException('SMTP 550 mailbox unavailable');
            }

            $sent[] = $user->email;
        });

        $alert = $this->openAlert();

        $delivery = $this->delivery($alert, 'email', 'opened');
        $this->assertSame('sent', $delivery->status);
        $this->assertSame([$ok->email], $sent);

        // A later failure must not resend to the recipient who already got it:
        // the row is `sent`, so the reminder command leaves it alone until the
        // reminder window, and a failed retry is not scheduled at all.
        $delivery->forceFill(['next_retry_at' => now()->subMinute()])->save();
        Carbon::setTestNow('2026-11-23 10:10:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $this->assertSame([$ok->email], $sent);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame(DeliveryResult::SENT, $delivery->fresh()->status);
    }

    public function test_the_email_channel_reports_sent_when_at_least_one_recipient_succeeds(): void
    {
        $this->user('ok', UserRole::Admin, notify: true);
        $this->user('bad', UserRole::Operator, notify: true);

        Notification::fake();
        Notification::shouldReceive('send')->andReturnUsing(function (User $user): void {
            if ($user->email === 'bad@noc.example.test') {
                throw new \RuntimeException('SMTP rejected for bad@noc.example.test');
            }
        });

        $alert = $this->openAlert();

        $result = $this->app->make(EmailChannel::class)->send($alert, 'opened');

        $this->assertSame(DeliveryResult::SENT, $result->status);
        $this->assertSame(1, $result->recipientCount);
        $this->assertNotNull($result->error);
        $this->assertStringNotContainsString('bad@noc.example.test', (string) $result->error);
    }

    public function test_the_email_channel_fails_only_when_every_recipient_fails(): void
    {
        $this->user('one', UserRole::Admin, notify: true);
        $this->user('two', UserRole::Operator, notify: true);

        Notification::fake();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $alert = $this->openAlert();

        // The open-time attempt ran while every recipient failed; it is
        // recorded as `failed` with a retry time and never leaks an exception.
        $delivery = $this->delivery($alert, 'email', 'opened');
        $this->assertSame(DeliveryResult::FAILED, $delivery->status);

        $result = $this->app->make(EmailChannel::class)->send($alert, 'opened');

        $this->assertSame(DeliveryResult::FAILED, $result->status);
        $this->assertSame(0, $result->recipientCount);
    }

    // --- Minor: skipped transition -----------------------------------------

    public function test_a_channel_disabled_between_dispatch_and_handle_is_skipped(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $this->enableTelegram();
        Http::fake();

        $alert = $this->openAlert();
        $delivery = $this->delivery($alert, 'telegram', 'opened');

        // The open-time attempt already consumed the only fake; reset the
        // recorder so the post-disable attempt can be checked in isolation.
        Http::fake();

        // The channel is switched off after the row was created.
        config(['alerts.channels.telegram.enabled' => false]);
        $delivery->forceFill(['status' => 'pending'])->save();

        (new SendAlertNotification($alert->id, 'telegram', 'opened'))->handle($this->app->make(AlertNotifier::class));

        $this->assertSame(DeliveryResult::SKIPPED, $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    // --- Minor: Telegram sendTest honours enabled() ------------------------

    public function test_the_telegram_test_send_refuses_a_disabled_channel(): void
    {
        config(['alerts.channels.telegram.enabled' => false, 'alerts.channels.telegram.bot_token' => self::BOT_TOKEN, 'alerts.channels.telegram.chat_id' => '-100']);
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            $this->app->make(AlertNotifier::class)->sendTest('telegram', $this->user('admin', UserRole::Admin, notify: true));
        } finally {
            Http::assertNothingSent();
        }
    }

    // --- Minor: notification counters are atomic ---------------------------

    public function test_the_notification_count_is_a_single_atomic_increment_per_message(): void
    {
        $this->user('admin', UserRole::Admin, notify: true);
        $alert = $this->openAlert();

        // Opened = one sent message on the email channel.
        $this->assertSame(1, $alert->fresh()->notification_count);
        $this->assertNotNull($alert->fresh()->last_notified_at);

        // Recovery is another sent message, not a new incident. The sync
        // queue already ran the recovery job; it must not be repeated.
        $this->app->make(AlertEngine::class)->resolve($alert);

        $this->assertSame(DeliveryResult::SENT, AlertDelivery::query()->where('alert_id', $alert->id)->where('event', 'resolved')->sole()->status);
        $this->assertSame(2, $alert->fresh()->notification_count);
        $this->assertSame(1, Alert::query()->count());
    }

    // --- helpers -----------------------------------------------------------

    private function runPendingJobFor(Alert $alert, string $channel, string $event): void
    {
        $delivery = AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', $channel)->where('event', $event)->sole();
        $this->app->make(AlertNotifier::class)->deliver($alert->fresh(), $delivery);
    }

    private function delivery(Alert $alert, string $channel, string $event): AlertDelivery
    {
        return AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', $channel)->where('event', $event)->sole();
    }

    private function openAlert(string $suffix = 'one'): Alert
    {
        $server = Server::query()->firstOrCreate(
            ['name' => 'server-'.$suffix],
            ['hostname' => $suffix.'.example.test', 'panel' => 'none', 'alerts_enabled' => true],
        );

        $this->app->make(AlertEngine::class)->evaluate($server, new IssueSet(
            ['memory_high'],
            ['memory_high' => ['value' => 97.0, 'threshold' => 95, 'severity' => 'critical']],
        ));

        return Alert::query()->where('server_id', $server->id)->where('status', AlertStatus::Open->value)->sole();
    }

    private function user(string $suffix, UserRole $role, bool $notify): User
    {
        return User::factory()->create([
            'email' => $suffix.'@noc.example.test',
            'role' => $role,
            'is_active' => true,
            'notify_email' => $notify,
        ]);
    }

    private function enableTelegram(): void
    {
        config([
            'alerts.channels.telegram.enabled' => true,
            'alerts.channels.telegram.bot_token' => self::BOT_TOKEN,
            'alerts.channels.telegram.chat_id' => '-1001234567890',
        ]);
    }
}
