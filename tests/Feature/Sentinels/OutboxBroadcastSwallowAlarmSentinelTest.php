<?php

namespace Tests\Feature\Sentinels;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Events\OrderPaymentStatusChanged;
use App\Events\OrderStatusChanged;
use App\Events\OutboxBroadcastSwallowedEvent;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * @FK-ID WJ-4-WI5-OBSOUTBOX01 — Outbox swallow alarm sentinel.
 *
 * @source plans/wave-j-2026-05-19 / OBS-OUTBOX-01 P1
 * @severity P1 (observability gap — silent broadcast loss under dual
 *               worker+cron downtime)
 *
 * Invariant: when `DispatchDomainEventsJob::dispatch(...)` throws from inside
 * the `DB::afterCommit` hook of any of the 4 OUTBOX persist listeners
 * (PersistOrderCreatedToOutbox / PersistOrderStatusChangedToOutbox /
 * PersistOrderPaymentStatusChangedToOutbox / PersistOrderPaidAtCounterToOutbox),
 * the listener MUST:
 *
 *   1. Catch the Throwable (existing behavior — preserve cascade isolation),
 *   2. Escalate the structured log to `Log::error` (was `Log::warning`,
 *      production alerting tiers typically only watch ERROR+),
 *   3. Dispatch a typed `OutboxBroadcastSwallowedEvent` carrying context
 *      (domain_event_id, event_type, aggregate_id, branch_id, listener,
 *       error message, failed_at) so ops can wire structured alerting,
 *   4. Wrap step 3 in a defensive try/catch so the observability event
 *      itself cannot re-break the cascade.
 *
 * Strategy (mirroring `OrderCreatedFailureIsolationSentinelTest`):
 *   - 1 runtime sentinel — bind a throwing Bus dispatcher, fire one of the
 *     real OrderXxx events, assert `OutboxBroadcastSwallowedEvent` was
 *     dispatched with the expected payload (the cheapest listener to wire
 *     is the OrderCreated path).
 *   - 4 structural sentinels (one per listener file) — file_get_contents
 *     + regex / substring asserts that lock the WJ-4 policy unambiguously
 *     against silent regression.
 *
 * Order-independence discriminator: structural sentinels read the file
 * source directly, so they do NOT depend on EventServiceProvider listener
 * ordering nor on any queue / Bus runtime setup.
 */
class OutboxBroadcastSwallowAlarmSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Runtime sentinel — when DispatchDomainEventsJob::dispatch() throws,
     * `PersistOrderCreatedToOutbox` MUST dispatch an
     * `OutboxBroadcastSwallowedEvent` with the expected context fields.
     */
    public function test_persist_order_created_dispatches_swallow_event_on_broadcast_failure(): void
    {
        $order = $this->createOrder();

        // Bind a throwing Bus dispatcher only for the DispatchDomainEventsJob
        // class — every other dispatch (e.g. our observability event under
        // Event::fake) is unaffected.
        $throwingBus = Mockery::mock(BusDispatcher::class);
        $throwingBus->shouldReceive('dispatch')
            ->withArgs(function ($job): bool {
                return $job instanceof DispatchDomainEventsJob;
            })
            ->atLeast()->once()
            ->andThrow(new RuntimeException('Simulated broadcast dispatch crash'));
        // Pass-through for any other dispatch (e.g. listener internals).
        $throwingBus->shouldReceive('dispatch')
            ->andReturnUsing(function ($job) {
                return $job;
            });
        $throwingBus->shouldReceive('dispatchSync')->andReturnNull();
        $throwingBus->shouldReceive('hasCommandHandler')->andReturnFalse();
        $throwingBus->shouldReceive('getCommandHandler')->andReturnFalse();
        $throwingBus->shouldReceive('pipeThrough')->andReturnSelf();
        $throwingBus->shouldReceive('map')->andReturnSelf();
        $this->app->instance(BusDispatcher::class, $throwingBus);
        $this->app->instance('Illuminate\Bus\Dispatcher', $throwingBus);

        // Capture observability events without faking the OrderCreated chain
        // (we need the persist listener to run for real).
        Event::fake([OutboxBroadcastSwallowedEvent::class]);

        // Suppress the escalated Log::error so the assertion log stays clean.
        Log::shouldReceive('sharedContext')->andReturn([]);
        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        OrderCreated::dispatch($order);

        Event::assertDispatched(
            OutboxBroadcastSwallowedEvent::class,
            function (OutboxBroadcastSwallowedEvent $evt) use ($order): bool {
                return $evt->branchId === (int) $order->branch_id
                    && $evt->aggregateId === (int) $order->id
                    && $evt->eventType === EventType::ORDER_CREATED
                    && $evt->listener === \App\Listeners\PersistOrderCreatedToOutbox::class
                    && str_contains($evt->errorMessage, 'Simulated broadcast dispatch crash')
                    && $evt->domainEventId > 0;
            }
        );
    }

    /**
     * Structural sentinel — `PersistOrderCreatedToOutbox` source MUST:
     *   - Reference `OutboxBroadcastSwallowedEvent` (typed observability hook).
     *   - Use `Log::error` for the swallow tier (was `Log::warning`).
     *   - Keep the existing `catch (\Throwable $broadcastException)` around
     *     the `DispatchDomainEventsJob::dispatch` call (regression lock).
     */
    public function test_persist_order_created_source_has_swallow_alarm(): void
    {
        $source = file_get_contents(base_path('app/Listeners/PersistOrderCreatedToOutbox.php'));

        $this->assertStringContainsString(
            'OutboxBroadcastSwallowedEvent',
            $source,
            'WJ-4 swallow alarm: PersistOrderCreatedToOutbox MUST reference '
            . 'OutboxBroadcastSwallowedEvent. Without the typed event, ops has '
            . 'no structured signal when worker+cron are simultaneously down.'
        );

        $this->assertStringContainsString(
            'Log::error',
            $source,
            'WJ-4 swallow alarm: PersistOrderCreatedToOutbox MUST escalate the '
            . 'swallow log from warning to error so production alerting tiers '
            . 'catch silent broadcast loss.'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$broadcastException\s*\)/',
            $source,
            'WJ-4 swallow alarm: PersistOrderCreatedToOutbox MUST keep the '
            . 'Throwable catch around DispatchDomainEventsJob::dispatch — the '
            . 'isolation contract pre-dates WJ-4 (cluster-8 round-3 2026-05-11).'
        );
    }

    /**
     * Structural sentinel — same policy on PersistOrderStatusChangedToOutbox.
     */
    public function test_persist_order_status_changed_source_has_swallow_alarm(): void
    {
        $source = file_get_contents(base_path('app/Listeners/PersistOrderStatusChangedToOutbox.php'));

        $this->assertStringContainsString(
            'OutboxBroadcastSwallowedEvent',
            $source,
            'WJ-4 swallow alarm: PersistOrderStatusChangedToOutbox MUST '
            . 'reference OutboxBroadcastSwallowedEvent (mirror policy across '
            . 'the 3 outbox persist listeners).'
        );

        $this->assertStringContainsString(
            'Log::error',
            $source,
            'WJ-4 swallow alarm: PersistOrderStatusChangedToOutbox MUST '
            . 'escalate the swallow log from warning to error.'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$broadcastException\s*\)/',
            $source,
            'WJ-4 swallow alarm: PersistOrderStatusChangedToOutbox MUST keep '
            . 'the Throwable catch around DispatchDomainEventsJob::dispatch.'
        );
    }

    /**
     * Structural sentinel — same policy on
     * PersistOrderPaymentStatusChangedToOutbox.
     */
    public function test_persist_order_payment_status_changed_source_has_swallow_alarm(): void
    {
        $source = file_get_contents(base_path('app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php'));

        $this->assertStringContainsString(
            'OutboxBroadcastSwallowedEvent',
            $source,
            'WJ-4 swallow alarm: PersistOrderPaymentStatusChangedToOutbox MUST '
            . 'reference OutboxBroadcastSwallowedEvent (mirror policy across '
            . 'the 3 outbox persist listeners).'
        );

        $this->assertStringContainsString(
            'Log::error',
            $source,
            'WJ-4 swallow alarm: PersistOrderPaymentStatusChangedToOutbox MUST '
            . 'escalate the swallow log from warning to error.'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$broadcastException\s*\)/',
            $source,
            'WJ-4 swallow alarm: PersistOrderPaymentStatusChangedToOutbox MUST '
            . 'keep the Throwable catch around DispatchDomainEventsJob::dispatch.'
        );
    }

    /**
     * Structural sentinel — same policy on PersistOrderPaidAtCounterToOutbox.
     *
     * [GOAL-sync-ordertaking 2026-05-29 H4] OrderPaidAtCounter is a
     * fiscal-adjacent (payment-confirmed) event yet was the ONLY order outbox
     * persist listener missing the swallow alarm — a swallowed broadcast left
     * the operator un-paged. This locks the parity fix against regression.
     */
    public function test_persist_order_paid_at_counter_source_has_swallow_alarm(): void
    {
        $source = file_get_contents(base_path('app/Listeners/PersistOrderPaidAtCounterToOutbox.php'));

        $this->assertStringContainsString(
            'OutboxBroadcastSwallowedEvent',
            $source,
            'WJ-4 swallow alarm: PersistOrderPaidAtCounterToOutbox MUST '
            . 'reference OutboxBroadcastSwallowedEvent (mirror policy across '
            . 'the 4 outbox persist listeners — fiscal-adjacent payment event).'
        );

        $this->assertStringContainsString(
            'Log::error',
            $source,
            'WJ-4 swallow alarm: PersistOrderPaidAtCounterToOutbox MUST '
            . 'escalate the swallow log from warning to error.'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$broadcastException\s*\)/',
            $source,
            'WJ-4 swallow alarm: PersistOrderPaidAtCounterToOutbox MUST keep '
            . 'the Throwable catch around DispatchDomainEventsJob::dispatch.'
        );
    }

    /**
     * Structural sentinel — OutboxBroadcastSwallowedEvent itself MUST be:
     *   - `final` (no inheritance — prevents accidental decoration drift),
     *   - carry the contract context fields (domainEventId, eventType,
     *     aggregateId, branchId, listener, errorMessage, failedAt).
     */
    public function test_outbox_broadcast_swallowed_event_contract_shape(): void
    {
        $source = file_get_contents(base_path('app/Events/OutboxBroadcastSwallowedEvent.php'));

        $this->assertMatchesRegularExpression(
            '/final\s+class\s+OutboxBroadcastSwallowedEvent/',
            $source,
            'WJ-4 swallow alarm: OutboxBroadcastSwallowedEvent MUST be final '
            . 'class — observability hook, no inheritance allowed.'
        );

        foreach (
            [
                'int $domainEventId',
                'string $eventType',
                'int $aggregateId',
                'int $branchId',
                'string $listener',
                'string $errorMessage',
                '\DateTimeImmutable $failedAt',
            ] as $expected
        ) {
            $this->assertStringContainsString(
                $expected,
                $source,
                'WJ-4 swallow alarm: OutboxBroadcastSwallowedEvent MUST carry '
                . "field [$expected] so ops alerting has full context."
            );
        }
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
            'user_id'        => $user->id,
            'branch_id'      => $branch->id,
            'queue_number'   => 'Q-WJ4-' . random_int(1000, 9999),
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'order_type'     => 1,
            'total'          => 12.50,
        ], $overrides));
    }
}
