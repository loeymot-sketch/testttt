<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KioskRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_order_created_outbox_payload_exposes_pos_realtime_identity(): void
    {
        Queue::fake();

        $order = $this->createKioskOrder([
            'queue_number' => 'A0042',
            'payment_method' => PaymentGateway::CARD,
        ]);

        OrderCreated::dispatch($order);

        $event = DomainEvent::query()
            ->where('event_type', EventType::ORDER_CREATED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('A0042', $event->payload['queue_number']);
        $this->assertSame('kiosk', $event->payload['_origin']);
        $this->assertSame(PaymentGateway::CARD, $event->payload['payment_method']);
        $this->assertSame(['private-branch.' . $order->branch_id], json_decode($event->channel, true));
    }

    public function test_kiosk_order_status_changed_outbox_payload_exposes_pos_realtime_identity(): void
    {
        Queue::fake();

        $order = $this->createKioskOrder([
            'queue_number' => 'A0043',
            'payment_method' => PaymentGateway::TICKET_RESTAURANT,
            'status' => OrderStatus::ACCEPT,
        ]);

        OrderStatusChanged::dispatch($order, OrderStatus::ACCEPT, OrderStatus::PREPARING);

        $event = DomainEvent::query()
            ->where('event_type', EventType::ORDER_STATUS_CHANGED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('A0043', $event->payload['queue_number']);
        $this->assertSame('kiosk', $event->payload['_origin']);
        $this->assertSame(PaymentGateway::TICKET_RESTAURANT, $event->payload['payment_method']);
        $this->assertSame(OrderStatus::ACCEPT, $event->payload['old_status']);
        $this->assertSame(OrderStatus::PREPARING, $event->payload['new_status']);
    }

    private function createKioskOrder(array $overrides = []): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
        ]);

        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'source_surface' => 'kiosk',
            'queue_number' => 'A0001',
            'status' => OrderStatus::PENDING,
            'order_type' => 10,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'total' => 12.50,
        ], $overrides));
    }
}
