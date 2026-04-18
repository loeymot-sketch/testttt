<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CleanupStalePendingOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancels_stale_pending_kiosk_orders_only(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        $stale = $this->makeOrder($branch->id, $customer->id, now()->subMinutes(20), 'kiosk');
        $fresh = $this->makeOrder($branch->id, $customer->id, now()->subMinutes(5), 'kiosk');
        $web = $this->makeOrder($branch->id, $customer->id, now()->subMinutes(20), 'web');

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::REJECTED, (int) $stale->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, (int) $fresh->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, (int) $web->fresh()->status);

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($stale): bool {
            return (int) $event->order->id === (int) $stale->id
                && $event->oldStatus === OrderStatus::PENDING
                && $event->newStatus === OrderStatus::REJECTED;
        });
    }

    public function test_kernel_registers_cleanup_job_every_five_minutes(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $cleanupEvent = $events->first(function ($event) {
            return str_contains((string) ($event->description ?? ''), CleanupStalePendingKioskOrders::class)
                || str_contains((string) ($event->command ?? ''), CleanupStalePendingKioskOrders::class);
        });

        $this->assertNotNull($cleanupEvent);
        $this->assertContains($cleanupEvent->expression, ['*/5 * * * *', '5 * * * *']);
        $this->assertTrue((bool) $cleanupEvent->withoutOverlapping);
    }

    private function makeOrder(int $branchId, int $userId, \Illuminate\Support\Carbon $createdAt, string $sourceSurface): FrontendOrder
    {
        return FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no' => 'KIOSK-CLEAN-' . fake()->unique()->numerify('###'),
            'user_id' => $userId,
            'branch_id' => $branchId,
            'subtotal' => 10,
            'discount' => 0,
            'delivery_charge' => 0,
            'total_tax' => 1,
            'total' => 11,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => $createdAt,
            'preparation_time' => 15,
            'is_advance_order' => 0,
            'payment_method' => 1,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source' => 10,
            'source_surface' => $sourceSurface,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
