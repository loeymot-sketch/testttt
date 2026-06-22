<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\RefundCreated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [REFUND-EVENT-WIRE] Assert PaymentService::cashBack() now dispatches the
 * RefundCreated domain event so its registered listeners (stock release +
 * availability release) actually run in production.
 *
 * Before the wire fix this event had ZERO production callers; the listeners
 * were registered but never fired, causing refunded items to remain
 * unavailable forever (stock counter decremented but never released).
 */
class RefundCreatedDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Swap audit log writer to a no-op so cashBack's HMAC chain write does
        // not require seeded chain state (tests/Feature/CancelAuditTrailTest
        // already exhaustively covers the audit path).
        $this->app->instance(AuditLogService::class, new class {
            public function write(array $payload): void {}
        });
    }

    public function test_cashback_dispatches_refund_created_event_once(): void
    {
        Event::fake([RefundCreated::class]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'total' => 25.00,
        ]);

        // cashBack requires a prior `payment` transaction to fire — without
        // it the early-return path runs and no event should be dispatched.
        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-RC-PAID',
            'amount' => 25.00,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $service = app(PaymentService::class);
        $service->cashBack($order, 'cash', 'FK-RC-CB-1');

        Event::assertDispatched(
            RefundCreated::class,
            fn (RefundCreated $event) => (int) $event->order->id === (int) $order->id
        );
        Event::assertDispatchedTimes(RefundCreated::class, 1);
    }

    public function test_cashback_idempotent_call_does_not_re_dispatch_event(): void
    {
        Event::fake([RefundCreated::class]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'total' => 18.50,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-RC-PAID-2',
            'amount' => 18.50,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $service = app(PaymentService::class);
        $service->cashBack($order, 'cash', 'FK-RC-CB-A');
        // Second call hits the existing-cashback early-return BEFORE the
        // `if ($transaction)` dispatch block — event must NOT re-fire.
        $service->cashBack($order, 'cash', 'FK-RC-CB-B');

        Event::assertDispatchedTimes(RefundCreated::class, 1);
    }
}
