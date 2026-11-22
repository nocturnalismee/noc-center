<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleIngestFailures
{
    /**
     * Count total ingest traffic and reject IPs that failed auth too often.
     *
     * Only the failed-authentication bucket is enforced here: the per-token
     * bucket can only be checked after the token has been resolved, which
     * happens in the next middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        RateLimiter::hit('ingest:global', 60);

        $key = 'ingest:fail:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('ingest.failed_per_ip_per_minute'))) {
            return response()
                ->json(['error' => 'rate_limited'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        return $next($request);
    }
}
