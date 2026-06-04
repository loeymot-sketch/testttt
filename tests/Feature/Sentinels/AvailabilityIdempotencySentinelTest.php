<?php

namespace Tests\Feature\Sentinels;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — Heal C.1 (Z2 P1) — 2026-05-19.
 *
 * Locks the per-order idempotency guard on AvailabilityService::decrementForOrder.
 *
 * Why this sentinel exists
 * ------------------------
 * Pre-heal, `decrementForOrder` did a raw SQL `daily_consumed_qty + {$qty}`
 * UPDATE filtered only by `whereNotNull('max_daily_qty')` — ZERO per-order
 * key. If `OrderCreated` fired twice for the same order (queue replay,
 * listener resurrection on retry, refactor moving listener to `ShouldQueue`,
 * a bad merge), the daily quota counter double-counted → premature
 * `is_available=false / unavailable_reason='out_of_stock'` flip. Sibling
 * `DecrementStockOnOrderCreated` was already idempotent via
 * `stock_movements.idempotency_key` (Z1 verified). This listener was the
 * only V1-reachable correctness hole in the OrderCreated cascade.
 *
 * Two paired tests
 * ----------------
 *  - Behavioral: dispatch twice → assert counter incremented ONCE.
 *  - Source-grep: assert `Cache::add` guard literal is present.
 *
 * Behavioral catches the bug. Source-grep catches a "clever" refactor that
 * removes the guard but accidentally still passes the behavioral check (e.g.
 * by replacing `Cache::add` with something race-unsafe that happens to be
 * deterministic in a single-process test). The pair locks BOTH the
 * invariant AND the mechanism.
 *
 * See: reports/audit/v1-sync-deep-audit-2026-05-19/HEAL-PLAN-C-order-lifecycle.md §3.5
 */
class AvailabilityIdempotencySentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ItemAvailabilityChanged::class]);
        // Clear cache so a stale guard from prior tests can't short-circuit
        // the FIRST decrement here.
        Cache::flush();
    }

    public function test_decrement_for_order_is_idempotent_on_duplicate_dispatch(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $order = $this->makeOrderFor($branch, $item, quantity: 2);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'unavailable_since' => null,
            'max_daily_qty' => 10,
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $service = app(AvailabilityService::class);

        // First dispatch → +2.
        $service->decrementForOrder($order->fresh('orderItems'));
        $row = ItemBranchAvailability::query()
            ->where('item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->first();
        $this->assertSame(2, (int) $row->daily_consumed_qty, 'First decrement applied');

        // Second dispatch with SAME order_id → guard must short-circuit, counter unchanged.
        $service->decrementForOrder($order->fresh('orderItems'));
        $row = ItemBranchAvailability::query()
            ->where('item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->first();
        $this->assertSame(
            2,
            (int) $row->daily_consumed_qty,
            'Duplicate dispatch MUST be short-circuited (Heal C.1 guard).'
        );
    }

    public function test_decrement_for_different_orders_does_not_share_guard(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'unavailable_since' => null,
            'max_daily_qty' => 10,
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $orderA = $this->makeOrderFor($branch, $item, quantity: 2);
        $orderB = $this->makeOrderFor($branch, $item, quantity: 3);

        $service = app(AvailabilityService::class);
        $service->decrementForOrder($orderA->fresh('orderItems'));
        $service->decrementForOrder($orderB->fresh('orderItems'));

        $row = ItemBranchAvailability::query()
            ->where('item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->first();
        $this->assertSame(
            5,
            (int) $row->daily_consumed_qty,
            'Distinct order_ids must NOT share the idempotency guard.'
        );
    }

    public function test_availability_service_decrement_uses_cache_add_guard(): void
    {
        $source = file_get_contents(base_path('app/Services/Menu/AvailabilityService.php'));
        $this->assertNotFalse($source, 'AvailabilityService.php must be readable');

        // Source-grep the heal mechanism. The pair (Cache::add + key prefix)
        // resists accidental removal of either half.
        $this->assertStringContainsString(
            'Cache::add(',
            $source,
            'Heal C.1 guard removed: AvailabilityService must use Cache::add SETNX.'
        );
        $this->assertStringContainsString(
            'availability:decremented:',
            $source,
            'Heal C.1 key prefix missing: cache key must be `availability:decremented:*`.'
        );

        // Locate decrementForOrder method bounds (defensive — the test's job
        // is to catch removal of the guard FROM THIS METHOD specifically).
        $methodStart = strpos($source, 'function decrementForOrder');
        $this->assertNotFalse($methodStart, 'decrementForOrder method must still exist');
        // The guard must live inside this method, not somewhere else.
        $guardOffset = strpos($source, 'availability:decremented:', $methodStart);
        $this->assertNotFalse(
            $guardOffset,
            'Heal C.1 guard must live inside decrementForOrder().'
        );
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
