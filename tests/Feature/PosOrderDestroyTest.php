<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [POS-9.1.2] OrderService::destroy — branch isolation, PAID guard, ActionLog.
 *
 * Ensures:
 * - Order belonging to branch A cannot be destroyed by a branch-B operator (403).
 * - A PAID order cannot be destroyed by a standard operator (403).
 * - A PAID order CAN be destroyed by a user carrying `pos-destroy-paid`.
 * - Destruction is soft (Order::trashed() = true, no forceDelete).
 * - An immutable `action_logs` row is written with action=order.destroyed.
 */
class PosOrderDestroyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashierA;
    protected User $cashierB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->cashierA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password' => Hash::make('pwd'),
        ]);
        $this->cashierA->assignRole('POS Operator');

        $this->cashierB = User::factory()->create([
            'branch_id' => $this->branchB->id,
            'password' => Hash::make('pwd'),
        ]);
        $this->cashierB->assignRole('POS Operator');
    }

    private function makeOrder(int $branchId, int $paymentStatus = PaymentStatus::UNPAID): Order
    {
        return Order::factory()->create([
            'branch_id'      => $branchId,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::PENDING,
            'payment_status' => $paymentStatus,
            'total'          => 12.50,
        ]);
    }

    public function test_cannot_destroy_order_from_another_branch(): void
    {
        $order = $this->makeOrder($this->branchA->id);
        $this->actingAs($this->cashierB, 'sanctum'); // wrong branch

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson('/api/admin/pos-order/' . $order->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_destroy_paid_order_without_permission(): void
    {
        $order = $this->makeOrder($this->branchA->id, PaymentStatus::PAID);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson('/api/admin/pos-order/' . $order->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'deleted_at' => null,
        ]);
    }

    public function test_can_destroy_paid_order_with_permission(): void
    {
        $this->cashierA->givePermissionTo('pos-destroy-paid');
        $order = $this->makeOrder($this->branchA->id, PaymentStatus::PAID);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson('/api/admin/pos-order/' . $order->id . '?destroy_reason=Test%20motif');

        $response->assertStatus(202);
        // Soft-deleted
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_destroy_writes_action_log(): void
    {
        $order = $this->makeOrder($this->branchA->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson('/api/admin/pos-order/' . $order->id . '?destroy_reason=Client%20no-show');

        $log = ActionLog::where('action', 'order.destroyed')
            ->where('resource', 'Order #' . $order->id)
            ->first();

        $this->assertNotNull($log, 'ActionLog should be written on destroy.');
        $this->assertEquals($this->cashierA->id, $log->user_id);
        $details = json_decode($log->details, true);
        $this->assertEquals($order->id, $details['order_id']);
        $this->assertEquals($this->branchA->id, $details['branch_id']);
        $this->assertEquals('Client no-show', $details['reason']);
    }
}
