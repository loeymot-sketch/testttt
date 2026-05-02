<?php

namespace Tests\Feature\Stock;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AvailabilityDecrementConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ItemAvailabilityChanged::class]);
    }

    public function test_single_decrement_under_cap_keeps_item_available_without_event(): void
    {
        $fixture = $this->makeAvailabilityFixture(consumed: 5, max: 10, quantity: 2);

        app(AvailabilityService::class)->decrementForOrder($fixture['order']->fresh('orderItems'));

        $row = $fixture['availability']->fresh();

        $this->assertSame(7, (int) $row->daily_consumed_qty);
        $this->assertTrue((bool) $row->is_available);
        $this->assertNull($row->unavailable_reason);
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_single_decrement_crossing_threshold_flips_once(): void
    {
        $fixture = $this->makeAvailabilityFixture(consumed: 8, max: 10, quantity: 2);

        app(AvailabilityService::class)->decrementForOrder($fixture['order']->fresh('orderItems'));

        $row = $fixture['availability']->fresh();

        $this->assertSame(10, (int) $row->daily_consumed_qty);
        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($fixture): bool {
            return $event->itemId === (int) $fixture['item']->id
                && $event->branchId === (int) $fixture['branch']->id
                && $event->isAvailable === false
                && $event->reason === 'out_of_stock';
        });
    }

    public function test_single_decrement_overshooting_caps_and_flips_once(): void
    {
        $fixture = $this->makeAvailabilityFixture(consumed: 8, max: 10, quantity: 5);

        app(AvailabilityService::class)->decrementForOrder($fixture['order']->fresh('orderItems'));

        $row = $fixture['availability']->fresh();

        $this->assertSame(10, (int) $row->daily_consumed_qty);
        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
    }

    public function test_serialized_concurrent_decrements_dispatch_flip_event_once(): void
    {
        $fixture = $this->makeAvailabilityFixture(consumed: 8, max: 10, quantity: 2);

        $orders = [
            $fixture['order'],
            $this->makeOrderFor($fixture['branch'], $fixture['item'], 2),
            $this->makeOrderFor($fixture['branch'], $fixture['item'], 2),
        ];

        foreach ($orders as $order) {
            app(AvailabilityService::class)->decrementForOrder($order->fresh('orderItems'));
        }

        $row = $fixture['availability']->fresh();

        $this->assertSame(10, (int) $row->daily_consumed_qty);
        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
    }

    /**
     * @return array{branch: Branch, item: Item, order: Order, availability: ItemBranchAvailability}
     */
    private function makeAvailabilityFixture(int $consumed, int $max, int $quantity): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $order = $this->makeOrderFor($branch, $item, $quantity);

        $availability = ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'unavailable_since' => null,
            'max_daily_qty' => $max,
            'daily_consumed_qty' => $consumed,
            'daily_reset_at' => now()->toDateString(),
        ]);

        return compact('branch', 'item', 'order', 'availability');
    }

    private function makeOrderFor(Branch $branch, Item $item, int $quantity): Order
    {
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'price' => 1,
            'discount' => 0,
            'total_price' => $quantity,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        return $order;
    }
}
