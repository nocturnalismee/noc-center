<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Attach the per-request CSP nonce and the baseline security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));

        Vite::useCspNonce($nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $connectSrc = "'self'";
        $realtimeOrigin = $this->realtimeOrigin();

        if ($realtimeOrigin !== null) {
            $connectSrc .= ' '.$realtimeOrigin;
        }

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src {$connectSrc}; object-src 'none'; frame-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function realtimeOrigin(): ?string
    {
        if (! config('noc.realtime.enabled')) {
            return null;
        }

        $host = config('reverb.apps.apps.0.options.host');

        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = config('reverb.apps.apps.0.options.scheme', 'http');
        $port = (int) config('reverb.apps.apps.0.options.port', 8080);
        $websocketScheme = $scheme === 'https' ? 'wss' : 'ws';
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $websocketScheme.'://'.$host.($port !== $defaultPort && $port > 0 ? ':'.$port : '');
    }
}
