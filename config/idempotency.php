<?php

/**
 * F-VERIFY-09-02 — HTTP-level idempotency configuration.
 *
 * `enabled`        : master flag, default OFF for safe roll-out (per PLAN_P11 §2).
 * `ttl_seconds`    : how long a replay record is kept (24h default).
 * `race_wait_ms`   : time to wait for an in-flight twin request to publish
 *                    its COMPLETED record before returning 425.
 * `fail_open`      : when true, missing/unhealthy storage falls back to the
 *                    app-layer UNIQUE backstop instead of returning 503.
 * `required_routes`: opt-in list. Routes outside this list are *not*
 *                    rejected when the header is missing — backwards-compat
 *                    with existing kiosk/mobile clients.
 * `cache_store`    : null = `cache.default` (`array` in tests, `redis` in prod).
 *                    Override only in unusual deployments.
 */

return [
    'enabled'      => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false),
    'ttl_seconds'  => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),
    'race_wait_ms' => (int) env('IDEMPOTENCY_RACE_WAIT_MS', 1500),
    'fail_open'    => (bool) env('IDEMPOTENCY_FAIL_OPEN', false),

    'required_routes' => [
        'api/admin/pos',
        'api/admin/pos-order/change-payment-status/*',
        'api/admin/pos-order/select-delivery-boy/*',
        'api/admin/online-order/change-payment-status/*',
        'api/admin/online-order/select-delivery-boy/*',
        'api/admin/table-order/change-payment-status/*',
        'api/frontend/order',
        'api/frontend/order/*/payment-confirm',
    ],

    'cache_store' => env('IDEMPOTENCY_CACHE_STORE'),
];
