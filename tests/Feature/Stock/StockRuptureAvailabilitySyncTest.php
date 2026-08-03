<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Services\Kiosk\KioskMenuService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StockRuptureAvailabilitySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_reaching_zero_marks_item_unavailable_for_pos_and_kiosk_projection(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);

        Event::fake([ItemAvailabilityChanged::class]);

        app(StockService::class)->decrementForOrder($order);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($branch, $item): bool {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branch->id
                && $event->isAvailable === false
                && $event->reason === 'stock_rupture';
        });

        $menu = app(KioskMenuService::class)->build($branch);
        $projected = collect($menu['items'])->firstWhere('id', $item->id);
        $this->assertFalse($projected['is_available']);
        $this->assertSame('stock_rupture', $projected['unavailable_reason']);
    }

    public function test_stock_release_restores_only_auto_stock_ruptures(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);

        app(StockService::class)->decrementForOrder($order);

        Event::fake([ItemAvailabilityChanged::class]);

        app(StockService::class)->releaseForOrder($order);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
        ]);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($branch, $item): bool {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branch->id
                && $event->isAvailable === true
                && $event->reason === 'stock_restocked';
        });
    }

    public function test_stock_zero_does_not_override_manual_admin_rupture_reason(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'admin_86',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        Event::fake([ItemAvailabilityChanged::class]);

        app(StockService::class)->decrementForOrder($order);
        app(StockService::class)->releaseForOrder($order);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'admin_86',
        ]);
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    private function orderWithTrackedStock(int $onHand): array
    {
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$branch, $order, $item];
    }
}
