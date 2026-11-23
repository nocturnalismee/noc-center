<?php

namespace App\Jobs;

use RuntimeException;

/**
 * Thrown by {@see SendAlertNotification} when one delivery attempt failed, so
 * Laravel's queue worker applies `$tries` / `backoff()` and retries the job
 * for real.
 *
 * Only a failed delivery throws: `sent` (including a partial email where at
 * least one recipient got the message) and `skipped` are terminal, and the
 * early-return cases (an `opened` job for an alert that is no longer open, a
 * `resolved` job for an alert that is still open) are not errors.
 *
 * The message is already redacted by the channel; it is safe for a failed-job
 * record.
 */
class RetryDelivery extends RuntimeException
{
    public static function forAttempt(string $channel, string $event, string $alertId, ?string $error): self
    {
        return new self(sprintf(
            'Alert delivery %s/%s for alert %s failed: %s',
            $channel,
            $event,
            $alertId,
            $error ?? 'unknown error',
        ));
    }
}
