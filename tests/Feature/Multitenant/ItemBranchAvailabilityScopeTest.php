<?php

namespace Tests\Feature\Multitenant;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [2026-05-18 PR-D T7 heal — ItemBranchAvailability multi-tenant gap]
 *
 * Before this commit, `ItemBranchAvailability` was readable across all
 * branches by any authenticated staff query (Eloquent `query()` without
 * an explicit `where('branch_id', ...)`). Per ultra-review PR-D F-D2,
 * V1 single-restaurant deploys don't surface the leak, but the V1.0.2
 * multi-restaurant deploy would silently expose Branch B's 86-rupture
 * matrix to Branch A's stock-rupture dashboard.
 *
 * The standard `BranchScope` is correct for this table (column = `branch_id`
 * strict equality, no nullable-global semantics like `ItemWizardProfile`).
 * Admin (branch_id=0) bypasses.
 */
class ItemBranchAvailabilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $admin;
    protected User $staffA;
    protected User $staffB;
    protected Item $item;
    protected ItemBranchAvailability $availA;
    protected ItemBranchAvailability $availB;

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

        $cat = ItemCategory::factory()->create(['name' => 'Cat', 'status' => Status::ACTIVE]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'name' => 'ScopedItem',
            'status' => Status::ACTIVE,
        ]);

        $this->availA = ItemBranchAvailability::create([
            'item_id' => $this->item->id,
            'branch_id' => $this->branchA->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'daily_reset_at' => now()->toDateString(),
        ]);
        $this->availB = ItemBranchAvailability::create([
            'item_id' => $this->item->id,
            'branch_id' => $this->branchB->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'daily_reset_at' => now()->toDateString(),
        ]);
    }

    public function test_admin_sees_all_branch_rows(): void
    {
        $this->actingAs($this->admin);
        $ids = ItemBranchAvailability::query()->pluck('id')->all();
        $this->assertContains($this->availA->id, $ids);
        $this->assertContains($this->availB->id, $ids);
    }

    public function test_staff_branch_a_only_sees_own_branch(): void
    {
        $this->actingAs($this->staffA);
        $ids = ItemBranchAvailability::query()->pluck('id')->all();
        $this->assertContains($this->availA->id, $ids, 'Staff A must see own-branch availability.');
        $this->assertNotContains($this->availB->id, $ids, 'Branch-B availability must not leak to staff A.');
    }

    public function test_staff_branch_b_only_sees_own_branch(): void
    {
        $this->actingAs($this->staffB);
        $ids = ItemBranchAvailability::query()->pluck('id')->all();
        $this->assertContains($this->availB->id, $ids);
        $this->assertNotContains($this->availA->id, $ids);
    }

    public function test_withoutGlobalScopes_lets_console_callers_see_all_branches(): void
    {
        // Console commands (StockScanRupture etc.) need cross-branch reads.
        $this->actingAs($this->staffA);
        $all = ItemBranchAvailability::query()
            ->withoutGlobalScope(BranchScope::class)
            ->pluck('id')
            ->all();
        $this->assertContains($this->availA->id, $all);
        $this->assertContains($this->availB->id, $all);
    }
}
