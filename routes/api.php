<?php

use App\Http\Controllers\Api\IngestController;
use App\Http\Middleware\LogIngestRequest;
use Illuminate\Support\Facades\Route;

Route::post('/v1/ingest', IngestController::class)
    ->middleware([LogIngestRequest::class, 'ingest.size', 'ingest.rate', 'ingest.auth', 'ingest.throttle'])
    ->name('ingest');
