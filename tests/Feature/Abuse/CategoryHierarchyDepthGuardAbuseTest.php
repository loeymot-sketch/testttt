<?php

namespace Tests\Feature\Abuse;

use App\Models\ItemCategory;
use App\Models\User;
use App\Services\ItemCategoryHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ABUSE #2 — category hierarchy depth validation TOCTOU (P2).
 *
 * ItemCategoryService::store()/update() called the hierarchy validateParent()
 * OUTSIDE the DB::transaction, with no row lock on the parent. Two concurrent
 * "set parent" updates could therefore EACH pass validation (each sees the
 * pre-change state) and then both commit, producing depth > 2 or a cycle that
 * the rule was meant to forbid.
 *
 * The fix relocates the validation INSIDE the transaction and takes a
 * ->lockForUpdate() on the parent row so the second writer serializes behind
 * the first and re-validates against its pending change.
 *
 * NOTE on the TOCTOU race: a true concurrent race needs two real DB
 * connections holding FOR UPDATE locks, which is not deterministic under
 * SQLite :memory: (single connection, no real row locks). These tests prove
 * the GUARD itself (depth > 2, cycle, self-parent are rejected) and that it
 * still fires from inside the relocated/locked transaction path — i.e. the
 * relocation did not weaken the existing depth-2 rule. The lock is the
 * concurrency hardening; the guard is what we lock down here.
 *
 * @see app/Services/ItemCategoryService.php
 * @see app/Services/ItemCategoryHierarchyService.php
 */
class CategoryHierarchyDepthGuardAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);
    }

    private function endpoint(ItemCategory $category): string
    {
        return '/api/admin/setting/item-category/' . $category->id;
    }

    /** @test */
    public function it_rejects_making_a_third_level_via_the_service_transaction_path(): void
    {
        // A (root) -> B (child). Now try to give a third category C a parent of B,
        // which would make C a 3rd level. Must be rejected from inside the txn.
        $root = ItemCategory::factory()->create(['name' => 'Niveau A']);
        $child = ItemCategory::factory()->create(['name' => 'Niveau B', 'parent_id' => $root->id]);
        $grandchild = ItemCategory::factory()->create(['name' => 'Niveau C']);

        $response = $this->putJson($this->endpoint($grandchild), [
            'name'      => 'Niveau C',
            'parent_id' => $child->id,
            'status'    => 1,
        ]);

        $response->assertStatus(422);
        // Persistence must be unchanged — C did NOT get re-parented under B.
        $this->assertNull($grandchild->fresh()->parent_id);
    }

    /** @test */
    public function it_rejects_a_self_parent(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Solo']);

        $response = $this->putJson($this->endpoint($category), [
            'name'      => 'Solo',
            'parent_id' => $category->id,
            'status'    => 1,
        ]);

        $response->assertStatus(422);
        $this->assertNull($category->fresh()->parent_id);
    }

    /**
     * Direct A->B->A cycle attempt at the service-rule level (the FormRequest
     * notIn() blocks self-parent, but the cycle/depth guard is the deeper net).
     *
     * @test
     */
    public function it_rejects_a_cycle_a_b_a_at_the_guard_level(): void
    {
        // A is root, B is A's child. Attempt to set A's parent to B -> cycle A->B->A.
        $a = ItemCategory::factory()->create(['name' => 'Cycle A']);
        $b = ItemCategory::factory()->create(['name' => 'Cycle B', 'parent_id' => $a->id]);

        $service = app(ItemCategoryHierarchyService::class);

        $this->expectException(\InvalidArgumentException::class);
        // Parent B already has a parent (A) -> depth rule fires first ("limitee a deux niveaux"),
        // which itself prevents the cycle. Either guard message is acceptable.
        $service->validateParent((int) $b->id, (int) $a->id);
    }

    /** @test */
    public function it_still_allows_a_legitimate_two_level_assignment(): void
    {
        // Root + a fresh category re-parented under the root = depth 2, must succeed.
        $root = ItemCategory::factory()->create(['name' => 'Bon Parent']);
        $leaf = ItemCategory::factory()->create(['name' => 'Bon Enfant']);

        $response = $this->putJson($this->endpoint($leaf), [
            'name'      => 'Bon Enfant',
            'parent_id' => $root->id,
            'status'    => 1,
        ]);

        $this->assertContains($response->status(), [200, 201, 202], 'Body: ' . $response->getContent());
        $this->assertSame((int) $root->id, (int) $leaf->fresh()->parent_id);
    }
}
