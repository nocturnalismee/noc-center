<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIngestBodySize
{
    /**
     * Reject oversized or non-JSON ingest requests before authentication.
     *
     * `Content-Length` is checked when the client sends it, but the body
     * length is always measured too: a chunked or dishonest client cannot
     * bypass the limit by omitting or understating the header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('ingest.max_bytes');

        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return $this->payloadTooLarge();
        }

        $content = $request->getContent();

        if (strlen($content) > $maxBytes) {
            return $this->payloadTooLarge();
        }

        if ($content !== '' && ! $this->isJson($request->headers->get('Content-Type'))) {
            return response()->json(['error' => 'unsupported_media_type'], 415);
        }

        return $next($request);
    }

    private function payloadTooLarge(): Response
    {
        return response()->json(['error' => 'payload_too_large'], 413);
    }

    private function isJson(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        // Accept `application/json` with optional parameters, for example
        // `application/json;charset=UTF-8`.
        return preg_match('/^application\/json\b/i', trim($contentType)) === 1;
    }
}
