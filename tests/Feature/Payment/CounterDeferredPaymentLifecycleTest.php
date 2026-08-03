<?php

namespace Tests\Feature\Payment;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CounterDeferredPaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_pos_confirm_allocates_fiscal_sequence_and_payment_event(): void
    {
        Queue::fake();

        [$branch, $operator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch, ['total' => 18.50]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 20,
                'note' => 'Test encaissement comptoir',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID)
            ->assertJsonPath('data.fiscal_sequence_no', 1);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertSame(PosPaymentMethod::CASH, (int) $fresh->pos_payment_method);
        $this->assertSame(1, (int) $fresh->fiscal_sequence_no);

        $this->assertDatabaseHas(Transaction::class, [
            'order_id' => $order->id,
            'type' => 'payment',
            'payment_method' => 'counter_cash',
        ]);

        $event = DomainEvent::query()->where('aggregate_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(EventType::ORDER_PAYMENT_CONFIRMED, $event->event_type);
        $this->assertSame('OrderPaidAtCounter', $event->broadcast_as);
        $this->assertSame(PaymentStatus::PAID, (int) $event->payload['payment_status']);
        $this->assertSame(1, (int) $event->payload['fiscal_sequence_no']);
    }

    public function test_pos_cancel_counter_payment_does_not_allocate_fiscal_sequence(): void
    {
        Event::fake([OrderCanceled::class, OrderStatusChanged::class]);

        [$branch, $operator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/cancel", [
                'reason' => 'Client absent',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED)
            ->assertJsonPath('data.status', OrderStatus::CANCELED);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::REFUNDED, (int) $fresh->payment_status);
        $this->assertSame(OrderStatus::CANCELED, (int) $fresh->status);
        $this->assertNull($fresh->fiscal_sequence_no);

        Event::assertDispatched(OrderCanceled::class);
        Event::assertDispatched(OrderStatusChanged::class);
    }

    public function test_pending_counter_list_is_branch_scoped(): void
    {
        [$branch, $operator] = $this->branchOperator();
        $visible = $this->pendingCounterOrder($branch, ['queue_number' => 'A0101']);
        $this->pendingCounterOrder(Branch::factory()->create(), ['queue_number' => 'A0202']);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/pos/counter-collect/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.payment_pending_counter', true);
    }

    public function test_counter_confirm_does_not_cross_branch(): void
    {
        [$branch] = $this->branchOperator();
        [, $foreignOperator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($foreignOperator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 12,
            ])
            ->assertNotFound();

        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->fiscal_sequence_no);
    }

    public function test_counter_confirm_requires_pos_permission(): void
    {
        [$branch] = $this->branchOperator();
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 12,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Branch, 1: User}
     */
    private function branchOperator(): array
    {
        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        return [$branch, $operator];
    }

    private function pendingCounterOrder(Branch $branch, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
            'subtotal' => 12.00,
            'total' => 12.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'fiscal_sequence_no' => null,
        ]);
    }
}
