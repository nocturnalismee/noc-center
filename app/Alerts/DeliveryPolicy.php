<?php

namespace App\Alerts;

use App\Alerts\Channels\DeliveryResult;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The delivery rules for alert pages, reminders, retries and recoveries
 * (spec §9.4).
 *
 * This class only decides: given an alert, a delivery row and a clock, is the
 * row owed now, what should the job do with it, and when is the next retry.
 * It never writes. `AlertNotifier` remains the writer, and the job and the
 * reminder command are thin adapters over these decisions.
 */
final class DeliveryPolicy
{
    /**
     * Queue backoff used when `alerts.retry_backoff_seconds` is empty or has
     * no positive value.
     *
     * @var list<int>
     */
    public const DEFAULT_BACKOFF = [30, 120, 300];

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The action the delivery job should take for this row.
     *
     * The order matters: a recovery is only owed once the alert resolves, an
     * `opened` row never goes out for a resolved alert, a reminder queued
     * before an acknowledgement is restored rather than sent, and a `sent`
     * row only repeats inside a due reminder window. A `skipped` row is not a
     * terminal gate: a stray job still delivers it.
     */
    public function action(Alert $alert, AlertDelivery $delivery, CarbonInterface $now): DeliveryAction
    {
        if ($alert->status !== AlertStatus::Resolved && $delivery->event === 'resolved') {
            return DeliveryAction::Skip;
        }

        if ($alert->status === AlertStatus::Resolved && $delivery->event === 'opened') {
            return DeliveryAction::Skip;
        }

        if (
            $delivery->event === 'opened'
            && $alert->status === AlertStatus::Acknowledged
            && $delivery->sent_at !== null
        ) {
            return DeliveryAction::RestoreSent;
        }

        if ($delivery->status === DeliveryResult::SENT && ! $this->reminderDue($delivery, $now)) {
            return DeliveryAction::Skip;
        }

        return DeliveryAction::Deliver;
    }

    /**
     * Whether the reminder command should requeue this row.
     *
     * A resolved alert only retries an undelivered recovery. An open alert
     * only considers `opened` rows, and only when they failed, are inside a
     * due reminder window, or are stuck `pending`. Acknowledged alerts (and
     * any other status) are never due: acknowledging pauses reminders.
     */
    public function isDue(Alert $alert, AlertDelivery $delivery, CarbonInterface $now): bool
    {
        if ($alert->status === AlertStatus::Resolved) {
            return $delivery->event === 'resolved'
                && $delivery->status === DeliveryResult::FAILED
                && ($delivery->next_retry_at === null || $delivery->next_retry_at->lessThanOrEqualTo($now));
        }

        if ($alert->status === AlertStatus::Open) {
            if ($delivery->event !== 'opened' || $delivery->status === DeliveryResult::SKIPPED) {
                return false;
            }

            return $this->deliveryDue($delivery, $now);
        }

        return false;
    }

    /**
     * An `opened` row becomes a reminder once `alerts.reminder_hours` have
     * passed since its last successful send. A `resolved` row is never
     * repeated.
     */
    public function reminderDue(AlertDelivery $delivery, CarbonInterface $now): bool
    {
        if ($delivery->event !== 'opened' || $delivery->sent_at === null) {
            return false;
        }

        return $delivery->sent_at->copy()->addHours($this->reminderHours())->lessThanOrEqualTo($now);
    }

    public function reminderHours(): int
    {
        return max(1, (int) $this->settings->get('alerts.reminder_hours', config('alerts.reminder_hours', 6)));
    }

    public function stuckPendingAfter(): int
    {
        return max(1, (int) config('alerts.stuck_pending_after', 300));
    }

    /**
     * The queue worker's backoff: the configured positive steps, or the
     * default when none is usable.
     *
     * @return list<int>
     */
    public function queueBackoff(): array
    {
        $backoff = array_values(array_filter(
            array_map('intval', (array) config('alerts.retry_backoff_seconds', [])),
            static fn (int $seconds): bool => $seconds > 0,
        ));

        return $backoff === [] ? self::DEFAULT_BACKOFF : $backoff;
    }

    /**
     * When the next attempt is due after `$attempts` failed tries, or `null`
     * when the configuration has no usable step.
     *
     * Unlike {@see queueBackoff()} this does not filter or fall back: an empty
     * list, or an invalid selected step, means "no retry time".
     */
    public function nextRetryAt(int $attempts, ?CarbonInterface $now = null): ?Carbon
    {
        $backoff = array_values((array) config('alerts.retry_backoff_seconds', []));

        if ($backoff === []) {
            return null;
        }

        $seconds = $backoff[min(max($attempts, 1), count($backoff)) - 1];

        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return null;
        }

        return Carbon::instance($now ?? now())->addSeconds((int) $seconds);
    }

    /**
     * The `sent` and `pending` rules for an open alert. `failed` retries when
     * its `next_retry_at` is due; a stuck `pending` row is one that was queued
     * but never attempted and is older than `alerts.stuck_pending_after`.
     */
    private function deliveryDue(AlertDelivery $delivery, CarbonInterface $now): bool
    {
        if ($delivery->status === DeliveryResult::FAILED) {
            return $delivery->next_retry_at === null || $delivery->next_retry_at->lessThanOrEqualTo($now);
        }

        if ($delivery->status === DeliveryResult::SENT) {
            return $delivery->sent_at !== null && $this->reminderDue($delivery, $now);
        }

        return $delivery->last_attempt_at === null
            && $delivery->created_at !== null
            && $delivery->created_at->copy()->addSeconds($this->stuckPendingAfter())->lessThanOrEqualTo($now);
    }
}
