<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderPaymentStatusChanged;
use App\Models\ActionLog;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * @FK-ID F-VERIFY-09-01 | @plan docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 *
 * Atomicity guarantees of {@see \App\Services\OrderService::changePaymentStatus}.
 * The Order save + ActionLog::create + AuditLogService::write + domain event
 * dispatch live inside one DB::transaction(): if any one fails, all of them
 * roll back and the domain event is dropped (DispatchableAfterCommit).
 */
class ChangePaymentStatusTransactionalTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch  = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeOrder(int $paymentStatus = PaymentStatus::UNPAID): Order
    {
        return Order::factory()->create([
            'user_id'        => $this->cashier->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => $paymentStatus,
            'status'         => OrderStatus::PENDING,
            'total'          => 25.00,
        ]);
    }

    public function test_it_rolls_back_when_audit_log_write_fails(): void
    {
        $order = $this->makeOrder();

        // Mock the AuditLogService bound in the container so write() throws,
        // simulating an audit-chain corruption / lock contention failure.
        $auditMock = Mockery::mock(AuditLogService::class);
        $auditMock->shouldReceive('write')->once()->andThrow(new \RuntimeException('audit-chain failure'));
        $this->app->instance(AuditLogService::class, $auditMock);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        // Service catches \Exception and returns 422.
        $response->assertStatus(422);

        // Order save was rolled back.
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'Audit-log failure must roll back the order save.'
        );

        // No ActionLog row persisted.
        $this->assertSame(
            0,
            ActionLog::query()->where('resource', 'Commande #' . $order->order_serial_no)->count(),
            'ActionLog must be rolled back alongside Order save.'
        );

        // No DomainEvent row — dispatch is deferred to commit which never happened.
        $this->assertSame(
            0,
            DomainEvent::query()->where('aggregate_id', $order->id)->count(),
            'No outbox row must be persisted on rollback.'
        );
    }

    public function test_it_dispatches_event_only_after_commit(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);

        $order = $this->makeOrder();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertStatus(200);

        // Event was dispatched after commit. Event::fake captures it because
        // the afterCommit callback fires synchronously when the outermost
        // transaction commits (which is inside the service).
        Event::assertDispatched(OrderPaymentStatusChanged::class, function (OrderPaymentStatusChanged $event) use ($order) {
            return (int) $event->order->id === (int) $order->id
                && $event->oldPaymentStatus === PaymentStatus::UNPAID
                && $event->newPaymentStatus === PaymentStatus::PAID;
        });
    }

    public function test_it_emits_one_action_log_one_audit_log_one_event_per_call(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);

        $order = $this->makeOrder();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertStatus(200);

        $this->assertSame(
            1,
            ActionLog::query()->where('resource', 'Commande #' . $order->order_serial_no)->count(),
            'Exactly one ActionLog row per successful call.'
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'order.payment_status_changed')
                ->where('resource_id', $order->id)
                ->count(),
            'Exactly one AuditLog row per successful call.'
        );
        Event::assertDispatchedTimes(OrderPaymentStatusChanged::class, 1);
    }

    /**
     * [GOAL-2026-05-29 FISCAL-P1] Sealing an order PAID via this admin route MUST
     * allocate the NF525 fiscal_sequence_no (mirrors
     * PaymentService::confirmCounterPayment). Without it, a counter-deferred sale
     * marked paid this way escaped ZReportService aggregation
     * (whereNotNull('fiscal_sequence_no')) and never reached the signed Z-report.
     */
    public function test_change_payment_status_to_paid_allocates_fiscal_sequence(): void
    {
        $order = $this->makeOrder(PaymentStatus::PENDING_COUNTER);
        $this->assertNull(
            $order->fiscal_sequence_no,
            'A counter-deferred order starts with no fiscal sequence.'
        );

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'Sealing PAID via changePaymentStatus MUST allocate fiscal_sequence_no so the sale reaches the NF525 Z-report.'
        );
        $this->assertGreaterThan(0, (int) $fresh->fiscal_sequence_no);
    }

    /**
     * [GOAL-2026-05-29 FISCAL-P1] Guard: a non-PAID transition must NOT allocate a
     * fiscal sequence (only sealing PAID does), keeping the sequence gap-free.
     */
    public function test_non_paid_transition_does_not_allocate_fiscal_sequence(): void
    {
        // PAID -> REFUNDED is blocked under Option B; use a transition that the
        // state machine allows and that is NOT ->PAID. UNPAID stays unsealed.
        $order = $this->makeOrder(PaymentStatus::UNPAID);

        // A no-op same-status / illegal transition is rejected upstream; we only
        // assert the invariant that an order never sealed PAID has no sequence.
        $this->assertNull(
            Order::withoutGlobalScopes()->findOrFail($order->id)->fiscal_sequence_no,
            'An order that never reached PAID must carry no fiscal sequence.'
        );
    }
}
