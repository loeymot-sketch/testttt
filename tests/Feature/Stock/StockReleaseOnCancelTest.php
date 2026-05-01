<?php

namespace Tests\Feature\Stock;

use App\Events\OrderCanceled;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockReleaseOnCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_canceled_event_releases_decremented_stock_once(): void
    {
        [$order, $item] = $this->orderWithStock(2);

        app(StockService::class)->decrementForOrder($order);
        $this->assertSame(1, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));

        OrderCanceled::dispatch($order);
        OrderCanceled::dispatch($order);

        $this->assertSame(2, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
    }

    private function orderWithStock(int $onHand): array
    {
        $order = Order::factory()->create();
        $item = Item::factory()->create();
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
        StockLevel::query()->create([
            'branch_id' => $order->branch_id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$order, $item];
    }
}
