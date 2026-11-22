<?php

use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/ingest', IngestController::class)
    ->middleware(['ingest.size', 'ingest.rate', 'ingest.auth', 'ingest.throttle'])
    ->name('ingest');
