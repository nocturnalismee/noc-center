<?php

use App\Http\Controllers\ServerSeriesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard-placeholder')->name('dashboard');
    Route::get('/servers/{server}/series', ServerSeriesController::class)->name('servers.series');
});
