<?php

use App\Http\Controllers\ServerSeriesController;
use App\Http\Controllers\StoredSnapshotController;
use App\Http\Controllers\TokenRevealController;
use App\Livewire\Activity\Index as ActivityIndex;
use App\Livewire\Alerts\Index as AlertsIndex;
use App\Livewire\Alerts\Show as AlertsShow;
use App\Livewire\Dashboard;
use App\Livewire\Servers\Create;
use App\Livewire\Servers\Edit;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\Show;
use App\Livewire\Servers\Tokens;
use App\Livewire\Servers\Waiting;
use App\Livewire\Settings\AlertSettings;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\TwoFactor;
use App\Livewire\Settings\Users;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/servers', ServersIndex::class)->name('servers.index');

    // Registered before the show route so `/servers/create` is not captured
    // by route-model binding for `/servers/{server}`.
    Route::livewire('/servers/create', Create::class)->name('servers.create');
    Route::livewire('/servers/{server}', Show::class)->name('servers.show');
    Route::livewire('/servers/{server}/tokens', Tokens::class)->name('servers.tokens');
    Route::livewire('/servers/{server}/edit', Edit::class)->name('servers.edit');
    Route::get('/servers/{server}/series', ServerSeriesController::class)->name('servers.series');
    Route::get('/servers/{server}/snapshot', StoredSnapshotController::class)->name('servers.snapshot');
    Route::get('/servers/{server}/token-reveal', [TokenRevealController::class, 'show'])->name('servers.token-reveal');
    Route::livewire('/servers/{server}/waiting', Waiting::class)->name('servers.waiting');

    Route::livewire('/alerts', AlertsIndex::class)->name('alerts.index');
    Route::livewire('/alerts/{alert}', AlertsShow::class)->name('alerts.show');

    Route::livewire('/settings', SettingsIndex::class)->name('settings.index');
    Route::livewire('/settings/profile', Profile::class)->name('settings.profile');
    Route::livewire('/settings/security', TwoFactor::class)->name('settings.security');
    Route::livewire('/settings/users', Users::class)->name('settings.users');
    Route::livewire('/settings/alerts', AlertSettings::class)->name('settings.alerts');

    Route::livewire('/activity', ActivityIndex::class)->name('activity.index');
});
