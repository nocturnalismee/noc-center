<?php

namespace App\Console\Commands;

use App\Alerts\AlertNotifier;
use App\Alerts\DeliveryPolicy;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use Illuminate\Console\Command;

/**
 * Reminders and retries for alert deliveries (spec §9.4).
 *
 * The candidate rows per alert status are selected here; whether a row is
 * actually due is decided by {@see DeliveryPolicy::isDue()}:
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

    public function handle(AlertNotifier $notifier, DeliveryPolicy $policy): int
    {
        $now = now();
        $queued = 0;

        Alert::query()
            ->with('server')
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Resolved->value])
            ->orderBy('last_seen_at')
            ->chunkById(100, function ($alerts) use ($notifier, $policy, $now, &$queued): void {
                foreach ($alerts as $alert) {
                    if ($alert->server === null) {
                        continue;
                    }

                    $event = $alert->status === AlertStatus::Resolved ? 'resolved' : 'opened';

                    $deliveries = AlertDelivery::query()
                        ->where('alert_id', $alert->getKey())
                        ->where('event', $event)
                        ->get();

                    foreach ($deliveries as $delivery) {
                        if (! $policy->isDue($alert, $delivery, $now)) {
                            continue;
                        }

                        $notifier->requeue($alert, $delivery);
                        $queued++;
                    }
                }
            });

        $this->info(sprintf('Queued %d alert reminder(s) and retry(s).', $queued));

        return self::SUCCESS;
    }
}
