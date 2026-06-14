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
