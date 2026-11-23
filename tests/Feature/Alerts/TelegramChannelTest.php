<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertEngine;
use App\Alerts\AlertNotifier;
use App\Alerts\Channels\TelegramChannel;
use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\UserRole;
use App\Issues\IssueSet;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramChannelTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '987654321:AAFakeTokenValueForTests';

    private const CHAT_ID = '-1001234567890';

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

    public function test_a_disabled_channel_makes_no_http_call(): void
    {
        // The channel is disabled before the alert opens, so no delivery row
        // is created at all (spec §9.4: only enabled channels get one).
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        $this->enableTelegram(enabled: false);

        $alert = $this->openAlert();

        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->count());
        Queue::assertNotPushed(SendAlertNotification::class, fn (SendAlertNotification $job): bool => $job->channel === 'telegram');
        Http::assertNothingSent();
    }

    public function test_an_enabled_channel_without_a_chat_id_is_skipped(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->enableTelegram(chat: null);

        $alert = $this->openAlert();

        // Enabled but not configured: no row, no HTTP call.
        $this->assertSame(0, AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->count());
        Http::assertNothingSent();
    }

    public function test_the_payload_carries_the_chat_id_and_html_parse_mode(): void
    {
        $this->fakeTelegramOk();
        $this->enableTelegram();
        $alert = $this->openAlert();

        $this->runJob($alert, 'telegram');

        Http::assertSent(function (Request $request) use ($alert): bool {
            $this->assertSame(
                'https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage',
                $request->url(),
            );
            $this->assertSame(self::CHAT_ID, $request['chat_id']);
            $this->assertSame('HTML', $request['parse_mode']);
            $this->assertTrue($request['disable_web_page_preview']);
            $this->assertIsString($request['text']);
            $this->assertStringContainsString($alert->server->name, $request['text']);

            return true;
        });

        $this->assertSame('sent', $this->delivery($alert)->fresh()->status);
    }

    public function test_message_thread_id_is_sent_only_when_non_empty(): void
    {
        $this->fakeTelegramOk();
        $this->enableTelegram(thread: 42);
        $alert = $this->openAlert('threaded');

        $this->runJob($alert, 'telegram');

        Http::assertSent(fn (Request $request): bool => ($request['message_thread_id'] ?? null) === 42);

        foreach ([null, ''] as $index => $thread) {
            Http::fake([
                'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            ]);

            $this->enableTelegram(thread: $thread);
            $other = $this->openAlert('no-thread-'.$index);
            $this->runJob($other, 'telegram');

            Http::assertSent(function (Request $request): bool {
                $this->assertArrayNotHasKey('message_thread_id', $request->data());

                return true;
            });
        }
    }

    public function test_html_special_characters_are_escaped(): void
    {
        $this->fakeTelegramOk();
        $this->enableTelegram();
        $alert = $this->openAlert('escaped', server: 'web <b>&"01');

        $this->runJob($alert, 'telegram');

        Http::assertSent(function (Request $request): bool {
            $text = $request['text'];

            // The channel's own markup stays; the server name is escaped.
            $this->assertStringNotContainsString('web <b>', $text);
            $this->assertStringNotContainsString('web &"', $text);
            $this->assertStringContainsString('web &lt;b&gt;&amp;&quot;01', $text);

            return true;
        });
    }

    public function test_a_429_with_retry_after_sets_next_retry_about_42_seconds_ahead(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests: retry after 42',
                'parameters' => ['retry_after' => 42],
            ], 429),
        ]);

        $this->enableTelegram();
        $alert = $this->openAlert();

        $this->runJob($alert, 'telegram');

        $delivery = $this->delivery($alert)->fresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertSame(42, (int) Carbon::parse($delivery->next_retry_at)->diffInSeconds(now(), true));

        $this->assertStringNotContainsString(self::BOT_TOKEN, (string) $delivery->error);
        $this->assertStringNotContainsString('api.telegram.org', (string) $delivery->error);
    }

    public function test_a_connection_failure_records_a_redacted_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out for https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage'));

        $this->enableTelegram();
        $alert = $this->openAlert();

        $this->runJob($alert, 'telegram');

        $delivery = $this->delivery($alert)->fresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->error);
        $this->assertStringNotContainsString(self::BOT_TOKEN, $delivery->error);
        $this->assertStringNotContainsString('api.telegram.org', $delivery->error);
        $this->assertStringNotContainsString('bot', $delivery->error);
    }

    public function test_timeouts_are_bounded(): void
    {
        $this->fakeTelegramOk();

        $this->enableTelegram();
        $alert = $this->openAlert();

        $this->runJob($alert, 'telegram');

        // Open-time delivery plus the explicit re-run of the same row.
        Http::assertSentCount(2);

        // Connection timeout 5 s, total timeout 10 s (spec §9.2). Laravel's
        // fake client does not expose the options, so the channel is checked
        // through its configured limits.
        $channel = $this->app->make(TelegramChannel::class);
        $this->assertSame(5, $channel->connectTimeout());
        $this->assertSame(10, $channel->timeout());
    }

    public function test_one_channel_failing_still_lets_the_other_send_and_the_in_app_row_exists(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        config(['mail.default' => 'array', 'alerts.channels.email.enabled' => true]);
        $admin = User::factory()->create([
            'email' => 'admin@noc.example.test',
            'role' => UserRole::Admin,
            'notify_email' => true,
        ]);

        $this->enableTelegram();
        $alert = $this->openAlert();

        $this->runJob($alert, 'email');
        $this->runJob($alert, 'telegram');

        $this->assertSame('sent', AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'email')->sole()->status);
        $this->assertSame('failed', AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->sole()->status);
        $this->assertTrue($alert->fresh()->status === AlertStatus::Open);
        $this->assertNotNull($admin->fresh());
    }

    private function fakeTelegramOk(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
        ]);
    }

    /**
     * One attempt through the notifier. The job's release/throw behaviour for
     * a due retry is covered by AlertNotificationTest.
     */
    private function runJob(Alert $alert, string $channel): void
    {
        $delivery = $this->delivery($alert);

        $this->app->make(AlertNotifier::class)->deliver($alert->fresh(), $delivery);
    }

    private function delivery(Alert $alert): AlertDelivery
    {
        return AlertDelivery::query()->where('alert_id', $alert->id)->where('channel', 'telegram')->where('event', 'opened')->sole();
    }

    private function openAlert(string $suffix = 'one', string $server = 'web01'): Alert
    {
        $model = Server::query()->create([
            'name' => $server.'-'.$suffix,
            'hostname' => $suffix.'.example.test',
            'panel' => 'none',
            'alerts_enabled' => true,
        ]);

        $this->app->make(AlertEngine::class)->evaluate($model, new IssueSet(
            [AlertRule::MemoryHigh->value],
            [AlertRule::MemoryHigh->value => ['value' => 97.0, 'threshold' => 95, 'severity' => AlertSeverity::Critical->value]],
        ));

        return Alert::query()->where('server_id', $model->id)->sole();
    }

    private function enableTelegram(
        bool $enabled = true,
        ?string $chat = self::CHAT_ID,
        ?string $token = self::BOT_TOKEN,
        int|string|null $thread = null,
    ): void {
        config([
            'alerts.channels.telegram.enabled' => $enabled,
            'alerts.channels.telegram.bot_token' => $token,
            'alerts.channels.telegram.chat_id' => $chat,
            'alerts.channels.telegram.message_thread_id' => $thread,
        ]);
    }
}
