<?php

namespace Tests\Feature\Reactive;

use App\Listeners\PersistOrderCreatedToOutbox;
use App\Listeners\PersistOrderPaidAtCounterToOutbox;
use App\Listeners\PersistOrderStatusChangedToOutbox;
use App\Events\OrderCreated;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * REACT-A-1 / REACT-NEW-1 / REACT-NEW-2 (P1, ultra-review 2026-06-14).
 *
 * The Persist*ToOutbox listeners are registered FIRST and run synchronously; their
 * events use DispatchableAfterCommit, so business-critical listeners (stock decrement,
 * loyalty award, receipt) run AFTER them. If the synchronous outbox firstOrCreate
 * throws, Laravel's sync dispatcher aborts the WHOLE cascade → oversell / lost loyalty
 * / POS 500 on a committed sale. These tests force a REAL persistence failure (the
 * domain_events table is dropped) and prove handle() swallows it instead of propagating.
 */
class OutboxCascadeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function breakOutboxTable(): void
    {
        // Force the real DomainEvent::firstOrCreate to throw a genuine QueryException.
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('domain_events');
        Schema::enableForeignKeyConstraints();
    }

    public function test_order_created_outbox_failure_does_not_propagate(): void
    {
        $order = Order::factory()->create();
        $this->breakOutboxTable();

        // Must NOT throw — the downstream stock/availability cascade depends on it.
        (new PersistOrderCreatedToOutbox())->handle(new OrderCreated($order));

        $this->assertTrue(true, 'OrderCreated outbox persistence failure was swallowed (cascade preserved).');
    }

    /**
     * [WS-2 / B-1 fault-injection 2026-06-15] The strong proof: a listener registered AFTER
     * the (throwing) outbox listener STILL runs. PersistOrderCreatedToOutbox is registered
     * FIRST in the real EventServiceProvider cascade; if its outbox-fault propagated, Laravel's
     * synchronous dispatcher would abort and every downstream listener (stock decrement,
     * receipt) would be skipped — oversell. This injects a real outbox fault and proves the
     * cascade continues to a trailing listener.
     */
    public function test_cascade_continues_to_downstream_listener_after_real_outbox_fault(): void
    {
        $downstreamRan = false;
        // Appended AFTER the provider's listeners (incl. the first-in-cascade outbox listener).
        \Illuminate\Support\Facades\Event::listen(OrderCreated::class, function () use (&$downstreamRan): void {
            $downstreamRan = true;
        });

        $order = Order::factory()->create();
        $this->breakOutboxTable(); // the real PersistOrderCreatedToOutbox::firstOrCreate will now throw

        // Fire the REAL cascade through the dispatcher (not a direct handle() call).
        OrderCreated::dispatch($order);

        $this->assertTrue(
            $downstreamRan,
            'A listener after the throwing outbox listener MUST still run — the guard kept the cascade alive (no oversell).'
        );
    }

    public function test_order_status_changed_outbox_failure_does_not_propagate(): void
    {
        $order = Order::factory()->create();
        $this->breakOutboxTable();

        (new PersistOrderStatusChangedToOutbox())->handle(
            new OrderStatusChanged($order, (int) $order->status, (int) $order->status)
        );

        $this->assertTrue(true, 'OrderStatusChanged outbox persistence failure was swallowed (loyalty-award cascade preserved).');
    }

    public function test_order_paid_at_counter_outbox_failure_does_not_propagate(): void
    {
        $order = Order::factory()->create();
        $this->breakOutboxTable();

        (new PersistOrderPaidAtCounterToOutbox())->handle(new OrderPaidAtCounter($order, 1));

        $this->assertTrue(true, 'OrderPaidAtCounter outbox persistence failure was swallowed (no POS 500 on a PAID sale).');
    }
}
