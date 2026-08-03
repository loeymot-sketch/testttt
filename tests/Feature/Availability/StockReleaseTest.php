<?php

namespace Tests\Feature\Availability;

use App\Events\ItemAvailabilityChanged;
use App\Events\OrderCanceled;
use App\Events\RefundCreated;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [F-01 + NEW-05] Sentinel tests for compensating stock release on cancel/refund.
 *
 * Plan: plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_v2_2026-04-23.md lot 1.B.1
 *
 * Coverage:
 *   - Full cancel of a 5-unit line releases counters back to 0 and stamps released_qty=5.
 *   - Full cancel flips a 86'd item back to available + dispatches ItemAvailabilityChanged.
 *   - Partial refund of 2/5 only releases 2 (released_qty=2; daily_consumed_qty -= 2).
 *   - Duplicate OrderCanceled is idempotent (released_qty stays at quantity).
 *   - OrderCanceled then full RefundCreated → 2nd is a no-op.
 */
class StockReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /**
     * Helper: create a (branch, category, item, order, orderItem) fixture with the
     * given quantity, plus an ItemBranchAvailability row.
     *
     * @return array{branch: Branch, item: Item, order: Order, orderItem: OrderItem}
     */
    private function makeOrderFixture(
        int $orderItemQty,
        int $dailyConsumedQty,
        int $maxDailyQty,
        bool $isAvailable = true,
        ?string $unavailableReason = null
    ): array {
        $branch = Branch::factory()->create();
        $category = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 12.50,
        ]);

        $user = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);

        $orderItem = OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => $orderItemQty,
            'discount' => 0,
            'tax_name' => null,
            'tax_rate' => 0,
            'tax_type' => 1,
            'tax_amount' => 0,
            'price' => 12.50,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 12.50 * $orderItemQty,
            'released_qty' => 0,
            'released_at' => null,
        ]);

        DB::table('item_branch_availability')->insert([
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'is_available' => $isAvailable,
            'unavailable_reason' => $isAvailable ? null : ($unavailableReason ?? 'out_of_stock'),
            'unavailable_since' => $isAvailable ? null : now(),
            'max_daily_qty' => $maxDailyQty,
            'daily_consumed_qty' => $dailyConsumedQty,
            'daily_reset_at' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('branch', 'item', 'order', 'orderItem');
    }

    public function test_full_cancel_releases_consumed_stock_and_stamps_released_qty(): void
    {
        $f = $this->makeOrderFixture(orderItemQty: 5, dailyConsumedQty: 5, maxDailyQty: 10, isAvailable: true);

        OrderCanceled::dispatch($f['order']->fresh('orderItems'));

        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $f['branch']->id,
            'item_id' => $f['item']->id,
            'daily_consumed_qty' => 0,
            'is_available' => 1,
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $f['orderItem']->id,
            'released_qty' => 5,
        ]);
        $this->assertNotNull($f['orderItem']->fresh()->released_at);
    }

    /**
     * @test — [SELF-AUDIT R4 P2 2026-07-05] Un 86 MANUEL (supplier_issue) — ou le 86 préventif du cron
     * ('stock_rupture') — sur un article qui a AUSSI un plafond journalier NE DOIT PAS être levé par une
     * annulation/remboursement d'une commande sans rapport. Seul le 86 auto-quota ('out_of_stock') se
     * lève automatiquement (miroir setMaxDailyQty). Avant le fix, l'annulation remettait l'article en
     * vente en silence → la cuisine recevait des commandes qu'elle ne pouvait honorer.
     */
    public function manual_86_survives_an_unrelated_cancel_release(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $f = $this->makeOrderFixture(orderItemQty: 5, dailyConsumedQty: 5, maxDailyQty: 10, isAvailable: false, unavailableReason: 'supplier_issue');

        OrderCanceled::dispatch($f['order']->fresh('orderItems'));

        // Le compteur redescend (arithmétique correcte) MAIS l'article reste 86'd manuellement.
        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $f['branch']->id,
            'item_id' => $f['item']->id,
            'daily_consumed_qty' => 0,
            'is_available' => 0,
            'unavailable_reason' => 'supplier_issue',
        ]);
        // Pas de remise en vente → aucun événement de disponibilité.
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_full_cancel_flips_unavailable_item_back_to_available_and_dispatches_event(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $f = $this->makeOrderFixture(orderItemQty: 5, dailyConsumedQty: 5, maxDailyQty: 5, isAvailable: false);

        OrderCanceled::dispatch($f['order']->fresh('orderItems'));

        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $f['branch']->id,
            'item_id' => $f['item']->id,
            'daily_consumed_qty' => 0,
            'is_available' => 1,
            'unavailable_reason' => null,
        ]);

        Event::assertDispatched(ItemAvailabilityChanged::class, 1);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $e) use ($f) {
            return $e->itemId === (int) $f['item']->id
                && $e->branchId === (int) $f['branch']->id
                && $e->isAvailable === true
                && $e->reason === 'released_after_cancel_or_refund';
        });
    }

    public function test_partial_refund_releases_only_requested_quantity(): void
    {
        $f = $this->makeOrderFixture(orderItemQty: 5, dailyConsumedQty: 5, maxDailyQty: 10, isAvailable: true);

        RefundCreated::dispatch($f['order']->fresh('orderItems'), [
            ['order_item_id' => (int) $f['orderItem']->id, 'qty' => 2],
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $f['orderItem']->id,
            'released_qty' => 2,
        ]);
        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $f['branch']->id,
            'item_id' => $f['item']->id,
            'daily_consumed_qty' => 3,
        ]);
    }

    public function test_duplicate_order_canceled_is_idempotent(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $f = $this->makeOrderFixture(orderItemQty: 5, dailyConsumedQty: 5, maxDailyQty: 5, isAvailable: false);

        OrderCanceled::dispatch($f['order']->fresh('orderItems'));
        OrderCanceled::dispatch($f['order']->fresh('orderItems'));
        OrderCanceled::dispatch($f['order']->fresh('orderItems'));

        $this->assertDatabaseHas('order_items', [
            'id' => $f['orderItem']->id,
            'released_qty' => 5,
        ]);
        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $f['branch']->id,
            'item_id' => $f['item']->id,
            'daily_consumed_qty' => 0,
            'is_available' => 1,
        ]);

        Event::assertDispatched(ItemAvailabilityChanged::class, 1);
    }

    public function test_order_canceled_then_full_refund_second_delivery_is_no_op(): void
    {
        $f = $this->makeOrderFixture(orderItemQty: 5, dailyConsumedQty: 5, maxDailyQty: 10, isAvailable: true);

        OrderCanceled::dispatch($f['order']->fresh('orderItems'));
        RefundCreated::dispatch($f['order']->fresh('orderItems'), []);

        $this->assertDatabaseHas('order_items', [
            'id' => $f['orderItem']->id,
            'released_qty' => 5,
        ]);
        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $f['branch']->id,
            'item_id' => $f['item']->id,
            'daily_consumed_qty' => 0,
        ]);
    }
}
