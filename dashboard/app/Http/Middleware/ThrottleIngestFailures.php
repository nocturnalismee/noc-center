<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RejectsIngestRequests;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleIngestFailures
{
    use RejectsIngestRequests;

    /**
     * Enforce the global ingest ceiling and reject IPs that failed auth too
     * often.
     *
     * Only the failed-authentication bucket is enforced here: the per-token
     * bucket can only be checked after the token has been resolved, which
     * happens in the next middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $global = 'ingest:global';

        if (RateLimiter::tooManyAttempts($global, (int) config('ingest.global_per_minute'))) {
            return $this->rateLimited($global);
        }

        RateLimiter::hit($global, 60);

        $failures = 'ingest:fail:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, (int) config('ingest.failed_per_ip_per_minute'))) {
            return $this->rateLimited($failures);
        }

        return $next($request);
    }
}
