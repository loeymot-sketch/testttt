<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Database\Factories\BranchFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-19 loyalty-refund] ONLINE-REFUND-LOYALTY-01 sentinel.
 *
 * Pre-heal exploit (Admin-only insider / operational error): a DELIVERED order is
 * refunded (status=RETURNED + payment_status=REFUNDED via the canonical pre-Z refund),
 * then an Admin uses the terminal-state override (OrderStateMachine RETURNED->DELIVERED)
 * to re-deliver it. AwardLoyaltyPointsOnDelivery only guarded CANCELED, never the
 * refunded/returned state, so it re-awarded the full points on a fully-refunded sale —
 * value-bearing store credit the customer keeps for free (the matching clawback never
 * runs again because PaymentService::cashBack's idempotency short-circuits RefundCreated).
 *
 * This sentinel dispatches the REAL OrderStatusChanged event (end-to-end wiring) so a
 * regression that removes the guard turns red.
 */
class LoyaltyNoAwardAfterRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_refunded_order_redelivered_awards_no_loyalty_points(): void
    {
        $branch = BranchFactory::new()->create();

        $customer = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => 0,
        ]);

        $staff = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);

        // A delivery order that has already been refunded: RETURNED + REFUNDED,
        // never previously awarded (loyalty_points_awarded = NULL).
        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'order_serial_no' => 'NOAWARD-'.uniqid(),
            'subtotal' => 30.00,
            'total' => 30.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'status' => OrderStatus::RETURNED,
            'payment_method' => 1,
            'payment_status' => PaymentStatus::REFUNDED,
            'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code,
            'loyalty_points_awarded' => null,
        ]);

        // Admin terminal-state override re-fires the delivered transition.
        // dispatchNow bypasses DispatchableAfterCommit (RefreshDatabase tx wrap).
        OrderStatusChanged::dispatchNow($order, OrderStatus::RETURNED, OrderStatus::DELIVERED);

        $customer->refresh();
        $order->refresh();

        $this->assertSame(
            0,
            (int) $customer->loyalty_points,
            'A refunded (RETURNED/REFUNDED) order must NOT award loyalty points on re-delivery.'
        );
        $this->assertNull(
            $order->loyalty_points_awarded,
            'loyalty_points_awarded must stay NULL — no award claimed on a refunded order.'
        );
    }

    /**
     * Control: a normal PAID delivery order at DELIVERED still awards points
     * (proves the new guard does not over-block the legitimate path).
     */
    public function test_paid_delivered_order_still_awards_points(): void
    {
        $branch = BranchFactory::new()->create();

        $customer = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => 0,
        ]);
        $staff = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);

        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'order_serial_no' => 'AWARD-'.uniqid(),
            'subtotal' => 30.00,
            'total' => 30.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'status' => OrderStatus::DELIVERED,
            'payment_method' => 1,
            'payment_status' => PaymentStatus::PAID,
            'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code,
            'loyalty_points_awarded' => null,
        ]);

        OrderStatusChanged::dispatchNow($order, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED);

        $customer->refresh();
        $this->assertGreaterThan(
            0,
            (int) $customer->loyalty_points,
            'A legitimate PAID delivered order must still award points.'
        );
    }
}
