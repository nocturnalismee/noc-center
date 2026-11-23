<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RejectsIngestRequests;
use App\Services\Tokens\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentToken
{
    use RejectsIngestRequests;

    public function __construct(private readonly TokenService $tokens)
    {
        //
    }

    /**
     * Authenticate the agent bearer token.
     *
     * A token must exist, be unrevoked and unexpired, and belong to a
     * server that exists. Any of those failures is reported generically as
     * 401 and counts against the failed-authentication IP budget.
     *
     * A valid token on an inactive server is a 403: the token is genuine,
     * but the server is not allowed to ingest. That rejection still spends
     * both the failed-authentication IP budget and the token's own bucket
     * first, so an inactive server cannot issue unlimited 403s (R32a).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->bearerToken($request);
        $token = $plain === null ? null : $this->tokens->resolve($plain);

        if ($token === null) {
            return $this->unauthorized($request, $plain);
        }

        $server = $token->server;

        // `resolve()` eager-loads the server with the trashed scope, so a
        // soft-deleted server is still inspectable and must be treated as
        // unknown rather than allowed through.
        if ($server === null || $server->trashed()) {
            return $this->unauthorized($request, $plain);
        }

        if ($server->is_active !== true) {
            $failures = 'ingest:fail:'.$request->ip();
            $bucket = 'ingest:token:'.$token->getKey();

            RateLimiter::hit($failures, 60);

            if (RateLimiter::tooManyAttempts($failures, (int) config('ingest.failed_per_ip_per_minute'))) {
                return $this->rateLimited($failures);
            }

            if (RateLimiter::attempts($bucket) >= (int) config('ingest.per_token_per_minute')) {
                return $this->rateLimited($bucket);
            }

            RateLimiter::hit($bucket, 60);

            $this->logFailure($request, $plain);

            return $this->reject('inactive_server', 403);
        }

        $request->attributes->set('noc_token', $token);

        return $next($request);
    }

    private function unauthorized(Request $request, ?string $plain): Response
    {
        RateLimiter::hit('ingest:fail:'.$request->ip(), 60);

        $this->logFailure($request, $plain);

        return $this->reject('unauthorized', 401);
    }

    /**
     * Log only the eight-character token prefix; the plaintext, its hash and
     * the request body must never reach the log. The prefix is derived from
     * the sanitised token, so a malformed one cannot inject control bytes
     * (newlines, terminal escapes) into the log (R32h).
     */
    private function logFailure(Request $request, ?string $plain): void
    {
        $prefix = $plain === null ? '' : substr(self::sanitiseToken($plain), 0, 8);

        Log::warning('Ingest authentication failed.', [
            'token_prefix' => $prefix === '' ? null : $prefix,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Normalise a client-supplied token for the comparison that produces the
     * logged prefix.
     *
     * A raw escape byte cannot appear in `\S+`, but a terminal escape is
     * `ESC "["`: the bracket survives, so stripping only the forbidden bytes
     * would still let `[31m` reach the log. Every character the bearer pattern
     * accepts is kept and the rest is turned into a constant marker, so the
     * prefix is stable, bounded and free of control sequences (R32h).
     */
    private static function sanitiseToken(string $plain): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '.', $plain);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if ($header === null) {
            return null;
        }

        if (preg_match('/\ABearer\s+(\S+)\z/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
