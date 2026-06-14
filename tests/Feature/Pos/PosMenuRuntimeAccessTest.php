<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\DefaultAccess;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosMenuRuntimeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_operator_can_read_runtime_menu_without_catalog_management_permission(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        $this->assertTrue($operator->can('pos'));
        $this->assertFalse($operator->can('items_show'));
        $this->assertFalse($operator->can('items_create'));

        [$categoryA, $categoryB, $itemA, $itemB] = $this->menuFixture();

        $categories = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/pos-category?paginate=0&status=' . Status::ACTIVE . '&surface=pos')
            ->assertOk()
            ->json('data');

        $this->assertNotNull(collect($categories)->firstWhere('id', $categoryA->id));
        $this->assertNotNull(collect($categories)->firstWhere('id', $categoryB->id));

        $rows = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&surface=pos&branch_id=' . $branch->id . '&item_category_id=' . $categoryA->id)
            ->assertOk()
            ->json('data');

        $ids = collect($rows)->pluck('id')->all();
        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids, 'A POS category must not leak products from another category.');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item/details/' . $itemA->id . '?surface=pos&branch_id=' . $branch->id)
            ->assertOk()
            ->assertJsonPath('data.id', $itemA->id)
            ->assertJsonPath('data.itemAttributes', []);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item/details/' . $itemA->id . '?surface=pos')
            ->assertOk()
            ->assertJsonPath('data.id', $itemA->id);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item/lookup-barcode/POS-RUNTIME-001')
            ->assertOk()
            ->assertJsonPath('data.id', $itemA->id);

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/item', [])
            ->assertForbidden();
    }

    public function test_pos_runtime_details_reject_items_hidden_from_pos_surface(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        [$category] = $this->menuFixture();

        $hidden = Item::factory()->create([
            'name' => 'Kiosk Only Runtime Item',
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
            'channels' => ['kiosk'],
            'is_available' => true,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item/details/' . $hidden->id . '?surface=pos&branch_id=' . $branch->id)
            ->assertNotFound();
    }

    /**
     * STOCK-RUPTURE (massive-2dot0 #15) — a POS operator MUST NOT be able to sell
     * (via barcode lookup) an item that is ruptured for THEIR branch. index()
     * applies a per-branch ItemBranchAvailability overlay; lookup-barcode bypassed
     * it, resolving a globally-available item 200 even when is_available=0 for the
     * operator's branch. The barcode endpoint must refuse (404) so the ruptured
     * item cannot be added — enforced server-side because pos-wizard.js is frozen.
     */
    public function test_pos_operator_cannot_lookup_barcode_of_item_ruptured_at_their_branch(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        [, , $itemA] = $this->menuFixture();

        // Sanity: available at this branch -> resolves 200.
        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item/lookup-barcode/POS-RUNTIME-001')
            ->assertOk()
            ->assertJsonPath('data.id', $itemA->id);

        // Mark ruptured for this branch.
        ItemBranchAvailability::create([
            'branch_id' => $branch->id,
            'item_id' => $itemA->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'daily_reset_at' => now()->toDateString(),
        ]);

        // Ruptured -> must NOT be sellable via barcode.
        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item/lookup-barcode/POS-RUNTIME-001')
            ->assertNotFound()
            ->assertJsonPath('error', 'branch_unavailable');
    }

    public function test_pos_runtime_menu_reads_force_cashier_branch_scope_even_if_forged(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branchA->id]);
        $operator->assignRole('POS Operator');
        [$category, , $itemA] = $this->menuFixture();

        ItemBranchAvailability::create([
            'branch_id' => $branchA->id,
            'item_id' => $itemA->id,
            'is_available' => false,
            'unavailable_reason' => 'branch_a_rupture',
            'daily_reset_at' => now()->toDateString(),
        ]);
        ItemBranchAvailability::create([
            'branch_id' => $branchB->id,
            'item_id' => $itemA->id,
            'is_available' => true,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $rows = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&surface=pos&branch_id=' . $branchB->id . '&item_category_id=' . $category->id)
            ->assertOk()
            ->json('data');

        $item = collect($rows)->firstWhere('id', $itemA->id);
        $this->assertNotNull($item);
        $this->assertFalse($item['is_available'], 'Forged branch_id must be ignored; POS must use the cashier branch availability.');
        $this->assertSame('branch_a_rupture', $item['availability_reason']);
    }

    public function test_pos_runtime_menu_reads_do_not_trip_mutation_throttle(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        [$categoryA, , $itemA] = $this->menuFixture();

        for ($i = 0; $i < 35; $i++) {
            $this->actingAs($operator, 'sanctum')
                ->getJson('/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&surface=pos&branch_id=' . $branch->id . '&item_category_id=' . $categoryA->id)
                ->assertOk();

            $this->actingAs($operator, 'sanctum')
                ->getJson('/api/admin/item/details/' . $itemA->id . '?surface=pos&branch_id=' . $branch->id)
                ->assertOk();
        }
    }

    public function test_non_pos_user_cannot_read_pos_runtime_categories(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?paginate=0&status=' . Status::ACTIVE . '&surface=pos')
            ->assertForbidden();
    }

    public function test_pos_operator_bootstrap_survives_missing_default_access_row(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        [$categoryA, $categoryB, $itemA, $itemB] = $this->menuFixture();

        DefaultAccess::where('user_id', $operator->id)->delete();
        $this->assertDatabaseMissing('default_access', [
            'user_id' => $operator->id,
            'name' => 'branch_id',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/default-access')
            ->assertOk()
            ->assertJsonPath('data.branch_id', $branch->id);

        $categories = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/pos-category?paginate=0&status=' . Status::ACTIVE . '&surface=pos')
            ->assertOk()
            ->json('data');

        $this->assertNotNull(collect($categories)->firstWhere('id', $categoryA->id));
        $this->assertNotNull(collect($categories)->firstWhere('id', $categoryB->id));

        $itemResponse = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&surface=pos&item_category_id=' . $categoryA->id);
        $this->assertSame(200, $itemResponse->status(), $itemResponse->getContent());
        $rows = $itemResponse->json('data');

        $ids = collect($rows)->pluck('id')->all();
        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids, 'POS bootstrap must keep category filtering exact without a saved default_access row.');
    }

    /**
     * @return array{0: ItemCategory, 1: ItemCategory, 2: Item, 3: Item}
     */
    private function menuFixture(): array
    {
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $categoryA = ItemCategory::factory()->create([
            'name' => 'POS Runtime A',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);
        $categoryB = ItemCategory::factory()->create([
            'name' => 'POS Runtime B',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $itemA = Item::factory()->create([
            'name' => 'POS Runtime Item A',
            'barcode' => 'POS-RUNTIME-001',
            'item_category_id' => $categoryA->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'channels' => null,
            'is_available' => true,
        ]);
        $itemB = Item::factory()->create([
            'name' => 'POS Runtime Item B',
            'barcode' => 'POS-RUNTIME-002',
            'item_category_id' => $categoryB->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'channels' => null,
            'is_available' => true,
        ]);

        return [$categoryA, $categoryB, $itemA, $itemB];
    }
}
