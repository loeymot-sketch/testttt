<?php

namespace Tests\Feature\Menu;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sentinel — Mission #1 Vague 2 action 2.5.
 *
 * For a given branch, asserts that the set of items + categories visible
 * on POS and on Kiosk is reconciled — same item ids appear on both
 * surfaces unless explicitly restricted by `channels`.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §A.1 #2
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md tasks 2.4 → 2.5
 *
 * Contract once unskipped:
 *
 *   1. For an item with channels=NULL (back-compat) on a branch where it
 *      is_available=true, both POS and Kiosk projections list it.
 *
 *   2. For an item with channels=['kiosk'] only, POS does NOT list it but
 *      Kiosk does.
 *
 *   3. For an item with channels=['pos'] only, Kiosk does NOT list it but
 *      POS does.
 *
 *   4. For an item available globally but with item_branch_availability(branch=X)
 *      is_available=false, neither POS nor Kiosk list it as orderable
 *      (POS may still list with disabled state — to confirm in plan).
 *
 *   5. The category set on POS is a (possibly improper) subset of the
 *      category set on Kiosk minus the kiosk-only categories.
 */
class PosKioskProjectionParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_visible_on_both_when_channels_null(): void
    {
        $this->markTestSkipped('Pending plan tasks 2.4 and 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_kiosk_only_item_not_on_pos(): void
    {
        $this->markTestSkipped('Pending plan tasks 2.4 and 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_pos_only_item_not_on_kiosk(): void
    {
        $this->markTestSkipped('Pending plan tasks 2.4 and 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_branch_unavailable_item_disabled_on_both(): void
    {
        $this->markTestSkipped('Pending plan tasks 2.4 and 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_pos_categories_subset_of_kiosk(): void
    {
        $this->markTestSkipped('Pending plan tasks 2.4 and 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }
}
