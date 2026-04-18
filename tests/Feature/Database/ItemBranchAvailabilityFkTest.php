<?php

namespace Tests\Feature\Database;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemBranchAvailabilityFkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $driver = config('database.default');
        if (config("database.connections.$driver.driver") !== 'mysql') {
            $this->markTestSkipped('MySQL-only: ALTER TABLE foreign keys are asserted in CI on MySQL.');
        }
    }

    public function test_cascade_on_item_delete(): void
    {
        $item = Item::factory()->create();
        $branch = Branch::factory()->create();

        $availability = ItemBranchAvailability::query()->create([
            'item_id'            => $item->id,
            'branch_id'          => $branch->id,
            'is_available'       => false,
            'unavailable_reason' => 'out_of_stock',
            'daily_reset_at'     => now()->toDateString(),
        ]);

        $this->assertDatabaseHas('item_branch_availability', [
            'id'      => $availability->id,
            'item_id' => $item->id,
        ]);

        $item->forceDelete();

        $this->assertDatabaseMissing('item_branch_availability', [
            'id' => $availability->id,
        ]);
    }
}
