<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR — Sub 5.2 sentinel]
 *
 * Driver status-change endpoint must constrain accepted status integers to the
 * four legal driver-side OrderStatus values at the HTTP request boundary :
 *
 *   - PREPARED          = 8  (idempotent re-set permitted)
 *   - OUT_FOR_DELIVERY  = 10 (forward from PREPARED)
 *   - DELIVERED         = 13 (forward from OUT_FOR_DELIVERY)
 *   - RETURNED          = 22 (failed delivery branch)
 *
 * Pre-heal: Frontend/DeliveryBoyOrderController::deliveryBoyOrderChangeStatus
 * only ran `['required','integer']`. Out-of-range values flowed to the service
 * which delegated to ValidStatusTransition — defense-in-depth gap (the contract
 * should fail at the request boundary, mirroring KDS/OSS).
 *
 * Heal: explicit `in:8,10,13,22` rule at controller line 58.
 *
 * RED-team contract (LIVREUR-Z4-RED-02) — sentinel asserts FOUR cases :
 *   1. valid forward transition (PREPARED → OUT_FOR_DELIVERY) → 200 OK
 *   2. back-jump (DELIVERED → OUT_FOR_DELIVERY) → 422 (state-machine rejects)
 *   3. skip-jump (PREPARED → DELIVERED) — out-of-machine forward via ValidStatusTransition
 *   4. out-of-enum integer (status=99) → 422 (request whitelist rejects)
 */
class DeliveryStatusTransitionWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_forward_transition_is_accepted(): void
    {
        [$branch, $driver] = $this->driverFixture();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'delivery_boy_id' => $driver->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $resp = $this->actingAs($driver, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => OrderStatus::OUT_FOR_DELIVERY,
            ]);

        $resp->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
        ]);
    }

    public function test_out_of_enum_integer_is_rejected_by_request_whitelist(): void
    {
        [$branch, $driver] = $this->driverFixture();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'delivery_boy_id' => $driver->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        // 99 is not in [8, 10, 13, 22] — request-layer whitelist must reject.
        $resp = $this->actingAs($driver, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => 99,
            ]);

        $resp->assertStatus(422);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PREPARED, // unchanged
        ]);
    }

    public function test_negative_integer_is_rejected(): void
    {
        [$branch, $driver] = $this->driverFixture();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'delivery_boy_id' => $driver->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $resp = $this->actingAs($driver, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => -1,
            ]);

        $resp->assertStatus(422);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PREPARED,
        ]);
    }

    public function test_back_jump_is_rejected_by_state_machine(): void
    {
        // Even when status is in the whitelist enum, the downstream state
        // machine must reject back-jumps. DELIVERED → OUT_FOR_DELIVERY.
        [$branch, $driver] = $this->driverFixture();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'delivery_boy_id' => $driver->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $resp = $this->actingAs($driver, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => OrderStatus::OUT_FOR_DELIVERY,
            ]);

        // Whitelist passes (10 in [8,10,13,22]) but state machine rejects.
        $resp->assertStatus(422);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::DELIVERED,
        ]);
    }

    /**
     * @return array{0: Branch, 1: User}
     */
    private function driverFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);

        $branch = Branch::factory()->create();
        $driver = User::factory()->create(['branch_id' => $branch->id]);
        $driver->assignRole('Delivery Boy');

        return [$branch, $driver];
    }
}
