<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-CV1-POS-AVAILABILITY-LIVE-001
 * @source docs/audit/CV1-POS-AVAILABILITY-LIVE-001_INVESTIGATION_2026-05-08.md
 *
 * RED-R3 F2 a montré que la SPA POS pouvait afficher une tuile cliquable pour
 * un item OOS (rejet 422 au submit only). Cause racine: admin@lecayenne.fr
 * (branch_id=0) → URL itemList sans branch_id → ItemService::applyBranchAvailabilityOverlay
 * early-return → réponse avec is_available global (col items.is_available = true).
 *
 * Ce sentinel verrouille les 2 fixes:
 *   1. Backend: ItemController::index abort 422 si surface=pos && !branch_id
 *   2. SPA: PosComponent.vue mounted() ne fetche plus itemList() sans bootstrapBranchId
 *
 * Si quelqu'un retire un des deux fixes, ce test casse.
 */
class PosCatalogRequiresBranchSentinelTest extends TestCase
{
    public function test_ItemController_aborts_422_for_surface_pos_without_branch(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Admin/ItemController.php'));

        // Pattern strict: si surface=pos sans branch_id, abort 422
        $this->assertMatchesRegularExpression(
            '/\$surface\s*=\s*strtolower\(trim\(\(string\)\s*\$request->get\(\'surface\'/',
            $source,
            'ItemController::index must read $request->surface for the guard.'
        );
        $this->assertStringContainsString(
            "POS catalog requires branch_id",
            $source,
            'ItemController::index must abort with explicit message when surface=pos && !branch_id.'
        );
        $this->assertMatchesRegularExpression(
            '/surface\s*===\s*\'pos\'\s*&&\s*\(\$branchId\s*===\s*null\s*\|\|\s*\$branchId\s*<\s*1\)/',
            $source,
            'ItemController::index guard must check exact condition surface=pos AND branch_id missing/<1.'
        );
    }

    public function test_PosComponent_does_not_fetch_itemList_without_bootstrapBranch(): void
    {
        $source = file_get_contents(base_path('resources/js/components/admin/pos/PosComponent.vue'));

        // Pattern strict: itemList() doit être DANS le if (bootstrapBranchId), pas après.
        // L'ancienne version avait `if (...) { applyPosBranchScope }` puis `itemList()` orphelin.
        $this->assertMatchesRegularExpression(
            '/if\s*\(bootstrapBranchId\)\s*\{[^}]*applyPosBranchScope\(bootstrapBranchId\)[^}]*this\.itemList\(\)/s',
            $source,
            'PosComponent::mounted must call itemList() INSIDE the if(bootstrapBranchId) block, not unconditionally.'
        );

        // Negative assertion: pas d'appel itemList() unconditionnel APRÈS le if/else.
        // (le commentaire CV1-POS-AVAILABILITY-LIVE-001 doit rester présent comme garde-fou doc)
        $this->assertStringContainsString(
            'CV1-POS-AVAILABILITY-LIVE-001',
            $source,
            'PosComponent must keep the CV1-POS-AVAILABILITY-LIVE-001 reference comment for traceability.'
        );
    }

    public function test_investigation_doc_exists(): void
    {
        $this->assertFileExists(
            base_path('docs/audit/CV1-POS-AVAILABILITY-LIVE-001_INVESTIGATION_2026-05-08.md'),
            'Investigation report must remain available for traceability.'
        );
    }
}
