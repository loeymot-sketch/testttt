<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 FIX (was a characterization pair pinning the defect): the KDS
 * change-status endpoint now enforces the board-release rule.
 *
 * KitchenDisplaySystemOrderService::changeStatus() used to allow bumping ANY
 * order that passed the state-machine transition check, without verifying the
 * order is released onto the kitchen board. That violated the invariant
 * enforced by list() (which only surfaces released orders), so an UNPAID order
 * was invisible to the chef yet still bumpable via a direct API call — firing
 * SendOrderMail/Sms/Push customer notifications before payment.
 *
 * Fix: changeStatus() now applies KitchenReleaseRule::orderIsReleasedForBoard()
 * — the SSOT mirror of list()'s applyBoardReleaseFilter(). Unreleased orders
 * (UNPAID delivery, UNPAID non-cash POS) are now blocked with HTTP 422 and the
 * status is unchanged. PENDING_COUNTER and PAID orders stay bumpable.
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

    private function chef(Branch $branch): User
    {
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        return $chef;
    }

    private function bump(Order $order)
    {
        return $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status' => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);
    }

    public function test_unpaid_delivery_order_is_blocked_from_bump(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        // Unpaid delivery order — NOT released (UNPAID, not POS-cash, not PENDING_COUNTER).
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

        $response = $this->bump($order);

        $this->assertEquals(422, $response->status(),
            'Unpaid delivery order must be blocked (HTTP 422), not bumped.');
        $this->assertEquals(OrderStatus::ACCEPT, (int) Order::find($order->id)->status,
            'Status must remain ACCEPT — the unreleased order was not bumped.');
    }

    public function test_unpaid_non_cash_pos_order_is_blocked_from_bump(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        // POS UNPAID non-cash — NOT released (cash exemption requires CASH method).
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'source' => Source::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $response = $this->bump($order);

        $this->assertEquals(422, $response->status(),
            'Unpaid non-cash POS order must be blocked (HTTP 422).');
        $this->assertEquals(OrderStatus::ACCEPT, (int) Order::find($order->id)->status,
            'Status must remain ACCEPT — the unreleased order was not bumped.');
    }

    public function test_paid_order_is_still_bumpable(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::WEB,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $response = $this->bump($order);

        $this->assertEquals(202, $response->status(),
            'Released (PAID) order must still be bumpable — no over-blocking.');
        $this->assertEquals(OrderStatus::PREPARING, (int) Order::find($order->id)->status);
    }

    public function test_pending_counter_order_is_still_bumpable(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        // Plan B kiosk→counter encashment: chef prepares while customer pays at till.
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'source' => Source::WEB,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $response = $this->bump($order);

        $this->assertEquals(202, $response->status(),
            'PENDING_COUNTER order must stay bumpable (Plan B) — list() shows it.');
        $this->assertEquals(OrderStatus::PREPARING, (int) Order::find($order->id)->status);
    }

    public function test_pos_cash_unpaid_order_is_still_bumpable(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        // POS cash collected at close — released before the PAID flag flips.
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'source' => Source::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $response = $this->bump($order);

        $this->assertEquals(202, $response->status(),
            'POS cash order must stay bumpable — released before paid flag.');
        $this->assertEquals(OrderStatus::PREPARING, (int) Order::find($order->id)->status);
    }
}
