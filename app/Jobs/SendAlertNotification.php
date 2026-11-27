<?php

namespace App\Jobs;

use App\Alerts\AlertNotifier;
use App\Alerts\Channels\DeliveryResult;
use App\Alerts\DeliveryAction;
use App\Alerts\DeliveryPolicy;
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
 * Whether the row is owed at all is decided by {@see DeliveryPolicy}; this
 * class only looks the row up, applies the decision, and reports a failed
 * attempt. A failed attempt throws {@see RetryDelivery} so the queue worker's
 * `$tries` and `backoff()` retry the job for real. `sent` and `skipped` never
 * throw, and neither do the early returns. The row keeps `next_retry_at` even
 * after the last try, so `noc:alert-reminders` can pick the delivery up later.
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
        return app(DeliveryPolicy::class)->queueBackoff();
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
            (new WithoutOverlapping(self::lockKey($this->alertId, $this->channel, $this->event)))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public static function lockKey(string $alertId, string $channel, string $event): string
    {
        return sprintf('alert:%s:%s:%s', $alertId, $channel, $event);
    }

    public function handle(AlertNotifier $notifier, ?DeliveryPolicy $policy = null): void
    {
        $policy ??= app(DeliveryPolicy::class);

        $alert = Alert::query()->with('server')->find($this->alertId);

        if ($alert === null) {
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

        $action = $policy->action($alert, $delivery, now());

        if ($action === DeliveryAction::RestoreSent) {
            // A reminder queued just before an acknowledgement must not slip
            // out afterwards. Requeued reminders are marked `pending`, but
            // keep the `sent_at` of their previous successful page, while an
            // initial page has never been sent. Restore the row to `sent` so
            // the UI does not leave a stale pending delivery after the ack.
            $delivery->forceFill([
                'status' => DeliveryResult::SENT,
                'next_retry_at' => null,
            ])->save();

            return;
        }

        if ($action === DeliveryAction::Skip) {
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
}
