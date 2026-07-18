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
    | [2026-06-04 P-OWNER ALL-CATEGORIES] Owner decision: the POS strip on
    | `/admin/pos` shows ALL categories directly — a single horizontal
    | scrollable row, each with its photo — NOT a curated featured subset.
    | More categories are coming, so no per-slug allowlist to maintain.
    |
    | Mechanism: an EMPTY allowlist = "no filter". The controller
    | (PosCategoryController.php:204-206) marks every category
    | `featured=true` when `$featuredSet === null` (empty resolved IDs),
    | so `displayedCategories` (PosComponent.vue:1940) renders all 11
    | categories and `hasNonFeaturedCategories` becomes false → the
    | "Toutes les catégories" escape-hatch pill auto-hides (no longer
    | needed since nothing is hidden). The CSS strip is already
    | `flex-wrap:nowrap; overflow-x:auto` (single horizontal scrollable
    | row) and each category resolves a `thumb` photo via the
    | `cat-<slug>.png` fallback in config/menu_images.php.
    |
    | --- HISTORY (superseded) ------------------------------------------
    | 2026-05-18 → 2026-05-20: a curated best-seller subset (8 slugs) was
    | the landing default; categories outside it were reachable via search
    | or the "Toutes" pill. The slug-based source of truth (stable across
    | reseeds, vs the original env-dependent raw integer IDs) is retained:
    | the controller resolves slugs → IDs via the `item_categories` table
    | at request time. To re-enable a curated subset, set a non-empty
    | env CSV:  POS_FEATURED_CATEGORY_SLUGS=sandwich-cayenne,burgers,tacos
    | -------------------------------------------------------------------
    |
    | Default = EMPTY → all categories shown (single row, all photos).
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
            '',
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

    /*
    |--------------------------------------------------------------------------
    | Manual POS discount (GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30)
    |--------------------------------------------------------------------------
    |
    | DEFAULT FALSE for V1. At a non-zero VAT rate (10% TTC) the discount→HT/TVA
    | split in the FROZEN ZReportService/PricingService is wrong (TVA on the
    | PRE-discount base) — the dormant-at-0%-VAT "F1" defect. A discounted order
    | would sign a fiscally-incorrect NF525 Z. Until F1 is fixed under a
    | lock-plan, OrderService::assertPosManualDiscountAllowed refuses any
    | non-zero manual discount. Non-discounted orders decompose correctly.
    | Re-enable (POS_MANUAL_DISCOUNT_ENABLED=true) ONLY after F1 is fixed + a
    | behavioral Z test proves discounted TVA is computed on the NET base.
    */
    // [GOAL-GOLIVE-VAT10 / F1-fix-r2 2026-05-31] Default flipped false → true.
    // F1 (fiscal-incorrect Z on a discounted order at non-zero VAT) is FIXED in
    // ZReportService under LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md and
    // proven E2E by ZReportDiscountNettingTest::test_discounted_z_close_signs_and_chain_verifies
    // (signed Z verifySignature ✓ + verifyChain.valid ✓ + EXACT identity
    // total_tva == Σ total_by_tax_rate on a real discounted Z). Reactivation per
    // owner AskUserQuestion. The flag remains a runtime KILL-SWITCH: setting
    // POS_MANUAL_DISCOUNT_ENABLED=false in .env re-engages every dormancy gate
    // (refusing non-zero discounts at every order-creation chokepoint, hiding the
    // kiosk loyalty button + web coupon entry, refusing the pre-redeem at source).
    // The kill-switch path is locked by the *_killswitch_* sentinels.
    // [OWNER 2026-07-18] « Coupe les remises ! » → défaut RE-flippé à false
    // (kill-switch réengagé). Refuse toute remise non-nulle aux chokepoints de
    // création, masque le bouton fidélité borne + l'entrée coupon web, refuse le
    // pre-redeem. Côté sûr fiscal tant que la contradiction preflight↔config sur
    // F1 (split TVA remise) n'est pas retranchée. Réactivation = owner + .env
    // POS_MANUAL_DISCOUNT_ENABLED=true.
    'manual_discount_enabled' => filter_var(
        env('POS_MANUAL_DISCOUNT_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? false,

    /*
    |--------------------------------------------------------------------------
    | Walk-in route to counter (GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30)
    |--------------------------------------------------------------------------
    |
    | Owner model (B): route POS walk-in orders through the SAME deferred
    | counter-collection queue as the Borne (kiosk Plan B), so EVERY payment —
    | Borne + Caisse, espèces + carte — is collected from the unified
    | /admin/encaissement page. Symmetric to kiosk.payment_route_all_to_counter.
    |
    | When TRUE, OrderService::posOrderStore creates the walk-in order as
    | PENDING_COUNTER + COUNTER_DEFERRED (payment_method=CASH_ON_DELIVERY marker,
    | kitchen prepares before pay per W-D1) and DEFERS the fiscal_sequence_no
    | allocation to collection time (PaymentService::confirmCounterPayment),
    | exactly like the kiosk cash-at-counter path. NF525 stays gap-free: the
    | seq is allocated once, at collection, never at this deferred creation.
    |
    | DEFAULT = false — preserves the current inline-paid-at-creation POS flow.
    | Activation is an OWNER GATE (it changes the protected POS checkout UX:
    | the cashier collects later instead of inline). A per-request
    | `defer_to_counter=true` flag also triggers the deferred path so a
    | future non-frozen PosComponent control can opt in per order without a
    | global flip.
    */
    'walkin_route_to_counter' => filter_var(
        env('POS_WALKIN_ROUTE_TO_COUNTER', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? false,
];
