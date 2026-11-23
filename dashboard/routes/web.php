<?php

use App\Http\Controllers\ServerSeriesController;
use App\Http\Controllers\StoredSnapshotController;
use App\Livewire\Dashboard;
use App\Livewire\Servers\Show;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/servers/{server}', Show::class)->name('servers.show');
    Route::get('/servers/{server}/series', ServerSeriesController::class)->name('servers.series');
    Route::get('/servers/{server}/snapshot', StoredSnapshotController::class)->name('servers.snapshot');
});
