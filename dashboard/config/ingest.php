<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ingest limits
    |--------------------------------------------------------------------------
    |
    | The agent ingest endpoint accepts at most 64 KiB per request, thirty
    | requests per minute per token, and sixty failed authentications per
    | minute per IP. The global counter caps total ingest traffic.
    |
    */

    'max_bytes' => 65_536,

    'per_token_per_minute' => 30,

    'failed_per_ip_per_minute' => 60,

    'global_per_minute' => 1_000,
];
