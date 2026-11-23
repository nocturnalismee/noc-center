<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Alert opened" for one server and rule (spec §9.2).
 */
class AlertOpened extends AlertNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mail(
            sprintf('[%s] %s on %s', $this->severity(), $this->ruleTitle(), $this->serverName()),
            sprintf('%s triggered on %s.', $this->ruleTitle(), $this->serverName()),
            'View alert',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'opened',
            'alert_id' => $this->alert->getKey(),
            'server' => $this->serverName(),
            'rule' => $this->ruleTitle(),
            'severity' => $this->alert->severity->value,
            'value' => $this->triggerValue(),
            'at' => $this->alert->first_seen_at?->toIso8601String(),
            'url' => $this->alertUrl(),
            'lines' => $this->lines(),
        ];
    }

    protected function headline(): string
    {
        return sprintf('Alert opened: %s', $this->ruleTitle());
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return [
            sprintf('%s: %s', $this->severity(), $this->ruleTitle()),
            sprintf('Server: %s', $this->serverName()),
            sprintf('Trigger value: %s', $this->triggerValue()),
            sprintf('First seen: %s', $this->alert->first_seen_at?->toDayDateTimeString() ?? 'n/a'),
            sprintf('Alert: %s', $this->alertUrl()),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [
            'Server' => $this->serverName(),
            'Rule' => $this->ruleTitle(),
            'Severity' => $this->severity(),
            'Trigger value' => $this->triggerValue(),
            'First seen' => $this->alert->first_seen_at?->toDayDateTimeString() ?? 'n/a',
        ];
    }
}
