<?php

/*
 * [Sprint H4 Z3-NEW-006 2026-05-17] KDS V2 kill-switch and adjacent runtime config.
 *
 * V2 KDS unified-queue layout is the default rollout (Wave Z 5C made the
 * flip). This config provides the org-wide enable/disable lever so an
 * operator can rollback all devices to legacy without per-tab flipping.
 *
 * Precedence resolved in KitchenDisplaySystemComponent::useV2Layout:
 *   1. URL `?v2=1`/`?v2=0`             — operator emergency, single tab
 *   2. localStorage `kds.v2_enabled`   — per-tab sticky
 *   3. window.FK_KDS_V2_DEFAULT_ENABLED — org config (this key)
 *   4. true                            — final fallback (V2 is default)
 *
 * Override via `.env`:
 *   KDS_V2_DEFAULT_ENABLED=false
 *
 * to rollback the whole org to legacy KDS until you can re-test the
 * upgrade. CLAUDE.md §11 (memory discipline for runtime config).
 */

return [
    'v2_default_enabled' => filter_var(
        env('KDS_V2_DEFAULT_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    /*
     * [PR-02 core-bulletproof 2026-06-04] KDS sync-degradation banner contract.
     *
     * Owner mandate: a silent degradation is GRAVE. When soketi drops and the
     * KDS falls back to ~30-60s polling, the kitchen MUST see it. The real
     * Le Cayenne box runs APP_ENV=local, so the old "hide in local" gate left
     * the kitchen blind. This key makes the contract explicit and FAIL-SAFE:
     *
     *   - BOX DEFAULT = VISIBLE (true). No override → kitchen always warned.
     *   - DEV OPT-OUT  = set KDS_SHOW_FALLBACK_BANNER=false in .env to silence
     *     the permanent banner that appears when soketi is intentionally off
     *     in development. The risky state is opt-OUT, never opt-in.
     *
     * Precedence in KitchenDisplaySystemComponent::kdsSuppressFallbackBanner:
     *   suppress ONLY IF (appEnv === 'local' AND
     *                     window.FK_KDS_SHOW_FALLBACK_BANNER === false)
     *
     * NOTE: the frontend currently reads window.FK_KDS_SHOW_FALLBACK_BANNER.
     * Wiring THIS config value through master.blade.php into that global is
     * DEFERRED to avoid a collision with a parallel session editing
     * master.blade.php. Until that wiring lands, the box default (flag
     * undefined → banner VISIBLE) already satisfies the mandate; this key
     * documents the intended contract and is the .env knob's home.
     *
     * Override via `.env`:
     *   KDS_SHOW_FALLBACK_BANNER=false
     */
    'show_fallback_banner' => filter_var(
        env('KDS_SHOW_FALLBACK_BANNER', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
     * [Wave R-1 P-OWNER 2026-05-20] KDS bump CTA rate-limit per-minute ceiling.
     * Owner mandate: chef chains multiple orders rapidly in fast-food kitchen.
     * Local dev raises to 1000/min in `.env` (owner manual-test bursts).
     * Production default 120/min — generous for any realistic kitchen pace.
     */
    'rate_limit_bump' => max(1, (int) env('KDS_RATE_LIMIT_BUMP', 120)),
];
