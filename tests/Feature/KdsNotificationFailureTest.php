<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderSms;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Ultrareview remediation — #1 + #16.
 * A bump whose DB transaction already COMMITTED must return 202 even if a
 * post-commit dispatch (notification listener OR the KDS ticket broadcast)
 * throws. Before the fix the throw bubbled to catch(Exception) and the chef
 * saw a 422 (or, for an \Error, an uncaught 500) on a committed bump.
 */
class KdsNotificationFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function chefAndOrder(): array
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

        return [$chef, $order];
    }

    /** #1 — a throwing post-commit notification dispatch must NOT surface as 422. */
    public function test_committed_bump_returns_202_when_a_notification_listener_throws(): void
    {
        [$chef, $order] = $this->chefAndOrder();

        // Register a listener that throws synchronously so SendOrderSms::dispatch()
        // throws (queue is sync in tests). The #1 try/catch(\Throwable) must swallow it.
        Event::listen(SendOrderSms::class, function () {
            throw new \RuntimeException('sms listener boom');
        });

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, [
                'status' => OrderStatus::PREPARED,
                'expected_status' => OrderStatus::PREPARING,
            ])
            ->assertAccepted();

        // Transaction committed: status must be PREPARED despite the notification failure.
        $this->assertSame(OrderStatus::PREPARED, (int) $order->fresh()->status);
    }

    /** #16 — an \Error from the KDS ticket broadcast must be caught (\Throwable), not 500. */
    public function test_committed_bump_returns_202_when_kds_ticket_dispatch_throws_error(): void
    {
        [$chef, $order] = $this->chefAndOrder();

        // DispatchKdsTicket::dispatch() fires OrderStatusChanged. A listener that throws
        // an \Error makes that dispatch throw an \Error — caught only by \Throwable (#16),
        // not by \Exception. $reached confirms the kdsTicket path was actually exercised.
        $reached = false;
        Event::listen(OrderStatusChanged::class, function () use (&$reached) {
            $reached = true;
            throw new \Error('kds ticket type error');
        });

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, [
                'status' => OrderStatus::PREPARED,
                'expected_status' => OrderStatus::PREPARING,
            ])
            ->assertAccepted();

        $this->assertTrue($reached, 'OrderStatusChanged (kdsTicket path) must have fired');
        $this->assertSame(OrderStatus::PREPARED, (int) $order->fresh()->status);
    }
}
