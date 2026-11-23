<?php

namespace App\Alerts\Channels;

use App\Enums\UserRole;
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
 *
 * One notification is sent per recipient in a loop instead of a single
 * `Notification::send($collection, …)`, so a failure for one mailbox (a
 * rejected address, a full inbox) cannot prevent the others from receiving
 * the message. The attempt is `sent` when at least one recipient succeeded —
 * a retry after that would duplicate the message for the recipients who
 * already got it — and `failed` only when every recipient failed. The
 * partial-failure note is redacted, so it never carries an address or
 * SMTP credential.
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
            ->whereIn('role', [UserRole::Admin->value, UserRole::Operator->value])
            ->orderBy('id')
            ->get();

        if ($recipients->isEmpty()) {
            return DeliveryResult::skipped();
        }

        $notification = match ($event) {
            'opened' => new AlertOpened($alert),
            'resolved' => new AlertResolved($alert),
            default => throw new \InvalidArgumentException(sprintf('Unknown alert event [%s].', $event)),
        };

        $sent = 0;
        $lastError = null;

        foreach ($recipients as $recipient) {
            try {
                Notification::send($recipient, $notification);
                $sent++;
            } catch (Throwable $exception) {
                $lastError = AlertErrorRedactor::fromThrowable($exception);
            }
        }

        if ($sent > 0) {
            $note = $lastError === null
                ? null
                : sprintf('%d of %d recipients failed; last error: %s', $recipients->count() - $sent, $recipients->count(), $lastError);

            return new DeliveryResult(DeliveryResult::SENT, $sent, $note);
        }

        return DeliveryResult::failed($lastError ?? 'No alert email could be delivered.');
    }
}
