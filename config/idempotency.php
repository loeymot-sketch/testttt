<?php

/**
 * F-VERIFY-09-02 — HTTP-level idempotency configuration.
 *
 * `enabled`             : master flag, default OFF for safe roll-out (per PLAN_P11 §2).
 * `ttl_seconds`         : how long a COMPLETED replay record is kept (24h default).
 * `pending_ttl_seconds` : how long a PENDING placeholder lives before self-
 *                         expiring (30s default). Decoupled from `ttl_seconds`
 *                         so SIGKILL/server-restart between Phase-2 acquire()
 *                         and Phase-3 release() does NOT trap the key for 24h.
 *                         Source: Phase F.6 audit finding F-6-6-FIND-04 P2.
 *                         Trade-off: with 30s, a >30s slow request can be
 *                         re-acquired during execution; DB UNIQUE backstop
 *                         (PLAN_P11 §1.2) remains the safety net, not this TTL.
 * `race_wait_ms`        : time to wait for an in-flight twin request to publish
 *                         its COMPLETED record before returning 425.
 * `fail_open`           : when true, missing/unhealthy storage falls back to the
 *                         app-layer UNIQUE backstop instead of returning 503.
 * `required_routes`     : opt-in list. Routes outside this list are *not*
 *                         rejected when the header is missing — backwards-compat
 *                         with existing kiosk/mobile clients.
 * `cache_store`         : null = `cache.default` (`array` in tests, `redis` in prod).
 *                         Override only in unusual deployments.
 */

return [
    'enabled'             => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false),
    'ttl_seconds'         => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),
    'pending_ttl_seconds' => (int) env('IDEMPOTENCY_PENDING_TTL_SECONDS', 30),
    'race_wait_ms'        => (int) env('IDEMPOTENCY_RACE_WAIT_MS', 1500),
    'fail_open'           => (bool) env('IDEMPOTENCY_FAIL_OPEN', false),

    'required_routes' => [
        'api/admin/pos',
        'api/admin/pos-order/change-payment-status/*',
        'api/admin/pos-order/select-delivery-boy/*',
        'api/admin/online-order/change-payment-status/*',
        'api/admin/online-order/select-delivery-boy/*',
        'api/admin/table-order/change-payment-status/*',
        'api/frontend/order',
        'api/frontend/order/*/payment-confirm',
        // [GOAL-CMS-2026-05-18 C-P0-H heal] — close header-omission bypass on
        // every route declared with `idempotency` middleware. Source: R3
        // T-1.4.2 Sec S-1 + sentinel `IdempotencyRequiredRoutesCoverageTest`
        // which surfaced 10 more URIs the R3 finding's literal list missed
        // (different `/pos/` prefix on cash-drawer + 6 change-status flows).
        // Without these entries the middleware silent-passes on missing
        // X-Idempotency-Key → double-execute on retry (double charge,
        // double cash-drawer-open, double order-status-change).
        'api/admin/pos/counter-collect/*/confirm',
        'api/admin/pos/counter-collect/*/cancel',
        'api/admin/pos/collect-kiosk-cash/*',
        'api/admin/pos/orders/*/print-receipt',
        'api/admin/pos/cash-drawer/open',
        'api/admin/pos/cash-drawer/sessions/open',
        'api/admin/pos/cash-drawer/sessions/*/close',
        'api/admin/pos/cash-drawer/sessions/*/reconcile',
        'api/admin/pos-order/*/refund-with-counter-entry',
        'api/admin/pos-order/change-status/*',
        'api/admin/online-order/change-status/*',
        'api/admin/table-order/change-status/*',
        'api/admin/kds-order/change-status/*',
        'api/frontend/order/change-status/*',
        'api/frontend/delivery-boy-order/change-status/*',
        // Livreur cash-session routes (new V1.0.2-sub6-3 NF525 cash session
        // foundation, commit 3d5ca01f6 — parallel mission)
        'api/admin/delivery-boy/cash-sessions/open',
        'api/admin/delivery-boy/cash-sessions/*/close',
        'api/admin/delivery-boy/cash-sessions/*/reconcile',
        // [LCS-S-002 / 2026-05-19] Loyalty redeem — mobile sends Idempotency-Key
        // per B-02 spec but server ignored before this commit. Network retry
        // would double-debit loyalty points balance.
        'api/frontend/loyalty/redeem',
        // [Wave E-1 / 2026-05-19] POS cashier loyalty redeem at-payment.
        // Route declared `idempotency` middleware in routes/api.php but was
        // missing from required_routes — WE-4 final convergence sentinel
        // IdempotencyRequiredRoutesCoverageTest correctly flagged the gap.
        'api/admin/pos-order/*/redeem-loyalty',
    ],

    'cache_store' => env('IDEMPOTENCY_CACHE_STORE'),
];
