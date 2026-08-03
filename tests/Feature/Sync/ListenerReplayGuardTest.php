<?php

namespace Tests\Feature\Sync;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderPaidAtCounter;
use App\Jobs\DispatchDomainEventsJob;
use App\Listeners\PersistOrderCreatedToOutbox;
use App\Listeners\PersistOrderPaidAtCounterToOutbox;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [Sprint 3B P1-SYNC-03 2026-05-16]
 *
 * Pre-Sprint 3B audit (Wave D) flagged that the two order-lifecycle listeners
 * `PersistOrderCreatedToOutbox` and `PersistOrderPaidAtCounterToOutbox` were
 * registering a `DB::afterCommit(DispatchDomainEventsJob::dispatch(...))` call
 * unconditionally on every `handle()` fire. On a listener replay (queue retry
 * between persistence and post-commit dispatch), the second `firstOrCreate`
 * collapses to the existing row via the UNIQUE idempotency_key index, but
 * the second `DispatchDomainEventsJob` was still queued — wasting a worker
 * slot and polluting logs (Phase 1 of the job claims the dispatched_at column
 * via `lockForUpdate` and exits silently, so correctness was preserved, but
 * the overhead was free to eliminate).
 *
 * Parity with the 6 listeners already guarded by `wasRecentlyCreated`
 * (PersistCatalogChangedToOutbox:92, PersistCouponChangedToOutbox:82, plus
 * the 4 in iter15 P1b ListenerReplayDedupeTest).
 *
 * This sentinel pins the contract: after two `handle()` calls with the same
 * event/correlation, exactly ONE `DispatchDomainEventsJob` is queued. The
 * single outbox row is still inserted (covered by the `firstOrCreate` UNIQUE
 * index — same as the existing dedupe tests).
 */
class ListenerReplayGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FIXED_CORRELATION = 'corr-sprint3b-listener-replay-guard-fixture';

    protected function setUp(): void
    {
        parent::setUp();

        // Pin correlation_id so each handle() call within the same test sees the
        // same correlation. Mirrors ListenerReplayDedupeTest setUp pattern —
        // without it `resolveCorrelationId()` falls back to a fresh Str::uuid()
        // per invocation and the "replay" assertion becomes "two distinct events".
        Log::shareContext(['correlation_id' => self::FIXED_CORRELATION]);

        Bus::fake([DispatchDomainEventsJob::class]);
    }

    public function test_persist_order_created_replay_queues_dispatch_job_only_once(): void
    {
        $order = $this->makeOrder('Q-3B-1');

        $event = new OrderCreated($order);
        $listener = new PersistOrderCreatedToOutbox();

        // DB::transaction wrap is required because `RefreshDatabase` keeps the
        // outer test transaction open, so the listener's `DB::afterCommit`
        // callback would otherwise never fire. The savepoint commit at the
        // close of this closure flushes the afterCommit callback registered
        // by the FIRST handle() — and importantly, the SECOND handle() must
        // NOT register a second callback because `wasRecentlyCreated` is false.
        DB::transaction(function () use ($listener, $event): void {
            $listener->handle($event);
            $listener->handle($event); // replay — same key, wasRecentlyCreated=false on row 2
        });

        $this->assertSame(
            1,
            DomainEvent::query()->where('event_type', EventType::ORDER_CREATED)->count(),
            'firstOrCreate UNIQUE-index dedupe must collapse the replay to one row.'
        );

        Bus::assertDispatchedTimes(DispatchDomainEventsJob::class, 1);
    }

    public function test_persist_order_paid_at_counter_replay_queues_dispatch_job_only_once(): void
    {
        $order = $this->makeOrder('Q-3B-2');

        $event = new OrderPaidAtCounter(
            order: $order,
            paymentMethod: 1,
        );
        $listener = new PersistOrderPaidAtCounterToOutbox();

        DB::transaction(function () use ($listener, $event): void {
            $listener->handle($event);
            $listener->handle($event); // replay
        });

        $this->assertSame(
            1,
            DomainEvent::query()->where('event_type', EventType::ORDER_PAYMENT_CONFIRMED)->count(),
            'firstOrCreate UNIQUE-index dedupe must collapse the replay to one row.'
        );

        Bus::assertDispatchedTimes(DispatchDomainEventsJob::class, 1);
    }

    public function test_persist_order_created_first_fire_still_queues_dispatch_job(): void
    {
        // Sanity guard: the new wasRecentlyCreated check must NOT regress the
        // happy path — first fire still enqueues exactly one dispatch.
        $order = $this->makeOrder('Q-3B-3');

        $event = new OrderCreated($order);
        $listener = new PersistOrderCreatedToOutbox();

        DB::transaction(function () use ($listener, $event): void {
            $listener->handle($event);
        });

        Bus::assertDispatchedTimes(DispatchDomainEventsJob::class, 1);
    }

    public function test_persist_order_paid_at_counter_first_fire_still_queues_dispatch_job(): void
    {
        $order = $this->makeOrder('Q-3B-4');

        $event = new OrderPaidAtCounter(
            order: $order,
            paymentMethod: 1,
        );
        $listener = new PersistOrderPaidAtCounterToOutbox();

        DB::transaction(function () use ($listener, $event): void {
            $listener->handle($event);
        });

        Bus::assertDispatchedTimes(DispatchDomainEventsJob::class, 1);
    }

    private function makeOrder(string $queueNumber): Order
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        // Explicit `phone` because Sprint 2B / DEL-4 migration
        // (2026_05_16_140100_make_user_phone_required.php) made users.phone
        // NOT NULL but UserFactory::definition() does not provide it.
        // Sibling defense: avoid coupling Sprint 3B sentinel to that drift.
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'phone' => 'TEST-' . $queueNumber,
        ]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'queue_number' => $queueNumber,
            'status' => \App\Enums\OrderStatus::PENDING,
            'order_type' => 1,
            'total' => 19.90,
        ]);
    }
}
