<?php

namespace App\Console\Commands;

use App\Alerts\AlertNotifier;
use App\Alerts\Channels\DeliveryResult;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reminders and retries for alert deliveries (spec §9.4).
 *
 * The rules are per alert status:
 *
 * - **open**: only `event = opened` rows are considered. A row is re-queued
 *   when it failed and `next_retry_at` is due, or it was sent and the last
 *   successful attempt is at least `alerts.reminder_hours` old, or it is still
 *   `pending` with no attempt and older than `alerts.stuck_pending_after`
 *   (a lost job). A failed `resolved` row on a still-open alert is noise and
 *   is never re-queued.
 * - **resolved**: a failed `resolved` row with a due `next_retry_at` is
 *   retried, so an undelivered recovery is eventually sent.
 *
 * Acknowledged alerts are skipped entirely: acknowledging stops reminders
 * until the alert resolves. A reminder never creates a new row: the `opened`
 * row is updated, keeping one message per channel per window.
 */
class SendAlertReminders extends Command
{
    protected $signature = 'noc:alert-reminders';

    protected $description = 'Re-queue due alert reminders and retries';

    public function handle(AlertNotifier $notifier): int
    {
        $reminderHours = max(1, (int) config('alerts.reminder_hours', 6));
        $stuckAfter = max(1, (int) config('alerts.stuck_pending_after', 300));
        $now = now();
        $queued = 0;

        Alert::query()
            ->with('server')
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Resolved->value])
            ->orderBy('last_seen_at')
            ->chunkById(100, function ($alerts) use ($notifier, $reminderHours, $stuckAfter, $now, &$queued): void {
                foreach ($alerts as $alert) {
                    if ($alert->server === null) {
                        continue;
                    }

                    foreach ($this->dueDeliveries($alert, $reminderHours, $stuckAfter, $now) as $delivery) {
                        $notifier->requeue($alert, $delivery);
                        $queued++;
                    }
                }
            });

        $this->info(sprintf('Queued %d alert reminder(s) and retry(s).', $queued));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, AlertDelivery>
     */
    private function dueDeliveries(Alert $alert, int $reminderHours, int $stuckAfter, CarbonInterface $now): Collection
    {
        if ($alert->status === AlertStatus::Resolved) {
            return AlertDelivery::query()
                ->where('alert_id', $alert->getKey())
                ->where('event', 'resolved')
                ->where('status', DeliveryResult::FAILED)
                ->where(fn (Builder $query) => $query
                    ->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now))
                ->get();
        }

        return AlertDelivery::query()
            ->where('alert_id', $alert->getKey())
            ->where('event', 'opened')
            ->where('status', '!=', DeliveryResult::SKIPPED)
            ->get()
            ->filter(fn (AlertDelivery $delivery): bool => $this->due($delivery, $reminderHours, $stuckAfter, $now))
            ->values();
    }

    private function due(AlertDelivery $delivery, int $reminderHours, int $stuckAfter, CarbonInterface $now): bool
    {
        if ($delivery->status === DeliveryResult::FAILED) {
            return $delivery->next_retry_at === null || $delivery->next_retry_at->lessThanOrEqualTo($now);
        }

        if ($delivery->status === DeliveryResult::SENT) {
            return $delivery->sent_at !== null
                && $delivery->sent_at->copy()->addHours($reminderHours)->lessThanOrEqualTo($now);
        }

        // `pending`: a job that was queued but never started. Only a row with
        // no attempt at all, older than the stuck threshold, is recovered;
        // the 30 s backoff grace must never be used here, or a requeue would
        // race the in-flight job.
        return $delivery->last_attempt_at === null
            && $delivery->created_at !== null
            && $delivery->created_at->copy()->addSeconds($stuckAfter)->lessThanOrEqualTo($now);
    }
}
