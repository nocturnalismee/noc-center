<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Shared presentation for alert notifications (spec §9.2).
 *
 * A notification never carries token, bot token, SMTP credential or MySQL
 * query text: only the server name, the rule title, the trigger value, the
 * threshold, the alert time and a URL are rendered. Everything goes through
 * Blade's escaping (HTML) and the plain-text view.
 */
abstract class AlertNotification extends Notification
{
    public function __construct(public readonly Alert $alert)
    {
        $this->alert->loadMissing('server');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Human rule title, e.g. "CPU high".
     */
    protected function ruleTitle(): string
    {
        return $this->alert->title;
    }

    protected function serverName(): string
    {
        return $this->alert->server?->name ?? 'Unknown server';
    }

    protected function triggerValue(): string
    {
        $value = $this->alert->context_json['value'] ?? null;
        $threshold = $this->alert->context_json['threshold'] ?? null;

        if ($value === null) {
            return 'n/a';
        }

        $rendered = is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: '');

        if ($threshold === null) {
            return $rendered;
        }

        return sprintf('%s (threshold %s)', $rendered, is_scalar($threshold) ? (string) $threshold : '');
    }

    protected function alertUrl(): string
    {
        $base = rtrim((string) config('alerts.url'), '/');

        return $base.'/alerts/'.$this->alert->getKey();
    }

    protected function severity(): string
    {
        return ucfirst($this->alert->severity->value);
    }

    /**
     * @return list<string>
     */
    abstract public function lines(): array;

    protected function mail(string $subject, string $line, string $actionLabel): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.alerts.notification', [
                'preheader' => $line,
                'alert' => $this->alert,
                'title' => $this->headline(),
                'summary' => $line,
                'rows' => $this->attributes(),
                'url' => $this->alertUrl(),
                'actionLabel' => $actionLabel,
            ])
            ->withSymfonyMessage($this->plainText(...));
    }

    /**
     * Plain-text alternative built from the same escaped lines, so the mail
     * has both an HTML and a text part (spec §9.2).
     */
    private function plainText(Email $message): void
    {
        $view = app('view')->make('mail.alerts.notification-text', [
            'title' => $this->headline(),
            'lines' => $this->lines(),
        ]);

        $message->text($view->render());
    }

    abstract protected function headline(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function attributes(): array;
}
