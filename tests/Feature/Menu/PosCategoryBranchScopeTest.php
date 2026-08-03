<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sentinel — Mission #1 Vague 1 action 1.1.
 *
 * GET /api/admin/pos-category must respect active POS branch scope for POS-only
 * runtime users while keeping Admin / Tenant Admin and Branch Manager planning
 * views global.
 */
class PosCategoryBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $this->tax = Tax::factory()->create(['status' => Status::ACTIVE]);
    }

    public function test_pos_operator_on_branch_a_sees_only_branch_a_categories(): void
    {
        [$branchA, , $categories] = $this->seedBranchScopedCatalog();
        $operator = $this->posOperatorForBranch($branchA);

        $payload = $this->posCategoryPayloadFor($operator);
        $names = collect($payload)->pluck('name')->all();

        $this->assertSame(0, $payload[0]['id']);
        $this->assertContains($categories['branch_a']->name, $names);
        $this->assertNotContains($categories['branch_b']->name, $names);
        $this->assertNotContains($categories['unavailable']->name, $names);
        $this->assertNotContains($categories['item_kiosk_only']->name, $names);
        $this->assertNotContains($categories['category_kiosk_only']->name, $names);
    }

    public function test_pos_operator_on_branch_b_sees_only_branch_b_categories(): void
    {
        [, $branchB, $categories] = $this->seedBranchScopedCatalog();
        $operator = $this->posOperatorForBranch($branchB);

        $payload = $this->posCategoryPayloadFor($operator);
        $names = collect($payload)->pluck('name')->all();

        $this->assertSame(0, $payload[0]['id']);
        $this->assertNotContains($categories['branch_a']->name, $names);
        $this->assertContains($categories['branch_b']->name, $names);
        $this->assertNotContains($categories['unavailable']->name, $names);
        $this->assertNotContains($categories['item_kiosk_only']->name, $names);
        $this->assertNotContains($categories['category_kiosk_only']->name, $names);
    }

    public function test_tenant_admin_and_branch_manager_keep_global_pos_category_view(): void
    {
        [$branchA, , $categories] = $this->seedBranchScopedCatalog();

        $tenantAdminRole = Role::firstOrCreate(['name' => 'Tenant Admin', 'guard_name' => 'sanctum']);
        $tenantAdminRole->givePermissionTo('items_show');

        $tenantAdmin = User::factory()->create(['branch_id' => 0]);
        $tenantAdmin->assignRole($tenantAdminRole);
        $this->assertTrue($tenantAdmin->can('items_show'));

        $branchManager = User::factory()->create(['branch_id' => $branchA->id]);
        $branchManager->assignRole('Branch Manager');
        $this->assertTrue($branchManager->can('pos'));
        $this->assertFalse($branchManager->can('items_show'));

        foreach ([$tenantAdmin, $branchManager] as $user) {
            $payload = $this->posCategoryPayloadFor($user);
            $names = collect($payload)->pluck('name')->all();

            $this->assertSame(0, $payload[0]['id']);
            $this->assertContains($categories['branch_a']->name, $names);
            $this->assertContains($categories['branch_b']->name, $names);
            $this->assertContains($categories['unavailable']->name, $names);
            $this->assertContains($categories['item_kiosk_only']->name, $names);
            $this->assertNotContains($categories['category_kiosk_only']->name, $names);
        }
    }

    /**
     * @return array{0: Branch, 1: Branch, 2: array<string, ItemCategory>}
     */
    private function seedBranchScopedCatalog(): array
    {
        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);

        $branchACategory = $this->category('CV1 Branch A Pos', ['pos']);
        $branchBCategory = $this->category('CV1 Branch B Legacy', null);
        $unavailableCategory = $this->category('CV1 Branch Unavailable', ['pos']);
        $itemKioskOnlyCategory = $this->category('CV1 Item Kiosk Only', null);
        $categoryKioskOnly = $this->category('CV1 Category Kiosk Only', ['kiosk']);

        $branchAItem = $this->item('CV1 Item A', $branchACategory, ['pos']);
        $branchBItem = $this->item('CV1 Item B', $branchBCategory, null);
        $unavailableItem = $this->item('CV1 Item Unavailable', $unavailableCategory, ['pos']);
        $itemKioskOnly = $this->item('CV1 Item Kiosk Surface', $itemKioskOnlyCategory, ['kiosk']);
        $this->item('CV1 Item Category Kiosk Surface', $categoryKioskOnly, ['pos']);

        $this->availability($branchAItem, $branchB, false);
        $this->availability($branchBItem, $branchA, false);
        $this->availability($unavailableItem, $branchA, false);
        $this->availability($unavailableItem, $branchB, false);
        $this->availability($itemKioskOnly, $branchA, true);
        $this->availability($itemKioskOnly, $branchB, true);

        return [
            $branchA,
            $branchB,
            [
                'branch_a' => $branchACategory,
                'branch_b' => $branchBCategory,
                'unavailable' => $unavailableCategory,
                'item_kiosk_only' => $itemKioskOnlyCategory,
                'category_kiosk_only' => $categoryKioskOnly,
            ],
        ];
    }

    private function category(string $name, ?array $channels): ItemCategory
    {
        return ItemCategory::factory()->create([
            'name' => $name,
            'status' => Status::ACTIVE,
            'channels' => $channels,
        ]);
    }

    private function item(string $name, ItemCategory $category, ?array $channels): Item
    {
        return Item::factory()->create([
            'name' => $name,
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'status' => Status::ACTIVE,
            'channels' => $channels,
        ]);
    }

    private function availability(Item $item, Branch $branch, bool $isAvailable): void
    {
        ItemBranchAvailability::create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => $isAvailable,
            'unavailable_reason' => $isAvailable ? null : 'sentinel',
            'unavailable_since' => $isAvailable ? null : now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);
    }

    private function posOperatorForBranch(Branch $branch): User
    {
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $this->assertTrue($operator->can('pos'));
        $this->assertFalse($operator->can('items_show'));

        return $operator;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posCategoryPayloadFor(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?paginate=0&status=' . Status::ACTIVE)
            ->assertOk()
            ->json('data');
    }
}
