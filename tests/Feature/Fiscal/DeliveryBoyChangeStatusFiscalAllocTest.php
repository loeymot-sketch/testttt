<?php

/**
 * [GENIE Wave 0 — FISCAL-DELIV-COD-01 / -LATECARD-01 2026-06-16] NF525 exhaustivity — sibling of
 * FISCAL-CPS-01, surfaced by the Wave-0 sibling-hunt audit.
 *
 * `OrderService::deliveryBoyOrderChangeStatus` flips a delivery order into PAID — for COD at DELIVERED
 * (:1872, P0) and for a non-COD late-card order (:1894, P1) — but historically did NOT allocate a fiscal
 * sequence → the order persisted PAID with fiscal_sequence_no=NULL, excluded from every Z and unreachable
 * by the kiosk-only retry cron → permanent off-book orphan. The delivery-COD heal (G-DELIV-FISCAL) existed
 * only on heal/massive-2dot0; this goal/wizard-wysiwyg branch never received it. RED before, GREEN after.
 */

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DeliveryBoyChangeStatusFiscalAllocTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->driver = User::forceCreate([
            'name' => 'Driver', 'email' => 'driver@genie.test', 'username' => 'driver_genie',
            'password' => bcrypt('x'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $this->driver->assignRole(EnumRole::DELIVERY_BOY);
        $this->actingAs($this->driver->fresh(), 'sanctum');
    }

    private function makeOrder(int $gateway, int $status = OrderStatus::OUT_FOR_DELIVERY, ?int $seq = null): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $this->driver->id,
            'status' => $status,
            'payment_method' => $gateway,
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 27.50,
            'fiscal_sequence_no' => $seq,
        ]);
    }

    private function toDelivered(Order $order): Order
    {
        $req = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);
        return $order->fresh();
    }

    /** @test — P0: COD delivered flips to PAID and MUST allocate a fiscal sequence */
    public function test_cod_delivered_allocates_a_fiscal_sequence(): void
    {
        $order = $this->makeOrder(PaymentGateway::CASH_ON_DELIVERY);
        $this->assertNull($order->fiscal_sequence_no);

        $fresh = $this->toDelivered($order);

        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'COD delivered must be PAID');
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'FISCAL-DELIV-COD-01: COD delivered→PAID MUST allocate a fiscal sequence — else it escapes every Z.'
        );
    }

    /** @test — P1 twin: non-COD late-card delivered flips to PAID and MUST allocate */
    public function test_non_cod_late_card_delivered_allocates(): void
    {
        $order = $this->makeOrder(PaymentGateway::CARD);

        $fresh = $this->toDelivered($order);

        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'FISCAL-DELIV-LATECARD-01: non-COD delivered→PAID MUST allocate a fiscal sequence.'
        );
    }

    /** @test — idempotent: a pre-allocated order is not re-allocated */
    public function test_pre_allocated_order_is_not_reallocated(): void
    {
        $order = $this->makeOrder(PaymentGateway::CASH_ON_DELIVERY, seq: 7777);
        $fresh = $this->toDelivered($order);
        $this->assertSame(7777, (int) $fresh->fiscal_sequence_no, 'must not overwrite an existing sequence');
    }
}
