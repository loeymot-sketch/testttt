<?php

namespace Tests\Feature\Domain;

use App\Domain\Order\IllegalTransitionException;
use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStateMachineApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_legal_transition_mutates_and_audits(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING);

        OrderStateMachine::apply($order, OrderStatus::ACCEPT, null, null);

        $order->refresh();
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->status);

        $rows = OrderStatusTransition::query()
            ->where('order_id', $order->id)
            ->where('order_type', Order::class)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(OrderStatus::PENDING, (int) $rows->first()->from_status);
        $this->assertSame(OrderStatus::ACCEPT, (int) $rows->first()->to_status);
    }

    public function test_apply_illegal_transition_throws_and_rolls_back(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING);
        $originalStatus = (int) $order->status;

        try {
            OrderStateMachine::apply($order, OrderStatus::RETURNED, null, null);
            $this->fail('Expected IllegalTransitionException');
        } catch (IllegalTransitionException $e) {
            $this->assertStringContainsString('Illegal transition', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame($originalStatus, (int) $order->status, 'Order status must NOT change on illegal transition');

        $this->assertSame(
            0,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'No audit row must be persisted on illegal transition'
        );
    }

    public function test_apply_identity_transition_is_noop(): void
    {
        $order = $this->makeOrder(OrderStatus::PREPARING);

        OrderStateMachine::apply($order, OrderStatus::PREPARING, null, null);

        $this->assertSame(
            0,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'Identity transition must not write an audit row'
        );
    }

    public function test_apply_cancel_requires_reason(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING);

        try {
            OrderStateMachine::apply($order, OrderStatus::CANCELED, null, null);
            $this->fail('Expected IllegalTransitionException for cancel without reason');
        } catch (IllegalTransitionException $e) {
            $this->assertStringContainsString('requires a non-empty reason', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame(OrderStatus::PENDING, (int) $order->status);
    }

    public function test_apply_cancel_with_reason_succeeds_and_persists_reason(): void
    {
        $order = $this->makeOrder(OrderStatus::PENDING);

        OrderStateMachine::apply($order, OrderStatus::CANCELED, null, 'customer_request');

        $order->refresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $order->status);

        $row = OrderStatusTransition::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('customer_request', $row->reason);
    }

    public function test_apply_records_actor_id_when_user_provided(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $order = $this->makeOrder(OrderStatus::PENDING, $branch->id);

        OrderStateMachine::apply($order, OrderStatus::ACCEPT, $user, null);

        $row = OrderStatusTransition::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $user->id, (int) $row->actor_id);
        $this->assertSame('user', $row->actor_type);
    }

    private function makeOrder(int $status, ?int $branchId = null): Order
    {
        $branch = $branchId ? Branch::find($branchId) : Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'queue_number' => 'Q-' . fake()->unique()->numberBetween(1, 9999),
            'status' => $status,
            'order_type' => 1,
            'total' => 10.00,
        ]);
    }
}
