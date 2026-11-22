<?php

namespace App\Http\Middleware;

use App\Models\ServerToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleIngest
{
    /**
     * Enforce the per-token ingest budget.
     *
     * The global failure bucket and the failed-authentication IP bucket were
     * counted by `ThrottleIngestFailures`; this middleware only needs the
     * resolved token, so it runs after authentication.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ServerToken $token */
        $token = $request->attributes->get('noc_token');

        $key = 'ingest:token:'.$token->getKey();

        if (RateLimiter::tooManyAttempts($key, (int) config('ingest.per_token_per_minute'))) {
            return response()
                ->json(['error' => 'rate_limited'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
