<?php

namespace Tests\Feature\Stock;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_stockable_can_have_isolated_levels_per_branch(): void
    {
        $item = Item::factory()->create();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        StockLevel::factory()->create([
            'branch_id' => $branchA->id,
            'stockable_type' => 'item',
            'stockable_id' => $item->id,
            'on_hand' => 3,
        ]);
        StockLevel::factory()->create([
            'branch_id' => $branchB->id,
            'stockable_type' => 'item',
            'stockable_id' => $item->id,
            'on_hand' => 9,
        ]);

        $this->assertSame(3, StockLevel::forBranch($branchA->id)->firstOrFail()->on_hand);
        $this->assertSame(9, StockLevel::forBranch($branchB->id)->firstOrFail()->on_hand);
    }
}
