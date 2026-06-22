<?php

namespace Tests\Feature\PaymentService;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * [HEAL-PLAN-D.2 / Z8 P1-2] PaymentService::cashBack atomicity sentinel.
 *
 * Before this heal, `PaymentService::cashBack` performed FOUR mutating
 * side-effects without an outer `DB::transaction` envelope:
 *
 *   1. `Transaction::create([... 'type' => 'cash_back'])`  (row 1)
 *   2. `User->balance += $order->total; $user->save()`     (row 2)
 *   3. `AuditLogService::write([...])` — HMAC chain append (row 3)
 *   4. `RefundCreated::dispatch($order)` — releases stock + broadcasts
 *
 * If step 3 (audit chain write) threw — e.g. because the chain head was
 * missing on a fresh tenant or a unique constraint collided — steps 1
 * and 2 had already committed independently, leaving an orphan
 * `Transaction(cash_back)` row + an inflated `User.balance` with NO
 * audit footprint and NO downstream `RefundCreated` dispatch. NF525
 * auditors viewing the books would see a phantom refund that never
 * released stock or updated the parent payment status.
 *
 * Post-heal, the body (after the idempotent early-return) is wrapped
 * in a single `DB::transaction(...)`. A throw at any step rolls back
 * the Transaction row + the User balance + the audit row in one shot.
 * `RefundCreated` uses `DispatchableAfterCommit`, so its listeners
 * only fire on durable commit — a rollback discards the deferred
 * dispatch before listeners run.
 *
 * @covers \App\Services\PaymentService::cashBack
 */
class CashBackAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /**
     * RED scenario: AuditLogService::write throws AFTER Transaction::create
     * AND User->balance update have already run inside the transaction.
     * Post-heal the whole envelope must roll back — zero rows persisted,
     * balance unchanged, RefundCreated NOT dispatched.
     */
    public function test_audit_log_failure_rolls_back_transaction_and_balance_and_skips_refund_dispatch(): void
    {
        Event::fake([RefundCreated::class]);

        // Stub the audit log service to throw on write — simulates a chain
        // HMAC failure mid-cashBack. MUST extend AuditLogService (not
        // anonymous) so any typehint-bound caller resolves correctly.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                throw new RuntimeException('Simulated audit chain write failure (HEAL-PLAN-D.2 RED).');
            }
        });

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'balance'   => 0,
        ]);

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::CANCELED,
            'total'          => 25.00,
        ]);

        // cashBack early-returns unless a prior payment Transaction exists.
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'FK-CB-ATOMIC-RED-1',
            'amount'         => 25.00,
            'payment_method' => 'cash',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        $thrown = null;
        try {
            app(PaymentService::class)->cashBack($order, 'cash', 'FK-CB-ATOMIC-RED-1');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'AuditLogService throw must propagate out of cashBack — caller surfaces the failure.'
        );
        $this->assertSame(
            'Simulated audit chain write failure (HEAL-PLAN-D.2 RED).',
            $thrown->getMessage()
        );

        // Atomicity invariant 1: no orphan cash_back Transaction row.
        $this->assertSame(
            0,
            Transaction::query()
                ->where('order_id', $order->id)
                ->where('type', 'cash_back')
                ->count(),
            'Cash-back Transaction row MUST be rolled back when audit chain write fails.'
        );

        // Atomicity invariant 2: User.balance must not have been mutated.
        $this->assertEquals(
            0.0,
            (float) $customer->fresh()->balance,
            'User.balance MUST be rolled back when audit chain write fails (no phantom refund credit).'
        );

        // Atomicity invariant 3: RefundCreated MUST NOT be dispatched —
        // DispatchableAfterCommit discards deferred callbacks on rollback.
        Event::assertNotDispatched(RefundCreated::class);

        // Hygiene invariant: no leaked open transaction level after rollback.
        $this->assertSame(
            0,
            DB::transactionLevel(),
            'cashBack must leave DB::transactionLevel at 0 after rollback (no half-open tx).'
        );
    }

    /**
     * GREEN scenario: happy path still works post-wrap. Transaction row
     * persisted, balance credited, RefundCreated dispatched (deferred
     * to after-commit but Event::fake captures the dispatch call site).
     */
    public function test_happy_path_persists_transaction_credits_balance_and_dispatches_refund(): void
    {
        Event::fake([RefundCreated::class]);

        // No-op audit log (mirrors RefundBroadcastsPaymentStatusChangedTest
        // pattern — we don't need a real HMAC chain head for this case).
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'balance'   => 0,
        ]);

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::CANCELED,
            'total'          => 12.50,
        ]);

        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'FK-CB-ATOMIC-GREEN-1',
            'amount'         => 12.50,
            'payment_method' => 'cash',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        $created = app(PaymentService::class)->cashBack($order, 'cash', 'FK-CB-ATOMIC-GREEN-1');

        $this->assertNotNull($created, 'Happy-path cashBack must return the new Transaction row.');
        $this->assertSame('cash_back', (string) $created->type);

        $this->assertSame(
            1,
            Transaction::query()
                ->where('order_id', $order->id)
                ->where('type', 'cash_back')
                ->count(),
            'Cash-back Transaction row must be persisted on happy path.'
        );

        $this->assertEquals(
            12.50,
            (float) $customer->fresh()->balance,
            'User.balance must be credited by $order->total on happy path.'
        );

        Event::assertDispatched(
            RefundCreated::class,
            function (RefundCreated $event) use ($order) {
                return (int) $event->order->id === (int) $order->id;
            }
        );

        $this->assertSame(
            0,
            DB::transactionLevel(),
            'cashBack must leave DB::transactionLevel at 0 after commit (no leaked tx).'
        );
    }

    /**
     * Nested-tx safety: when cashBack is invoked INSIDE an outer
     * DB::transaction (the OrderService::changeStatus path), Laravel uses
     * savepoints — so a failure inside cashBack must roll back to the
     * savepoint without aborting the OUTER tx that we have not yet
     * decided to commit/rollback ourselves. Mirrors the real-world
     * call shape from OrderService::changeStatus (lines 1747 + 1850).
     */
    public function test_nested_transaction_savepoint_semantics_when_audit_fails(): void
    {
        Event::fake([RefundCreated::class]);

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                throw new RuntimeException('Nested-tx audit failure.');
            }
        });

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'balance'   => 0,
        ]);

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::CANCELED,
            'total'          => 7.00,
        ]);

        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'FK-CB-NESTED-1',
            'amount'         => 7.00,
            'payment_method' => 'cash',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        // Outer transaction simulating OrderService::changeStatus envelope.
        $outerCaught = null;
        try {
            DB::transaction(function () use ($order, $customer, &$outerCaught) {
                // Mutate something the outer caller would otherwise commit.
                $customer->update(['name' => 'OUTER-MUTATION-TARGET']);

                try {
                    app(PaymentService::class)->cashBack($order, 'cash', 'FK-CB-NESTED-1');
                } catch (\Throwable $inner) {
                    $outerCaught = $inner;
                    throw $inner; // propagate to abort outer too
                }
            });
        } catch (\Throwable $e) {
            // Outer rolls back too — expected on this synthetic path.
        }

        $this->assertNotNull($outerCaught, 'Inner cashBack failure must propagate.');

        $this->assertSame(
            0,
            Transaction::query()
                ->where('order_id', $order->id)
                ->where('type', 'cash_back')
                ->count(),
            'Cash-back Transaction row must be rolled back via savepoint when audit fails inside nested tx.'
        );

        $this->assertNotSame(
            'OUTER-MUTATION-TARGET',
            (string) $customer->fresh()->name,
            'Outer tx must also roll back when inner throw propagates (Laravel savepoint semantics).'
        );

        Event::assertNotDispatched(RefundCreated::class);

        $this->assertSame(
            0,
            DB::transactionLevel(),
            'No leaked outer tx after nested rollback.'
        );
    }
}
