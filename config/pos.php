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
    | POS API rate-limit knobs (Wave O O-5 P-OWNER-5 heal 2026-05-20)
    |--------------------------------------------------------------------------
    |
    | The three POS-namespace throttle buckets used by RouteServiceProvider
    | (`pos-quote`, `pos-order-create`, `pos-order-update`) were hard-coded
    | at 120 / 60 / 120 req/min/user. During simulation-mode E2E owner
    | testing — repeated TPE retries while wiring composition fixes — the
    | `pos-order-create` 60/min ceiling was burned and every subsequent
    | submit surfaced `429 Too Many Attempts` ("Too many requests at this
    | time"). Owner had no escape valve short of editing source.
    |
    | Defaults below match the historical hard-coded values exactly so
    | production behaviour is unchanged. Local dev raises the ceiling via
    | `.env` only (POS_RATE_LIMIT_*). `.env.example` keeps the prod defaults
    | so a fresh clone is safe by default. AppServiceProvider's NF525 boot
    | guards are unaffected — these are throttle ceilings, not fiscal
    | invariants.
    */
    'rate_limit' => [
        'quote'        => max(1, (int) env('POS_RATE_LIMIT_QUOTE', 120)),
        'order_create' => max(1, (int) env('POS_RATE_LIMIT_ORDER_CREATE', 60)),
        'order_update' => max(1, (int) env('POS_RATE_LIMIT_ORDER_UPDATE', 120)),
    ],

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
    | [2026-05-20 P-OWNER-3 root-cause heal] The original implementation
    | shipped raw integer IDs `[344,345,346,306,348,347]` taken from an
    | older menu snapshot. After the menu reset 2026-05-13, real category
    | IDs in the Le Cayenne DB are `[1..11]` and the env-default list
    | matches NOTHING — every category collapses to `featured=false`,
    | the cashier lands on supplements/menu-builder items, and the
    | "Sandwiches/Tacos/Burgers/Bowls" strip never renders properly.
    |
    | Fix: switch the source of truth from raw IDs (env-dependent) to
    | **slugs** (stable across envs/reseeds). The controller now resolves
    | slugs → IDs via the `item_categories` table at request time.
    |
    | Default = Le Cayenne best-sellers per menu reset 2026-05-13:
    |   sandwich-cayenne, galette, sandwich-classique, tacos,
    |   bols-gourmands, frites, burgers, boissons.
    |
    | [2026-05-20 Wave O O10] Owner adds `boissons` to the featured strip —
    | drinks are a high-velocity attach for cashier upsell on the POS landing
    | screen. The 8 boisson items (coca, coca-zero, fanta, sprite, oasis,
    | orangina, eau-plate, capri-sun) all have media attached via O8
    | RestoreLeCayenneItemImagesSeeder (byte-identical owner uploads in
    | `storage/app/public/51-58/`), so the strip will surface the photos.
    |
    | Override via env CSV (slugs):
    |   POS_FEATURED_CATEGORY_SLUGS=sandwich-cayenne,burgers,tacos,...
    |
    | Empty list → "no filter" (all categories shown — safe default when
    | config not yet provisioned).
    |
    | Backward compatibility — `featured_category_ids` is preserved as a
    | secondary knob for ops that still ship integer-IDs via env
    | `POS_FEATURED_CATEGORY_IDS`. The controller honors slugs FIRST and
    | falls back to IDs when slugs resolve to nothing (e.g. dev fixtures
    | without seeded slugs).
    */
    'featured_category_slugs' => array_values(array_filter(array_map(
        static fn (string $s): string => trim($s),
        explode(',', (string) env(
            'POS_FEATURED_CATEGORY_SLUGS',
            'sandwich-cayenne,burgers,tacos,bols-gourmands,sandwich-classique,frites,galette,boissons',
        )),
    ))),

    'featured_category_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('POS_FEATURED_CATEGORY_IDS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Wave S-1 — auto-transition ACCEPT → PREPARING on payment confirmed
    |--------------------------------------------------------------------------
    |
    | Owner decision 2026-05-20 (P-OWNER Wave S-1):
    | When a paid order lands in ACCEPT (CONFIRMÉE), advance it immediately
    | to PREPARING (EN PRÉPARATION) so the kitchen sees it as "en cours"
    | without a second tap from the cashier.
    |
    | Exception (Wave S-5 sister mission): kiosk orders that the customer
    | chose to pay in CASH at the counter (`PosPaymentMethod::CASH` on
    | `confirmCounterPayment`) must stay in ACCEPT until the cashier
    | explicitly validates the cash collection through the S-5 cash-pending
    | UI. The policy class encapsulates this exception
    | ({@see \App\Domain\Order\AutoPrepareOnPaidPolicy}).
    |
    | Default = true (production). Set POS_AUTO_PREPARE_ON_PAID=false in env
    | for emergency rollback to the legacy "stay in ACCEPT" behaviour without
    | redeploying code. The KDS auto-transition watcher
    | (`KdsV2Grid.vue::autoTransitionEnabled`) defaults to FALSE post Wave
    | Q-2, so this backend hook is now the single source of truth for the
    | transition — no double-fire from the frontend.
    */
    'auto_prepare_on_paid' => filter_var(
        env('POS_AUTO_PREPARE_ON_PAID', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? true,
];
