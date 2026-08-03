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
use Tests\TestCase;

class KdsExpectedStatusConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kds_stale_expected_status_returns_409_under_server_lock(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARING,
        ]);

        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
        ]);

        $payload = [
            'status' => OrderStatus::PREPARED,
            'expected_status' => OrderStatus::PREPARING,
        ];

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, $payload)
            ->assertAccepted();

        $this->assertSame(OrderStatus::PREPARED, (int) $order->fresh()->status);

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, $payload)
            ->assertStatus(409);

        $this->assertSame(OrderStatus::PREPARED, (int) $order->fresh()->status);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_kds_expected_status_is_required_for_valid_target_status(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
        ]);

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, [
                'status' => OrderStatus::PREPARING,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expected_status']);

        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status);
    }

    public function test_kds_noop_expected_status_does_not_dispatch_status_event(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
        ]);

        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
        ]);

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, [
                'status' => OrderStatus::ACCEPT,
                'expected_status' => OrderStatus::ACCEPT,
            ])
            ->assertAccepted();

        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }
}
