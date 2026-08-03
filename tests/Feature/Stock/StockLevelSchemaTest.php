<?php

namespace Tests\Feature\Stock;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockLevelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_levels_schema_and_unique_stockable_tuple(): void
    {
        $this->assertTrue(Schema::hasTable('stock_levels'));
        foreach (['branch_id', 'stockable_type', 'stockable_id', 'on_hand', 'reserved', 'threshold_low'] as $column) {
            $this->assertTrue(Schema::hasColumn('stock_levels', $column), "{$column} column missing");
        }

        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => 'item',
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);

        $this->expectException(QueryException::class);

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => 'item',
            'stockable_id' => $item->id,
            'on_hand' => 5,
            'reserved' => 0,
        ]);
    }

    public function test_stock_level_rejects_reserved_greater_than_on_hand(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockLevel::factory()->create([
            'on_hand' => 1,
            'reserved' => 2,
        ]);
    }
}
