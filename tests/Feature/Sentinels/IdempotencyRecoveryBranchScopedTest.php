<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\FrontendOrderService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class IdempotencyRecoveryBranchScopedTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_recovery_returns_existing_order_in_same_branch_only(): void
    {
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        $orderA = Order::factory()->create($this->orderAttributes($branchA->id, 'shared-pos-key'));

        $service = app(OrderService::class);

        $this->assertSame(
            $orderA->id,
            $this->invokeRecovery($service, 'findExistingOrderForIdempotencyRecovery', 'shared-pos-key', $branchA->id)?->id
        );
        $this->assertNull(
            $this->invokeRecovery($service, 'findExistingOrderForIdempotencyRecovery', 'shared-pos-key', $branchB->id),
            'POS recovery must not return an order from another branch when the idempotency key matches only there.'
        );
    }

    public function test_pos_recovery_resolves_same_key_to_requested_branch_when_both_branches_have_it(): void
    {
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        $orderA = Order::factory()->create($this->orderAttributes($branchA->id, 'shared-pos-key-both'));
        $orderB = Order::factory()->create($this->orderAttributes($branchB->id, 'shared-pos-key-both'));

        $service = app(OrderService::class);

        $this->assertSame(
            $orderA->id,
            $this->invokeRecovery($service, 'findExistingOrderForIdempotencyRecovery', 'shared-pos-key-both', $branchA->id)?->id
        );
        $this->assertSame(
            $orderB->id,
            $this->invokeRecovery($service, 'findExistingOrderForIdempotencyRecovery', 'shared-pos-key-both', $branchB->id)?->id
        );
    }

    public function test_kiosk_recovery_returns_existing_order_in_same_branch_only(): void
    {
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        $orderA = FrontendOrder::query()->create($this->orderAttributes($branchA->id, 'shared-kiosk-key'));

        $service = app(FrontendOrderService::class);

        $this->assertSame(
            $orderA->id,
            $this->invokeRecovery($service, 'findExistingFrontendOrderForIdempotencyRecovery', 'shared-kiosk-key', $branchA->id)?->id
        );
        $this->assertNull(
            $this->invokeRecovery($service, 'findExistingFrontendOrderForIdempotencyRecovery', 'shared-kiosk-key', $branchB->id),
            'Kiosk recovery must not return an order from another branch when the idempotency key matches only there.'
        );
    }

    public function test_kiosk_recovery_resolves_same_key_to_requested_branch_when_both_branches_have_it(): void
    {
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        $orderA = FrontendOrder::query()->create($this->orderAttributes($branchA->id, 'shared-kiosk-key-both'));
        $orderB = FrontendOrder::query()->create($this->orderAttributes($branchB->id, 'shared-kiosk-key-both'));

        $service = app(FrontendOrderService::class);

        $this->assertSame(
            $orderA->id,
            $this->invokeRecovery($service, 'findExistingFrontendOrderForIdempotencyRecovery', 'shared-kiosk-key-both', $branchA->id)?->id
        );
        $this->assertSame(
            $orderB->id,
            $this->invokeRecovery($service, 'findExistingFrontendOrderForIdempotencyRecovery', 'shared-kiosk-key-both', $branchB->id)?->id
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAttributes(int $branchId, string $idempotencyKey): array
    {
        return [
            'branch_id' => $branchId,
            'user_id' => User::factory()->create(['branch_id' => $branchId])->id,
            'order_type' => OrderType::TAKEAWAY,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'idempotency_key' => $idempotencyKey,
            'subtotal' => 10,
            'total' => 10,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ];
    }

    private function invokeRecovery(object $service, string $method, string $key, int $branchId): ?object
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, $key, $branchId);
    }
}
