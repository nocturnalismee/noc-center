<?php

namespace App\Alerts;

use App\Alerts\Channels\AlertChannel;
use App\Alerts\Channels\AlertErrorRedactor;
use App\Alerts\Channels\DeliveryResult;
use App\Alerts\Channels\EmailChannel;
use App\Alerts\Channels\TelegramChannel;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\User;
use App\Notifications\AlertTestNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delivery bookkeeping for alerts (spec §7.8, §9.2, §9.4).
 *
 * One `alert_deliveries` row per alert, channel and event is the source of
 * truth for throttle, retry and recovery status. The in-app channel is the
 * `alerts` row itself: it needs no row and works even when SMTP or Telegram
 * fails.
 *
 * `dispatch()` only writes rows and queues jobs (after commit) so ingest never
 * waits for a mail or HTTP call. `deliver()` performs one attempt and updates
 * the row; the job calls it and reads the result to schedule a retry.
 */
class AlertNotifier
{
    /**
     * @var list<AlertChannel>
     */
    private readonly array $channels;

    public function __construct(
        private readonly EmailChannel $email,
        private readonly TelegramChannel $telegram,
        private readonly AuditLogger $audit,
    ) {
        $this->channels = [$this->email, $this->telegram];
    }

    /**
     * @return list<string>
     */
    public function channelNames(): array
    {
        return array_map(static fn (AlertChannel $channel): string => $channel->name(), $this->channels);
    }

    /**
     * Create the per-channel delivery rows for an event and queue one job per
     * row after commit. A repeated call is idempotent: the unique
     * `(alert_id, channel, event)` rows are created once.
     */
    public function dispatch(Alert $alert, string $event = 'opened'): void
    {
        if ($event !== 'opened' && $event !== 'resolved') {
            throw new \InvalidArgumentException(sprintf('Unknown alert event [%s].', $event));
        }

        // The alert may be brand new and still inside its creating transaction
        // (evaluate → syncRule), so it is never re-read here: the caller's
        // instance already has the id, status and severity this needs.
        $openedChannels = $event === 'resolved'
            ? AlertDelivery::query()
                ->where('alert_id', $alert->getKey())
                ->where('event', 'opened')
                ->pluck('channel')
                ->all()
            : [];

        foreach ($this->channels as $channel) {
            if (! $channel->enabled()) {
                continue;
            }

            if ($event === 'resolved' && ! in_array($channel->name(), $openedChannels, true)) {
                // The channel was enabled after the alert opened: it gets no
                // recovery for an incident it never saw.
                continue;
            }

            $created = AlertDelivery::query()->firstOrCreate(
                [
                    'alert_id' => $alert->getKey(),
                    'channel' => $channel->name(),
                    'event' => $event,
                ],
                ['status' => 'pending'],
            );

            if (! $created->wasRecentlyCreated) {
                continue;
            }

            $this->queue($alert, $created);
        }
    }

    /**
     * Re-queue a delivery that is due (a reminder or a retry). The row is
     * marked pending again so the next attempt is visible in the UI.
     */
    public function requeue(Alert $alert, AlertDelivery $delivery): void
    {
        $delivery->forceFill(['status' => 'pending'])->save();

        $this->queue($alert, $delivery);
    }

    /**
     * One delivery attempt. Never throws: the outcome is written to the row
     * and returned so the job can decide whether to retry.
     */
    public function deliver(Alert $alert, AlertDelivery $delivery): DeliveryResult
    {
        $alert->refresh();

        $channel = $this->channelNamed($delivery->channel);

        if ($channel === null || ! $channel->enabled()) {
            $delivery->forceFill([
                'status' => DeliveryResult::SKIPPED,
                'last_attempt_at' => now(),
                'next_retry_at' => null,
            ])->save();

            return DeliveryResult::skipped();
        }

        try {
            $result = $channel->send($alert, $delivery->event);
        } catch (Throwable $exception) {
            $result = DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        }

        $this->record($alert, $delivery, $result);

        return $result;
    }

