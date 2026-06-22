<?php

namespace Tests\Feature\Stock;

use App\Events\RefundCreated;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockReleaseOnRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_event_releases_decremented_stock_once(): void
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
            'on_hand' => 2,
            'reserved' => 0,
        ]);

        app(StockService::class)->decrementForOrder($order);
        RefundCreated::dispatch($order);
        RefundCreated::dispatch($order);

        $this->assertSame(2, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
    }

    public function test_decrement_and_partial_refund_track_addon_target_stock_from_composition_snapshot(): void
    {
        $order = Order::factory()->create();
        $item = Item::factory()->create();
        $addonItem = Item::factory()->create();
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'item_id' => $item->id,
            'quantity' => 2,
            'released_qty' => 0,
            'price' => 1,
            'discount' => 0,
            'total_price' => 1,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'composition_snapshot' => [
                'schema_version' => 1,
                'addons' => [[
                    'addon_id' => 700,
                    'addon_item_id' => $addonItem->id,
                    'role' => 'drink',
                    'quantity' => 1,
                    'unit_price' => 2.5,
                    'line_total' => 2.5,
                ]],
            ],
        ]);
        StockLevel::query()->create([
            'branch_id' => $order->branch_id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 5,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $order->branch_id,
            'stockable_type' => Item::class,
            'stockable_id' => $addonItem->id,
            'on_hand' => 5,
            'reserved' => 0,
        ]);

        app(StockService::class)->decrementForOrder($order);

        $this->assertSame(3, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(3, (int) StockLevel::query()->where('stockable_id', $addonItem->id)->value('on_hand'));

        RefundCreated::dispatch($order->fresh('orderItems'), [
            ['order_item_id' => (int) $orderItem->id, 'qty' => 1],
        ]);

        $this->assertSame(4, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(4, (int) StockLevel::query()->where('stockable_id', $addonItem->id)->value('on_hand'));
        $this->assertSame(1, (int) $orderItem->fresh()->released_qty);
        $this->assertSame(1, (int) StockMovement::query()->where('reason', 'refund')->where('stock_level_id', StockLevel::query()->where('stockable_id', $addonItem->id)->value('id'))->count());

        RefundCreated::dispatch($order->fresh('orderItems'), [
            ['order_item_id' => (int) $orderItem->id, 'qty' => 1],
        ]);

        $this->assertSame(5, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(5, (int) StockLevel::query()->where('stockable_id', $addonItem->id)->value('on_hand'));
        $this->assertSame(2, (int) $orderItem->fresh()->released_qty);

        RefundCreated::dispatch($order->fresh('orderItems'), [
            ['order_item_id' => (int) $orderItem->id, 'qty' => 1],
        ]);

        $this->assertSame(5, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(5, (int) StockLevel::query()->where('stockable_id', $addonItem->id)->value('on_hand'));
        $this->assertSame(2, (int) $orderItem->fresh()->released_qty);
    }
}
