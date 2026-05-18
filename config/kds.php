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
];