    /**
     * Persist the outcome of one attempt. A `sent` delivery clears the retry
     * time and advances the alert's notification summary; a `failed` one
     * schedules the next retry from the configured backoff unless the channel
     * asked for a specific `retry_after`.
     */
    public function record(Alert $alert, AlertDelivery $delivery, DeliveryResult $result): void
    {
        $attempts = $delivery->attempts + ($result->status === DeliveryResult::SKIPPED ? 0 : 1);
        $now = now();

        $attributes = [
            'status' => $result->status,
            'attempts' => $attempts,
            'last_attempt_at' => $now,
            'recipient_count' => max($result->recipientCount, $result->isSent() ? $delivery->recipient_count : 0),
            'error' => $result->error,
        ];

        if ($result->isSent()) {
            $attributes['sent_at'] = $now;
            $attributes['next_retry_at'] = null;
        } elseif ($result->status === DeliveryResult::FAILED) {
            $attributes['sent_at'] = null;
            $attributes['next_retry_at'] = $result->retryAfter !== null
                ? $now->copy()->addSeconds($result->retryAfter)
                : $this->nextBackoff($attempts);
        } else {
            $attributes['next_retry_at'] = null;
        }

        $delivery->forceFill($attributes)->save();

        if ($result->isSent()) {
            $alert->forceFill([
                'notification_count' => $alert->notification_count + 1,
                'last_notified_at' => $now,
            ])->save();
        }
    }

    public function nextBackoff(int $attempts): ?Carbon
    {
        $backoff = array_values((array) config('alerts.retry_backoff_seconds', []));

        if ($backoff === []) {
            return null;
        }

        $seconds = $backoff[min(max($attempts, 1), count($backoff)) - 1];

        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return null;
        }

        return now()->addSeconds((int) $seconds);
    }

    /**
     * Send one test message to the actor's email or the configured Telegram
     * chat (spec §9.4). Audits `alert_channel.tested`; a failure is a
     * validation error whose message is redacted.
     */
    public function sendTest(string $channel, User $actor): void
    {
        $result = match ($channel) {
            'email' => $this->testEmail($actor),
            'telegram' => $this->testTelegram(),
            default => throw ValidationException::withMessages([
                'channel' => ['Unknown alert channel.'],
            ]),
        };

        if (! $result->isSent()) {
            throw ValidationException::withMessages([
                'channel' => [$result->error ?? 'The test message could not be sent.'],
            ]);
        }

        $this->audit->log(
            'alert_channel.tested',
            null,
            ['channel' => $channel, 'recipient_count' => $result->recipientCount],
            $actor->getKey(),
        );
    }

    private function testEmail(User $actor): DeliveryResult
    {
        if (! $this->email->enabled()) {
            return DeliveryResult::skipped();
        }

        try {
            Notification::send($actor, new AlertTestNotification);
        } catch (Throwable $exception) {
            return DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        }

        return DeliveryResult::sent(1);
    }

    private function testTelegram(): DeliveryResult
    {
        if (! $this->telegram->enabled()) {
            return DeliveryResult::skipped();
        }

        try {
            $this->telegram->sendTest();
        } catch (Throwable $exception) {
            return DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        }

        return DeliveryResult::sent(1);
    }

    private function queue(Alert $alert, AlertDelivery $delivery): void
    {
        try {
            // The job runs after the surrounding commit when there is one
            // (a test's RefreshDatabase transaction, or a caller's). A sync
            // queue outside a transaction runs it immediately.
            SendAlertNotification::dispatch($alert->getKey(), $delivery->channel, $delivery->event)
                ->afterCommit();
        } catch (Throwable $exception) {
            // A queue backend failure must not fail ingest or a manual resolve:
            // the pending row stays and the reminder command picks it up.
            Log::warning('alert notification could not be queued', [
                'alert_id' => $alert->getKey(),
                'channel' => $delivery->channel,
                'event' => $delivery->event,
                'error' => AlertErrorRedactor::fromThrowable($exception),
            ]);
        }
    }

    private function channelNamed(string $name): ?AlertChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->name() === $name) {
                return $channel;
            }
        }

        return null;
    }
}
