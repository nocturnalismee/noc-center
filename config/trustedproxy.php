<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs/CIDRs that may send X-Forwarded-* headers, or `*`.
    | Laravel's TrustProxies middleware reads this at request time, so the
    | value survives `php artisan config:cache`. Use `*` only when the origin
    | is firewalled to the proxy; otherwise clients can spoof the header.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
