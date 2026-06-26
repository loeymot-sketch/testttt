<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        env('APP_URL'),
        env('KIOSK_DOMAIN'),
        env('ADMIN_DOMAIN'),
        // Wave Y A-002 — kiosk SPA loads from 127.0.0.1:8000 while APP_URL=localhost:8000.
        // Allow both same-host variants explicitly so Echo / broadcasting auth handshake
        // passes CORS without depending on the operator aligning APP_URL with the served origin.
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        // [WEB-WIREUP 2026-06-26] Standalone customer web (React CDN, no build) served on :8011,
        // calls the API cross-origin with Bearer token + X-API-Key (no cookies). FRONTEND_WEB_DOMAIN
        // overrides for prod; the localhost variants cover local dev / e2e.
        env('FRONTEND_WEB_DOMAIN'),
        'http://localhost:8011',
        'http://127.0.0.1:8011',
    ]))),
    'allowed_origins_patterns' => [
        // [WEB-WIREUP 2026-06-26] Loopback dev origins (any port) — the standalone web / e2e
        // server runs on assorted local ports. Safe: only matches localhost/127.0.0.1 (same box),
        // never a remote origin; production uses the explicit APP_URL / FRONTEND_WEB_DOMAIN above.
        '#^http://(localhost|127\.0\.0\.1):\d{2,5}$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => true,
];
