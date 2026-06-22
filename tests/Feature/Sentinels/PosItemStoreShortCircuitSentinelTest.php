<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID Wave T R3 F1 — silent 422 on POS landing
 * @source reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-2/wave-A-findings.json (WT-A-R2-002)
 *
 * Wave T R2 found that POS landing for admin@lecayenne.fr (branch_id=0)
 * triggered a SILENT 422 on /api/admin/item?status=5&surface=pos. The
 * controller guard at app/Http/Controllers/Admin/ItemController.php:60-64
 * was correct (NF525-adjacent CV1-POS-AVAILABILITY-LIVE-001) — relaxing it
 * would re-introduce false-positive tile clickability + revenue loss. The
 * heal short-circuits the Vuex `item/lists` action when `surface=pos` AND
 * no usable `branch_id` resolves: it returns an empty payload synchronously
 * so no doomed network request fires. The post-defaultAccess refetch (with
 * the resolved branch) then populates the catalog normally.
 *
 * Defense-in-depth: bootstrap.js extended its axios response interceptor to
 * also toast 4xx on critical paths (/api/admin/item, /api/admin/fiscal/) so
 * that any future regression that re-introduces a silent 4xx on the SSOT
 * surfaces visibly to the operator.
 *
 * This sentinel locks both:
 *   1. Vuex `item/lists` short-circuits when surface=pos + no branch_id.
 *   2. bootstrap.js declares the critical-path 4xx pattern set.
 *   3. i18n keys `error.catalog_unavailable` + `error.fiscal_unavailable` exist.
 *
 * If anyone removes either layer the test breaks.
 */
class PosItemStoreShortCircuitSentinelTest extends TestCase
{
    public function test_item_store_lists_short_circuits_pos_surface_without_branch(): void
    {
        $source = file_get_contents(base_path('resources/js/store/modules/item.js'));

        // The short-circuit must check both surface=pos AND no usable branch_id.
        $this->assertStringContainsString(
            "declaredSurface === 'pos'",
            $source,
            'item/lists must short-circuit when surface=pos with no usable branch_id (prevents silent 422 on POS landing).'
        );

        $this->assertStringContainsString(
            'hasUsableBranchId',
            $source,
            'item/lists short-circuit must compute hasUsableBranchId from resolved payload.'
        );

        $this->assertStringContainsString(
            'pos_surface_requires_branch_id',
            $source,
            'item/lists short-circuit must tag the skipped resolution with reason="pos_surface_requires_branch_id".'
        );

        // The short-circuit must `return` (not throw) so the caller chain
        // continues normally — PosComponent does .then(()=>{}).catch(()=>{}) and
        // a throw would land in catch unnecessarily.
        $this->assertMatchesRegularExpression(
            "/declaredSurface === 'pos'.*hasUsableBranchId.*resolve\(.*pos_surface_requires_branch_id/s",
            $source,
            'item/lists short-circuit must resolve() with empty payload, not reject.'
        );

        // Reference to the CV1 guard comment for traceability.
        $this->assertStringContainsString(
            'CV1-POS-AVAILABILITY-LIVE-001',
            $source,
            'item/lists short-circuit must reference CV1-POS-AVAILABILITY-LIVE-001 for traceability.'
        );
    }

    public function test_bootstrap_interceptor_toasts_critical_path_4xx(): void
    {
        $source = file_get_contents(base_path('resources/js/bootstrap.js'));

        $this->assertStringContainsString(
            '_CRITICAL_4XX_PATTERNS',
            $source,
            'bootstrap.js must define _CRITICAL_4XX_PATTERNS for critical-path 4xx toast layer.'
        );

        $this->assertStringContainsString(
            "'/api/admin/item'",
            $source,
            'bootstrap.js critical-path patterns must include /api/admin/item (POS catalog SSOT).'
        );

        $this->assertStringContainsString(
            "'/api/admin/fiscal/'",
            $source,
            'bootstrap.js critical-path patterns must include /api/admin/fiscal/ (NF525 chain endpoints).'
        );

        $this->assertStringContainsString(
            'isCriticalPathError',
            $source,
            'bootstrap.js interceptor must compute isCriticalPathError and toast it.'
        );

        $this->assertStringContainsString(
            "error.catalog_unavailable",
            $source,
            'bootstrap.js interceptor must reference error.catalog_unavailable i18n key for /api/admin/item 4xx.'
        );

        $this->assertStringContainsString(
            "error.fiscal_unavailable",
            $source,
            'bootstrap.js interceptor must reference error.fiscal_unavailable i18n key for /api/admin/fiscal/ 4xx.'
        );

        // Confirm 401 is still excluded (auth flow is router-handled).
        $this->assertMatchesRegularExpression(
            '/status\s*!==\s*401\s*&&\s*status\s*>=\s*400\s*&&\s*status\s*<\s*500/',
            $source,
            'bootstrap.js critical-path 4xx detector must exclude 401 (auth flow is handled by router push to /login).'
        );
    }

    public function test_i18n_keys_for_critical_path_toasts_exist(): void
    {
        $fr = json_decode(file_get_contents(base_path('resources/js/languages/fr.json')), true);
        $en = json_decode(file_get_contents(base_path('resources/js/languages/en.json')), true);

        $this->assertArrayHasKey('catalog_unavailable', $fr['error'] ?? [], 'fr.json must define error.catalog_unavailable.');
        $this->assertArrayHasKey('fiscal_unavailable', $fr['error'] ?? [], 'fr.json must define error.fiscal_unavailable.');
        $this->assertArrayHasKey('catalog_unavailable', $en['error'] ?? [], 'en.json must define error.catalog_unavailable.');
        $this->assertArrayHasKey('fiscal_unavailable', $en['error'] ?? [], 'en.json must define error.fiscal_unavailable.');

        // FR messages must include "actualisez" or "manager" to be operator-actionable.
        $this->assertMatchesRegularExpression(
            '/actualisez|recharg|rafra|page/iu',
            $fr['error']['catalog_unavailable'],
            'FR catalog_unavailable must be operator-actionable.'
        );
        $this->assertMatchesRegularExpression(
            '/manager|alert/iu',
            $fr['error']['fiscal_unavailable'],
            'FR fiscal_unavailable must direct to manager escalation.'
        );
    }

    public function test_controller_guard_still_returns_422_for_safety(): void
    {
        // Defense-in-depth: even with the frontend short-circuit, the backend
        // guard at ItemController.php:60-64 must STILL return 422 if any
        // future caller bypasses our store action (e.g. a direct axios call).
        // The PosCatalogRequiresBranchSentinelTest already covers this — we
        // re-assert here only the bare minimum so this sentinel stays
        // self-contained.
        $source = file_get_contents(base_path('app/Http/Controllers/Admin/ItemController.php'));
        $this->assertStringContainsString(
            'POS catalog requires branch_id',
            $source,
            'ItemController guard MUST remain — frontend short-circuit is the immediate fix; the backend guard is the safety net.'
        );
    }
}
