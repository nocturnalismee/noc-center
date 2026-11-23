<?php

namespace App\Jobs;

use App\Alerts\AlertNotifier;
use App\Models\Alert;
use App\Models\AlertDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * One delivery attempt for one `(alert, channel, event)` (spec §9.4).
 *
 * Idempotent: the attempt updates the existing `alert_deliveries` row instead
 * of inserting. It is skipped when the row is missing or the alert has since
 * been acknowledged/resolved (a reminder is no longer owed); a `sent` row is
 * only re-attempted when the reminder window has elapsed.
 *
 * `$tries = 3` with the configured 30/120/300 s backoff. A delivered attempt
 * never throws: `alert_deliveries` (status + `next_retry_at`) is the single
 * source of truth for retries, and `noc:alert-reminders` re-queues a row whose
 * retry is due. Throwing here would make the queue worker's own retry and the
 * row's backoff fight each other, and a `429 retry_after` must not burn the
 * three tries.
 *
 * Uniqueness is per `(alert_id, channel, event)` for
 * `alerts.notification_unique_for` seconds so a duplicate dispatch cannot
 * double-send; a failed job releases the lock when it starts running.
 */
class SendAlertNotification implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        return sprintf('alert:%s:%s:%s', $this->alertId, $this->channel, $this->event);
    }

    public function uniqueFor(): int
    {
        return max(0, (int) config('alerts.notification_unique_for', 300));
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
            (new WithoutOverlapping($this->uniqueId()))->releaseAfter(30),
        ];
    }

    public function handle(AlertNotifier $notifier): void
    {
        $alert = Alert::query()->with('server')->find($this->alertId);

        if ($alert === null) {
            return;
        }

        // A recovery is only owed once the alert is resolved; an `opened`
        // delivery (initial or reminder) belongs to an alert still firing.
        if ($this->event === 'resolved' && $alert->status->value !== 'resolved') {
            return;
        }

        if ($this->event === 'opened' && $alert->status->value !== 'open') {
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

        if ($delivery->status === 'sent' && ! $this->reminderDue($delivery)) {
            return;
        }

        $notifier->deliver($alert, $delivery);
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
