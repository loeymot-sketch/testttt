<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\RefundCreated;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Database\Factories\BranchFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL-J2-HEAL-07 2026-05-24] Phase J-ADV-3 L3 P1 CONFIRMED sentinel.
 *
 * Pre-heal exploit: customer earns 300 pts (= 3€) on a 30€ DELIVERED
 * order, then the order is refunded — but no listener decremented
 * users.loyalty_points by orders.loyalty_points_awarded. Repeatable:
 * customer earns infinite money.
 *
 * This sentinel exercises the END-TO-END wiring by dispatching
 * RefundCreated (NOT calling LoyaltyService::clawbackEarnedPoints
 * directly) — that way a regression where
 * ClawbackLoyaltyPointsOnRefund is removed from EventServiceProvider
 * also turns red. Advisor mandate 2026-05-24.
 */
class LoyaltyClawbackOnRefundSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function makeOrderWithAwardedPoints(int $awarded, int $startingBalance): array
    {
        $branch = BranchFactory::new()->create();

        $customer = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => $startingBalance,
        ]);

        $staff = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);

        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'order_serial_no' => 'CLAW-'.uniqid(),
            'subtotal' => 30.00,
            'total' => 30.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'status' => OrderStatus::DELIVERED,
            'payment_method' => 1,
            'payment_status' => 1,
            'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code,
            'loyalty_points_awarded' => $awarded,
        ]);

        // Simulate the earn ledger row produced by AwardLoyaltyPointsOnDelivery.
        LoyaltyTransaction::create([
            'user_id' => $customer->id,
            'loyalty_code' => $customer->loyalty_code,
            'order_id' => $order->id,
            'type' => 'earn',
            'points' => $awarded,
            'balance_after' => $startingBalance,
            'source_surface' => 'pos',
            'description' => 'Earn for delivered order',
        ]);

        return [$order, $customer];
    }

    /**
     * Primary invariant — 300 pts awarded → refund → 0 pts remaining
     * + a manual_deduct ledger row written.
     */
    public function test_refund_claws_back_earned_loyalty_points(): void
    {
        [$order, $customer] = $this->makeOrderWithAwardedPoints(300, 300);

        $this->assertSame(300, (int) $customer->fresh()->loyalty_points, 'pre-condition');

        // dispatchNow bypasses DispatchableAfterCommit guard — RefreshDatabase
        // wraps the test in a transaction, so dispatch() would queue afterCommit
        // and never fire during the test (pattern documented in trait line 63-68).
        RefundCreated::dispatchNow($order);

        $customer->refresh();
        $this->assertSame(
            0,
            (int) $customer->loyalty_points,
            'Earned 300 pts must be clawed back on refund (no double-dip).'
        );

        $clawback = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'manual_deduct')
            ->first();

        $this->assertNotNull($clawback, 'manual_deduct ledger row must exist.');
        $this->assertSame(-300, (int) $clawback->points, 'Ledger must record -300.');
        $this->assertSame(0, (int) $clawback->balance_after, 'balance_after snapshot must be 0.');
        $this->assertSame($customer->id, (int) $clawback->user_id);
        $this->assertSame($customer->loyalty_code, $clawback->loyalty_code);
        $this->assertSame('refund', $clawback->source_surface);
        $this->assertStringContainsString('Clawback', (string) $clawback->description);
    }

    /**
     * Idempotency — two RefundCreated dispatches must not double-deduct.
     * Guards against duplicate-event scenarios (manual retry, broadcast
     * replay, etc.) — the existence check + UNIQUE (user_id, order_id,
     * type) index keeps the second call a silent NOOP.
     */
    public function test_double_refund_event_is_idempotent_noop(): void
    {
        [$order, $customer] = $this->makeOrderWithAwardedPoints(300, 300);

        // dispatchNow bypasses DispatchableAfterCommit guard — RefreshDatabase
        // wraps the test in a transaction, so dispatch() would queue afterCommit
        // and never fire during the test (pattern documented in trait line 63-68).
        RefundCreated::dispatchNow($order);
        $customer->refresh();
        $this->assertSame(0, (int) $customer->loyalty_points, 'first dispatch');
        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)
                ->where('type', 'manual_deduct')->count(),
            'first dispatch writes 1 ledger row'
        );

        // Second dispatch — must NOOP.
        // dispatchNow bypasses DispatchableAfterCommit guard — RefreshDatabase
        // wraps the test in a transaction, so dispatch() would queue afterCommit
        // and never fire during the test (pattern documented in trait line 63-68).
        RefundCreated::dispatchNow($order);
        $customer->refresh();
        $this->assertSame(
            0,
            (int) $customer->loyalty_points,
            'Second dispatch must NOT push balance negative (idempotent).'
        );
        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)
                ->where('type', 'manual_deduct')->count(),
            'Second dispatch must NOT insert a duplicate manual_deduct row.'
        );
    }

    /**
     * Balance clamp — if the customer has already spent some of the
     * awarded points before the refund (current_balance < awarded),
     * the clawback must clamp at 0 instead of going negative.
     */
    public function test_clawback_clamps_balance_at_zero(): void
    {
        // Customer earned 300 but only 100 remain (spent 200 elsewhere).
        [$order, $customer] = $this->makeOrderWithAwardedPoints(300, 100);

        // dispatchNow bypasses DispatchableAfterCommit guard — RefreshDatabase
        // wraps the test in a transaction, so dispatch() would queue afterCommit
        // and never fire during the test (pattern documented in trait line 63-68).
        RefundCreated::dispatchNow($order);

        $customer->refresh();
        $this->assertSame(
            0,
            (int) $customer->loyalty_points,
            'Balance must clamp at 0, never go negative.'
        );

        $clawback = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'manual_deduct')->first();
        $this->assertNotNull($clawback);
        $this->assertSame(
            -100,
            (int) $clawback->points,
            'Ledger records the actual deduction (100), not the attempted 300.'
        );
    }

    /**
     * Anonymous-order NOOP — refunds on orders without a
     * loyalty_customer_code AND without a loyalty user_id must not
     * throw and must not write a ledger row.
     */
    public function test_anonymous_order_refund_is_safe_noop(): void
    {
        $branch = BranchFactory::new()->create();
        $staff = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);

        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'order_serial_no' => 'ANON-'.uniqid(),
            'subtotal' => 25.00,
            'total' => 25.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'status' => OrderStatus::DELIVERED,
            'payment_method' => 1,
            'payment_status' => 1,
            'source' => 1,
            // Explicitly null — anonymous walk-in customer.
            'loyalty_customer_code' => null,
            // 0 = ineligible per migration comment, also covers
            // "no customer to award to" case.
            'loyalty_points_awarded' => 0,
        ]);

        // Must not throw.
        // dispatchNow bypasses DispatchableAfterCommit guard — RefreshDatabase
        // wraps the test in a transaction, so dispatch() would queue afterCommit
        // and never fire during the test (pattern documented in trait line 63-68).
        RefundCreated::dispatchNow($order);

        $this->assertSame(
            0,
            LoyaltyTransaction::where('order_id', $order->id)
                ->where('type', 'manual_deduct')->count(),
            'No clawback ledger row for anonymous order.'
        );
    }

    /**
     * Zero-awarded NOOP — orders that never received points (kiosk
     * abandoned before delivery, ineligible 0€ comp, etc.) must NOOP
     * gracefully.
     */
    public function test_order_with_zero_awarded_is_noop(): void
    {
        [$order, $customer] = $this->makeOrderWithAwardedPoints(0, 50);
        // Wipe the pre-seeded earn ledger row so the test reflects
        // a true "never awarded" case (helper above writes it).
        LoyaltyTransaction::where('order_id', $order->id)->delete();

        // dispatchNow bypasses DispatchableAfterCommit guard — RefreshDatabase
        // wraps the test in a transaction, so dispatch() would queue afterCommit
        // and never fire during the test (pattern documented in trait line 63-68).
        RefundCreated::dispatchNow($order);

        $customer->refresh();
        $this->assertSame(
            50,
            (int) $customer->loyalty_points,
            'Balance untouched when awarded = 0.'
        );
        $this->assertSame(
            0,
            LoyaltyTransaction::where('order_id', $order->id)
                ->where('type', 'manual_deduct')->count()
        );
    }
}
