<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

trait RejectsIngestRequests
{
    /**
     * Build an uncacheable ingest rejection response.
     *
     * Every ingest error carries `Cache-Control: no-store`: the endpoint
     * answers authenticated, token-scoped traffic and no error may be stored
     * by a proxy or the agent between attempts (R32).
     *
     * @param  array<string, string>  $headers
     */
    protected function reject(string $error, int $status, array $headers = []): Response
    {
        return response()
            ->json(['error' => $error], $status, $headers)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Reject with 429 and a `Retry-After` clamped to the shared 60-second
     * decay window, so the header is always an integer between 1 and 60.
     */
    protected function rateLimited(string $key): Response
    {
        $retryAfter = min(60, max(1, RateLimiter::availableIn($key)));

        return $this->reject('rate_limited', 429, ['Retry-After' => (string) $retryAfter]);
    }
}
