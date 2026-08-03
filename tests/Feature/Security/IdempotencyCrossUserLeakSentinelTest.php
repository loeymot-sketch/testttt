<?php

namespace Tests\Feature\Security;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * @see app/Services/OrderService.php :: findExistingOrderForIdempotencyRecovery
 * @see database/migrations/2026_05_24_023000_add_user_id_to_orders_idempotency_unique.php
 * @see CLAUDE.md §9 — IdempotencyKey scope MUST be (branch_id, user_id, hash(key))
 *
 * Phase H.1 P0 RED reproduced empirically (5/6 runs) a cross-user idempotency
 * leak: a second cashier retrying with the first cashier's X-Idempotency-Key
 * received the first cashier's order. Root cause was the app-layer
 * defense-in-depth backstop (DB UNIQUE on orders + recovery query) querying
 * only (branch_id, idempotency_key) when CLAUDE.md §9 mandates
 * (branch_id, user_id, hash(key)).
 *
 * Scope of THIS sentinel — honest framing:
 *   - `orders.user_id` semantically stores the CUSTOMER id (see line 699
 *     of posOrderStore: `'user_id' => $request->customer_id`).
 *   - Recovery discrimination is therefore CUSTOMER-level. Two different
 *     customers sharing a key on the same branch is closed (test #1, #2).
 *   - Anonymous walk-ins (customer = null/0) intentionally fall back to
 *     (branch, key) recovery to preserve legitimate retry rescue. Their
 *     cashier-level protection is the L7 IdempotencyKeyMiddleware (FROZEN).
 *   - H2-HEAL-02 introduces `creator_id` (cashier column) for a future
 *     cashier-level UNIQUE — out of scope for THIS heal.
 */
class IdempotencyCrossUserLeakSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_customer_recovery_does_not_leak_same_branch(): void
    {
        $branch    = Branch::factory()->create();
        $customerA = User::factory()->create(['branch_id' => $branch->id]);
        $customerB = User::factory()->create(['branch_id' => $branch->id]);

        // Customer A's order on branch with shared key.
        $orderA = Order::factory()->create([
            'branch_id'        => $branch->id,
            'user_id'          => $customerA->id,
            'order_type'       => OrderType::TAKEAWAY,
            'payment_status'   => PaymentStatus::UNPAID,
            'status'           => OrderStatus::PENDING,
            'idempotency_key'  => 'shared-cross-customer-key',
            'subtotal'         => 10,
            'total'            => 10,
            'discount'         => 0,
            'delivery_charge'  => 0,
            'order_datetime'   => now(),
        ]);

        $service    = app(OrderService::class);
        $reflection = new ReflectionMethod($service, 'findExistingOrderForIdempotencyRecovery');
        $reflection->setAccessible(true);

        // Customer A retries → finds OWN order.
        $hitOwn = $reflection->invoke($service, 'shared-cross-customer-key', $branch->id, $customerA->id);
        $this->assertNotNull($hitOwn, 'Customer A must recover their own order on retry.');
        $this->assertSame($orderA->id, $hitOwn->id);

        // Customer B retries with the SAME idempotency_key → MUST NOT see Customer A's order.
        $hitOther = $reflection->invoke($service, 'shared-cross-customer-key', $branch->id, $customerB->id);
        $this->assertNull(
            $hitOther,
            'Cross-customer idempotency leak: customer B must NOT receive customer A\'s order '
            . 'even when sharing the same X-Idempotency-Key on the same branch.'
        );
    }

    public function test_each_customer_finds_their_own_when_both_have_same_key(): void
    {
        // Edge case: both customers happen to have an order with the same key
        // on the same branch — each MUST resolve to THEIR OWN order.
        $branch    = Branch::factory()->create();
        $customerA = User::factory()->create(['branch_id' => $branch->id]);
        $customerB = User::factory()->create(['branch_id' => $branch->id]);

        $orderA = Order::factory()->create($this->makeAttrs($branch->id, $customerA->id, 'shared-key-both-have'));
        $orderB = Order::factory()->create($this->makeAttrs($branch->id, $customerB->id, 'shared-key-both-have'));

        $service    = app(OrderService::class);
        $reflection = new ReflectionMethod($service, 'findExistingOrderForIdempotencyRecovery');
        $reflection->setAccessible(true);

        $this->assertSame(
            $orderA->id,
            $reflection->invoke($service, 'shared-key-both-have', $branch->id, $customerA->id)?->id,
            'Customer A scoped recovery must return customer A\'s order, not B\'s.'
        );
        $this->assertSame(
            $orderB->id,
            $reflection->invoke($service, 'shared-key-both-have', $branch->id, $customerB->id)?->id,
            'Customer B scoped recovery must return customer B\'s order, not A\'s.'
        );
    }

    public function test_anonymous_walk_in_recovery_falls_back_to_branch_key_match(): void
    {
        // When the caller passes $userId = null (anonymous walk-in case, where
        // $request->customer_id is empty/0), the helper MUST skip the user_id
        // filter and behave like the legacy (branch_id, key) lookup. This
        // preserves legitimate retry rescue for anonymous orders — their
        // cashier-level protection is the L7 middleware.
        $branch    = Branch::factory()->create();
        $someUser  = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create($this->makeAttrs($branch->id, $someUser->id, 'anonymous-fallback-key'));

        $service    = app(OrderService::class);
        $reflection = new ReflectionMethod($service, 'findExistingOrderForIdempotencyRecovery');
        $reflection->setAccessible(true);

        $hit = $reflection->invoke($service, 'anonymous-fallback-key', $branch->id, null);
        $this->assertSame(
            $order->id,
            $hit?->id,
            'When $userId is null, recovery must fall back to (branch, key) match — anonymous retry rescue.'
        );
    }

    public function test_branch_isolation_still_holds_with_user_scope(): void
    {
        // Defense-in-depth: even with same customer and same key, recovery must
        // not bleed across branches.
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        $customer            = User::factory()->create(['branch_id' => $branchA->id]);

        $orderA = Order::factory()->create($this->makeAttrs($branchA->id, $customer->id, 'cross-branch-key'));

        $service    = app(OrderService::class);
        $reflection = new ReflectionMethod($service, 'findExistingOrderForIdempotencyRecovery');
        $reflection->setAccessible(true);

        $this->assertSame(
            $orderA->id,
            $reflection->invoke($service, 'cross-branch-key', $branchA->id, $customer->id)?->id,
        );
        $this->assertNull(
            $reflection->invoke($service, 'cross-branch-key', $branchB->id, $customer->id),
            'Branch isolation must hold even when customer_id matches.',
        );
    }

    public function test_db_unique_constraint_is_user_scoped(): void
    {
        // Inspect the live schema to confirm the migration applied and the
        // composite UNIQUE now spans (branch_id, user_id, idempotency_key).
        $driver  = Schema::getConnection()->getDriverName();
        $indexes = $this->orderIndexColumns($driver);

        $newIndex = 'orders_branch_user_idempotency_unique';
        $oldIndex = 'orders_branch_id_idempotency_key_unique';

        $this->assertArrayHasKey(
            $newIndex,
            $indexes,
            sprintf(
                'NEW composite UNIQUE %s must exist on orders. Run migrations. Indexes seen: %s',
                $newIndex,
                implode(', ', array_keys($indexes))
            )
        );

        $this->assertEqualsCanonicalizing(
            ['branch_id', 'user_id', 'idempotency_key'],
            $indexes[$newIndex],
            'NEW UNIQUE must span exactly (branch_id, user_id, idempotency_key).'
        );

        $this->assertArrayNotHasKey(
            $oldIndex,
            $indexes,
            sprintf('Legacy 2-column UNIQUE %s must be dropped.', $oldIndex)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAttrs(int $branchId, int $userId, string $key): array
    {
        return [
            'branch_id'        => $branchId,
            'user_id'          => $userId,
            'order_type'       => OrderType::TAKEAWAY,
            'payment_status'   => PaymentStatus::UNPAID,
            'status'           => OrderStatus::PENDING,
            'idempotency_key'  => $key,
            'subtotal'         => 10,
            'total'            => 10,
            'discount'         => 0,
            'delivery_charge'  => 0,
            'order_datetime'   => now(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function orderIndexColumns(string $driver): array
    {
        if ($driver === 'sqlite') {
            $definitions = [];
            $rows = DB::select("PRAGMA index_list('orders')");
            foreach ($rows as $row) {
                if ((int) ($row->unique ?? 0) !== 1) {
                    continue;
                }
                $cols = DB::select("PRAGMA index_info('" . $row->name . "')");
                $definitions[$row->name] = array_values(array_map(
                    static fn ($c): string => (string) $c->name,
                    $cols
                ));
            }

            return $definitions;
        }

        $rows = DB::select('SHOW INDEX FROM `orders`');
        $defs = [];
        foreach ($rows as $row) {
            if ((int) ($row->Non_unique ?? 1) !== 0) {
                continue;
            }
            $name           = (string) $row->Key_name;
            $defs[$name]    ??= [];
            $defs[$name][((int) $row->Seq_in_index) - 1] = (string) $row->Column_name;
        }
        foreach ($defs as $n => $cols) {
            ksort($cols);
            $defs[$n] = array_values($cols);
        }

        return $defs;
    }
}
