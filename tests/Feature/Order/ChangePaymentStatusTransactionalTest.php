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
        $order = Order::factory()->create([
            'user_id'        => $this->cashier->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => $paymentStatus,
            'status'         => OrderStatus::PENDING,
            'total'          => 25.00,
        ]);

        // [RED-DASH-02] The off-book settlement guard now refuses → PAID on
        // orders with zero tender trace. These tests pin the transactional
        // mechanics of the flip, so give the order a legitimate gateway-style
        // trace to keep exercising the same code path.
        \App\Models\Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'test-trace-' . $order->id,
            'amount'         => (float) $order->total,
            'payment_method' => '1',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        return $order;
    }

    /**
     * [GOAL-2026-05-29 F2] Concurrency guard. A second (stale) staff request whose
     * target equals what a concurrent request already persisted must idempotent-SKIP
     * inside the lockForUpdate — NO re-save, NO duplicate ActionLog/AuditLog/
     * OrderPaymentStatusChanged (which would double-hit outbox/KDS/Z). Pre-fix the
     * staff path had no lockForUpdate, so two concurrent flips both processed.
     */
    public function test_concurrent_flip_to_target_is_idempotent_no_double_effect(): void
    {
        Event::fake([OrderPaymentStatusChanged::class]);
        $this->actingAs($this->cashier, 'sanctum');

        $order = $this->makeOrder(PaymentStatus::UNPAID);

        // Concurrent winner: another request already flipped the DB row to PAID
        // out-of-band; the route-bound $order in memory still reads UNPAID.
        Order::withoutGlobalScopes()->where('id', $order->id)
            ->update(['payment_status' => PaymentStatus::PAID]);

        $auditBefore = AuditLog::query()
            ->where('action', 'order.payment_status_changed')->where('resource_id', $order->id)->count();

        $request = new \App\Http\Requests\PaymentStatusRequest();
        $request->merge(['payment_status' => PaymentStatus::PAID]);

        app(\App\Services\OrderService::class)->changePaymentStatus($order, $request, false);

        // In-lock freshOld=PAID===target -> idempotent skip: no new event, no new audit.
        Event::assertNotDispatched(OrderPaymentStatusChanged::class);
        $this->assertSame(
            $auditBefore,
            AuditLog::query()->where('action', 'order.payment_status_changed')->where('resource_id', $order->id)->count(),
            'In-lock idempotent skip must NOT write a duplicate audit row.'
        );
        $this->assertSame(
            PaymentStatus::PAID,
            (int) Order::withoutGlobalScopes()->find($order->id)->payment_status,
            'Row stays at the concurrent winner state (PAID), not double-processed.'
        );
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
}
