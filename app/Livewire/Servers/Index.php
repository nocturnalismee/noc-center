<?php

namespace App\Livewire\Servers;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.servers.index', [
            'total' => Server::query()->count(),
        ]);
    }
}
