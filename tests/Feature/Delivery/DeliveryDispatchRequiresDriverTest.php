<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G-DELIV-ORPHAN (P1, owner-gated 2026-06-14) — a DELIVERY order must not be
 * dispatched OUT_FOR_DELIVERY with no assigned driver. "Out for delivery" with
 * delivery_boy_id=NULL is an incoherent state: no one is delivering it, COD is
 * never collected, and the cash/fiscal trail breaks. The driver self-service path
 * is already safe (requires delivery_boy_id==auth); this closes the admin/OSS path
 * (OrderService::changeStatus validated only branch + ValidStatusTransition).
 */
class DeliveryDispatchRequiresDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        return $admin;
    }

    private function deliveryOrder(int $branchId, ?int $driverId): Order
    {
        return Order::factory()->create([
            'branch_id'       => $branchId,
            'order_type'      => OrderType::DELIVERY,
            'status'          => OrderStatus::PREPARED,
            'payment_status'  => PaymentStatus::PAID,
            'delivery_boy_id' => $driverId,
        ]);
    }

    private function dispatch(Order $order): void
    {
        $req = OrderStatusRequest::create('/x', 'POST', ['status' => OrderStatus::OUT_FOR_DELIVERY]);
        app(OrderService::class)->changeStatus($order, $req);
    }

    public function test_dispatch_to_ofd_is_blocked_when_no_driver_assigned(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->admin(), 'sanctum');
        $order = $this->deliveryOrder($branch->id, null);

        $threw = false;
        try {
            $this->dispatch($order);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Dispatching a driverless DELIVERY order to OUT_FOR_DELIVERY must be rejected.');
        $this->assertSame(
            OrderStatus::PREPARED,
            (int) $order->fresh()->status,
            'A driverless delivery order must NOT advance to OUT_FOR_DELIVERY.'
        );
    }

    /**
     * P1b (code-review of G-DELIV-FISCAL) — the admin/OSS path must NOT finalize an
     * UNPAID COD delivery to DELIVERED: that marks it off-book (no PAID flip, no
     * fiscal_sequence_no, no cash-escrow audit). Only the driver collects COD cash
     * at the doorstep via the fiscally-correct deliveryBoyOrderChangeStatus path.
     */
    public function test_admin_cannot_finalize_unpaid_cod_delivery_to_delivered(): void
    {
        $branch = Branch::factory()->create();
        $driver = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($this->admin(), 'sanctum');
        $order = Order::factory()->create([
            'branch_id'       => $branch->id,
            'order_type'      => OrderType::DELIVERY,
            'status'          => OrderStatus::OUT_FOR_DELIVERY,
            'payment_status'  => PaymentStatus::UNPAID,
            'payment_method'  => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'delivery_boy_id' => $driver->id,
        ]);

        $threw = false;
        try {
            $req = OrderStatusRequest::create('/x', 'POST', ['status' => OrderStatus::DELIVERED]);
            app(OrderService::class)->changeStatus($order, $req);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Admin must not finalize an unpaid COD delivery off-book.');
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $fresh->status, 'Order must not advance to DELIVERED via the admin off-book path.');
        $this->assertNull($fresh->fiscal_sequence_no, 'No off-book fiscal allocation.');
    }

    public function test_dispatch_to_ofd_succeeds_with_driver_assigned(): void
    {
        $branch = Branch::factory()->create();
        $driver = User::factory()->create(['branch_id' => $branch->id]); // just the FK target; no role needed
        $this->actingAs($this->admin(), 'sanctum');
        $order = $this->deliveryOrder($branch->id, $driver->id);

        $this->dispatch($order);

        $this->assertSame(
            OrderStatus::OUT_FOR_DELIVERY,
            (int) $order->fresh()->status,
            'A delivery order WITH an assigned driver must dispatch normally.'
        );
    }
}
