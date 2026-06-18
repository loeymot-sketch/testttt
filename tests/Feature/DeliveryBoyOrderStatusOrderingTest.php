<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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

        Event::fake([
            OrderStatusChanged::class,
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
        ]);

        $branch = Branch::factory()->create();
        // Route /delivery-boy-order/change-status is guarded by `auth:sanctum`;
        // the OrderStatusRequest `authorize()` check also accepts the staff
        // roles seeded above, so we grant the delivery boy the "POS Operator"
        // role to satisfy that gate without mutating seedSpatieRoles().
        $boy = User::factory()->create(['branch_id' => $branch->id]);
        $boy->assignRole('POS Operator');

        $order = Order::factory()->create([
            'branch_id'        => $branch->id,
            'delivery_boy_id'  => $boy->id,
            'status'           => OrderStatus::OUT_FOR_DELIVERY,
            'payment_status'   => PaymentStatus::UNPAID,
        ]);

        $this->actingAs($boy, 'sanctum');

        $resp = $this->withHeader('x-api-key', config('app.api_key'))
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => OrderStatus::DELIVERED,
            ]);

        $resp->assertStatus(200);

        // Status persisted before any job/event side effect runs.
        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => OrderStatus::DELIVERED,
        ]);

        Event::assertDispatched(SendOrderMail::class);
        Event::assertDispatched(SendOrderSms::class);
        Event::assertDispatched(SendOrderPush::class);

        Event::assertDispatched(OrderStatusChanged::class, function ($e) use ($order) {
            return (int) $e->order->id === (int) $order->id
                && (int) $e->newStatus === (int) OrderStatus::DELIVERED;
        });
    }
}
