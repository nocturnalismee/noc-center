<?php

namespace App\Livewire\Alerts;

use App\Alerts\AlertEngine;
use App\Alerts\Channels\AlertErrorRedactor;
use App\Alerts\Channels\EmailChannel;
use App\Alerts\Channels\TelegramChannel;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Alert $alert;

    public string $note = '';

    public ?string $statusMessage = null;

    public string $statusTone = 'success';

    public function mount(Alert $alert): void
    {
        Gate::authorize('view', $alert);

        $this->alert = $alert;
    }

    public function pollRefresh(): void
    {
        // A no-op render target for the Livewire polling safety net.
    }

    /**
     * Acknowledge without resolving: the alert stays visible, reminders stop,
     * and the engine writes `alert.acknowledged` with the actor.
     */
    public function acknowledge(): void
    {
        Gate::authorize('acknowledge', $this->alert);

        $this->statusMessage = null;

        if ($this->alert->status !== AlertStatus::Open) {
            $this->statusTone = 'warning';
            $this->statusMessage = 'Only an open alert can be acknowledged.';

            return;
        }

        app(AlertEngine::class)->acknowledge($this->alert, auth()->user());

        $this->statusTone = 'success';
        $this->alert->refresh();
        $this->statusMessage = 'Alert acknowledged. Reminders are paused until it resolves.';
    }

    /**
     * A manual resolve asserts that the condition is gone, so it sends the
     * one recovery per channel. The note is mandatory.
     */
    public function resolve(): void
    {
        Gate::authorize('resolve', $this->alert);

        $this->statusMessage = null;

        if ($this->alert->status === AlertStatus::Resolved) {
            $this->statusTone = 'warning';
            $this->statusMessage = 'This alert is already resolved.';

            return;
        }

        $this->note = trim($this->note);

        $this->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        app(AlertEngine::class)->resolve($this->alert, auth()->user(), $this->note, true);

        $this->statusTone = 'success';
        $this->alert->refresh();
        $this->note = '';
        $this->statusMessage = 'Alert resolved. A recovery message was queued for each channel that actually opened this alert.';
    }

    public function render(): View
    {
        $actors = User::query()
            ->whereIn('id', collect([$this->alert->acknowledged_by, $this->alert->resolved_by])->filter()->unique()->all())
            ->pluck('name', 'id');

        return view('livewire.alerts.show', [
            'canOperate' => auth()->user()?->canOperate() ?? false,
            'contextRows' => $this->contextRows(),
            'timeline' => $this->timeline($actors),
            'deliveryRows' => $this->deliveryRows(),
        ]);
    }

    /**
     * Flatten the stored context into escaped key/value rows, redacting query
     * text and credentials recursively.
     *
     * @return list<array{key: string, value: string}>
     */
    private function contextRows(): array
    {
        $rows = [];

        $this->flatten($this->alert->context_json ?? [], '', false, false, $rows);

        return $rows;
    }

    /**
     * @param  list<array{key: string, value: string}>  $rows
     */
    private function flatten(mixed $value, string $path, bool $mask, bool $hide, array &$rows): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                $segment = strtolower((string) $key);

                $this->flatten(
                    $child,
                    $childPath,
                    $mask || $this->isQueryKey($segment) || $this->isQueryKey(strtolower($childPath)),
                    $hide || preg_match('/(token|secret|password)/i', $segment) === 1,
                    $rows,
                );
            }

            return;
        }

        $rows[] = [
            'key' => $path === '' ? 'value' : $path,
            'value' => $this->contextValue($value, $mask, $hide),
        ];
    }

    private function isQueryKey(string $key): bool
    {
        return $key === 'info'
            || str_contains($key, 'query')
            || str_ends_with($key, '.info');
    }

    private function contextValue(mixed $value, bool $mask, bool $hide): string
    {
        if ($hide || $mask) {
            return '[redacted]';
        }

        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '[structured value]',
        };
    }

    /**
     * @param  Collection<int, string>  $actors
     * @return list<array{at: string, label: string, detail: string}>
     */
    private function timeline($actors): array
    {
        $entries = [[
            'at' => $this->formatDate($this->alert->first_seen_at),
            'label' => 'Opened',
            'detail' => $this->alert->rule_key->value.' on '.($this->alert->server?->name ?? 'this server'),
        ]];

        if ($this->alert->acknowledged_at !== null) {
            $entries[] = [
                'at' => $this->formatDate($this->alert->acknowledged_at),
                'label' => 'Acknowledged',
                'detail' => $actors[$this->alert->acknowledged_by] ?? 'Operator',
            ];
        }

        if ($this->alert->resolved_at !== null) {
            $actor = $this->alert->resolved_by !== null
                ? ($actors[$this->alert->resolved_by] ?? 'Operator')
                : 'Automatic';

            $entries[] = [
                'at' => $this->formatDate($this->alert->resolved_at),
                'label' => 'Resolved',
                'detail' => trim(($this->alert->resolution_note ?? 'Issue cleared').' · '.$actor),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{channel: string, opened: array{status: string, meta: ?string, error: ?string}, recovery: array{status: string, meta: ?string, error: ?string}}>
     */
    private function deliveryRows(): array
    {
        $deliveries = $this->alert->deliveries()
            ->get()
            ->keyBy(static fn (AlertDelivery $delivery): string => $delivery->channel.'|'.$delivery->event);

        $emailEnabled = app(EmailChannel::class)->enabled();
        $telegramEnabled = app(TelegramChannel::class)->enabled();

        $rows = [[
            'channel' => 'In-app',
            'opened' => $this->deliveryCell('in-app', 'opened', null, true),
            'recovery' => $this->deliveryCell('in-app', 'resolved', null, true),
        ]];

        foreach ([
            ['Email', 'email', $emailEnabled],
            ['Telegram', 'telegram', $telegramEnabled],
        ] as [$label, $key, $enabled]) {
            $rows[] = [
                'channel' => $label,
                'opened' => $this->deliveryCell($key, 'opened', $deliveries->get($key.'|opened'), $enabled),
                'recovery' => $this->deliveryCell($key, 'resolved', $deliveries->get($key.'|resolved'), $enabled),
            ];
        }

        return $rows;
    }

    /**
     * @return array{status: string, meta: ?string, error: ?string}
     */
    private function deliveryCell(string $channel, string $event, ?AlertDelivery $delivery, bool $configured): array
    {
        if ($channel === 'in-app') {
            return $event === 'opened'
                ? ['status' => 'Delivered', 'meta' => 'Alert row', 'error' => null]
                : ['status' => '—', 'meta' => null, 'error' => null];
        }

        if (! $configured) {
            return ['status' => 'Not configured', 'meta' => null, 'error' => null];
        }

        if ($delivery === null) {
            return ['status' => '—', 'meta' => null, 'error' => null];
        }

        $attempts = $delivery->attempts === 0
            ? 'No attempts'
            : $delivery->attempts.' '.($delivery->attempts === 1 ? 'attempt' : 'attempts');

        $lastAttempt = $delivery->last_attempt_at === null
            ? null
            : 'last '.$this->formatDate($delivery->last_attempt_at);

        return [
            'status' => ucfirst($delivery->status),
            'meta' => implode(' · ', array_filter([$attempts, $lastAttempt])),
            'error' => AlertErrorRedactor::redact($delivery->error),
        ];
    }

    public function formatDate(?\DateTimeInterface $at): string
    {
        if ($at === null) {
            return '—';
        }

        return Carbon::instance($at)
            ->timezone('Asia/Jakarta')
            ->format('d M Y · H:i').' WIB';
    }
}
