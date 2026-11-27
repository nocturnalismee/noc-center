<?php

namespace Tests\Feature\Alerts;

use App\Alerts\Channels\DeliveryResult;
use App\Alerts\DeliveryAction;
use App\Alerts\DeliveryPolicy;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\Server;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The delivery rules for alert pages, reminders, retries and recoveries.
 *
 * This is the extracted decision module behind `SendAlertNotification` and
 * `noc:alert-reminders`: the matrix below pins every outcome those callers
 * produced before the extraction.
 */
class DeliveryPolicyTest extends TestCase
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

    // --- action(): what the job should do with the row ---------------------

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, DeliveryAction}>
     */
    public static function actionMatrix(): array
    {
        $default = ['reminder_hours' => null];

        return [
            'opened/open/pending delivers' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => 'pending']],
                $default,
                DeliveryAction::Deliver,
            ],
            'opened/open/failed delivers' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::FAILED]],
                $default,
                DeliveryAction::Deliver,
            ],
            'opened/open/sent 5h ago is skipped' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 5]],
                $default,
                DeliveryAction::Skip,
            ],
            'opened/open/sent exactly 6h ago delivers' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 6]],
                $default,
                DeliveryAction::Deliver,
            ],
            'opened/open/sent 3h ago with a 3h override delivers' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 3]],
                ['reminder_hours' => 3],
                DeliveryAction::Deliver,
            ],
            'opened/acknowledged/already sent restores sent' => [
                ['status' => AlertStatus::Acknowledged, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_at' => '2026-11-23 09:00:00']],
                $default,
                DeliveryAction::RestoreSent,
            ],
            'opened/acknowledged/never sent still delivers' => [
                ['status' => AlertStatus::Acknowledged, 'event' => 'opened', 'row' => ['status' => 'pending']],
                $default,
                DeliveryAction::Deliver,
            ],
            'opened/resolved is skipped' => [
                ['status' => AlertStatus::Resolved, 'event' => 'opened', 'row' => ['status' => 'pending']],
                $default,
                DeliveryAction::Skip,
            ],
            'resolved/open is skipped' => [
                ['status' => AlertStatus::Open, 'event' => 'resolved', 'row' => ['status' => 'pending']],
                $default,
                DeliveryAction::Skip,
            ],
            'resolved/acknowledged is skipped' => [
                ['status' => AlertStatus::Acknowledged, 'event' => 'resolved', 'row' => ['status' => 'pending']],
                $default,
                DeliveryAction::Skip,
            ],
            'resolved/resolved/failed delivers' => [
                ['status' => AlertStatus::Resolved, 'event' => 'resolved', 'row' => ['status' => DeliveryResult::FAILED]],
                $default,
                DeliveryAction::Deliver,
            ],
            'resolved/resolved/sent is skipped' => [
                ['status' => AlertStatus::Resolved, 'event' => 'resolved', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 24]],
                $default,
                DeliveryAction::Skip,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('actionMatrix')]
    public function test_action_matrix(array $scenario, array $settings, DeliveryAction $expected): void
    {
        if ($settings['reminder_hours'] !== null) {
            app(SettingsRepository::class)->set('alerts.reminder_hours', $settings['reminder_hours'], 'int');
        }

        $alert = $this->alert($scenario['status']);
        $delivery = $this->delivery($alert, $scenario['event'], $scenario['row']);

        $this->assertSame($expected, $this->policy()->action($alert, $delivery, now()));
    }

    public function test_a_stray_job_can_still_deliver_a_skipped_row(): void
    {
        // Preserved from the job: `skipped` is not a terminal gate.
        $alert = $this->alert(AlertStatus::Open);
        $delivery = $this->delivery($alert, 'opened', ['status' => DeliveryResult::SKIPPED]);

        $this->assertSame(DeliveryAction::Deliver, $this->policy()->action($alert, $delivery, now()));
    }

    public function test_a_reminder_queued_before_ack_is_restored_even_when_it_is_due(): void
    {
        // The acknowledgement restore wins over the reminder window: the row
        // was requeued (pending) but keeps the previous `sent_at`.
        $alert = $this->alert(AlertStatus::Acknowledged);
        $delivery = $this->delivery($alert, 'opened', [
            'status' => 'pending',
            'sent_ago_hours' => 7,
        ]);

        $this->assertSame(DeliveryAction::RestoreSent, $this->policy()->action($alert, $delivery, now()));
    }

    // --- isDue(): what the reminder command should requeue -----------------

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function dueMatrix(): array
    {
        return [
            'open/failed/null retry is due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::FAILED]],
                [],
                true,
            ],
            'open/failed/future retry is not due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::FAILED, 'next_retry_in_seconds' => 60]],
                [],
                false,
            ],
            'open/failed/past retry is due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::FAILED, 'next_retry_in_seconds' => -60]],
                [],
                true,
            ],
            'open/sent at the reminder boundary is due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 6]],
                [],
                true,
            ],
            'open/sent inside the reminder window is not due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 5]],
                [],
                false,
            ],
            'open/pending older than the stuck threshold is due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => 'pending', 'created_ago_seconds' => 301]],
                [],
                true,
            ],
            'open/pending newer than the stuck threshold is not due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => 'pending', 'created_ago_seconds' => 299]],
                [],
                false,
            ],
            'open/pending with an attempt is not due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => 'pending', 'created_ago_seconds' => 3600, 'attempted' => true]],
                [],
                false,
            ],
            'open/skipped is not due' => [
                ['status' => AlertStatus::Open, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SKIPPED]],
                [],
                false,
            ],
            'acknowledged/sent is not due' => [
                ['status' => AlertStatus::Acknowledged, 'event' => 'opened', 'row' => ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 24]],
                [],
                false,
            ],
            'resolved/failed/resolved event due' => [
                ['status' => AlertStatus::Resolved, 'event' => 'resolved', 'row' => ['status' => DeliveryResult::FAILED, 'next_retry_in_seconds' => -60]],
                [],
                true,
            ],
            'resolved/failed/resolved event future' => [
                ['status' => AlertStatus::Resolved, 'event' => 'resolved', 'row' => ['status' => DeliveryResult::FAILED, 'next_retry_in_seconds' => 60]],
                [],
                false,
            ],
            'resolved/pending/resolved event is not due' => [
                ['status' => AlertStatus::Resolved, 'event' => 'resolved', 'row' => ['status' => 'pending', 'created_ago_seconds' => 3600]],
                [],
                false,
            ],
            'resolved/open event is not due' => [
                ['status' => AlertStatus::Resolved, 'event' => 'opened', 'row' => ['status' => DeliveryResult::FAILED]],
                [],
                false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('dueMatrix')]
    public function test_due_matrix(array $scenario, array $settings, bool $expected): void
    {
        $alert = $this->alert($scenario['status']);
        $delivery = $this->delivery($alert, $scenario['event'], $scenario['row']);

        $this->assertSame($expected, $this->policy()->isDue($alert, $delivery, now()));
    }

    public function test_a_resolved_alert_ignores_an_overdue_opened_row(): void
    {
        $alert = $this->alert(AlertStatus::Resolved);

        $opened = $this->delivery($alert, 'opened', [
            'channel' => 'email',
            'status' => DeliveryResult::FAILED,
        ]);

        $this->assertFalse($this->policy()->isDue($alert, $opened, now()));
    }

    public function test_due_uses_the_configured_reminder_window(): void
    {
        app(SettingsRepository::class)->set('alerts.reminder_hours', 3, 'int');

        $alert = $this->alert(AlertStatus::Open);
        $delivery = $this->delivery($alert, 'opened', ['status' => DeliveryResult::SENT, 'sent_ago_hours' => 3]);

        $this->assertTrue($this->policy()->isDue($alert, $delivery, now()));
    }

    public function test_due_honours_the_stuck_pending_override(): void
    {
        config(['alerts.stuck_pending_after' => 60]);

        $alert = $this->alert(AlertStatus::Open);

        $older = $this->delivery($alert, 'opened', [
            'channel' => 'email',
            'status' => 'pending',
            'created_ago_seconds' => 61,
        ]);
        $newer = $this->delivery($alert, 'opened', [
            'channel' => 'telegram',
            'status' => 'pending',
            'created_ago_seconds' => 59,
        ]);

        $this->assertTrue($this->policy()->isDue($alert, $older, now()));
        $this->assertFalse($this->policy()->isDue($alert, $newer, now()));
    }

    // --- reminderDue() ----------------------------------------------------

    public function test_reminder_due_requires_an_opened_event_with_a_send(): void
    {
        $alert = $this->alert(AlertStatus::Open);

        $resolved = $this->delivery($alert, 'resolved', [
            'status' => DeliveryResult::SENT,
            'sent_ago_hours' => 24,
        ]);
        $neverSent = $this->delivery($alert, 'opened', ['status' => 'pending']);

        $this->assertFalse($this->policy()->reminderDue($resolved, now()));
        $this->assertFalse($this->policy()->reminderDue($neverSent, now()));
    }

    public function test_reminder_due_flips_exactly_at_the_window(): void
    {
        $alert = $this->alert(AlertStatus::Open);
        $delivery = $this->delivery($alert, 'opened', [
            'status' => DeliveryResult::SENT,
            'sent_at' => '2026-11-23 04:00:00',
        ]);

        // A second before the six-hour window closes nothing is owed.
        Carbon::setTestNow('2026-11-23 09:59:59');
        $this->assertFalse($this->policy()->reminderDue($delivery, now()));

        // Exactly at the window it is.
        Carbon::setTestNow('2026-11-23 10:00:00');
        $this->assertTrue($this->policy()->reminderDue($delivery, now()));
    }

    // --- Settings: reminder hours and stuck threshold ----------------------

    public function test_reminder_hours_defaults_to_six(): void
    {
        $this->assertSame(6, $this->policy()->reminderHours());
    }

    public function test_reminder_hours_reads_the_setting_override(): void
    {
        app(SettingsRepository::class)->set('alerts.reminder_hours', 3, 'int');

        $this->assertSame(3, $this->policy()->reminderHours());
    }

    public function test_reminder_hours_never_drops_below_one(): void
    {
        app(SettingsRepository::class)->set('alerts.reminder_hours', 0, 'int');
        $this->assertSame(1, $this->policy()->reminderHours());

        app(SettingsRepository::class)->set('alerts.reminder_hours', -5, 'int');
        $this->assertSame(1, $this->policy()->reminderHours());
    }

    public function test_stuck_pending_after_defaults_to_three_hundred(): void
    {
        $this->assertSame(300, $this->policy()->stuckPendingAfter());
    }

    public function test_stuck_pending_after_reads_the_config_override(): void
    {
        config(['alerts.stuck_pending_after' => 45]);

        $this->assertSame(45, $this->policy()->stuckPendingAfter());
    }

    public function test_stuck_pending_after_never_drops_below_one(): void
    {
        config(['alerts.stuck_pending_after' => 0]);
        $this->assertSame(1, $this->policy()->stuckPendingAfter());

        config(['alerts.stuck_pending_after' => -10]);
        $this->assertSame(1, $this->policy()->stuckPendingAfter());
    }

    // --- Backoff ----------------------------------------------------------

    public function test_queue_backoff_defaults_to_thirty_twelve_and_three_hundred(): void
    {
        $this->assertSame([30, 120, 300], $this->policy()->queueBackoff());
    }

    public function test_queue_backoff_filters_non_positive_values(): void
    {
        config(['alerts.retry_backoff_seconds' => [10, 0, -5, 60]]);

        $this->assertSame([10, 60], $this->policy()->queueBackoff());
    }

    public function test_queue_backoff_falls_back_when_nothing_positive_remains(): void
    {
        config(['alerts.retry_backoff_seconds' => [0, -1]]);
        $this->assertSame([30, 120, 300], $this->policy()->queueBackoff());

        config(['alerts.retry_backoff_seconds' => []]);
        $this->assertSame([30, 120, 300], $this->policy()->queueBackoff());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function retryAttempts(): array
    {
        return [
            'first attempt' => [1, 30],
            'second attempt' => [2, 120],
            'third attempt' => [3, 300],
            'beyond the list clamps to the last' => [4, 300],
        ];
    }

    #[DataProvider('retryAttempts')]
    public function test_next_retry_at_maps_attempts_onto_the_backoff(int $attempts, int $expectedSeconds): void
    {
        $retry = $this->policy()->nextRetryAt($attempts, now());

        $this->assertNotNull($retry);
        $this->assertSame(now()->addSeconds($expectedSeconds)->toDateTimeString(), $retry->toDateTimeString());
    }

    public function test_next_retry_at_clamps_a_zero_attempt_to_the_first_step(): void
    {
        $retry = $this->policy()->nextRetryAt(0, now());

        $this->assertNotNull($retry);
        $this->assertSame(now()->addSeconds(30)->toDateTimeString(), $retry->toDateTimeString());
    }

    public function test_next_retry_at_returns_null_for_empty_config(): void
    {
        config(['alerts.retry_backoff_seconds' => []]);

        $this->assertNull($this->policy()->nextRetryAt(1, now()));
    }

    public function test_next_retry_at_returns_null_for_an_invalid_selected_value(): void
    {
        config(['alerts.retry_backoff_seconds' => ['nope', 120]]);
        $this->assertNull($this->policy()->nextRetryAt(1, now()));

        config(['alerts.retry_backoff_seconds' => [0, 120]]);
        $this->assertNull($this->policy()->nextRetryAt(1, now()));
    }

    public function test_next_retry_at_defaults_to_the_current_clock(): void
    {
        $retry = $this->policy()->nextRetryAt(2);

        $this->assertNotNull($retry);
        $this->assertSame(now()->addSeconds(120)->toDateTimeString(), $retry->toDateTimeString());
    }

    // --- Helpers ----------------------------------------------------------

    private function policy(): DeliveryPolicy
    {
        return $this->app->make(DeliveryPolicy::class);
    }

    private function alert(AlertStatus $status): Alert
    {
        $server = Server::query()->firstOrCreate(
            ['name' => 'policy-'.$status->value],
            ['hostname' => $status->value.'.example.test', 'panel' => 'none', 'alerts_enabled' => true],
        );

        return Alert::query()->create([
            'server_id' => $server->id,
            'rule_key' => 'memory_high',
            'severity' => 'critical',
            'status' => $status->value,
            'title' => 'Memory high',
            'message' => 'Memory usage is above the critical threshold.',
            'context_json' => [],
            'first_seen_at' => now()->subHours(8),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function delivery(Alert $alert, string $event, array $row): AlertDelivery
    {
        $createdAt = now()->subSeconds($row['created_ago_seconds'] ?? 0);

        $attributes = [
            'status' => $row['status'],
            'sent_at' => isset($row['sent_ago_hours'])
                ? now()->subHours($row['sent_ago_hours'])
                : ($row['sent_at'] ?? null),
            'next_retry_at' => isset($row['next_retry_in_seconds'])
                ? now()->addSeconds($row['next_retry_in_seconds'])
                : null,
            'last_attempt_at' => ($row['attempted'] ?? false) ? now()->subSeconds(30) : null,
        ];

        $delivery = AlertDelivery::query()->create([
            'alert_id' => $alert->id,
            'channel' => $row['channel'] ?? 'email',
            'event' => $event,
            'status' => $attributes['status'],
            'next_retry_at' => $attributes['next_retry_at'],
            'sent_at' => $attributes['sent_at'],
            'last_attempt_at' => $attributes['last_attempt_at'],
        ]);

        // `created_at` is not fillable and is normally set by the clock, so
        // the stuck-pending rows are backdated explicitly.
        AlertDelivery::query()
            ->whereKey($delivery->getKey())
            ->update(['created_at' => $createdAt]);

        return $delivery->refresh();
    }
}
