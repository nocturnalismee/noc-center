<?php

namespace App\Alerts\Channels;

use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertOpened;
use App\Notifications\AlertResolved;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * SMTP email via queued Laravel notifications (spec §9.2).
 *
 * Recipients are active admin and operator users with `notify_email = true`.
 * The recipients are decided when the job runs, from the database.
 */
class EmailChannel implements AlertChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function enabled(): bool
    {
        return (bool) config('alerts.channels.email.enabled', true);
    }

    public function send(Alert $alert, string $event): DeliveryResult
    {
        if (! $this->enabled()) {
            return DeliveryResult::skipped();
        }

        $recipients = User::query()
            ->where('is_active', true)
            ->where('notify_email', true)
            ->whereIn('role', ['admin', 'operator'])
            ->get();

        if ($recipients->isEmpty()) {
            return DeliveryResult::skipped();
        }

        $notification = match ($event) {
            'opened' => new AlertOpened($alert),
            'resolved' => new AlertResolved($alert),
            default => throw new \InvalidArgumentException(sprintf('Unknown alert event [%s].', $event)),
        };

        try {
            Notification::send($recipients, $notification);
        } catch (Throwable $exception) {
            return DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        }

        return DeliveryResult::sent($recipients->count());
    }
}
