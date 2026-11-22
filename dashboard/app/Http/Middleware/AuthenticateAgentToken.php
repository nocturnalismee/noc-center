<?php

namespace App\Http\Middleware;

use App\Services\Tokens\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentToken
{
    public function __construct(private readonly TokenService $tokens)
    {
        //
    }

    /**
     * Authenticate the agent bearer token.
     *
     * A token must exist, be unrevoked and unexpired, and belong to a
     * server that exists. Any of those failures is reported generically as
     * 401 and counts against the failed-authentication IP budget. A valid
     * token on an inactive server is a 403: the token is genuine, but the
     * server is not allowed to ingest.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->bearerToken($request);
        $token = $plain === null ? null : $this->tokens->resolve($plain);

        if ($token === null) {
            return $this->reject($request, $plain);
        }

        $server = $token->server;

        // `resolve()` eager-loads the server with the trashed scope, so a
        // soft-deleted server is still inspectable and must be treated as
        // unknown rather than allowed through.
        if ($server === null || $server->trashed()) {
            return $this->reject($request, $plain);
        }

        if ($server->is_active !== true) {
            $this->logFailure($request, $plain);

            return response()->json(['error' => 'inactive_server'], 403);
        }

        $request->attributes->set('noc_token', $token);

        return $next($request);
    }

    private function reject(Request $request, ?string $plain): Response
    {
        RateLimiter::hit('ingest:fail:'.$request->ip(), 60);

        $this->logFailure($request, $plain);

        return response()->json(['error' => 'unauthorized'], 401);
    }

    /**
     * Log only the eight-character token prefix; the plaintext, its hash and
     * the request body must never reach the log.
     */
    private function logFailure(Request $request, ?string $plain): void
    {
        Log::warning('Ingest authentication failed.', [
            'token_prefix' => $plain === null ? null : substr($plain, 0, 8),
            'ip' => $request->ip(),
        ]);
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
