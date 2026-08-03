<?php

namespace Tests\Feature\Stock;

use App\Enums\EventType;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Services\Menu\MenuSnapshot;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class StockAvailabilityAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_availability_side_effects_run_after_successful_commit(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);
        $snapshot = app(MenuSnapshot::class);
        $cacheKey = 'kiosk.menu.branch.' . $branch->id;

        Cache::put($cacheKey, ['stale' => true], 120);
        $this->assertSame(1, $snapshot->current((int) $branch->id));

        app(StockService::class)->decrementForOrder($order);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
        $this->assertFalse(Cache::has($cacheKey));
        $this->assertSame(2, $snapshot->current((int) $branch->id));

        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_id' => $item->id,
            'branch_id' => $branch->id,
            'broadcast_as' => 'ItemAvailabilityChanged',
        ]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::CATALOG_CHANGED,
            'aggregate_id' => $item->id,
            'branch_id' => $branch->id,
            'broadcast_as' => 'CatalogChanged',
        ]);
    }

    public function test_stock_availability_side_effects_are_dropped_when_outer_transaction_rolls_back(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);
        $snapshot = app(MenuSnapshot::class);
        $cacheKey = 'kiosk.menu.branch.' . $branch->id;

        Cache::put($cacheKey, ['stale' => true], 120);
        $this->assertSame(1, $snapshot->current((int) $branch->id));

        try {
            DB::transaction(function () use ($order): void {
                app(StockService::class)->decrementForOrder($order);

                throw new RuntimeException('force outer rollback');
            });

            $this->fail('Expected the outer transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force outer rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
        $this->assertSame(1, (int) StockLevel::query()
            ->where('stockable_type', Item::class)
            ->where('stockable_id', $item->id)
            ->where('branch_id', $branch->id)
            ->value('on_hand'));
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertSame(1, $snapshot->current((int) $branch->id));
        $this->assertDatabaseMissing('domain_events', [
            'broadcast_as' => 'ItemAvailabilityChanged',
            'aggregate_id' => $item->id,
            'branch_id' => $branch->id,
        ]);
        $this->assertDatabaseMissing('domain_events', [
            'broadcast_as' => 'CatalogChanged',
            'aggregate_id' => $item->id,
            'branch_id' => $branch->id,
        ]);
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
