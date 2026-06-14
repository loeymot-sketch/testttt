<?php

namespace Tests\Feature\Kds;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID KDS-OSS-01 (ultra-audit 2026-06-10, héritier du P1 BRAIN 2026-06-09) | LOT E
 *
 * changeStatus() applied canTransition + OrderStateMachine::allows but NEVER
 * KitchenReleaseRule::orderIsReleased — so orders the kitchen board does NOT
 * even show (DELIVERY+UNPAID, POS+UNPAID+non-cash) were bumpable through the
 * raw API. The guard must mirror the board's visibility predicate
 * (KitchenDisplaySystemOrderService:74-82): PAID OR PENDING_COUNTER
 * (counter-collect, kitchen-prepares-before-pay per owner decision W-D1)
 * OR POS+CASH.
 */
class KdsUnreleasedOrderBumpGuardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->chef   = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->chef->assignRole('Chef');
    }

    private function makeOrder(array $attrs): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status'    => OrderStatus::ACCEPT,
        ], $attrs));
    }

    private function bump(Order $order)
    {
        return $this->actingAs($this->chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status'          => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);
    }

    public function test_delivery_unpaid_order_cannot_be_bumped(): void
    {
        $order = $this->makeOrder([
            'order_type'     => OrderType::DELIVERY,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        $this->bump($order)->assertStatus(422);
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status, 'Unreleased order must not advance.');
    }

    public function test_pos_unpaid_card_order_cannot_be_bumped(): void
    {
        $order = $this->makeOrder([
            'order_type'         => OrderType::POS,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CARD,
        ]);

        $this->bump($order)->assertStatus(422);
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status);
    }

    public function test_pending_counter_order_still_bumpable_plan_b(): void
    {
        // Kiosk Plan B / walk-in deferred: kitchen prepares BEFORE payment
        // (owner decision W-D1) — the guard must NOT break this core flow.
        $order = $this->makeOrder([
            'order_type'         => OrderType::TAKEAWAY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
        ]);

        $this->bump($order)->assertSuccessful();
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
    }

    public function test_paid_order_still_bumpable(): void
    {
        $order = $this->makeOrder([
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $this->bump($order)->assertSuccessful();
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
    }

    public function test_pos_cash_unpaid_still_bumpable(): void
    {
        $order = $this->makeOrder([
            'order_type'         => OrderType::POS,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ]);

        $this->bump($order)->assertSuccessful();
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
    }

    /**
     * BUMP-RESILIENCE (massive-2dot0 #11) — once the status transition is COMMITTED,
     * a failure in the post-commit notification dispatch (mail/sms/push) must NOT be
     * re-wrapped into a 422. Pre-heal the unguarded SendOrder* dispatches threw into
     * the outer catch → the chef saw a 422 on a bump that actually persisted, then
     * re-bumped in confusion. The transaction already committed; notifications are
     * fire-and-forget. (recordTransition does not dispatch, so the Bus mock isolates
     * the failure to the post-commit path only.)
     */
    public function test_committed_bump_survives_post_commit_notification_failure(): void
    {
        $order = $this->makeOrder([
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
        ]);

        // SendOrderMail/Sms/Push are events (Foundation\Events\Dispatchable);
        // a synchronous listener throwing simulates a notification backend outage
        // on the post-commit leg. This is isolated to the SendOrderMail event.
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\SendOrderMail::class,
            function () { throw new \RuntimeException('notif backend down'); }
        );

        $this->bump($order)->assertSuccessful();
        $this->assertSame(
            OrderStatus::PREPARING,
            (int) $order->fresh()->status,
            'A committed bump must persist even when post-commit notifications fail.'
        );
    }
}
