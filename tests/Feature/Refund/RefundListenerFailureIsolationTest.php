<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderPaymentStatusChanged;
use App\Events\RefundCreated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [HEAL-PLAN-D.3 / RED-Z8 P2-2] Refund listener failure-isolation sentinel.
 *
 * Verifies that the {@see RefundCreated} listener array is ordered so that
 * {@see \App\Listeners\PersistOrderPaymentStatusChangedOnRefundCreated}
 * (the WG-1 broadcast bridge) runs BEFORE any throw-prone listener that
 * could halt the Laravel sync dispatcher loop.
 *
 * Laravel's {@see \Illuminate\Events\Dispatcher::dispatch} does NOT catch
 * listener exceptions (verified vendor/.../Events/Dispatcher.php lines
 * 233-269). A throw in listener N halts N+1..L. Pre-this-heal the array
 * was [ReleaseStock, ReleaseAvailability, Persist] — a throw in
 * {@see \App\Services\Stock\StockService::releaseForOrder} silently
 * re-opened the WG-1 P1-1 broadcast hole.
 *
 * This sentinel guards the reorder by binding a throwing StockService,
 * dispatching the event directly, and asserting the realtime refund
 * signal {@see OrderPaymentStatusChanged} STILL fires.
 */
class RefundListenerFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // No-op audit log writer — chain head seeding not required.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });
    }

    /**
     * RED before reorder, GREEN after: when ReleaseStockOnRefundCreated
     * throws, the OrderPaymentStatusChanged broadcast MUST still fire
     * (Persist listener runs first → its internal try/catch envelope
     * isolates its own failures, and the downstream throw cannot mask
     * the realtime refund signal that already happened).
     */
    public function test_persist_broadcast_fires_when_stock_release_throws(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);

        // Bind a StockService that explodes — simulates the exact failure
        // mode flagged by RED-Z8 P2-2 (e.g. branch_scoped lookup miss,
        // DB lock timeout, malformed refundedItems payload).
        $this->app->instance(StockService::class, new class extends StockService {
            public function __construct()
            {
            }

            public function releaseForOrder(Model $order, string $reason = 'order_cancel', array $refundedItems = []): void
            {
                throw new \RuntimeException('Simulated stock release failure (D.3 sentinel)');
            }
        });

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::CANCELED,
            'total'          => 25.00,
        ]);

        // Dispatching directly (NOT via PaymentService::cashBack) isolates
        // the listener-ordering invariant from any caller-side wrapping.
        // We expect the StockService throw to bubble up — caught here so
        // the test continues to its real assertion.
        try {
            RefundCreated::dispatch($order);
        } catch (\RuntimeException $e) {
            // Expected — the throw still happens, but the question is
            // whether Persist ran BEFORE the throw halted the chain.
            $this->assertStringContainsString('Simulated stock release failure', $e->getMessage());
        }

        // The real invariant: Persist listener must have run BEFORE the
        // ReleaseStock throw halted the dispatcher loop. Proof = the
        // realtime refund broadcast fired.
        Event::assertDispatched(
            OrderPaymentStatusChanged::class,
            function (OrderPaymentStatusChanged $event) use ($order) {
                return (int) $event->order->id === (int) $order->id
                    && (int) $event->newPaymentStatus === PaymentStatus::REFUNDED;
            }
        );
    }

    /**
     * Happy path: when no listener throws, all three still run and the
     * Persist broadcast fires (regression guard — reorder doesn't break
     * the WG-1 happy path).
     */
    public function test_persist_broadcast_fires_on_happy_path_after_reorder(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);

        // No-op StockService — happy path.
        $this->app->instance(StockService::class, new class extends StockService {
            public function __construct()
            {
            }

            public function releaseForOrder(Model $order, string $reason = 'order_cancel', array $refundedItems = []): void
            {
                // no-op
            }
        });

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::CANCELED,
            'total'          => 12.34,
        ]);

        RefundCreated::dispatch($order);

        Event::assertDispatched(
            OrderPaymentStatusChanged::class,
            fn (OrderPaymentStatusChanged $event) => (int) $event->order->id === (int) $order->id
                && (int) $event->newPaymentStatus === PaymentStatus::REFUNDED
        );
    }
}
