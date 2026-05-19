<?php

namespace Tests\Feature\Refund;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderPaymentStatusChanged;
use App\Events\RefundCreated;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Order\RefundWithCounterEntryService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [WG-1-WF6-P1-1] Refund payment_status broadcast hole sentinel.
 *
 * Before this heal, neither {@see PaymentService::cashBack} nor
 * {@see RefundWithCounterEntryService::execute} dispatched the
 * {@see OrderPaymentStatusChanged} domain event — so connected
 * POS / admin / OSS clients NEVER received a realtime refund signal.
 *
 * Heal strategy: a NEW listener on {@see RefundCreated} that
 *  (a) updates the parent's payment_status to REFUNDED iff the parent
 *      is mutable (pre-Z path = cashBack), and
 *  (b) always dispatches OrderPaymentStatusChanged so the outbox
 *      listener PersistOrderPaymentStatusChangedToOutbox writes a row
 *      and broadcasts to private-branch.{branch_id}.
 *
 * The post-Z path (sealed parent) keeps the parent IMMUTABLE — but
 * still emits the broadcast so the UI knows a counter-entry happened.
 */
class RefundBroadcastsPaymentStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Audit log is exercised in dedicated tests — no-op here so we
        // don't need to seed a chain head for cashBack / counter-entry.
        // MUST extend AuditLogService (not anonymous-only) because
        // RefundWithCounterEntryService::__construct typehints the param.
        // Returns a stub AuditLog model to satisfy the non-nullable return.
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
     * P1-1 case 1: PaymentService::cashBack on a pre-Z order
     * MUST dispatch OrderPaymentStatusChanged AND mutate the parent
     * payment_status to REFUNDED (parent is mutable pre-Z).
     */
    public function test_cashback_dispatches_order_payment_status_changed_and_mutates_parent(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);

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

        // cashBack early-returns unless a prior payment transaction exists.
        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-CB-PAID-1',
            'amount' => 25.00,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        app(PaymentService::class)->cashBack($order, 'cash', 'FK-CB-1');

        Event::assertDispatched(
            OrderPaymentStatusChanged::class,
            function (OrderPaymentStatusChanged $event) use ($order) {
                return (int) $event->order->id === (int) $order->id
                    && (int) $event->oldPaymentStatus === PaymentStatus::PAID
                    && (int) $event->newPaymentStatus === PaymentStatus::REFUNDED;
            }
        );

        // Pre-Z parent is mutable — payment_status persisted as REFUNDED.
        $this->assertSame(
            PaymentStatus::REFUNDED,
            (int) $order->fresh()->payment_status,
            'Pre-Z cashBack must persist payment_status=REFUNDED on the parent.'
        );
    }

    /**
     * P1-1 case 2: RefundWithCounterEntryService::execute (post-Z)
     * MUST dispatch OrderPaymentStatusChanged so clients see the refund,
     * WITHOUT mutating the sealed parent (NF525 immutability invariant).
     */
    public function test_counter_entry_dispatches_order_payment_status_changed_without_mutating_parent(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);

        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($cashier);

        // Seal Z window so RefundWithCounterEntry::assertSealed passes.
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 18.00,
            'total'          => 18.00,
            'total_tax'      => 0,
            'created_at'     => $opened->copy()->addHours(2),
        ]);
        $parent->fiscal_sequence_no = 200;
        $parent->save();

        // Mock FiscalSequenceService::next() to bypass branch-level lock
        // complications in sqlite test env (deterministic 201 next).
        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            public function __construct()
            {
            }

            public function next(int $branchId): int
            {
                return 201;
            }
        });

        app(RefundWithCounterEntryService::class)->execute($parent, 'sentinel-test-refund');

        Event::assertDispatched(
            OrderPaymentStatusChanged::class,
            function (OrderPaymentStatusChanged $event) use ($parent) {
                return (int) $event->order->id === (int) $parent->id
                    && (int) $event->newPaymentStatus === PaymentStatus::REFUNDED;
            }
        );

        // Sealed parent stays IMMUTABLE — payment_status MUST NOT change.
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $parent->fresh()->payment_status,
            'NF525: sealed parent payment_status must not be mutated by counter-entry refund.'
        );
    }

    /**
     * P1-1 case 3: End-to-end — RefundCreated → listener →
     * OrderPaymentStatusChanged → PersistOrderPaymentStatusChangedToOutbox
     * writes a domain_events row visible to broadcast workers.
     *
     * Does NOT fake events — the full chain runs so the outbox row is
     * actually persisted.
     */
    public function test_refund_chain_persists_domain_events_row_for_broadcast(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'total' => 12.34,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-CB-PAID-3',
            'amount' => 12.34,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        app(PaymentService::class)->cashBack($order, 'cash', 'FK-CB-3');

        $outboxRow = DomainEvent::query()
            ->where('event_type', EventType::ORDER_PAYMENT_STATUS_CHANGED)
            ->where('aggregate_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($outboxRow, 'Outbox row MUST be written on refund chain.');
        $this->assertSame((int) $branch->id, (int) $outboxRow->branch_id);
        $this->assertSame('OrderPaymentStatusChanged', $outboxRow->broadcast_as);

        $payload = is_array($outboxRow->payload) ? $outboxRow->payload : json_decode($outboxRow->payload, true);
        $this->assertSame((int) $order->id, (int) $payload['order_id']);
        $this->assertSame(PaymentStatus::REFUNDED, (int) $payload['new_status']);
    }
}
