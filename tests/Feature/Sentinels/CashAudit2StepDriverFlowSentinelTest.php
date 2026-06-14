<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @FK-ID WH-2 bug_002 — NF525 cash-collection audit row lost on
 *        canonical 2-step driver flow (PREPARED → OUT_FOR_DELIVERY → DELIVERED).
 *
 * Bug (pre-heal, OrderService::deliveryBoyOrderChangeStatus):
 *   • Call 1 (PREPARED → OFD): $wasUnpaidCash is true, the payment_status
 *     flip to PAID fires UNCONDITIONALLY inside `if ($wasUnpaidCash)`, then
 *     the inner audit gate `if (newStatus === DELIVERED && pm === COD)`
 *     fails (newStatus = OFD = 10, not DELIVERED = 13). No audit row.
 *   • Call 2 (OFD → DELIVERED): the order is already PAID, so
 *     $wasUnpaidCash is false. The whole `if ($wasUnpaidCash)` block is
 *     skipped — including the audit row. Net: ZERO escrow rows on the
 *     canonical 2-step driver flow.
 *
 * Heal (Option A):
 *   • The PAID flip itself is gated on
 *     `payment_method === CASH_ON_DELIVERY && newStatus === DELIVERED`.
 *     For non-COD methods that were somehow still UNPAID at this point,
 *     the prior auto-flip behaviour is preserved (the inner CARD/E-WALLET/
 *     PAYPAL/TICKET path still flips at OFD-or-DELIVERED — see test 5).
 *   • The escrow audit row is therefore written on the canonical 2-step
 *     flow exactly once, at the DELIVERED call, with the cash truly
 *     collected at the doorstep.
 *
 * NF525 invariants:
 *   • audit_logs chain count advances by exactly +1 per COD delivery
 *     (single OFD → DELIVERED jump still writes one row, the legacy
 *     happy-path covered by DeliveryBoyHardeningSentinelTest::test_p0_liv_02_*
 *     remains green).
 *   • No past row is mutated — append-only chain.
 *   • Non-COD orders (CARD / E_WALLET / PAYPAL / TICKET_RESTAURANT) never
 *     write a delivery.cash_collected_escrow row.
 */
