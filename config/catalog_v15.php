<?php

/*
|--------------------------------------------------------------------------
| FoodKing — Catalog V1.5 feature flags
|--------------------------------------------------------------------------
|
| Drives the staged convergence of POS / Kiosk / KDS catalog projections
| (Mission #1) and the lifecycle UX hardening (Mission #2).
|
| Audit references:
| - reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md
| - reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md
| - plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md
| - plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md
|
| Flags are read by:
| - app/Services/Menu/PosMenuProjection.php (unified_projection)
| - app/Services/Catalog/CatalogWarningService.php (warnings)
| - resources/js/services/PosSyncService.js (pos_fallback_polling) via meta tag
| - app/Console/Commands/StockScanRupture.php (auto_86_preventive_cron)
| - app/Http/Requests/OrderRequest.php (composer_profile_version_check)
|
| Operational rule: every flag defaults to FALSE / safe-no-op so that this
| file alone does not change runtime behavior. Each flag is enabled only
| once its sentinel suite is green and its gate (if any) is cleared.
|
*/

return [

    /*
    |---------------------------------------------------------------------
    | Mission #1 — Catalog Sync convergence
    |---------------------------------------------------------------------
    */

    'unified_projection' => [
        // When true, PosMenuProjection::forBranch delegates to
        // MenuProjectionService::forChannel('pos', $branchId) instead of
        // legacy PosCategoryController array literal + ItemController::index.
        'enabled' => env('FK_CATALOG_UNIFIED_PROJECTION_ENABLED', false),

        // When true, both code paths are executed; the legacy response is
        // returned to the client but the unified payload is computed and
        // diffed in storage/logs/catalog-shadow-diff.log. Used to validate
        // parity before flipping `enabled` to true.
        'shadow_compare' => env('FK_CATALOG_UNIFIED_PROJECTION_SHADOW_COMPARE', false),

        // Panic kill-switch. When true, forces legacy path even if `enabled`
        // is true. Toggled via env without redeploy if production diverges.
        'kill_switch' => env('FK_CATALOG_UNIFIED_PROJECTION_KILL_SWITCH', false),
    ],

    'pos_fallback_polling' => [
        // When true, PosComponent.vue mounts a PosSyncService that polls
        // /api/admin/items every N ms while the Echo connection state is
        // DISCONNECTED. Symmetric with KdsSyncService.
        'enabled' => env('FK_CATALOG_POS_FALLBACK_POLLING_ENABLED', false),

        // Polling cadence when the Echo socket is in DISCONNECTED state.
        // Suspended (Infinity equivalent) while CONNECTED.
        'interval_ms_when_disconnected' => env('FK_CATALOG_POS_FALLBACK_INTERVAL_MS', 30_000),
    ],

    'oss_fallback_polling' => [
        // OSS (client screen) keeps polling even when websocket is connected,
        // but at a low cadence. In disconnected state, cadence is tightened.
        // This hardens the "orders in preparation/ready" display during WS drift.
        'enabled' => env('FK_CATALOG_OSS_FALLBACK_POLLING_ENABLED', true),
        'interval_ms_when_connected' => env('FK_CATALOG_OSS_FALLBACK_CONNECTED_INTERVAL_MS', 60_000),
        'interval_ms_when_disconnected' => env('FK_CATALOG_OSS_FALLBACK_DISCONNECTED_INTERVAL_MS', 5_000),
    ],

    'kds_fallback_polling' => [
        // KDS cadence tuning (defaults preserve existing behavior).
        // CONNECTED remains Infinity in KdsSyncService; these values apply
        // to degraded/disconnected windows and high-activity mode.
        'high_activity_base_ms' => env('FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS', 3_000),
        'high_activity_jitter_ms' => env('FK_CATALOG_KDS_HIGH_ACTIVITY_JITTER_MS', 1_000),
        'degraded_base_ms' => env('FK_CATALOG_KDS_DEGRADED_BASE_MS', 5_000),
        'degraded_jitter_ms' => env('FK_CATALOG_KDS_DEGRADED_JITTER_MS', 2_000),
        'disconnected_base_ms' => env('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', 10_000),
        'disconnected_jitter_ms' => env('FK_CATALOG_KDS_DISCONNECTED_JITTER_MS', 3_000),
    ],

    'pos_wizard_composer_aware' => [
        // [T-WC-POS-RUNTIME-01] When true, public/js/pos-wizard.js builds wizard
        // pages from the published composer profile (item.composer_profile.steps)
        // instead of the legacy heuristic buildSteps(data). Default false ensures
        // production-safe rollout via env flip.
        'enabled' => env('FK_POS_WIZARD_COMPOSER_AWARE_ENABLED', false),
    ],

    'channels_filter' => [
        // When true, ItemRequest validation requires `channels` to be a
        // non-empty array. Migration backfill (Vague 3) runs first.
        // Until then, NULL is accepted and triggers a server warning.
        'channels_required' => env('FK_CATALOG_CHANNELS_REQUIRED', false),

        // When true, ItemService::store/update emit a warning log
        // [catalog.channels-null] whenever an item or category is saved
        // with channels=NULL. Read-only: no behavioral change.
        'warn_on_null' => env('FK_CATALOG_WARN_CHANNELS_NULL', true),
    ],

    /*
    |---------------------------------------------------------------------
    | Mission #2 — Lifecycle UX hardening
    |---------------------------------------------------------------------
    */

    'warnings' => [
        // When true, ItemController::show response includes a `warnings`
        // array surfaced by CatalogWarningService (composer_unpublished,
        // channels_null, missing_photo, etc.). Frontend admin renders
        // them as non-blocking badges.
        'expose_to_admin_show' => env('FK_CATALOG_WARNINGS_EXPOSE_ADMIN', true),
    ],

    'auto_86_preventive_cron' => [
        // When true, php artisan stock:scan-rupture runs every 5 minutes
        // and flips item_branch_availability.is_available=false for items
        // whose stockable variations all reached on_hand <= 0.
        'enabled' => env('FK_CATALOG_AUTO_86_CRON_ENABLED', false),

        // Cron schedule expression (overridable for testing / staging
        // tuning). Default = every 5 minutes.
        'cron_expression' => env('FK_CATALOG_AUTO_86_CRON_EXPRESSION', '*/5 * * * *'),
    ],

    'composer_profile_version_check' => [
        // When true, OrderRequest accepts an optional
        // `composer_profile_version_at_open` integer; PricingService
        // (frozen — UNFROZEN VIA GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK)
        // compares it to the current published version and returns
        // HTTP 409 with a remediation payload on mismatch.
        //
        // ⚠️ Touching PricingService requires the gate brief to be cleared
        // in docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK.md.
        'enabled' => env('FK_CATALOG_COMPOSER_VERSION_CHECK_ENABLED', false),
    ],

    'item_deletion' => [
        // When true, ItemService::destroy refuses force-delete if any
        // OrderItem references the item. Soft-delete remains allowed.
        // Default true: protect fiscal history by default.
        'protect_force_delete_when_referenced' => env('FK_CATALOG_PROTECT_FORCE_DELETE', true),
    ],

    'stock_low_alert' => [
        // When true, an email + dashboard toast is emitted when a
        // stock_levels row crosses on_hand <= threshold_low (preventive).
        'enabled' => env('FK_CATALOG_STOCK_LOW_ALERT_ENABLED', false),

        // Throttle: do not re-alert for the same (branch_id, stockable)
        // pair within this window (seconds).
        'throttle_seconds' => env('FK_CATALOG_STOCK_LOW_ALERT_THROTTLE', 3600),
    ],

    'features' => [
        'wizard_per_item_demo' => [
            'enabled' => env('FEATURE_WIZARD_PER_ITEM_DEMO', false),
        ],
    ],

];
