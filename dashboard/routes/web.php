<?php

use App\Http\Controllers\ServerSeriesController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/servers/{server}/series', ServerSeriesController::class)->name('servers.series');
});
