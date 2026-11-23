<?php

namespace App\Console\Commands;

use App\Alerts\AlertNotifier;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Reminders and retries for alert deliveries (spec §9.4).
 *
 * Only alerts with status `open` are reminded: acknowledging stops reminders
 * until the alert resolves. A delivery is re-queued when
 *
 * - it failed and `next_retry_at` is due, or
 * - it was never sent and is older than one backoff step (`pending` left by
 *   a lost job), or
 * - it was sent and the last successful attempt is at least
 *   `alerts.reminder_hours` old.
 *
 * A resolve or a reminder never creates a new row: the `opened` row is
 * updated, keeping one message per channel per window.
 */
class SendAlertReminders extends Command
{
    protected $signature = 'noc:alert-reminders';

    protected $description = 'Re-queue due alert reminders and retries';

    public function handle(AlertNotifier $notifier): int
    {
        $reminderHours = max(1, (int) config('alerts.reminder_hours', 6));
        $now = now();
        $queued = 0;

        Alert::query()
            ->with('server')
            ->where('status', AlertStatus::Open->value)
            ->orderBy('last_seen_at')
            ->chunkById(100, function ($alerts) use ($notifier, $reminderHours, $now, &$queued): void {
                foreach ($alerts as $alert) {
                    if ($alert->server === null) {
                        continue;
                    }

                    $deliveries = AlertDelivery::query()
                        ->where('alert_id', $alert->getKey())
                        ->where('status', '!=', 'skipped')
                        ->get();

                    foreach ($deliveries as $delivery) {
                        if (! $this->due($delivery, $reminderHours, $now)) {
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

    private function due(AlertDelivery $delivery, int $reminderHours, CarbonInterface $now): bool
    {
        if ($delivery->status === 'failed') {
            return $delivery->next_retry_at === null || $delivery->next_retry_at->lessThanOrEqualTo($now);
        }

        if ($delivery->status === 'sent') {
            if ($delivery->event !== 'opened' || $delivery->sent_at === null) {
                return false;
            }

            return $delivery->sent_at->copy()->addHours($reminderHours)->lessThanOrEqualTo($now);
        }

        // `pending`: a lost or still queued job. Give it one backoff step
        // before duplicating it.
        $grace = (int) (config('alerts.retry_backoff_seconds')[0] ?? 30);

        return $delivery->last_attempt_at === null
            ? $delivery->created_at === null || $delivery->created_at->copy()->addSeconds($grace)->lessThanOrEqualTo($now)
            : $delivery->last_attempt_at->copy()->addSeconds($grace)->lessThanOrEqualTo($now);
    }
}
