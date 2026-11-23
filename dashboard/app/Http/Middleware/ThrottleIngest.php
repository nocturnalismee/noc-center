<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RejectsIngestRequests;
use App\Models\ServerToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleIngest
{
    use RejectsIngestRequests;

    /**
     * Enforce the per-token ingest budget.
     *
     * The global failure bucket and the failed-authentication IP bucket were
     * handled by `ThrottleIngestFailures`; this middleware only needs the
     * resolved token, so it runs after authentication.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ServerToken $token */
        $token = $request->attributes->get('noc_token');

        $key = 'ingest:token:'.$token->getKey();

        // Check first, then count: rejecting before `hit()` keeps the 30th
        // request accepted and the 31st rejected, exactly as the contract says.
        if (RateLimiter::tooManyAttempts($key, (int) config('ingest.per_token_per_minute'))) {
            return $this->rateLimited($key);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
