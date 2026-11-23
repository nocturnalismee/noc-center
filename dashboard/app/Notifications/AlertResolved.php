<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Alert resolved" recovery for one server and rule (spec §9.2).
 *
 * Sent at most once per channel per alert: the notifier only creates a
 * `resolved` delivery for channels that already had an `opened` one, and the
 * unique `(alert_id, channel, event)` index makes a repeat impossible.
 */
class AlertResolved extends AlertNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mail(
            sprintf('[Recovered] %s on %s', $this->ruleTitle(), $this->serverName()),
            sprintf('%s on %s has recovered.', $this->ruleTitle(), $this->serverName()),
            'View alert',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'resolved',
            'alert_id' => $this->alert->getKey(),
            'server' => $this->serverName(),
            'rule' => $this->ruleTitle(),
            'severity' => $this->alert->severity->value,
            'recovered_at' => $this->alert->resolved_at?->toIso8601String(),
            'duration' => $this->duration(),
            'url' => $this->alertUrl(),
            'lines' => $this->lines(),
        ];
    }

    protected function headline(): string
    {
        return sprintf('Alert recovered: %s', $this->ruleTitle());
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return [
            sprintf('Recovered: %s', $this->ruleTitle()),
            sprintf('Server: %s', $this->serverName()),
            sprintf('Recovered at: %s', $this->alert->resolved_at?->toDayDateTimeString() ?? 'n/a'),
            sprintf('Duration: %s', $this->duration()),
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
            'Recovered at' => $this->alert->resolved_at?->toDayDateTimeString() ?? 'n/a',
            'Duration' => $this->duration(),
        ];
    }

    private function duration(): string
    {
        $from = $this->alert->first_seen_at;
        $to = $this->alert->resolved_at;

        if ($from === null || $to === null) {
            return 'n/a';
        }

        $minutes = max(0, (int) $from->diffInMinutes($to));

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' h' : $hours.' h '.$rest.' min';
    }
}
