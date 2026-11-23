<?php

namespace App\Alerts\Channels;

use App\Models\Alert;
use App\Services\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram `sendMessage` delivery (spec §9.2).
 *
 * The host is fixed at `api.telegram.org`; the bot token is the only variable
 * path segment and the full URL is never logged, thrown or stored. Payload:
 * `chat_id`, `text`, `parse_mode=HTML`, `disable_web_page_preview=true`, and
 * `message_thread_id` **only** when it was configured non-empty.
 *
 * Connection timeout is 5 s and the total timeout is 10 s. A `429` carries
 * `retry_after`, which is reported back so the delivery row can wait that
 * long instead of the default backoff. Every failure is returned, never
 * thrown: the notifier must update the delivery row and keep going.
 */
class TelegramChannel implements AlertChannel
{
    private const HOST = 'https://api.telegram.org';

    public function __construct(private readonly SettingsRepository $settings)
    {
        //
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function enabled(): bool
    {
        return $this->flag('enabled') && $this->chatId() !== null && $this->token() !== null;
    }

    public function connectTimeout(): int
    {
        return (int) config('alerts.telegram_connect_timeout', 5);
    }

    public function timeout(): int
    {
        return (int) config('alerts.telegram_timeout', 10);
    }

    public function send(Alert $alert, string $event): DeliveryResult
    {
        if (! $this->enabled()) {
            return DeliveryResult::skipped();
        }

        $payload = [
            'chat_id' => $this->chatId(),
            'text' => $this->text($alert, $event),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $thread = $this->threadId();

        if ($thread !== null) {
            $payload['message_thread_id'] = $thread;
        }

        try {
            $response = $this->client()->post($this->url(), $payload);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        } catch (RequestException $exception) {
            return $this->failure($exception->response);
        } catch (Throwable $exception) {
            return DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        }

        if (! $response->successful()) {
            return $this->failure($response);
        }

        return DeliveryResult::sent(1);
    }

    /**
     * One test message. Throws on failure so the settings page can show an
     * error, with a redacted message.
     */
    public function sendTest(): void
    {
        if ($this->chatId() === null || $this->token() === null) {
            throw new \InvalidArgumentException('The Telegram channel is not configured.');
        }

        $result = $this->sendMessage($this->chatId(), 'NOC dashboard test message.');

        if (! $result->isSent()) {
            throw new \RuntimeException($result->error ?? 'Telegram test delivery failed.');
        }
    }

    /**
     * A test send that is not tied to an alert.
     */
    public function sendMessage(string $chatId, string $text): DeliveryResult
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->escape($text),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $thread = $this->threadId();

        if ($thread !== null) {
            $payload['message_thread_id'] = $thread;
        }

        try {
            $response = $this->client()->post($this->url(), $payload);
        } catch (Throwable $exception) {
            return DeliveryResult::failed(AlertErrorRedactor::fromThrowable($exception));
        }

        if (! $response->successful()) {
            return $this->failure($response);
        }

        return DeliveryResult::sent(1);
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->acceptJson()
            ->asJson();
    }

    private function url(): string
    {
        return self::HOST.'/bot'.$this->token().'/sendMessage';
    }

    private function text(Alert $alert, string $event): string
    {
        $alert->loadMissing('server');

        $server = $alert->server?->name ?? 'Unknown server';
        $title = $alert->title;
        $url = rtrim((string) config('alerts.url'), '/').'/alerts/'.$alert->getKey();

        if ($event === 'resolved') {
            $at = $alert->resolved_at?->toDayDateTimeString() ?? 'now';

            return implode("\n", [
                '<b>✅ Recovered: '.$this->escape($title).'</b>',
                'Server: '.$this->escape($server),
                'Recovered: '.$this->escape($at),
                'Alert: '.$this->escape($url),
            ]);
        }

        $value = $alert->context_json['value'] ?? null;
        $value = is_scalar($value) ? (string) $value : 'n/a';
        $at = $alert->first_seen_at?->toDayDateTimeString() ?? 'now';

        return implode("\n", [
            '<b>🔴 '.$this->escape(strtoupper($alert->severity->value)).': '.$this->escape($title).'</b>',
            'Server: '.$this->escape($server),
            'Value: '.$this->escape($value),
            'At: '.$this->escape($at),
            'Alert: '.$this->escape($url),
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function failure(Response $response): DeliveryResult
    {
        $retryAfter = $this->retryAfter($response);
        $description = $response->json('description');
        $message = sprintf(
            'Telegram responded %d%s',
            $response->status(),
            is_string($description) && $description !== '' ? ': '.$description : '',
        );

        return DeliveryResult::failed($message, $retryAfter);
    }

    private function retryAfter(Response $response): ?int
    {
        if ($response->status() !== 429) {
            return null;
        }

        $retryAfter = $response->json('parameters.retry_after')
            ?? $response->header('Retry-After');

        if (! is_numeric($retryAfter)) {
            return null;
        }

        return max(0, (int) $retryAfter);
    }

    private function flag(string $key): bool
    {
        return (bool) $this->settings->get('alerts.channels.telegram.'.$key, config('alerts.channels.telegram.'.$key));
    }

    private function value(string $key): ?string
    {
        $value = $this->settings->get('alerts.channels.telegram.'.$key, config('alerts.channels.telegram.'.$key));

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function token(): ?string
    {
        return $this->value('bot_token');
    }

    private function chatId(): ?string
    {
        return $this->value('chat_id');
    }

    private function threadId(): ?int
    {
        $value = $this->value('message_thread_id');

        if ($value === null || ! ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
