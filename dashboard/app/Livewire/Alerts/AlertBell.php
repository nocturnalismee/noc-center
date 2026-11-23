<?php

namespace App\Livewire\Alerts;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AlertBell extends Component
{
    /**
     * The bell is a live count of active work: open and acknowledged alerts.
     * Resolved alerts are excluded even when they are still on screen.
     */
    public function refresh(): void
    {
        //
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
}
