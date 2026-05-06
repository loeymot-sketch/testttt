<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID F-VERIFY-09-01 | @plan docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 *
 * Validates the durcissement of {@see \App\Http\Requests\PaymentStatusRequest}
 * AND the wired state-machine guard in {@see \App\Services\OrderService::changePaymentStatus}.
 */
class ChangePaymentStatusValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch  = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function makeOrder(int $paymentStatus = PaymentStatus::UNPAID): Order
    {
        return Order::factory()->create([
            'user_id'        => $this->cashier->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => $paymentStatus,
            'status'         => OrderStatus::PENDING,
        ]);
    }

    public function test_payment_status_999_returns_422(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => 999,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'Invalid payment_status must NOT mutate the order.'
        );
    }

    public function test_payment_status_string_returns_422(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => 'paid',
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status
        );
    }

    public function test_payment_status_negative_returns_422(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => -1,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status
        );
    }

    public function test_payment_status_paid_to_unpaid_returns_422_via_state_machine(): void
    {
        // PAID is terminal under Option B — illegal transition rejected by
        // PaymentStateMachine::assertCanTransition() inside OrderService.
        $order = $this->makeOrder(PaymentStatus::PAID);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::UNPAID,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::PAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'PAID is terminal — state machine must reject reverting to UNPAID.'
        );
    }
}
