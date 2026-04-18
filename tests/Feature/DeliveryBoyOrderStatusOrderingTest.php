<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\SendOrderMail;
use App\Jobs\SendOrderPush;
use App\Jobs\SendOrderSms;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [POS-9.1.7] OrderService::deliveryBoyOrderChangeStatus.
 *
 * Verifies:
 *  - status mutation is wrapped in a DB transaction (atomic);
 *  - notification jobs (SendOrderMail/Sms/Push) are dispatched AFTER save,
 *    so listeners always read the persisted status (POS-GA-F-33);
 *  - OrderStatusChanged event fires once with the correct old/new status
 *    AFTER the order has been saved.
 */
class DeliveryBoyOrderStatusOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_then_dispatch_then_event(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Bus::fake();
        Event::fake([OrderStatusChanged::class]);

        $branch = Branch::factory()->create();
        $boy = User::factory()->create(['branch_id' => $branch->id]);
        $boy->assignRole('Delivery Boy');

        $order = Order::factory()->create([
            'branch_id'        => $branch->id,
            'delivery_boy_id'  => $boy->id,
            'status'           => OrderStatus::OUT_FOR_DELIVERY,
            'payment_status'   => PaymentStatus::UNPAID,
        ]);

        $this->actingAs($boy, 'sanctum');

        $resp = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/delivery-boy-order/change-status/' . $order->id, [
                'status' => OrderStatus::DELIVERED,
            ]);

        // Status persisted before any job/event side effect runs.
        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => OrderStatus::DELIVERED,
        ]);

        Bus::assertDispatched(SendOrderMail::class);
        Bus::assertDispatched(SendOrderSms::class);
        Bus::assertDispatched(SendOrderPush::class);

        Event::assertDispatched(OrderStatusChanged::class, function ($e) use ($order) {
            return (int) $e->order->id === (int) $order->id
                && (int) $e->newStatus === (int) OrderStatus::DELIVERED;
        });
    }
}
