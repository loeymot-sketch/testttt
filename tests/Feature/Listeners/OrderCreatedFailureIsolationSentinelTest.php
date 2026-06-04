<?php

namespace Tests\Feature\Listeners;

use App\Enums\EventType;
use App\Events\OrderCreated;
use App\Events\StockDecrementFailedEvent;
use App\Listeners\DecrementItemAvailabilityOnOrder;
use App\Listeners\DecrementStockOnOrderCreated;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * @FK-ID WG-2-WF4-PK1-ARCH-01 — Unified failure-isolation policy across the 4
 *        registered OrderCreated listeners.
 *
 * @source plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md / wave-g
 * @severity P1 (cross-validated by WF-4 + PK1-ARCH-01 + RED-team)
 *
 * Invariant : when any sibling listener in the OrderCreated cascade throws,
 * the dispatcher MUST NOT propagate the throw — sibling listeners (notably the
 * SSOT `PersistOrderCreatedToOutbox`) MUST continue to fire, and the POS HTTP
 * caller MUST NOT observe a 500 for an order that already exists in DB.
 *
 * Rationale (advisor 2026-05-19) : `OrderCreated` uses `DispatchableAfterCommit`,
 * so listeners run AFTER the outer DB transaction commits. Re-throwing rolls
 * back nothing — it only skips siblings + surfaces a misleading 500. The stale
 * "let it bubble up to the request stack" comment in `DecrementStockOnOrderCreated`
 * was the load-bearing drift that this cycle removes.
 *
 * Order-independence discriminator (advisor) : runtime sentinels use real
 * `OrderCreated::dispatch()` AND direct listener invocation, never relying
 * solely on EventServiceProvider registration order. Structural sentinels
 * lock the source so a future agent cannot silently reintroduce a `throw $e`.
 *
 * Cases :
 *   1. real-dispatch    : Stock throws → Outbox SSOT row still persists.
 *   2. structural       : Availability listener absorbs Throwable (mirror).
 *   3. real-dispatch    : Cascade complete + Outbox row when FCM (already
 *                          isolated since F-002 round-3) handles its own.
 *   4. direct-invoke    : Stock listener never re-throws (load-bearing).
 *
 * Plus 2 structural sentinels locking the source against regression.
 */
class OrderCreatedFailureIsolationSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Case 1 — Real dispatch with stock listener forced to throw. Outbox SSOT
     * row MUST exist post-dispatch. Pre-WG-2 this would have surfaced the
     * RuntimeException to the test caller (and the HTTP client in production).
     */
    public function test_decrement_stock_failure_does_not_block_outbox_persistence(): void
    {
        Queue::fake();

        $order = $this->createOrder();

        $throwingStock = Mockery::mock(StockService::class);
        $throwingStock->shouldReceive('decrementForOrder')
            ->atLeast()->once()
            ->andThrow(new RuntimeException('Simulated stock decrement crash'));
        $this->app->instance(StockService::class, $throwingStock);

        // Real fire — NO Event::fake() so registered listeners actually run.
        // Cascade per EventServiceProvider:145-151 :
        //   1) PersistOrderCreatedToOutbox (SSOT, first)
        //   2) DecrementItemAvailabilityOnOrder
        //   3) DecrementStockOnOrderCreated  ← throws
        //   4) SendFcmOnOrderCreated
        OrderCreated::dispatch($order);

        // WG-2 invariant : sibling Throwable must NOT skip Outbox SSOT row.
        $this->assertDatabaseHas('domain_events', [
            'event_type'   => EventType::ORDER_CREATED,
            'aggregate_id' => $order->id,
            'branch_id'    => $order->branch_id,
        ]);
    }

    /**
     * Case 2 — Structural sentinel : `DecrementItemAvailabilityOnOrder` must
     * absorb Throwable (mirror policy). Pre-WG-2 this listener had ZERO
     * try/catch — a throw inside `AvailabilityService::decrementForOrder`
     * (final class — cannot be mocked at runtime) would skip the cascade
     * starting from this position.
     *
     * Runtime injection is impractical because `AvailabilityService` is final.
     * The structural source check locks the WG-2 policy unambiguously.
     */
    public function test_decrement_item_availability_source_absorbs_throwable(): void
    {
        $source = file_get_contents(base_path('app/Listeners/DecrementItemAvailabilityOnOrder.php'));

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$/',
            $source,
            'WG-2 failure-isolation: DecrementItemAvailabilityOnOrder must catch Throwable. '
            . 'Pre-WG-2 it had no try/catch — sibling listeners (notably stock + FCM) were '
            . 'silently skipped on any AvailabilityService crash. Mirror the stock listener policy.'
        );

        $this->assertStringContainsString(
            'Log::',
            $source,
            'WG-2 failure-isolation: DecrementItemAvailabilityOnOrder must Log the absorbed error '
            . 'so ops can detect the silent drift.'
        );

        $this->assertStringNotContainsString(
            'throw $e;',
            $source,
            'WG-2 failure-isolation: DecrementItemAvailabilityOnOrder must NOT re-throw — same '
            . 'reasoning as DecrementStockOnOrderCreated (DispatchableAfterCommit).'
        );
    }

    /**
     * Case 3 — Real dispatch with the existing FCM listener (already isolated
     * via safeDispatch / F-002 round-3 2026-05-10). Locks the contract.
     */
    public function test_send_fcm_listener_isolates_per_dispatch(): void
    {
        Queue::fake();
        $order = $this->createOrder();

        Log::shouldReceive('sharedContext')->andReturn([]);
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        OrderCreated::dispatch($order);

        $this->assertDatabaseHas('domain_events', [
            'event_type'   => EventType::ORDER_CREATED,
            'aggregate_id' => $order->id,
            'branch_id'    => $order->branch_id,
        ]);

        // Lock FCM safeDispatch presence so a future refactor can't strip it.
        $fcmSource = file_get_contents(base_path('app/Listeners/SendFcmOnOrderCreated.php'));
        $this->assertStringContainsString(
            'safeDispatch',
            $fcmSource,
            'WG-2 failure-isolation: SendFcmOnOrderCreated must retain per-dispatch isolation.'
        );
    }

    /**
     * Case 4 — Direct invocation : `DecrementStockOnOrderCreated` MUST NEVER
     * re-throw on Throwable post-WG-2. Order-independent (advisor) — no
     * reliance on EventServiceProvider registration order.
     */
    public function test_decrement_stock_listener_invoked_directly_never_rethrows(): void
    {
        $order = $this->createOrder();

        $throwingStock = Mockery::mock(StockService::class);
        $throwingStock->shouldReceive('decrementForOrder')
            ->once()
            ->andThrow(new RuntimeException('Simulated crash'));
        $this->app->instance(StockService::class, $throwingStock);

        Log::shouldReceive('error')->atLeast()->once();

        $listener = $this->app->make(DecrementStockOnOrderCreated::class);

        try {
            $listener->handle(new OrderCreated($order));
        } catch (\Throwable $e) {
            $this->fail(
                'DecrementStockOnOrderCreated re-threw after WG-2 failure-isolation policy: '
                . $e::class . ' — ' . $e->getMessage()
            );
        }

        $this->addToAssertionCount(1); // listener returned cleanly
    }

    /**
     * Structural sentinel — locks the source so a future agent cannot silently
     * reintroduce the `throw $e;` regression by tweaking the catch block.
     */
    public function test_decrement_stock_source_has_no_unconditional_rethrow(): void
    {
        $source = file_get_contents(base_path('app/Listeners/DecrementStockOnOrderCreated.php'));

        $this->assertStringNotContainsString(
            'throw $e;',
            $source,
            'WG-2 failure-isolation: DecrementStockOnOrderCreated must not re-throw — '
            . 'OrderCreated runs after-commit, re-throwing skips Outbox SSOT and 500s '
            . 'the POS client for an order that exists.'
        );

        $this->assertStringContainsString(
            'StockDecrementFailedEvent',
            $source,
            'WG-2 failure-isolation: DecrementStockOnOrderCreated must dispatch a '
            . 'StockDecrementFailedEvent for ops observability.'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createOrder(array $overrides = []): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create(array_merge([
            'user_id'      => $user->id,
            'branch_id'    => $branch->id,
            'queue_number' => 'Q-WG2-' . random_int(1000, 9999),
            'status'       => \App\Enums\OrderStatus::PENDING,
            'order_type'   => 1,
            'total'        => 12.50,
        ], $overrides));
    }
}
