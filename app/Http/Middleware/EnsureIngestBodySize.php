<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RejectsIngestRequests;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIngestBodySize
{
    use RejectsIngestRequests;

    /**
     * Reject oversized or non-JSON ingest requests before authentication.
     *
     * `Content-Length` is checked when the client sends it, but the body
     * length is always measured too: a chunked or dishonest client cannot
     * bypass the limit by omitting or understating the header.
     *
     * Reading the body here is bounded: the SAPI (PHP-FPM) has already
     * buffered the request to `post_max_size` (8M in the deployment) before
     * any middleware runs, so `getContent()` cannot stream an unbounded
     * body. `ingest.max_bytes` (64 KiB) is the application limit enforced
     * below; `post_max_size` is the hard limit above which PHP discards the
     * body entirely.
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
            return $this->reject('unsupported_media_type', 415);
        }

        return $next($request);
    }

    private function payloadTooLarge(): Response
    {
        return $this->reject('payload_too_large', 413);
    }

    /**
     * Accept only `application/json`, case-insensitively, followed by the
     * end of the type or a `;` parameter list. Suffixed media types such as
     * `application/json-patch+json` are rejected.
     */
    private function isJson(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $type = strtolower(trim($contentType));

        return preg_match('/^application\/json(?:\s*;|$)/', $type) === 1;
    }
}
