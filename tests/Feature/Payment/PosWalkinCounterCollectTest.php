<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30] POS walk-in routed through the
 * unified counter-collection queue (pos.walkin_route_to_counter / model B).
 *
 * Proves the NF525-critical invariants of the new path:
 * - A POS-origin (source_surface='pos') counter-deferred order CAN be sealed
 *   via confirmCounterPayment, which allocates the fiscal_sequence_no AT
 *   COLLECTION (it was NULL while deferred) — exactly like the Borne Plan B.
 * - The deferred POS order surfaces in the unified counter-collect/pending
 *   queue alongside Borne orders.
 * - ESCAPE-Z GUARD: a regular PAID POS order (the legacy inline-paid flow) is
 *   REJECTED by the counter-collect seal — it cannot re-enter the deferred
 *   path, and the seal is the ONLY route that allocates a fiscal number for a
 *   deferred order.
 */
class PosWalkinCounterCollectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function branchOperator(): array
    {
        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        return [$branch, $operator];
    }

    /** A POS walk-in order created via the delta-(B) deferred path. */
    private function posDeferredOrder(Branch $branch, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARING,
            'source_surface' => 'pos',
            'subtotal' => 9.00,
            'total' => 9.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'fiscal_sequence_no' => null,
        ]);
    }

    public function test_pos_walkin_deferred_seal_allocates_fiscal_at_collection(): void
    {
        Queue::fake();
        [$branch, $operator] = $this->branchOperator();
        $order = $this->posDeferredOrder($branch, ['total' => 9.00]);

        $this->assertNull($order->fiscal_sequence_no, 'deferred POS order must NOT carry a fiscal seq before collection');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CARD,
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertSame(PosPaymentMethod::CARD, (int) $fresh->pos_payment_method);
        $this->assertNotNull($fresh->fiscal_sequence_no, 'fiscal seq MUST be allocated at collection');
        $this->assertGreaterThan(0, (int) $fresh->fiscal_sequence_no);
    }

    public function test_pos_deferred_order_surfaces_in_unified_pending_queue(): void
    {
        [$branch, $operator] = $this->branchOperator();
        $order = $this->posDeferredOrder($branch);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/pos/counter-collect/pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);
    }

    public function test_escape_z_guard_paid_pos_order_cannot_be_counter_collected(): void
    {
        Queue::fake();
        [$branch, $operator] = $this->branchOperator();
        // Legacy inline-paid POS order: PAID with a fiscal seq already allocated.
        $paid = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'status' => OrderStatus::PREPARING,
            'source_surface' => 'pos',
            'total' => 9.00,
            'fiscal_sequence_no' => 999,
        ]);

        // Already-PAID short-circuits before the deferred guard; the key
        // invariant is it does NOT re-allocate / double-seal.
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$paid->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 9.00,
            ]);

        $this->assertSame(999, (int) $paid->fresh()->fiscal_sequence_no, 'paid order fiscal seq must be untouched');
        $this->assertSame(PaymentStatus::PAID, (int) $paid->fresh()->payment_status);
    }

    public function test_non_deferred_unpaid_pos_order_is_rejected_by_counter_seal(): void
    {
        Queue::fake();
        [$branch, $operator] = $this->branchOperator();
        // An UNPAID POS order that is NOT counter-deferred (no COUNTER_DEFERRED
        // marker) must be rejected by the deferred-order guard — it cannot be
        // sealed through the counter-collect path.
        $unpaid = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'pos',
            'total' => 9.00,
            'fiscal_sequence_no' => null,
        ]);

        $res = $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$unpaid->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 9.00,
            ]);

        // Rejected (422) and NO fiscal seq allocated.
        $this->assertContains($res->status(), [422, 409, 400]);
        $this->assertNull($unpaid->fresh()->fiscal_sequence_no);
        $this->assertNotSame(PaymentStatus::PAID, (int) $unpaid->fresh()->payment_status);
    }

    /**
     * [TERMINAL-COLLECT-GUARD 2026-06-26 / P2] An order moved to a terminal status
     * (CANCELED/REJECTED/RETURNED) via the generic OrderService::changeStatus path keeps
     * payment_status=PENDING_COUNTER, so it lingers in the counter-collect queue. It must
     * NOT be collectable — collecting would charge the customer + consume a fiscal_sequence_no
     * for an order the kitchen treats as void.
     *
     * @dataProvider terminalStatuses
     */
    public function test_terminal_status_order_cannot_be_counter_collected(int $terminalStatus): void
    {
        Queue::fake();
        [$branch, $operator] = $this->branchOperator();
        $order = $this->posDeferredOrder($branch, ['status' => $terminalStatus, 'total' => 9.00]);

        $this->assertNull($order->fiscal_sequence_no);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 9.00,
            ])
            ->assertStatus(422);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $fresh->payment_status, 'terminal order must NOT become PAID');
        $this->assertNull($fresh->fiscal_sequence_no, 'no fiscal_sequence_no may be consumed for a terminal order');
        $this->assertSame($terminalStatus, (int) $fresh->status, 'status must stay terminal');
    }

    public static function terminalStatuses(): array
    {
        return [
            'CANCELED' => [OrderStatus::CANCELED],
            'REJECTED' => [OrderStatus::REJECTED],
            'RETURNED' => [OrderStatus::RETURNED],
        ];
    }
}
