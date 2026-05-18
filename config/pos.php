<?php

/**
 * POS runtime configuration.
 *
 * [2026-05-18] Adds the `simulation_hardware` flag used while the physical
 * POS hardware (cash drawer, TPE terminal, ticket printer) is not yet
 * connected. When the flag is ON, the controller-level guard that requires
 * an OPEN CashDrawerSession before a CASH-bearing order is bypassed —
 * mirroring the fact that no physical drawer exists to open.
 *
 * IMPORTANT: this flag does NOT bypass any pricing, composition, fiscal
 * sequence, audit-chain, or branch-isolation invariant. It only affects
 * the hardware-presence checks. NF525 compliance is preserved (sequence
 * allocation, HMAC chain signing, immutable composition_snapshot, etc.).
 *
 * Production day: set POS_SIMULATION_HARDWARE=false (default) and ensure
 * the physical cash drawer is open via `php artisan pos:open-drawer ...`
 * or the operator workflow.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Hardware simulation
    |--------------------------------------------------------------------------
    |
    | When true:
    |   - PosController::assertCashDrawerSessionOpenIfCashInvolved returns
    |     early (no drawer-open precondition required for CASH sales).
    |   - SplitPaymentService / PaymentService also skip the
    |     CashDrawerSessionNotOpenException short-circuit for CASH tranches
    |     when no physical drawer is attached.
    |
    | When false (default — production):
    |   - All hardware-presence checks fire normally.
    */
    'simulation_hardware' => filter_var(env('POS_SIMULATION_HARDWARE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Featured category allowlist — POS first-page filter
    |--------------------------------------------------------------------------
    |
    | Owner spec 2026-05-18: the cashier's landing screen on `/admin/pos`
    | shows ONLY this curated set of categories on the strip + their items
    | in the grid. All other categories remain accessible by:
    |   (a) typing into the search input (full menu, unfiltered), or
    |   (b) clicking the "Toutes les catégories" pill (escape hatch).
    |
    | Default = Le Cayenne best-sellers per menu reset 2026-05-13:
    |   344 Sandwich Cayenne, 345 Galette, 346 Sandwich Classique,
    |   306 Tacos, 348 Frites, 347 Bols Gourmands.
    |
    | Override via env CSV: POS_FEATURED_CATEGORY_IDS=344,345,346,306,348,347
    | Empty list → fallback "no filter" (all categories shown — safe default
    | when config not yet provisioned).
    */
    'featured_category_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('POS_FEATURED_CATEGORY_IDS', '344,345,346,306,348,347')),
    ))),
];
