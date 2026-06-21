<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ABUSE PROBE — pos-refund permission gate bypass via change-status RETURN.
 *
 * The dedicated refund endpoint (refund-with-counter-entry) requires the
 * `pos-refund` permission (Admin / Branch Manager only) as a documented
 * "mass-refund vector by junior cashier" mitigation. This probe demonstrates
 * the SAME financial effect (full cashBack) is reachable by a plain POS
 * Operator (who lacks pos-refund) through the legacy change-status -> RETURNED
 * path on a DELIVERED order, because OrderStateMachine::allows(DELIVERED,
 * RETURNED) is unconditional.
 */
class PosRefundChangeStatusBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
    }

    private function posOperator(Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('POS Operator'); // has pos-orders, NOT pos-refund
        return $user;
    }

    private function deliveredPaidOrder(Branch $branch, User $owner): Order
    {
        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $owner->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 20.00,
            'total'              => 20.00,
            'discount'           => 0,
            'delivery_charge'    => 0,
        ]);
        // A prior 'payment' transaction is required for cashBack() to issue money.
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'PAY-TEST',
            'amount'         => 20.00,
            'payment_method' => 'cash',
            'sign'           => '+',
            'type'           => 'payment',
        ]);
        return $order;
    }

    /** @test */
    public function pos_operator_is_denied_by_the_dedicated_refund_endpoint(): void
    {
        $branch = Branch::factory()->create();
        $operator = $this->posOperator($branch);
        $order = $this->deliveredPaidOrder($branch, $operator);

        Sanctum::actingAs($operator, ['admin:web']);

        $this->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
            'reason' => 'cashier attempts a refund',
        ], ['X-Idempotency-Key' => 'probe-ce-' . $order->id])
            ->assertStatus(403);

        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count());
    }

    /**
     * @test
     * HEAL W1b POS-REFUND-BYPASS-01: a POS Operator without pos-refund can NO LONGER
     * issue a refund via change-status -> RETURNED. The controller gates the RETURNED
     * transition on pos-refund (mirroring the dedicated /refund-with-counter-entry
     * endpoint), so the change-status bypass now returns 403 and issues NO cashBack.
     */
    public function pos_operator_is_blocked_from_refund_via_change_status_returned(): void
    {
        $branch = Branch::factory()->create();
        $operator = $this->posOperator($branch);
        $order = $this->deliveredPaidOrder($branch, $operator);

        $this->assertFalse($operator->can('pos-refund'));

        Sanctum::actingAs($operator, ['admin:web']);

        $this->postJson("/api/admin/pos-order/change-status/{$order->id}", [
            'status' => OrderStatus::RETURNED,
            'reason' => 'attempting the pos-refund bypass via change-status',
        ], ['X-Idempotency-Key' => 'probe-bypass-' . $order->id])
            ->assertStatus(403);

        $order->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status, 'status must NOT flip to RETURNED');
        $this->assertSame(
            0,
            Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count(),
            'no cashBack may be issued by a non-pos-refund operator'
        );
    }

    /**
     * @test
     * Control — a refund-capable user (Branch Manager, has pos-refund) CAN still
     * refund via change-status -> RETURNED (the gate does not over-block the legit path).
     */
    public function branch_manager_with_pos_refund_can_refund_via_change_status(): void
    {
        $branch = Branch::factory()->create();
        $owner = $this->posOperator($branch);
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->assignRole('Branch Manager');
        // Grant pos-refund directly (test seedSpatieRoles does not wire the role→permission
        // map; the guard under test checks the permission, not the role name).
        $manager->givePermissionTo('pos-refund');
        $this->assertTrue($manager->can('pos-refund'));

        $order = $this->deliveredPaidOrder($branch, $owner);

        Sanctum::actingAs($manager, ['admin:web']);

        $this->postJson("/api/admin/pos-order/change-status/{$order->id}", [
            'status' => OrderStatus::RETURNED,
            'reason' => 'legitimate manager refund',
        ], ['X-Idempotency-Key' => 'mgr-refund-' . $order->id])
            ->assertStatus(200);

        $order->refresh();
        $this->assertSame(OrderStatus::RETURNED, (int) $order->status);
        $cashBack = Transaction::where('order_id', $order->id)->where('type', 'cash_back')->first();
        $this->assertNotNull($cashBack, 'manager with pos-refund issues the cashBack');
        $this->assertEquals(20.00, (float) $cashBack->amount);
    }
}
