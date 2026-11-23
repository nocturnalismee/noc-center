<?php

namespace App\Livewire;

use App\Models\Server;
use App\Services\FleetSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render(): View
    {
        $metrics = app(FleetSummary::class)->summary();
        $latestSample = Server::query()->max('last_sample_at');

        return view('livewire.dashboard', [
            'summary' => $metrics,
            'latestSample' => $latestSample,
        ]);
    }
}
