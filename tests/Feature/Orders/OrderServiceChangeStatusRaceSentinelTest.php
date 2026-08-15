<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Http\Requests\OrderStatusRequest;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL-K2-HEAL-02 2026-05-24 K.2 H5 P1]
 *
 * Guards the multi-cashier POS Livré race fix in
 * {@see \App\Services\OrderService::changeStatus()} `else` branch
 * (line 1927+). Before this fix, two cashiers clicking "Livré" on the
 * same PREPARED order both read the same route-bound in-memory model
 * (fetched OUTSIDE the DB::transaction), both passed the idempotent
 * gate `if ($order->status === $statusInt)`, and both proceeded to
 * write `order_status_transitions` + `action_logs` rows — leaving
 * ambiguous "who delivered" attribution and a corrupted audit trail.
 *
 * The fix re-fetches the row inside the transaction with
 * `Order::query()->whereKey($id)->lockForUpdate()->firstOrFail()` and
 * runs all reads/writes on the locked instance. The route-bound model
 * is then synced via `setRawAttributes()` so the post-transaction
 * broadcasts at lines 2049-2068 read fresh state.
 *
 * Mirrors the disciplined pattern in:
 *   - {@see \App\Services\OrderService::changeStatus} self-cancel
 *     branch at lines 1871-1901 (already locked since iter13 P1)
 *   - {@see \App\Services\PaymentService::confirmCounterPayment} L219-249
 *   - {@see \App\Services\KitchenDisplaySystemOrderService::changeStatus}
 *     L257-261
 *   - {@see \Tests\Feature\OrderStateMachineLockForUpdateTest} (iter15)
 *
 * SQLite + RefreshDatabase serialises everything at runtime, so this
 * sentinel verifies the contract via:
 *   - source-level introspection (lockForUpdate present INSIDE the
 *     `else`-branch DB::transaction closure)
 *   - behavioural idempotency when the row is already at the target
 *     status (only ONE order_status_transitions row + ONE Changement
 *     de statut action_log per order)
 */
class OrderServiceChangeStatusRaceSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Source-level guard: the `else` branch of OrderService::changeStatus
     * must wrap mutate path in DB::transaction AND acquire lockForUpdate
     * INSIDE that closure (acquiring it OUTSIDE would not serialize
     * concurrent requests; reading it from a route-bound model would
     * not reflect a competing committed write).
     */
    public function test_change_status_else_branch_uses_lockForUpdate_inside_transaction(): void
    {
        $reflection = new ReflectionClass(OrderService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'public function changeStatus(Order $order, OrderStatusRequest $request',
            $source,
            'OrderService::changeStatus signature must exist.'
        );

        // Locate the `} else {` branch comment marker that bounds the
        // POS-staff path (the `if ($auth)` self-cancel path is already
        // locked since iter13 P1 — we are guarding the second branch).
        $anchor = '[GOAL-K2-HEAL-02 2026-05-24 K.2 H5 P1]';
        $this->assertStringContainsString(
            $anchor,
            $source,
            'GOAL-K2-HEAL-02 comment marker must remain in OrderService::changeStatus else branch.'
        );

        $anchorStart = strpos($source, $anchor);
        $this->assertNotFalse($anchorStart);

        // Capture ~3000 chars of the else-branch body — long enough to
        // span the DB::transaction closure + lockForUpdate acquisition
        // + idempotent early-return + setRawAttributes sync, short
        // enough to exclude unrelated downstream methods.
        $body = substr($source, $anchorStart, 5000);

        $this->assertStringContainsString(
            'DB::transaction(',
            $body,
            'else branch must wrap mutate path in DB::transaction().'
        );

        $this->assertStringContainsString(
            '->lockForUpdate()',
            $body,
            'else branch must call ->lockForUpdate() to serialize concurrent POS Livré clicks.'
        );

        // Ordering: DB::transaction( must precede ->lockForUpdate() in
        // the source — i.e. the lock is acquired INSIDE the tx scope.
        $txIndex = strpos($body, 'DB::transaction(');
        $lockIndex = strpos($body, '->lockForUpdate()');
        $this->assertNotFalse($lockIndex);
        $this->assertNotFalse($txIndex);
        $this->assertLessThan(
            $lockIndex,
            $txIndex,
            'lockForUpdate() must be acquired INSIDE DB::transaction(), not before it.'
        );

        // The locked row must be assigned to a $locked variable + used
        // for status read (otherwise the race-fix is a placebo: reading
        // status from the route-bound model would still race).
        $this->assertMatchesRegularExpression(
            '/\$locked\s*=\s*Order::query\(\)->whereKey\(\$order->id\)->lockForUpdate\(\)->firstOrFail\(\)/',
            $body,
            'else branch must lock the row by primary key and assign to $locked.'
        );

        $this->assertMatchesRegularExpression(
            '/\(int\)\s*\$locked->status\s*===\s*\$toStatus/',
            $body,
            'Idempotent gate must read status from the LOCKED row, not the in-memory model.'
        );

        // Post-mutation sync: route-bound $order must be refreshed from
        // $locked so the post-tx SendOrderMail/Sms/Push +
        // OrderStatusChanged + OrderCanceled dispatches see fresh state.
        $this->assertMatchesRegularExpression(
            '/\$order->setRawAttributes\(\$locked->getAttributes\(\),\s*true\)/',
            $body,
            'else branch must sync $order from $locked via setRawAttributes(..., true) so post-tx broadcasts read the persisted state.'
        );
    }

    /**
     * Behavioural idempotency: when a second changeStatus(DELIVERED)
     * is invoked on an order already at DELIVERED, the locked re-read
     * must short-circuit via the idempotent gate. Exactly ONE
     * order_status_transitions row + ONE "Changement de statut"
     * action_log must exist after both calls.
     */
    public function test_concurrent_change_status_to_delivered_writes_exactly_one_transition_and_one_action_log(): void
    {
        $branch = Branch::factory()->create();
        $cashierRole = Role::findOrCreate('Cashier', 'web');

        $cashier1 = User::factory()->create([
            'branch_id' => $branch->id,
            'username' => 'cashier1_race',
        ]);
        $cashier1->assignRole($cashierRole);

        $cashier2 = User::factory()->create([
            'branch_id' => $branch->id,
            'username' => 'cashier2_race',
        ]);
        $cashier2->assignRole($cashierRole);

        $order = Order::factory()->create([
            'user_id' => $cashier1->id,
            'branch_id' => $branch->id,
            'queue_number' => 'Q-RACE-' . fake()->unique()->numberBetween(1, 9999),
            'status' => OrderStatus::PREPARED,
            'order_type' => 1,
            'total' => 12.50,
        ]);

        $service = app(OrderService::class);

        // CASHIER 1 clicks Livré — succeeds, writes 1 transition + 1
        // ActionLog, mutates row to DELIVERED.
        $this->actingAs($cashier1, 'web');
        $request1 = OrderStatusRequest::create('/', 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        $request1->setContainer($this->app)->setRedirector($this->app['redirect']);
        $service->changeStatus($order, $request1);

        $this->assertSame(
            1,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'pre-condition: first changeStatus must write exactly one transition row.'
        );

        $this->assertSame(
            1,
            ActionLog::query()
                ->where('action', 'Changement de statut')
                ->where('resource', 'Commande #' . $order->order_serial_no)
                ->count(),
            'pre-condition: first changeStatus must write exactly one ActionLog row.'
        );

        // CASHIER 2 clicks Livré on a STALE in-memory copy that still
        // believes the row is PREPARED. Without the lockForUpdate fix,
        // the idempotent gate would pass against the stale in-memory
        // status and the closure would proceed to write a duplicate
        // transition + ActionLog with cashier2's attribution. With the
        // fix, the locked re-read inside the tx sees DELIVERED and the
        // closure short-circuits via the idempotent gate.
        /** @var Order $stale */
        $stale = Order::find($order->id);
        $stale->status = OrderStatus::PREPARED; // simulate stale route-bound model
        $this->assertSame(
            OrderStatus::PREPARED,
            (int) $stale->status,
            'pre-condition: cashier2 must arrive with a stale in-memory status.'
        );

        $this->actingAs($cashier2, 'web');
        $request2 = OrderStatusRequest::create('/', 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        $request2->setContainer($this->app)->setRedirector($this->app['redirect']);
        $service->changeStatus($stale, $request2);

        // Persisted state must remain DELIVERED — no double write.
        $stale->refresh();
        $this->assertSame(
            OrderStatus::DELIVERED,
            (int) $stale->status,
            'After both calls, persisted status must be DELIVERED.'
        );

        // EXACTLY one transition row — proves the idempotent path was
        // taken on cashier2's call (the lockForUpdate fix).
        $this->assertSame(
            1,
            OrderStatusTransition::query()->where('order_id', $order->id)->count(),
            'Two concurrent / repeated changeStatus(DELIVERED) calls must produce exactly one order_status_transitions row.'
        );

        // EXACTLY one Changement-de-statut ActionLog — proves the
        // ambiguous "who delivered" attribution is impossible.
        $this->assertSame(
            1,
            ActionLog::query()
                ->where('action', 'Changement de statut')
                ->where('resource', 'Commande #' . $order->order_serial_no)
                ->count(),
            'Two concurrent / repeated changeStatus(DELIVERED) calls must produce exactly one ActionLog row.'
        );

        // The single audit row must reflect cashier1's transition (the
        // winner), and from_status must be the LOCKED PREPARED state.
        $row = OrderStatusTransition::query()->where('order_id', $order->id)->first();
        $this->assertSame(OrderStatus::PREPARED, (int) $row->from_status);
        $this->assertSame(OrderStatus::DELIVERED, (int) $row->to_status);
        $this->assertSame((int) $cashier1->id, (int) $row->actor_id);
    }
}
