<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 DEFECT: KDS changeStatus endpoint lacks release-rule guard.
 * 
 * KitchenDisplaySystemOrderService::changeStatus() allows bumping ANY order
 * that passes the state-machine transition check, but does NOT verify the order
 * is released for kitchen (i.e., paid or POS cash). This violates the invariant
 * enforced by list(), which only shows released orders.
 *
 * Consequence: An unpaid order can be:
 *  1. Invisible in KDS list() → chef sees "order missing"
 *  2. BUT bumpable via direct API call → chef can mark it PREPARING
 *  3. This triggers SendOrderMail/Sms/Push (lines 453-455) → customer notified
 *  4. Before payment is actually received → UX/correctness breach
 *
 * Reproduction:
 *  - Create unpaid DELIVERY order at ACCEPT status
 *  - POST /api/admin/kds-order/change-status/{id} with ACCEPT→PREPARING
 *  - BUG: succeeds (202) + order status changes + notifications fired
 *  - EXPECTED: fails (422) + order unchanged + no notifications
 *
 * Root cause: Line 424-428 checks KitchenReleaseRule::canTransition() which
 * only validates state machine (ACCEPT→PREPARING valid), not release status.
 * list() correctly filters by KitchenReleaseRule::isReleasedToKitchen() at lines 75-82.
 */
class KdsUnreleasedOrderBumpP1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_unpaid_delivery_order_can_be_bumped_via_change_status()
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        // Unpaid delivery order — NOT released per KitchenReleaseRule::isReleasedToKitchen()
        // because payment_status = UNPAID (10) and order_type = DELIVERY (5)
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::WEB,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 1,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $this->actingAs($chef, 'sanctum');

        // Attempt bump via direct API endpoint
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status' => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);

        // BUG: Response is 202 (success) instead of 422 (unreleasable order)
        $this->assertEquals(202, $response->status(),
            'BUG: Unpaid order was bumped (HTTP 202) — should be blocked (HTTP 422)'
        );

        // BUG: Order status actually changed
        $updatedOrder = Order::find($order->id);
        $this->assertEquals(OrderStatus::PREPARING, $updatedOrder->status,
            'BUG: Order status changed to PREPARING despite being unpaid'
        );
    }

    public function test_unpaid_pos_cash_order_can_be_bumped_via_change_status()
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        // POS UNPAID order (cash payment) — SHOULD be released per KitchenReleaseRule
        // because order_type = POS (15) AND pos_payment_method = CASH
        // BUT let's test with a non-cash POS order that's unpaid
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'source' => Source::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'pos_payment_method' => 5, // Not CASH
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $this->actingAs($chef, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status' => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);

        // BUG: Succeeds even though unpaid POS (non-cash)
        $this->assertEquals(202, $response->status(),
            'BUG: Unpaid non-cash POS order was bumped (HTTP 202)'
        );

        $updatedOrder = Order::find($order->id);
        $this->assertEquals(OrderStatus::PREPARING, $updatedOrder->status,
            'BUG: Order status changed despite not being released'
        );
    }
}
