<?php

namespace Tests\Feature\Stock;

use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementsAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movements_are_append_only(): void
    {
        $level = StockLevel::factory()->create();
        $movement = StockMovement::query()->create([
            'stock_level_id' => $level->id,
            'branch_id' => $level->branch_id,
            'delta' => -1,
            'reason' => 'order_created',
            'idempotency_key' => 'order-1-line-1',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $movement->update(['delta' => -2]);
    }

    public function test_stock_movements_cannot_be_deleted(): void
    {
        $level = StockLevel::factory()->create();
        $movement = StockMovement::query()->create([
            'stock_level_id' => $level->id,
            'branch_id' => $level->branch_id,
            'delta' => 1,
            'reason' => 'manual_in',
            'idempotency_key' => 'manual-1',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $movement->delete();
    }

    public function test_stock_movement_idempotency_key_is_unique_when_present(): void
    {
        $level = StockLevel::factory()->create();
        StockMovement::query()->create([
            'stock_level_id' => $level->id,
            'branch_id' => $level->branch_id,
            'delta' => -1,
            'reason' => 'order_created',
            'idempotency_key' => 'same-key',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        StockMovement::query()->create([
            'stock_level_id' => $level->id,
            'branch_id' => $level->branch_id,
            'delta' => -1,
            'reason' => 'order_created',
            'idempotency_key' => 'same-key',
            'created_at' => now(),
        ]);
    }
}
