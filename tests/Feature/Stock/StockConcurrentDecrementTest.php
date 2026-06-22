<?php

namespace Tests\Feature\Stock;

use App\Exceptions\Stock\StockUnavailableException;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockConcurrentDecrementTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_guard_allows_only_available_quantity(): void
    {
        $item = Item::factory()->create();
        $branchId = null;
        StockLevel::query()->create([
            'branch_id' => ($branchId = Order::factory()->create()->branch_id),
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 3,
            'reserved' => 0,
        ]);

        $success = 0;
        $failures = 0;
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()->create(['branch_id' => $branchId]);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'branch_id' => $branchId,
                'item_id' => $item->id,
                'quantity' => 1,
                'price' => 1,
                'discount' => 0,
                'total_price' => 1,
                'item_variations' => json_encode([]),
                'item_extras' => json_encode([]),
            ]);

            try {
                app(StockService::class)->decrementForOrder($order);
                $success++;
            } catch (StockUnavailableException) {
                $failures++;
            }
        }

        $this->assertSame(3, $success);
        $this->assertSame(2, $failures);
        $this->assertSame(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
    }

    public function test_stress_guard_allows_only_20_successes_across_50_attempts(): void
    {
        $item = Item::factory()->create();
        $branchId = Order::factory()->create()->branch_id;

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 20,
            'reserved' => 0,
        ]);

        $success = 0;
        $failures = 0;

        for ($i = 0; $i < 50; $i++) {
            $order = Order::factory()->create(['branch_id' => $branchId]);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'branch_id' => $branchId,
                'item_id' => $item->id,
                'quantity' => 1,
                'price' => 1,
                'discount' => 0,
                'total_price' => 1,
                'item_variations' => json_encode([]),
                'item_extras' => json_encode([]),
            ]);

            try {
                app(StockService::class)->decrementForOrder($order);
                $success++;
            } catch (StockUnavailableException) {
                $failures++;
            }
        }

        $this->assertSame(20, $success);
        $this->assertSame(30, $failures);
        $this->assertSame(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(20, StockMovement::query()->where('branch_id', $branchId)->where('delta', -1)->count());
    }

    public function test_failed_multi_line_decrement_rolls_back_prior_stock_changes(): void
    {
        $order = Order::factory()->create();
        $availableItem = Item::factory()->create();
        $emptyItem = Item::factory()->create();

        foreach ([$availableItem, $emptyItem] as $item) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'item_id' => $item->id,
                'quantity' => 1,
                'price' => 1,
                'discount' => 0,
                'total_price' => 1,
                'item_variations' => json_encode([]),
                'item_extras' => json_encode([]),
            ]);
        }

        StockLevel::query()->create([
            'branch_id' => $order->branch_id,
            'stockable_type' => Item::class,
            'stockable_id' => $availableItem->id,
            'on_hand' => 1,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $order->branch_id,
            'stockable_type' => Item::class,
            'stockable_id' => $emptyItem->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        try {
            app(StockService::class)->decrementForOrder($order);
            $this->fail('Expected stock decrement to reject the empty line.');
        } catch (StockUnavailableException) {
            $this->assertSame(1, (int) StockLevel::where('stockable_id', $availableItem->id)->value('on_hand'));
            $this->assertSame(0, StockMovement::count());
        }
    }
}
