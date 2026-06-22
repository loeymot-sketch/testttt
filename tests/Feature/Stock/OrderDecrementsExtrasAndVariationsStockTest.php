<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Exceptions\Stock\StockUnavailableException;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F-016a-BIS] Anti-regression sentinel proving that
 * {@see StockService::decrementForOrder()} already covers ItemExtra and
 * ItemVariation polymorphically (via {@see StockService::requirementsForOrderItem()}).
 *
 * The Option 1bis architecture choice depends on this fact: extras and
 * variations decrement through the SAME stock_levels table the manual
 * rupture flow toggles, so a single SoT serves both.
 *
 * If a future refactor breaks this coupling, this sentinel fires loud.
 */
class OrderDecrementsExtrasAndVariationsStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_with_extra_decrements_extra_stock_level(): void
    {
        $order = Order::factory()->create();
        $branchId = (int) $order->branch_id;
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Bacon',
            'price' => 1.00,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => true,
        ]);

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 5,
            'reserved' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => 2,
            'price' => 1,
            'discount' => 0,
            'total_price' => 1,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([
                ['id' => $extra->id, 'quantity' => 1],
            ]),
        ]);

        app(StockService::class)->decrementForOrder($order);

        $this->assertSame(8, (int) StockLevel::query()
            ->where('stockable_type', Item::class)
            ->where('stockable_id', $item->id)
            ->value('on_hand'));
        $this->assertSame(3, (int) StockLevel::query()
            ->where('stockable_type', ItemExtra::class)
            ->where('stockable_id', $extra->id)
            ->value('on_hand'));
        $this->assertGreaterThan(0, StockMovement::query()
            ->where('reason', 'order_created')
            ->where('delta', -2)
            ->count());
    }

    public function test_order_with_variation_decrements_variation_stock_level(): void
    {
        $order = Order::factory()->create();
        $branchId = (int) $order->branch_id;
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Maxi',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'on_hand' => 4,
            'reserved' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 2,
            'discount' => 0,
            'total_price' => 2,
            'item_variations' => json_encode([
                ['id' => $variation->id, 'quantity' => 1],
            ]),
            'item_extras' => json_encode([]),
        ]);

        app(StockService::class)->decrementForOrder($order);

        $this->assertSame(3, (int) StockLevel::query()
            ->where('stockable_type', ItemVariation::class)
            ->where('stockable_id', $variation->id)
            ->value('on_hand'));
    }

    public function test_decrement_raises_when_extra_stock_insufficient(): void
    {
        $order = Order::factory()->create();
        $branchId = (int) $order->branch_id;
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Bacon',
            'price' => 1.00,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => true,
        ]);

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 1,
            'reserved' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => 3,
            'price' => 1,
            'discount' => 0,
            'total_price' => 1,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([
                ['id' => $extra->id, 'quantity' => 1],
            ]),
        ]);

        $this->expectException(StockUnavailableException::class);
        app(StockService::class)->decrementForOrder($order);
    }

    public function test_decrement_no_op_when_no_stock_level_row_for_extra(): void
    {
        // Untracked extras must not be silently consumed: absence of a row =
        // "we don't track this extra's inventory yet". Decrement skips it.
        $order = Order::factory()->create();
        $branchId = (int) $order->branch_id;
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Untracked',
            'price' => 0.10,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => true,
        ]);

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 1,
            'discount' => 0,
            'total_price' => 1,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([
                ['id' => $extra->id, 'quantity' => 1],
            ]),
        ]);

        app(StockService::class)->decrementForOrder($order);

        $this->assertNull(StockLevel::query()
            ->where('stockable_type', ItemExtra::class)
            ->where('stockable_id', $extra->id)
            ->first());
    }
}
