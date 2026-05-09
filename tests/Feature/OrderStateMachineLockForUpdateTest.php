<?php

namespace Tests\Feature;

use App\Domain\Order\IllegalTransitionException;
use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * [iter15 P0-12 LOCKFORUPDATE 2026-05-10]
 *
 * Guards the race-fix in {@see OrderStateMachine::apply()} where the source
 * status read used to happen against the in-memory model BEFORE the
 * transaction. Two concurrent apply() calls would both pass {@see OrderStateMachine::allows()}
 * and both write the target status, doubling audit rows.
 *
 * The fix wraps the read inside DB::transaction with `lockForUpdate()`,
 * mirroring `OrderService::changeStatus` (iter13 P1 LOCKFORUPDATE) at
 * `app/Services/OrderService.php:1515`.
 *
 * SQLite + RefreshDatabase serialises everything, so these tests verify
 * the contract via:
 *   - source-level introspection (lockForUpdate present inside transaction)
 *   - behavioural idempotency when the row is already at the target status
 *   - audit-row correctness after a locked write
 */
class OrderStateMachineLockForUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_uses_lockForUpdate_inside_transaction(): void
    {
        $reflection = new ReflectionClass(OrderStateMachine::class);
        $source = file_get_contents($reflection->getFileName());

        // Locate the apply() method body — the regex captures from the
        // declaration to the matching closing brace at the same indentation.
        $this->assertStringContainsString(
            'public static function apply(',
            $source,
            'apply() method must exist on OrderStateMachine.'
        );

        // The fix requires both a transaction wrapper AND a lockForUpdate
        // read INSIDE that wrapper. We assert ordering at the source level
        // because SQLite makes lockForUpdate a no-op at runtime.
        $applyStart = strpos($source, 'public static function apply(');
        $this->assertNotFalse($applyStart, 'apply() declaration must be findable.');

        $applyBody = substr($source, $applyStart);
        $endOfApply = strpos($applyBody, "\n    }\n");
        $this->assertNotFalse($endOfApply, 'apply() method must have a closing brace.');
        $applyBody = substr($applyBody, 0, $endOfApply);

        // Strip single-line comments — the iter15 fix carries a long
        // documentation block that mentions both `DB::transaction` and
        // `->lockForUpdate()->` in prose, which would skew positional
        // assertions about the EXECUTABLE control flow.
        $applyCode = preg_replace('/^\s*\/\/.*$/m', '', $applyBody);
        $this->assertIsString($applyCode, 'comment-stripping regex must succeed.');

        $this->assertStringContainsString(
            'DB::transaction(',
            $applyCode,
            'apply() must wrap mutate path in DB::transaction().'
        );

        $this->assertStringContainsString(
            'lockForUpdate()',
            $applyCode,
            'apply() must call lockForUpdate() to serialize concurrent transitions (iter15 P0-12).'
        );

        // The lockForUpdate must come AFTER DB::transaction( in the source —
        // i.e. the lock is acquired inside the transaction scope.
        $txIndex = strpos($applyCode, 'DB::transaction(');
        $lockIndex = strpos($applyCode, '->lockForUpdate()->');
        $this->assertNotFalse($lockIndex, 'apply() must contain a chained ->lockForUpdate()-> call site.');
        $this->assertLessThan(
            $lockIndex,
            $txIndex,
            'lockForUpdate() must be acquired INSIDE the DB::transaction closure, not before it.'
        );

        // Sanity: the locked row must be used to compute $from (otherwise the
        // race-fix is a placebo).
        $this->assertMatchesRegularExpression(
            '/\$locked\s*=\s*\$modelClass::query\(\)->whereKey\(\$orderKey\)->lockForUpdate\(\)->firstOrFail\(\)/',
            $applyCode,
            'apply() must lock the row by primary key before reading status.'
        );

        $this->assertMatchesRegularExpression(
            '/\$from\s*=\s*\(int\)\s*\$locked->status/',
            $applyCode,
            'apply() must read status from the LOCKED row, not the in-memory model.'
        );
    }

    public function test_concurrent_apply_calls_serialize_via_lock(): void
    {
        // Pessimistic / behavioural race test. SQLite serialises naturally,
        // so we cannot truly observe contention here, but we CAN observe the
        // idempotent guard the lock-then-reread enables: when the same
        // transition is requested twice on a stale in-memory model, only ONE
        // audit row is written, and the second call no-ops cleanly.
        $order = $this->makeOrder(OrderStatus::PREPARED);

        // First call — succeeds, writes audit, mutates row to DELIVERED.
        OrderStateMachine::apply($order, OrderStatus::DELIVERED, null, null);

        // Second instance with STALE in-memory status (still believes the
        // row is PREPARED). Without the iter15 fix, the old code would have
        // read in-memory PREPARED, passed allows(PREPARED→DELIVERED), and
        // written a second audit row. With the fix, the locked re-read sees
        // DELIVERED and short-circuits via the idempotent branch.
        /** @var Order $stale */
        $stale = Order::find($order->id);
        $stale->status = OrderStatus::PREPARED; // simulate stale read
        $this->assertSame(OrderStatus::PREPARED, (int) $stale->status, 'pre-condition: in-memory model is stale');

        OrderStateMachine::apply($stale, OrderStatus::DELIVERED, null, null);

        // Persisted state remains DELIVERED — no double write.
        $stale->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $stale->status);

        // Exactly ONE audit row exists for this order, even though apply()
        // was called twice with the same target.
        $this->assertSame(
            1,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'Concurrent / repeated apply() calls must produce exactly one audit row.'
        );

        $row = OrderStatusTransition::query()->where('order_id', $order->id)->first();
        $this->assertSame(OrderStatus::PREPARED, (int) $row->from_status);
        $this->assertSame(OrderStatus::DELIVERED, (int) $row->to_status);
    }

    public function test_apply_idempotent_when_already_at_target_status(): void
    {
        $order = $this->makeOrder(OrderStatus::PREPARING);

        // Move it to PREPARED first.
        OrderStateMachine::apply($order, OrderStatus::PREPARED, null, null);
        $order->refresh();
        $this->assertSame(OrderStatus::PREPARED, (int) $order->status);

        $this->assertSame(
            1,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'pre-condition: exactly one audit row after first transition'
        );

        // Now request PREPARED again — should be a no-op (idempotent).
        // The locked row read sees status=PREPARED already, so the closure
        // returns early without writing or auditing.
        OrderStateMachine::apply($order, OrderStatus::PREPARED, null, null);

        $order->refresh();
        $this->assertSame(
            OrderStatus::PREPARED,
            (int) $order->status,
            'Idempotent apply must leave persisted status unchanged.'
        );

        $this->assertSame(
            1,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'Idempotent apply at target status must NOT write a duplicate audit row.'
        );
    }

    public function test_apply_writes_audit_trail_with_locked_row(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $order = $this->makeOrder(OrderStatus::PENDING, $branch->id);

        // Sanity: capture queries to verify a SELECT against orders happens
        // inside the transaction (the lockForUpdate clause is a no-op on
        // SQLite but the query itself is observable).
        DB::enableQueryLog();
        DB::flushQueryLog();

        OrderStateMachine::apply($order, OrderStatus::ACCEPT, $user, null);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $orderTable = (new Order())->getTable();
        $hasLockedRead = false;
        foreach ($log as $entry) {
            $sql = strtolower($entry['query']);
            if (str_contains($sql, 'select') && str_contains($sql, $orderTable) && str_contains($sql, 'where') && str_contains($sql, '"id"')) {
                $hasLockedRead = true;
                break;
            }
        }
        $this->assertTrue(
            $hasLockedRead,
            'apply() must issue a SELECT against the orders table to fetch the locked row before mutating.'
        );

        // Persisted state and audit trail MUST reflect the transition driven
        // by the locked row (from=PENDING, to=ACCEPT, actor=user).
        $order->refresh();
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->status);

        $rows = OrderStatusTransition::query()
            ->where('order_id', $order->id)
            ->where('order_type', Order::class)
            ->get();

        $this->assertCount(1, $rows, 'exactly one audit row per transition');
        $row = $rows->first();
        $this->assertSame(OrderStatus::PENDING, (int) $row->from_status, 'from_status must come from the LOCKED row, not from a stale read');
        $this->assertSame(OrderStatus::ACCEPT, (int) $row->to_status);
        $this->assertSame((int) $user->id, (int) $row->actor_id);
        $this->assertSame('user', $row->actor_type);
    }

    public function test_apply_illegal_transition_inside_lock_still_rolls_back(): void
    {
        // Defensive: if the locked re-read reveals an illegal transition,
        // the throw must abort the transaction with NO audit row written.
        // This guards against a regression where the fix accidentally lets
        // a write slip through before the allows() guard.
        $order = $this->makeOrder(OrderStatus::PENDING);

        $this->expectException(IllegalTransitionException::class);

        try {
            OrderStateMachine::apply($order, OrderStatus::RETURNED, null, null);
        } finally {
            $order->refresh();
            $this->assertSame(OrderStatus::PENDING, (int) $order->status, 'illegal transition must NOT mutate the row');
            $this->assertSame(
                0,
                OrderStatusTransition::query()->where('order_id', $order->id)->count(),
                'illegal transition must NOT write an audit row'
            );
        }
    }

    private function makeOrder(int $status, ?int $branchId = null): Order
    {
        $branch = $branchId ? Branch::find($branchId) : Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'queue_number' => 'Q-' . fake()->unique()->numberBetween(1, 9999),
            'status' => $status,
            'order_type' => 1,
            'total' => 10.00,
        ]);
    }
}
