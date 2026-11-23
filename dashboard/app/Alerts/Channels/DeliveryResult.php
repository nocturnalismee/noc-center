<?php

namespace App\Alerts\Channels;

/**
 * Outcome of one channel delivery attempt.
 *
 * `status` is one of `sent`, `failed` or `skipped`. `recipientCount` is the
 * number of people or chats that received the message (0 when skipped).
 * `error` is already redacted: it never contains the Telegram bot token, the
 * full Telegram URL, an SMTP credential, a full email address or MySQL query
 * text. `retryAfter` carries the `retry_after` of a `429` (seconds) so the
 * delivery row can honour it instead of the default backoff.
 */
final readonly class DeliveryResult
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function __construct(
        public string $status,
        public int $recipientCount = 0,
        public ?string $error = null,
        public ?int $retryAfter = null,
    ) {
        //
    }

    public static function sent(int $recipientCount): self
    {
        return new self(self::SENT, $recipientCount);
    }

    public static function failed(string $error, ?int $retryAfter = null): self
    {
        return new self(self::FAILED, 0, AlertErrorRedactor::redact($error), $retryAfter);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }
}
