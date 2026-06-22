<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryOrderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_boy_api_lists_shows_and_advances_assigned_order_in_branch(): void
    {
        [$branch, $deliveryBoy] = $this->deliveryFixture();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'delivery_boy_id' => $deliveryBoy->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        Event::fake([
            OrderStatusChanged::class,
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
        ]);

        $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/delivery-boy-order/')
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);

        $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/delivery-boy-order/show/' . $order->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);

        $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => OrderStatus::OUT_FOR_DELIVERY,
            ])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
        ]);

        $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $order->id, [
                'status' => OrderStatus::DELIVERED,
            ])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        Event::assertDispatched(OrderStatusChanged::class, function ($event) use ($order) {
            return (int) $event->order->id === (int) $order->id
                && (int) $event->newStatus === (int) OrderStatus::OUT_FOR_DELIVERY;
        });
        Event::assertDispatched(OrderStatusChanged::class, function ($event) use ($order) {
            return (int) $event->order->id === (int) $order->id
                && (int) $event->newStatus === (int) OrderStatus::DELIVERED;
        });
    }

    public function test_delivery_boy_api_blocks_cross_branch_assigned_order(): void
    {
        [$branch, $deliveryBoy] = $this->deliveryFixture();
        $foreignBranch = Branch::factory()->create();
        $visibleOrder = Order::factory()->create([
            'branch_id' => $branch->id,
            'delivery_boy_id' => $deliveryBoy->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED,
        ]);
        $foreignOrder = Order::factory()->create([
            'branch_id' => $foreignBranch->id,
            'delivery_boy_id' => $deliveryBoy->id,
            'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED,
        ]);

        $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/delivery-boy-order/')
            ->assertOk()
            ->assertJsonFragment(['id' => $visibleOrder->id])
            ->assertJsonMissing(['id' => $foreignOrder->id]);

        $showResponse = $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/delivery-boy-order/show/' . $foreignOrder->id);
        $this->assertContains($showResponse->status(), [403, 404], $showResponse->getContent());

        $changeResponse = $this->actingAs($deliveryBoy, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/delivery-boy-order/change-status/' . $foreignOrder->id, [
                'status' => OrderStatus::OUT_FOR_DELIVERY,
            ]);
        $this->assertContains($changeResponse->status(), [403, 404], $changeResponse->getContent());

        $this->assertDatabaseHas('orders', [
            'id' => $foreignOrder->id,
            'status' => OrderStatus::PREPARED,
        ]);
    }

    /**
     * @return array{0: Branch, 1: User}
     */
    private function deliveryFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);

        $branch = Branch::factory()->create();
        $deliveryBoy = User::factory()->create(['branch_id' => $branch->id]);
        $deliveryBoy->assignRole('Delivery Boy');

        return [$branch, $deliveryBoy];
    }
}
