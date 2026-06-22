<?php

/**
 * [abuse-heal 2026-06-19 deliv-admin-twin] — admin changeStatus is the unguarded
 * TWIN of the driver-app deliveryBoyOrderChangeStatus.
 *
 * Runtime-confirmed on POST /api/admin/online-order/change-status (order 4981):
 *
 *  P1 ORPHAN — admin changeStatus let a DELIVERY order reach OUT_FOR_DELIVERY with
 *  delivery_boy_id = NULL (an unassigned order "on the road"). HTTP 200 observed.
 *  The driver path requires the order be assigned to the calling driver before any
 *  transition; the admin path had no equivalent "driver required" guard for OFD.
 *
 *  P1 OFF-BOOK COD (NF525) — admin changeStatus let a CASH_ON_DELIVERY delivery
 *  order reach DELIVERED while leaving payment_status = UNPAID and
 *  fiscal_sequence_no = NULL → the COD sale escaped every Z (ZReportService
 *  whereNotNull) and was unreachable by the kiosk-only retry cron = permanent
 *  off-book orphan. The driver path (OrderService::deliveryBoyOrderChangeStatus)
 *  correctly, on COD→DELIVERED: flips payment_status→PAID, allocates the next
 *  gap-free fiscal_sequence_no via FiscalSequenceService, writes the NF525
 *  cash-collection audit row, and records the DeliveryBoyCashMovement.
 *
 * The heal extracts that COD-delivery finalization into a shared private method
 * reused by BOTH paths (no 3rd twin) and adds the orphan guard to the admin path,
 * scoped strictly to order_type === DELIVERY so TAKEAWAY / DINE_IN / POS behaviour
 * through changeStatus is untouched. RED before, GREEN after.
 *
 * :memory: note — SQLite runs these sequentially; the locked-tx atomicity of the
 * fix (lockForUpdate + DB::transaction, already present in changeStatus) is the
 * driver-path's documented pattern and is exercised end-to-end against MySQL :8766.
 */

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDeliveryChangeStatusGuardAbuseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        // Admin (branch_id = 0 bypass) — the /api/admin/online-order endpoint runs as admin.
        $this->admin = User::forceCreate([
            'name' => 'Admin', 'email' => 'admin@deliv-twin.test', 'username' => 'admin_deliv_twin',
            'password' => bcrypt('x'), 'branch_id' => 0, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $this->admin->assignRole(EnumRole::ADMIN);

        // A driver to assign legitimate deliveries to.
        $this->driver = User::forceCreate([
            'name' => 'Driver', 'email' => 'driver@deliv-twin.test', 'username' => 'driver_deliv_twin',
            'password' => bcrypt('x'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $this->driver->assignRole(EnumRole::DELIVERY_BOY);

        $this->actingAs($this->admin->fresh(), 'sanctum');
    }

    private function statusRequest(int $status, ?string $reason = null): OrderStatusRequest
    {
        // changeStatus reads only ->status (and ->reason for cancel/reject/return);
        // validation runs at the controller boundary, not inside the service.
        $payload = ['status' => $status];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $request = OrderStatusRequest::create('/api/admin/online-order/change-status/x', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));

        return $request;
    }

    private function makeDeliveryOrder(
        int $gateway,
        int $status = OrderStatus::PREPARED,
        ?int $driverId = null,
        int $paymentStatus = PaymentStatus::UNPAID,
        ?int $seq = null
    ): Order {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'delivery_boy_id'    => $driverId,
            'status'             => $status,
            'payment_method'     => $gateway,
            'payment_status'     => $paymentStatus,
            'total'              => 27.50,
            'fiscal_sequence_no' => $seq,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  P1 ORPHAN
    // ------------------------------------------------------------------ //

    /** @test — admin must NOT push a driverless DELIVERY order OUT_FOR_DELIVERY */
    public function test_admin_cannot_send_unassigned_delivery_out_for_delivery(): void
    {
        $order = $this->makeDeliveryOrder(PaymentGateway::CASH_ON_DELIVERY, OrderStatus::PREPARED, driverId: null);

        $threw = false;
        try {
            app(OrderService::class)->changeStatus($order, $this->statusRequest(OrderStatus::OUT_FOR_DELIVERY));
        } catch (\Throwable $e) {
            $threw = true;
            $code = (int) ($e->getCode() ?: ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getStatusCode() : 0));
            $this->assertSame(422, $code, 'Driverless OFD must be rejected with 422 (invalid transition / driver required).');
        }

        $this->assertTrue($threw, 'ORPHAN: admin changeStatus must BLOCK OUT_FOR_DELIVERY when delivery_boy_id is NULL.');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PREPARED, (int) $fresh->status, 'Status must stay PREPARED — no orphan on the road.');
        $this->assertNull($fresh->delivery_boy_id);
    }

    // ------------------------------------------------------------------ //
    //  P1 OFF-BOOK COD (NF525)
    // ------------------------------------------------------------------ //

    /** @test — admin COD DELIVERY → DELIVERED must finalize: PAID + fiscal seq + driver cash movement */
    public function test_admin_cod_delivered_finalizes_paid_fiscal_and_cash_movement(): void
    {
        $order = $this->makeDeliveryOrder(
            PaymentGateway::CASH_ON_DELIVERY,
            OrderStatus::OUT_FOR_DELIVERY,
            driverId: $this->driver->id
        );
        $this->assertNull($order->fiscal_sequence_no);

        // Give the driver an OPEN cash session so the movement has somewhere to land.
        $session = app(DeliveryBoyCashSessionService::class)->openSession(
            (int) $this->branch->id,
            (int) $this->driver->id,
            0.0,
            (int) $this->driver->id,
        );

        app(OrderService::class)->changeStatus($order, $this->statusRequest(OrderStatus::DELIVERED));

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'COD delivered (admin path) must be PAID.');
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'NF525: admin COD delivered→PAID MUST allocate a fiscal sequence — else it escapes every Z.'
        );

        $movement = DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('delivery_boy_cash_session_id', $session->id)
            ->where('order_id', $order->id)
            ->where('type', DeliveryBoyCashMovement::TYPE_ORDER_COLLECT)
            ->first();

        $this->assertNotNull($movement, 'A driver cash movement (order_collect) must be recorded on admin COD DELIVERED.');
        $this->assertSame(DeliveryBoyCashMovement::DIRECTION_IN, $movement->direction);
        $this->assertEqualsWithDelta(27.50, (float) $movement->amount, 0.001);
    }

    /** @test — idempotent: a pre-allocated COD order keeps its sequence */
    public function test_admin_cod_delivered_does_not_reallocate_existing_sequence(): void
    {
        $order = $this->makeDeliveryOrder(
            PaymentGateway::CASH_ON_DELIVERY,
            OrderStatus::OUT_FOR_DELIVERY,
            driverId: $this->driver->id,
            seq: 8888
        );

        app(OrderService::class)->changeStatus($order, $this->statusRequest(OrderStatus::DELIVERED));

        $this->assertSame(8888, (int) $order->fresh()->fiscal_sequence_no, 'Must not overwrite an existing sequence.');
    }

    // ------------------------------------------------------------------ //
    //  REGRESSION — non-delivery + happy-path delivery
    // ------------------------------------------------------------------ //

    /** @test — TAKEAWAY through changeStatus is unaffected by the delivery guards */
    public function test_takeaway_change_status_is_unaffected(): void
    {
        // PREPARED→DELIVERED is a legal edge; a TAKEAWAY order must transition with
        // NO delivery/COD side-effects (no fiscal alloc, no cash movement, no PAID flip
        // driven by this code path) and certainly must not be blocked by a driver guard.
        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
            'delivery_boy_id'    => null,
            'status'             => OrderStatus::PREPARED,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'total'              => 12.00,
            'fiscal_sequence_no' => null,
        ]);

        app(OrderService::class)->changeStatus($order, $this->statusRequest(OrderStatus::DELIVERED));

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $fresh->status, 'TAKEAWAY PREPARED→DELIVERED must succeed.');
        // The delivery-COD finalization must NOT have run for a non-DELIVERY order.
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'TAKEAWAY must not be fiscally finalized by the delivery code path in changeStatus.'
        );
        $this->assertSame(0, DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('order_id', $order->id)->count(),
            'No driver cash movement for a TAKEAWAY order.');
    }

    /** @test — cancelling an UNPAID non-COD DELIVERY order must NOT flip it PAID nor allocate a sequence */
    public function test_cancel_unpaid_delivery_does_not_finalize_payment(): void
    {
        // An admin cancelling a still-UNPAID delivery order (e.g. CARD that never
        // captured) must leave it UNPAID with no fiscal sequence — cancelling is not
        // a sale. The delivery finalization must only fire on the forward
        // OUT_FOR_DELIVERY / DELIVERED anchors, never on CANCELED/REJECTED.
        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'delivery_boy_id'    => null,
            'status'             => OrderStatus::PREPARING, // PREPARING→CANCELED is a legal edge
            'payment_method'     => PaymentGateway::CARD,
            'payment_status'     => PaymentStatus::UNPAID,
            'total'              => 30.00,
            'fiscal_sequence_no' => null,
        ]);

        app(OrderService::class)->changeStatus(
            $order,
            $this->statusRequest(OrderStatus::CANCELED, 'OUT_OF_STOCK')
        );

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $fresh->status);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $fresh->payment_status,
            'Cancelling an unpaid delivery must NOT auto-mark it PAID.'
        );
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'Cancelling an unpaid delivery must NOT allocate a fiscal sequence.'
        );
    }

    /** @test — a DELIVERY order WITH a driver may go OUT_FOR_DELIVERY */
    public function test_assigned_delivery_can_go_out_for_delivery(): void
    {
        $order = $this->makeDeliveryOrder(
            PaymentGateway::CASH_ON_DELIVERY,
            OrderStatus::PREPARED,
            driverId: $this->driver->id
        );

        app(OrderService::class)->changeStatus($order, $this->statusRequest(OrderStatus::OUT_FOR_DELIVERY));

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $fresh->status, 'Assigned delivery must be allowed OFD.');
        // OFD is not the fiscal anchor for COD — payment stays UNPAID, no sequence yet.
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertNull($fresh->fiscal_sequence_no);
    }
}