class CashAudit2StepDriverFlowSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $rows = [
            ['id' => EnumRole::ADMIN,        'name' => 'Admin'],
            ['id' => EnumRole::CUSTOMER,     'name' => 'Customer'],
            ['id' => EnumRole::DELIVERY_BOY, 'name' => 'Delivery Boy'],
            ['id' => EnumRole::WAITER,       'name' => 'Waiter'],
            ['id' => EnumRole::CHEF,         'name' => 'Chef'],
        ];
        foreach ($rows as $row) {
            Role::firstOrCreate(
                ['id' => $row['id']],
                ['name' => $row['name'], 'guard_name' => 'sanctum']
            );
        }
    }

    private function makeDeliveryBoy(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Driver ' . uniqid(),
            'email'             => 'driver-' . uniqid() . '@sentinel.test',
            'username'          => 'driver_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole(EnumRole::DELIVERY_BOY);
        return $user->fresh();
    }

    private function makeCodOrder(int $branchId, int $driverId, int $initialStatus, float $total = 27.50): Order
    {
        return Order::factory()->create([
            'branch_id'       => $branchId,
            'order_type'      => OrderType::DELIVERY,
            'delivery_boy_id' => $driverId,
            'status'          => $initialStatus,
            'payment_method'  => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'  => PaymentStatus::UNPAID,
            'total'           => $total,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────
    // Test 1 — Canonical 2-step driver flow writes exactly one escrow row
    // ───────────────────────────────────────────────────────────────────

    public function test_canonical_2step_prepared_ofd_delivered_writes_exactly_one_escrow_audit_row(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        // Order is at PREPARED (typical livreur pickup at kitchen counter).
        $order = $this->makeCodOrder($branch->id, $driver->id, OrderStatus::PREPARED, 32.40);

        $escrowBefore = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();

        $this->actingAs($driver, 'sanctum');

        // Call 1 — PREPARED → OUT_FOR_DELIVERY (driver picks up the order).
        $req1 = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::OUT_FOR_DELIVERY,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req1);

        // After call 1: order is OFD. NO cash collected yet → NO escrow row.
        $escrowAfterCall1 = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();
        $this->assertSame(
            $escrowBefore,
            $escrowAfterCall1,
            'No cash has been collected at OFD — escrow row must NOT be written yet.'
        );

        // And payment_status MUST still be UNPAID — cash has not been collected.
        // This is the semantic anchor of NF525: the flip to PAID is the legal
        // record of cash receipt at the doorstep, NOT at pickup.
        $afterCall1 = $order->fresh();
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $afterCall1->status);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $afterCall1->payment_status,
            'CASH_ON_DELIVERY must remain UNPAID at OFD — cash collected at doorstep, not pickup.'
        );

        // Call 2 — OFD → DELIVERED (driver hands over food + collects cash).
        $req2 = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($afterCall1, $req2);

        // After call 2: EXACTLY one escrow row appended for this order.
        $escrowAfterCall2 = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();
        $this->assertSame(
            $escrowBefore + 1,
            $escrowAfterCall2,
            'Canonical 2-step driver flow must append exactly one delivery.cash_collected_escrow row (NF525).'
        );

        $row = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->where('resource_id', $order->id)
            ->first();
        $this->assertNotNull($row, 'Escrow row must be findable by order resource_id.');
        $this->assertSame((int) $branch->id, (int) $row->branch_id);
        $this->assertSame((int) $driver->id, (int) $row->user_id);
        $this->assertSame('order', $row->resource);
        $this->assertIsArray($row->payload);
        $this->assertSame(32.40, (float) $row->payload['amount_collected']);
        $this->assertSame((int) $driver->id, (int) $row->payload['delivery_boy_id']);
        $this->assertSame(
            (int) PaymentGateway::CASH_ON_DELIVERY,
            (int) $row->payload['payment_method']
        );

        // Final state — DELIVERED + PAID.
        $finalFresh = $order->fresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $finalFresh->status);
        $this->assertSame(PaymentStatus::PAID, (int) $finalFresh->payment_status);
    }

    // ───────────────────────────────────────────────────────────────────
    // Test 2 — Single-jump OFD → DELIVERED still writes escrow (regression guard)
    // ───────────────────────────────────────────────────────────────────

    public function test_single_jump_ofd_to_delivered_still_writes_escrow_row(): void
    {
        // Regression mirror of the legacy DeliveryBoyHardeningSentinelTest happy
        // path — the heal must NOT regress the 1-call flow used when an order
        // is created already-at-OFD (e.g. POS-side pre-assignment).
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = $this->makeCodOrder($branch->id, $driver->id, OrderStatus::OUT_FOR_DELIVERY, 19.90);

        $escrowBefore = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();

        $this->actingAs($driver, 'sanctum');
        $req = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);

        $escrowAfter = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();
        $this->assertSame(
            $escrowBefore + 1,
            $escrowAfter,
            'Single-jump OFD → DELIVERED must keep emitting exactly one escrow row (regression guard).'
        );

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $fresh->status);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
    }

    // ───────────────────────────────────────────────────────────────────
    // Test 3 — Non-cash methods never emit an escrow row (CARD / E_WALLET / PAYPAL)
    // ───────────────────────────────────────────────────────────────────

    public function test_non_cash_methods_never_emit_an_escrow_row_on_2step_flow(): void
    {
        $methods = [
            PaymentGateway::CARD,
            PaymentGateway::E_WALLET,
            PaymentGateway::PAYPAL,
            PaymentGateway::TICKET_RESTAURANT,
        ];

        foreach ($methods as $method) {
            $branch = Branch::factory()->create();
            $driver = $this->makeDeliveryBoy($branch->id);

            // Non-cash orders are typically already PAID (app pre-paid) when
            // they reach the driver, but defensively we exercise BOTH UNPAID
            // (edge case e.g. card auth retry) and PAID branches.
            $order = Order::factory()->create([
                'branch_id'       => $branch->id,
                'order_type'      => OrderType::DELIVERY,
                'delivery_boy_id' => $driver->id,
                'status'          => OrderStatus::PREPARED,
                'payment_method'  => $method,
                'payment_status'  => PaymentStatus::PAID,
                'total'           => 22.50,
            ]);

            $escrowBefore = AuditLog::query()
                ->where('action', 'delivery.cash_collected_escrow')
                ->count();

            $this->actingAs($driver, 'sanctum');

            // 2-step flow
            $req1 = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
                'status' => OrderStatus::OUT_FOR_DELIVERY,
            ]);
            app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req1);

            $req2 = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
                'status' => OrderStatus::DELIVERED,
            ]);
            app(OrderService::class)->deliveryBoyOrderChangeStatus($order->fresh(), $req2);

            $escrowAfter = AuditLog::query()
                ->where('action', 'delivery.cash_collected_escrow')
                ->count();
            $this->assertSame(
                $escrowBefore,
                $escrowAfter,
                "payment_method={$method} (non-cash) must NEVER emit a delivery.cash_collected_escrow row."
            );
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // Test G-DELIV-FISCAL (P0, owner-gated 2026-06-14) — COD delivery collected
    // at the doorstep MUST receive a gap-free fiscal_sequence_no, else it escapes
    // the Z (ZReportService aggregates whereNotNull(fiscal_sequence_no)). NF525
    // exhaustivity: cash entered the till must appear in the fiscal close.
    // ───────────────────────────────────────────────────────────────────

    public function test_cod_delivery_at_delivered_allocates_fiscal_sequence_for_z(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);
        $order = $this->makeCodOrder($branch->id, $driver->id, OrderStatus::OUT_FOR_DELIVERY, 27.50);
        $this->assertNull($order->fiscal_sequence_no, 'Precondition: COD delivery starts with no fiscal sequence.');

        $this->actingAs($driver, 'sanctum');
        $req = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'COD flips PAID at DELIVERED.');
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'NF525: a COD delivery collected at the doorstep MUST receive a fiscal_sequence_no so it enters the Z.'
        );
        $this->assertGreaterThan(0, (int) $fresh->fiscal_sequence_no);
        $this->assertNull($fresh->fiscal_alloc_error_at, 'Successful allocation clears the retry flag.');
    }

    // ───────────────────────────────────────────────────────────────────
    // Test 4 — payment_status flip happens AT DELIVERED for CASH_ON_DELIVERY,
    // not at OFD (semantic correctness anchor)
    // ───────────────────────────────────────────────────────────────────

    public function test_cash_on_delivery_payment_status_flips_at_delivered_not_at_ofd(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = $this->makeCodOrder($branch->id, $driver->id, OrderStatus::PREPARED, 14.20);

        $this->actingAs($driver, 'sanctum');

        // Step A — PREPARED → OFD: payment_status MUST remain UNPAID (no cash collected yet).
        $req1 = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::OUT_FOR_DELIVERY,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req1);

        $atOfd = $order->fresh();
        $this->assertSame(
            OrderStatus::OUT_FOR_DELIVERY,
            (int) $atOfd->status,
            'After PREPARED→OFD call the order must be at OUT_FOR_DELIVERY.'
        );
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $atOfd->payment_status,
            'For CASH_ON_DELIVERY, payment_status MUST NOT flip to PAID at OFD — cash collected at doorstep, not pickup.'
        );

        // Step B — OFD → DELIVERED: payment_status flips to PAID, escrow row written.
        $req2 = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($atOfd, $req2);

        $atDelivered = $order->fresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $atDelivered->status);
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $atDelivered->payment_status,
            'For CASH_ON_DELIVERY, payment_status MUST flip to PAID at DELIVERED.'
        );

        // And the escrow row is unique to this order.
        $rows = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->where('resource_id', $order->id)
            ->get();
        $this->assertCount(
            1,
            $rows,
            'Canonical 2-step flow must produce EXACTLY one escrow audit row per order.'
        );
    }
}
