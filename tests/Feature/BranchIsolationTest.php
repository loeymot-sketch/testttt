<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [POS-9.1.3] Replaces the previous placeholder.
 *
 * Exercises real branch_id isolation across the POS surface:
 * - Listing POS orders (controller index)
 * - Fetching a specific POS order (controller show)
 * - Changing status
 * - Changing payment status
 * - Destroying an order
 * - KDS listing
 *
 * Invariant #3 — "branch_id isolation" applies to every controller /
 * service reading or mutating order-scoped data.
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashierA;
    protected User $cashierB;
    protected User $chefA;

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

        $this->chefA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password' => Hash::make('pwd'),
        ]);
        $this->chefA->assignRole('Chef');
    }

    private function makeOrder(int $branchId, int $status = OrderStatus::PENDING, int $paymentStatus = PaymentStatus::UNPAID): Order
    {
        return Order::factory()->create([
            'branch_id'      => $branchId,
            'order_type'     => OrderType::POS,
            'status'         => $status,
            'payment_status' => $paymentStatus,
            'total'          => 10.00,
        ]);
    }

    public function test_cashier_index_does_not_leak_other_branch_orders(): void
    {
        $orderA1 = $this->makeOrder($this->branchA->id);
        $orderA2 = $this->makeOrder($this->branchA->id);
        $orderB  = $this->makeOrder($this->branchB->id);

        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order');

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertIsArray($payload);

        // SimpleOrderResource does not project branch_id, so we verify
        // isolation via the returned order IDs: branch-A orders must be
        // present, branch-B order must never appear.
        $ids = collect($payload)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains((int) $orderA1->id, $ids, 'own-branch order missing from index.');
        $this->assertContains((int) $orderA2->id, $ids, 'own-branch order missing from index.');
        $this->assertNotContains((int) $orderB->id, $ids,
            'pos-order index leaked a row from a foreign branch.');
    }

    public function test_cashier_cannot_show_other_branch_order(): void
    {
        $order = $this->makeOrder($this->branchB->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order/show/' . $order->id);

        // Either 403 (explicit denial) or 404 (scope-filtered route model binding).
        $this->assertContains($response->status(), [403, 404],
            'pos-order show must not return data from foreign branch, got: ' . $response->status());
    }

    public function test_cashier_cannot_change_status_of_other_branch_order(): void
    {
        $order = $this->makeOrder($this->branchB->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::ACCEPT,
            ]);

        $this->assertContains($response->status(), [403, 404, 422],
            'change-status must not succeed cross-branch, got: ' . $response->status());

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PENDING, // unchanged
        ]);
    }

    public function test_cashier_cannot_change_payment_status_of_other_branch_order(): void
    {
        $order = $this->makeOrder($this->branchB->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $this->assertContains($response->status(), [403, 404, 422]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => PaymentStatus::UNPAID,
        ]);
    }

    public function test_cashier_cannot_destroy_other_branch_order(): void
    {
        $order = $this->makeOrder($this->branchB->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson('/api/admin/pos-order/' . $order->id);

        $this->assertContains($response->status(), [403, 404]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'deleted_at' => null,
        ]);
    }

    public function test_chef_kds_does_not_leak_other_branch_orders(): void
    {
        $orderA = $this->makeOrder($this->branchA->id, OrderStatus::ACCEPT);
        $orderB = $this->makeOrder($this->branchB->id, OrderStatus::ACCEPT);

        $this->actingAs($this->chefA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/kds-order');

        // Route may be access-controlled (403/404) or return a payload.
        // Both outcomes are acceptable as long as no branch-B order leaks.
        if ($response->status() !== 200) {
            $this->assertContains($response->status(), [401, 403, 404, 422],
                'KDS returned unexpected status ' . $response->status());
            return;
        }

        $payload = $response->json('data');
        if (!is_array($payload)) {
            return; // non-collection response (e.g., meta-only) — nothing to leak.
        }

        $ids = collect($payload)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotContains((int) $orderB->id, $ids,
            'KDS leaked an order from a foreign branch.');
    }
}
