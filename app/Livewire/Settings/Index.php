<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Authenticated settings hub (R53).
 *
 * Lists every configurable area with role-aware visibility; it never 404s for
 * a signed-in user.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.settings.index');
    }
}
