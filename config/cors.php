<?php

/*
| Which websites may call the API from a browser. The API uses Bearer tokens (never cookies), so
| browsers cannot send a logged-in request on their own anyway; this list is defence in depth.
| Set CORS_ALLOWED_ORIGINS to the frontend URL(s), comma separated, e.g. https://bookly.example.com
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Let the frontend's JavaScript read these response headers.
    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Idempotent-Replayed', 'Content-Disposition'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
