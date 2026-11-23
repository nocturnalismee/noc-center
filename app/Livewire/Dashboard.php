<?php

namespace App\Livewire;

use App\Models\Server;
use App\Services\FleetSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Keep the summary strip in step with the table's 30-second poll.
     */
    #[On('fleet-summary-refresh')]
    public function refreshSummary(): void {}

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
