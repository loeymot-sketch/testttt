<?php

namespace Tests\Feature\Abuse;

use App\Exceptions\Stock\StockUnavailableException;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Menu\AvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR stock_oversell — abuse harness.
 *
 * NOTE on :memory: limitation. PHPUnit runs SQLite :memory: which IGNORES
 * `FOR UPDATE`. These tests therefore prove the *serialized* (single-process)
 * correctness of the decrement guard, the idempotency backstop, the model
 * non-negativity invariant, and the availability-gate semantics. A TRUE
 * lock-race (two PHP processes racing the same StockLevel row) can only be
 * proven against MySQL :8766 — see the orchestrator RECIPES in the report.
 */
class StockOversellAbuseTest extends TestCase
{
    use RefreshDatabase;

    private function makeStockedItem(int $branchId, int $onHand): Item
    {
        $item = Item::factory()->create();
        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return $item;
    }

    private function orderLine(int $branchId, Item $item, int $qty): Order
    {
        $order = Order::factory()->create(['branch_id' => $branchId]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => $qty,
            'price' => 1,
            'discount' => 0,
            'total_price' => $qty,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        return $order;
    }

    /**
     * ABUSE 1 — serialized oversell: 1-stock item, N sequential decrements.
     * At most 1 succeeds; on_hand never goes negative. (Defense baseline.)
     */
    public function test_one_stock_item_allows_exactly_one_decrement_rest_409(): void
    {
        $branchId = (int) Order::factory()->create()->branch_id;
        $item = $this->makeStockedItem($branchId, 1);

        $ok = 0;
        $rejected = 0;
        for ($i = 0; $i < 6; $i++) {
            $order = $this->orderLine($branchId, $item, 1);
            try {
                app(StockService::class)->decrementForOrder($order);
                $ok++;
            } catch (StockUnavailableException) {
                $rejected++;
            }
        }

        $this->assertSame(1, $ok, 'only one order may consume the single unit');
        $this->assertSame(5, $rejected);
        $this->assertSame(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
        $this->assertGreaterThanOrEqual(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
    }

    /**
     * ABUSE 2 — qty larger than stock is an all-or-nothing reject (no partial
     * decrement to negative).
     */
    public function test_qty_exceeding_stock_rejects_without_touching_on_hand(): void
    {
        $branchId = (int) Order::factory()->create()->branch_id;
        $item = $this->makeStockedItem($branchId, 2);
        $order = $this->orderLine($branchId, $item, 5);

        try {
            app(StockService::class)->decrementForOrder($order);
            $this->fail('Expected StockUnavailableException for qty 5 vs stock 2.');
        } catch (StockUnavailableException) {
            $this->assertSame(2, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
            $this->assertSame(0, StockMovement::query()->count());
        }
    }

    /**
     * ABUSE 3 — double-decrement on RETRY (queue replay / dup HTTP) must NOT
     * double-spend. Same order decremented twice => second is a no-op via the
     * stock_movements idempotency_key.
     */
    public function test_replaying_same_order_does_not_double_decrement(): void
    {
        $branchId = (int) Order::factory()->create()->branch_id;
        $item = $this->makeStockedItem($branchId, 5);
        $order = $this->orderLine($branchId, $item, 3);

        app(StockService::class)->decrementForOrder($order);
        // replay (e.g. OrderCreated re-fired)
        app(StockService::class)->decrementForOrder($order->fresh());

        $this->assertSame(2, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(1, StockMovement::query()->where('delta', -3)->count());
    }

    /**
     * ABUSE 4 — negative stock is structurally impossible at the model layer
     * (saving() guard), even via a forced direct write.
     */
    public function test_model_rejects_direct_negative_on_hand_write(): void
    {
        $branchId = (int) Order::factory()->create()->branch_id;
        $level = StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => Item::factory()->create()->id,
            'on_hand' => 1,
            'reserved' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $level->forceFill(['on_hand' => -1])->save();
    }

    /**
     * ABUSE 5 — flagged-unavailable item is rejected by the order gate on every
     * server path that calls assertItemsOrderableForBranch (quote / order-create).
     */
    public function test_flagged_unavailable_item_rejected_by_order_gate(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        try {
            app(AvailabilityService::class)->assertItemsOrderableForBranch($branch->id, [$item->id]);
            $this->fail('Expected unavailable item to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString((string) $item->id, $e->getMessage());
        }
    }

    /**
     * ABUSE 6 — DOCUMENTS THE GAP: the availability GATE does NOT enforce
     * on_hand quantity. An item with on_hand=0 but no is_available=false flag
     * PASSES the gate. The ONLY oversell defense is the in-transaction decrement
     * throw — NOT the quote gate. If a code path ever commits an order WITHOUT
     * the in-transaction decrementForOrder (relying on the post-commit listener,
     * which SWALLOWS StockUnavailableException), the order persists with no stock
     * decremented = silent oversell.
     */
    public function test_gate_does_not_block_zero_on_hand_when_flag_not_set(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        // on_hand depleted to 0 but is_available flag NOT set false on the
        // availability row (e.g. no ItemBranchAvailability row exists at all).
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        // Gate passes (absent availability row = available per V1 rule).
        app(AvailabilityService::class)->assertItemsOrderableForBranch($branch->id, [$item->id]);

        // The decrement is what actually throws.
        $order = $this->orderLine($branch->id, $item, 1);
        $this->expectException(StockUnavailableException::class);
        app(StockService::class)->decrementForOrder($order);
    }

    /**
     * ABUSE 7 — post-commit listener swallows StockUnavailableException.
     * Proves: if an order is created and only the OrderCreated listener path
     * runs the decrement (no in-transaction guard), an oversell does NOT roll
     * the order back and does NOT raise — it is logged + a no-op event. This is
     * the architectural reason the in-transaction decrement in
     * Order/FrontendOrderService is load-bearing.
     */
    public function test_order_created_listener_swallows_stock_failure(): void
    {
        $branchId = (int) Order::factory()->create()->branch_id;
        $item = $this->makeStockedItem($branchId, 0); // already empty
        $order = $this->orderLine($branchId, $item, 1);

        $listener = new \App\Listeners\DecrementStockOnOrderCreated();

        // Must NOT throw — listener isolates the failure.
        $listener->handle(new \App\Events\OrderCreated($order));

        // Order is untouched; stock stays 0; no negative.
        $this->assertSame(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /**
     * ABUSE 8 — ASYMMETRY DRIFT (the core finding). The POS path
     * (OrderService::posOrderStore line 1066) calls decrementForOrder INSIDE
     * the create transaction, so an oversell rolls the order back (HARD guard).
     * The WEB path (myOrderStore) and the QR-TABLE path (tableOrderStore) do
     * NOT — they rely solely on the post-commit OrderCreated listeners, which
     * swallow StockUnavailableException. This test simulates the web/QR commit
     * sequence: order is created (committed), THEN the listeners fire. Result:
     * the order STANDS and stock is NOT decremented = a SILENT OVERSELL with no
     * client-visible error and no rollback.
     *
     * Stock here = a tracked item depleted to on_hand=1, ordered qty=3, with NO
     * is_available=false flag (so assertItemsOrderableForBranch — which only
     * checks the flag, never on_hand — would have passed at quote time).
     */
    public function test_web_qr_path_oversells_because_listener_only_and_swallows(): void
    {
        $branchId = (int) Order::factory()->create()->branch_id;
        $item = $this->makeStockedItem($branchId, 1);   // only 1 unit left
        $order = $this->orderLine($branchId, $item, 3);  // customer ordered 3

        // The flag gate (the ONLY stock check on the web/QR paths) passes: no
        // ItemBranchAvailability row exists, so the item reads "available".
        app(AvailabilityService::class)->assertItemsOrderableForBranch($branchId, [$item->id]);

        // Order already committed (web/QR create tx returned). Now the
        // post-commit listeners fire. Neither throws to the caller.
        (new \App\Listeners\DecrementItemAvailabilityOnOrder(app(AvailabilityService::class)))
            ->handle(new \App\Events\OrderCreated($order));
        (new \App\Listeners\DecrementStockOnOrderCreated())
            ->handle(new \App\Events\OrderCreated($order));

        // OVERSELL: order stands, stock NOT decremented (qty 3 > on_hand 1 was
        // rejected by StockService and the rejection was swallowed). 3 units
        // were sold; only 1 existed; stock still reads 1 (drift).
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(
            1,
            (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'),
            'on_hand unchanged = the 3-unit sale was never decremented = silent oversell'
        );
        $this->assertSame(0, StockMovement::query()->count(), 'no decrement movement recorded');
    }

    /**
     * HEAL SENTINEL [abuse-2026-06-17 P2] — the QR-table path (tableOrderStore) MUST decrement stock
     * IN-TRANSACTION before dispatching OrderCreated (mirroring posOrderStore:1066), so an oversell
     * throws and rolls the QR order back atomically instead of being swallowed by the post-commit
     * listeners (ABUSE 8 above). This guards against regression of the asymmetry drift.
     */
    public function test_table_order_store_decrements_stock_in_transaction(): void
    {
        $src = file_get_contents(app_path('Services/OrderService.php'));
        $qrIdx = strpos($src, 'dine-in QR path');
        $this->assertNotFalse($qrIdx, 'QR-table OrderCreated marker (dine-in QR path) not found in OrderService');
        $window = substr($src, max(0, $qrIdx - 1400), 1400);
        $this->assertStringContainsString(
            'decrementForOrder',
            $window,
            'tableOrderStore must call StockService::decrementForOrder IN-TX before OrderCreated (QR oversell guard)'
        );
    }
}
