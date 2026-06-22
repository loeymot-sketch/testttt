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
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => true,
];
