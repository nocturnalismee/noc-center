<?php

use App\Http\Middleware\AuthenticateAgentToken;
use App\Http\Middleware\EnsureIngestBodySize;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleIngest;
use App\Http\Middleware\ThrottleIngestFailures;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');

        $middleware->web(append: [
            SecurityHeaders::class,
            EnsureUserIsActive::class,
            EnsurePasswordChanged::class,
        ]);

        $middleware->alias([
            'ingest.size' => EnsureIngestBodySize::class,
            'ingest.rate' => ThrottleIngestFailures::class,
            'ingest.auth' => AuthenticateAgentToken::class,
            'ingest.throttle' => ThrottleIngest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
