<?php

namespace Tests\Feature\Multitenant;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use App\Models\Scopes\WizardProfileBranchScope;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [2026-05-18 PR-D blocker heal] Multi-tenant isolation for wizard profiles.
 *
 * The `item_wizard_profiles.branch_id_scope` column is nullable —
 * NULL means "global profile, all branches see it" and INT means "branch-
 * scoped". The default `BranchScope` would have produced 500 SQL on
 * `unknown column 'branch_id'` (verified by ultra-review PR-D blocker).
 * `WizardProfileBranchScope` (added today) targets the right column and
 * implements the "global OR mine" semantics.
 *
 * Test matrix:
 *   - Admin (branch_id=0)       sees: ALL (global + branch A + branch B)
 *   - Staff branch A            sees: global + branch A — NOT branch B
 *   - Staff branch B            sees: global + branch B — NOT branch A
 *   - Unauth console (no actor) sees: ALL (no filter — same as today)
 */
class WizardProfileBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $admin;
    protected User $staffA;
    protected User $staffB;
    protected Item $itemA;
    protected Item $itemB;
    protected Item $itemGlobal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');

        $this->staffA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->staffA->assignRole('POS Operator');

        $this->staffB = User::factory()->create(['branch_id' => $this->branchB->id]);
        $this->staffB->assignRole('POS Operator');

        // Three items (we don't really need branch scope on items for this
        // test — we test the profile scope) with one profile each.
        $cat = ItemCategory::factory()->create(['name' => 'Cat', 'status' => Status::ACTIVE]);
        $this->itemGlobal = Item::factory()->create(['item_category_id' => $cat->id, 'name' => 'GlobalItem', 'status' => Status::ACTIVE]);
        $this->itemA      = Item::factory()->create(['item_category_id' => $cat->id, 'name' => 'BranchAItem', 'status' => Status::ACTIVE]);
        $this->itemB      = Item::factory()->create(['item_category_id' => $cat->id, 'name' => 'BranchBItem', 'status' => Status::ACTIVE]);

        // Three profiles.
        ItemWizardProfile::create([
            'item_id' => $this->itemGlobal->id, 'template' => 'custom',
            'version' => 1, 'is_published' => true, 'published_at' => now(),
            'branch_id_scope' => null,
        ]);
        ItemWizardProfile::create([
            'item_id' => $this->itemA->id, 'template' => 'custom',
            'version' => 1, 'is_published' => true, 'published_at' => now(),
            'branch_id_scope' => $this->branchA->id,
        ]);
        ItemWizardProfile::create([
            'item_id' => $this->itemB->id, 'template' => 'custom',
            'version' => 1, 'is_published' => true, 'published_at' => now(),
            'branch_id_scope' => $this->branchB->id,
        ]);
    }

    public function test_admin_sees_all_profiles(): void
    {
        $this->actingAs($this->admin);
        $ids = ItemWizardProfile::query()->pluck('item_id')->all();
        $this->assertContains($this->itemGlobal->id, $ids);
        $this->assertContains($this->itemA->id, $ids);
        $this->assertContains($this->itemB->id, $ids);
    }

    public function test_staff_branch_a_sees_global_plus_own_branch_only(): void
    {
        $this->actingAs($this->staffA);
        $ids = ItemWizardProfile::query()->pluck('item_id')->all();
        $this->assertContains($this->itemGlobal->id, $ids, 'Global profile (branch_id_scope NULL) must be visible to staff A.');
        $this->assertContains($this->itemA->id, $ids, 'Branch-A-scoped profile must be visible to staff A.');
        $this->assertNotContains($this->itemB->id, $ids, 'Branch-B-scoped profile must NOT leak to staff A.');
    }

    public function test_staff_branch_b_sees_global_plus_own_branch_only(): void
    {
        $this->actingAs($this->staffB);
        $ids = ItemWizardProfile::query()->pluck('item_id')->all();
        $this->assertContains($this->itemGlobal->id, $ids);
        $this->assertContains($this->itemB->id, $ids);
        $this->assertNotContains($this->itemA->id, $ids);
    }

    public function test_scope_query_does_not_produce_unknown_column_sql_error(): void
    {
        // Sentinel : a naked BranchScope would 500 on `unknown column branch_id`.
        // The new WizardProfileBranchScope must reference `branch_id_scope`.
        $this->actingAs($this->staffA);
        $this->expectNotToPerformAssertions();
        ItemWizardProfile::query()->count(); // would throw QueryException if mis-wired
    }

    public function test_withoutGlobalScopes_still_returns_all_for_admin_internals(): void
    {
        // Service code that needs unscoped reads (e.g. publish pipeline) must
        // be able to bypass the scope.
        $this->actingAs($this->staffA);
        $count = ItemWizardProfile::query()
            ->withoutGlobalScope(WizardProfileBranchScope::class)
            ->count();
        $this->assertSame(3, $count, 'withoutGlobalScope must lift the filter and surface every profile.');
    }
}
