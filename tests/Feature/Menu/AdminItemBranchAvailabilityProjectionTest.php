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
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class AdminItemBranchAvailabilityProjectionTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    public function test_admin_item_list_projects_effective_branch_availability_for_pos(): void
    {
        $this->seedSpatieRoles();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $branch = Branch::forceCreate([
            'name' => 'POS Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue POS',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10 POS',
            'code' => 'TVAPOS' . uniqid(),
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'POS Cat',
            'slug' => 'pos-cat-' . uniqid(),
            'status' => Status::ACTIVE,
            'channels' => ['pos'],
        ]);

        $item = Item::forceCreate([
            'name' => 'POS Burger',
            'slug' => 'pos-burger-' . uniqid(),
            'price' => 9.90,
            'status' => Status::ACTIVE,
            'is_available' => true,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        ItemBranchAvailability::create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
            'max_daily_qty' => null,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&branch_id=' . $branch->id);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $item->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['is_available']);
        $this->assertSame('stock_rupture', $row['availability_reason']);
    }
}
