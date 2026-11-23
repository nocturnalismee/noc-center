<?php

namespace App\Alerts\Channels;

use Throwable;

/**
 * Redact transport failures before they reach a database column, an audit
 * row, a log line or the UI (spec §7.8, §9.2).
 *
 * The Telegram bot token lives in the URL path, so `https://api.telegram.org/bot…`
 * must never survive; the host is removed for the same reason. SMTP URIs can
 * carry credentials and HTTP clients echo full request URLs in their messages.
 * The result is bounded to the `alert_deliveries.error` column width.
 */
final class AlertErrorRedactor
{
    private const MAX_LENGTH = 500;

    private const TELEGRAM_URL = '/https?:\/\/api\.telegram\.org\/bot[^\s\'")]*/i';

    private const TELEGRAM_HOST = '/(?:https?:\/\/)?api\.telegram\.org(?:\/bot[^\s\'")]*)?/i';

    private const BOT_TOKEN = '/(?<=\/bot)\d+:[A-Za-z0-9_-]+/';

    private const SMTP_URI = '/(?:smtps?|mailto):\/\/[^\s\'")]*/i';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const QUERY_TEXT = '/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE)\b[^\n]*/i';

    public static function redact(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $redacted = preg_replace([
            self::TELEGRAM_URL,
            self::TELEGRAM_HOST,
            self::BOT_TOKEN,
            self::SMTP_URI,
            self::EMAIL,
            self::QUERY_TEXT,
        ], [
            '[telegram]',
            '[telegram]',
            '[redacted]',
            '[smtp]',
            '[email]',
            '[redacted]',
        ], $message) ?? 'delivery failed';

        $redacted = trim(preg_replace('/\s+/', ' ', $redacted) ?? 'delivery failed');

        if ($redacted === '') {
            $redacted = 'delivery failed';
        }

        return mb_substr($redacted, 0, self::MAX_LENGTH);
    }

    public static function fromThrowable(Throwable $exception): string
    {
        return self::redact($exception->getMessage()) ?? 'delivery failed';
    }
}
