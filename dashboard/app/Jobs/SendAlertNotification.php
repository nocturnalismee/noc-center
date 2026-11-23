<?php

namespace App\Jobs;

use App\Alerts\AlertNotifier;
use App\Alerts\Channels\DeliveryResult;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * One delivery attempt for one `(alert, channel, event)` (spec §9.4).
 *
 * Idempotency lives in the `alert_deliveries` row (its unique
 * `(alert_id, channel, event)` index and its `status`), never in a cache lock:
 * the job deliberately does **not** implement `ShouldBeUnique`, because a
 * post-completion unique lock of a few minutes would silently swallow a
 * legitimate 30 s / 120 s requeue.
 *
 * A failed attempt throws {@see RetryDelivery} so the queue worker's `$tries`
 * and `backoff()` retry the job for real. `sent` and `skipped` never throw,
 * and neither do the early returns. The row keeps `next_retry_at` even after
 * the last try, so `noc:alert-reminders` can pick the delivery up later.
 */
class SendAlertNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $alertId,
        public readonly string $channel,
        public readonly string $event,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = array_values(array_filter(
            array_map('intval', (array) config('alerts.retry_backoff_seconds', [30, 120, 300])),
            static fn (int $seconds): bool => $seconds > 0,
        ));

        return $backoff === [] ? [30, 120, 300] : $backoff;
    }

    /**
     * Serialise attempts for the same delivery row only, so one channel's
     * failure never blocks the other channel of the same alert.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::lockKey($this->alertId, $this->channel, $this->event)))->releaseAfter(30),
        ];
    }

    public static function lockKey(string $alertId, string $channel, string $event): string
    {
        return sprintf('alert:%s:%s:%s', $alertId, $channel, $event);
    }

    public function handle(AlertNotifier $notifier): void
    {
        $alert = Alert::query()->with('server')->find($this->alertId);

        if ($alert === null) {
            return;
        }

        // A recovery is only owed once the alert is resolved; an `opened`
        // delivery (initial or reminder) belongs to an alert still firing.
        if ($this->event === 'resolved' && $alert->status !== AlertStatus::Resolved) {
            return;
        }

        if ($this->event === 'opened' && $alert->status !== AlertStatus::Open) {
            return;
        }

        $delivery = AlertDelivery::query()
            ->where('alert_id', $alert->getKey())
            ->where('channel', $this->channel)
            ->where('event', $this->event)
            ->first();

        if ($delivery === null) {
            return;
        }

        if ($delivery->status === DeliveryResult::SENT && ! $this->reminderDue($delivery)) {
            return;
        }

        $result = $notifier->deliver($alert, $delivery);

        if ($result->status === DeliveryResult::FAILED) {
            // `deliver()` already wrote `failed` + `next_retry_at`, so the
            // reminder command can recover the row even after the last try.
            //
            // Only a real worker may retry. The sync queue (tests, local dev
            // without a worker) rethrows at the dispatcher, which would break
            // the ingest response or an admin's resolve — there the row is the
            // retry source of truth and no exception escapes.
            if ($this->job instanceof JobContract && ! $this->job instanceof SyncJob) {
                throw RetryDelivery::forAttempt($this->channel, $this->event, $this->alertId, $result->error);
            }
        }
    }

    /**
     * An `opened` row becomes a reminder once `alerts.reminder_hours` have
     * passed since its last successful send. A `resolved` row is never
     * repeated.
     */
    private function reminderDue(AlertDelivery $delivery): bool
    {
        if ($this->event !== 'opened' || $delivery->sent_at === null) {
            return false;
        }

        $hours = max(1, (int) config('alerts.reminder_hours', 6));

        return $delivery->sent_at->copy()->addHours($hours)->lessThanOrEqualTo(now());
    }
}
