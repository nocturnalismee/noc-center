<?php

namespace App\Livewire\Alerts;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AlertBell extends Component
{
    /**
     * ULID of the newest open alert at the last render. Used only to detect a
     * newly opened alert for the browser chime — the visible badge counts open
     * and acknowledged alerts.
     */
    public ?string $latestAlertId = null;

    public function mount(): void
    {
        // Initialise without dispatching: loading the shell must not chime.
        $this->latestAlertId = $this->latestOpenAlertId();
    }

    /**
     * Poll every 30 seconds. A newer open alert id emits the browser-only
     * `alert-created` event, which the sound controller listens for.
     */
    public function refresh(): void
    {
        $latest = $this->latestOpenAlertId();
        $previous = $this->latestAlertId;

        $this->latestAlertId = $latest;

        if ($latest !== null && ($previous === null || strcmp($latest, $previous) > 0)) {
            $this->dispatch('alert-created');
        }
    }

    public function render(): View
    {
        $count = Alert::query()
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
            ->count();

        return view('livewire.alerts.alert-bell', [
            'count' => $count,
        ]);
    }

    private function latestOpenAlertId(): ?string
    {
        $id = Alert::query()
            ->where('status', AlertStatus::Open->value)
            ->orderByDesc('id')
            ->value('id');

        return is_string($id) ? $id : null;
    }
}
