<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogIngestRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->write($request, null, $started, $exception::class);

            throw $exception;
        }

        $this->write($request, $response->getStatusCode(), $started);

        return $response;
    }

    private function write(Request $request, ?int $status, int $started, ?string $exception = null): void
    {
        Log::info('ingest.request', array_filter([
            'request_id' => $request->headers->has('X-Request-Id')
                ? mb_substr((string) $request->headers->get('X-Request-Id'), 0, 100)
                : null,
            'server_id' => $request->attributes->get('noc_token')?->server_id,
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            'request_bytes' => (int) $request->headers->get('Content-Length', 0),
            'exception' => $exception,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
