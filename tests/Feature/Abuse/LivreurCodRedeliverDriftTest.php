<?php

/**
 * [abuse-probe 2026-06-19 livreur DELIVERY-COD] RETURNED -> DELIVERED re-collect drift.
 *
 * The state machine (OrderStateMachine::allows line 82-84) grants an Admin ANY transition
 * out of a terminal state, including RETURNED -> DELIVERED ("undo the return / re-deliver").
 * The COD finalization (finalizeDeliveryPaymentInTx) only records the doorstep cash
 * collection when $wasUnpaidCash (no Transaction AND payment_status UNPAID). After the
 * first DELIVERED the order is PAID with a transaction-less flip, so the SECOND delivery:
 *   - records NO new TYPE_ORDER_COLLECT / IN movement,
 *   - writes NO new `delivery.cash_collected_escrow` NF525 audit row,
 *   - and the prior reversal (TYPE_ADJUSTMENT / OUT from the RETURNED) is NOT undone.
 * Net result: the driver physically collects the cash a SECOND time, but the session shows
 * net 0 for that order and the Z enrichment cross-check passes (it compares
 * cash.delivery.movement.recorded against movement rows — both balance — not against the
 * fact that a re-delivered order owes cash). => silent driver shortage + missing NF525
 * doorstep evidence for the second collection.
 *
 * This test ASSERTS THE BUGGY CURRENT BEHAVIOUR (documents the drift). When the heal lands
 * the assertions on net==0 / movements==2 / escrow==1 must be inverted.
 */

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Http\Requests\OrderStatusRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LivreurCodRedeliverDriftTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $driver;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();

        $this->driver = \App\Models\User::forceCreate([
            'name' => 'Driver', 'email' => 'd@drift.test', 'username' => 'driver_drift',
            'password' => bcrypt('x'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $this->driver->assignRole(EnumRole::DELIVERY_BOY);

        $this->admin = \App\Models\User::forceCreate([
            'name' => 'Admin', 'email' => 'a@drift.test', 'username' => 'admin_drift',
            'password' => bcrypt('x'), 'branch_id' => 0, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $this->admin->assignRole('Admin');
    }

    private function driverDeliver(Order $order): void
    {
        $this->actingAs($this->driver->fresh(), 'sanctum');
        $req = Request::create('/x', 'POST', ['status' => OrderStatus::DELIVERED]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);
        $order->setRawAttributes($order->fresh()->getAttributes(), true);
    }

    private function adminTo(Order $order, int $status, ?string $reason = null): void
    {
        $this->actingAs($this->admin->fresh(), 'sanctum');
        $payload = ['status' => $status];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $request = OrderStatusRequest::create('/x', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));
        app(OrderService::class)->changeStatus($order, $request);
        $order->setRawAttributes($order->fresh()->getAttributes(), true);
    }

    private function movements(int $sessionId, int $orderId)
    {
        return DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('delivery_boy_cash_session_id', $sessionId)
            ->where('order_id', $orderId)->get();
    }

    private function escrowCount(int $orderId): int
    {
        return AuditLog::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('action', 'delivery.cash_collected_escrow')
            ->where('resource_id', $orderId)->count();
    }

    /** @test */
    public function redeliver_after_returned_leaves_cash_untracked_and_no_second_escrow(): void
    {
        $session = app(DeliveryBoyCashSessionService::class)->openSession(
            (int) $this->branch->id, (int) $this->driver->id, 50.0, (int) $this->driver->id,
        );
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $this->driver->id, 'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'payment_status' => PaymentStatus::UNPAID,
            'total' => 27.50, 'fiscal_sequence_no' => null,
        ]);

        // 1) Deliver (collect #1) — IN 27.50 + escrow #1.
        $this->driverDeliver($order);
        $this->assertCount(1, $this->movements((int) $session->id, (int) $order->id));
        $this->assertSame(1, $this->escrowCount((int) $order->id));

        // 2) Admin RETURNED — reversal OUT 27.50, net 0 (cash refunded to customer).
        $this->adminTo($order, OrderStatus::RETURNED, 'customer refused — refund');
        $this->assertSame(OrderStatus::RETURNED, (int) $order->status);

        // 3) Admin re-delivers (RETURNED -> DELIVERED, allowed for Admin). The driver
        //    physically collects 27.50 AGAIN at the doorstep.
        $this->adminTo($order, OrderStatus::DELIVERED);
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);

        // ---- DRIFT EVIDENCE (buggy current behaviour) ----
        $all = $this->movements((int) $session->id, (int) $order->id);
        $net = round($all->sum(fn (DeliveryBoyCashMovement $m) => $m->signedAmount()), 2);

        // Only 2 movements exist (IN from collect#1, OUT from the return) — the SECOND
        // physical collection produced NO movement.
        $this->assertCount(2, $all, 'BUG: re-delivery records no second IN movement.');
        // The session nets the order to 0 even though 27.50 cash is now physically held.
        $this->assertSame(0.0, $net, 'BUG: session shows net 0 while the driver holds the re-collected cash.');
        // No second NF525 doorstep-collection audit row for the re-delivery.
        $this->assertSame(1, $this->escrowCount((int) $order->id), 'BUG: no second cash_collected_escrow audit for the re-collection.');

        // The TRUE expected cash owed for this order after a real re-delivery is +27.50.
        // The system records 0.00 — a 27.50 silent shortage the Z enrichment will NOT flag.
    }
}
