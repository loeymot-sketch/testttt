<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [H2-HEAL-01 / H.1 P0 RED 2026-05-24] Updated to the 3-column UNIQUE
 * (branch_id, user_id, idempotency_key) introduced by migration
 * 2026_05_24_023000_add_user_id_to_orders_idempotency_unique.
 *
 * Original 2-column (branch_id, idempotency_key) UNIQUE was retired
 * because it leaked across customers (Phase H.1 P0 RED proved
 * empirically). Tests below realign to the new contract:
 *   - same (branch, user, key) duplicate → rejected by UNIQUE
 *   - same key, different branch → distinct rows OK
 *   - same key, same branch, different user → distinct rows OK (new
 *     behavior, exercised in tests/Feature/Security/
 *     IdempotencyCrossUserLeakSentinelTest)
 */
class IdempotencyBranchScopedTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_different_branches_ok(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        Order::factory()->create($this->orderAttributes($branchA->id, 'shared-key'));
        Order::factory()->create($this->orderAttributes($branchB->id, 'shared-key'));

        $this->assertSame(2, Order::withoutGlobalScopes()->where('idempotency_key', 'shared-key')->count());
        $this->assertOrdersIdempotencyIndexIsBranchUserScoped();
    }

    public function test_same_key_same_branch_same_user_duplicate_rejected(): void
    {
        // Updated for H2-HEAL-01: the rejection key is now the 3-tuple
        // (branch, user, key). Reuse ONE user across both creates so the
        // UNIQUE constraint actually fires (previously each Order::factory
        // call minted a new user via orderAttributes which would now be
        // legitimate under the new 3-column UNIQUE).
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $attrs  = $this->orderAttributes($branch->id, 'same-branch-user-key', $user->id);

        Order::factory()->create($attrs);

        $this->expectException(QueryException::class);

        try {
            Order::factory()->create($attrs);
        } finally {
            $this->assertSame(
                1,
                Order::withoutGlobalScopes()->where('idempotency_key', 'same-branch-user-key')->count()
            );
            $this->assertOrdersIdempotencyIndexIsBranchUserScoped();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAttributes(int $branchId, string $idempotencyKey, ?int $userId = null): array
    {
        return [
            'branch_id' => $branchId,
            'user_id' => $userId ?? User::factory()->create(['branch_id' => $branchId])->id,
            'order_type' => OrderType::TAKEAWAY,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function assertOrdersIdempotencyIndexIsBranchUserScoped(): void
    {
        $indexes = $this->indexNames('orders');

        $this->assertContains('orders_branch_user_idempotency_unique', $indexes);
        $this->assertSame(
            ['branch_id', 'user_id', 'idempotency_key'],
            $this->indexColumns('orders', 'orders_branch_user_idempotency_unique')
        );
        // Legacy 2-column UNIQUE must be gone (replaced by the 3-column one).
        $this->assertNotContains('orders_branch_id_idempotency_key_unique', $indexes);
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('" . $table . "')");

            return array_values(array_map(
                static fn ($row): string => (string) $row->name,
                $rows
            ));
        }

        $rows = DB::select('SHOW INDEX FROM `' . $table . '`');

        return array_values(array_unique(array_map(
            static fn ($row): string => (string) $row->Key_name,
            $rows
        )));
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $index): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_info('" . $index . "')");

            return array_values(array_map(
                static fn ($row): string => (string) $row->name,
                $rows
            ));
        }

        $rows = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]);
        usort($rows, static fn ($left, $right): int => ((int) $left->Seq_in_index) <=> ((int) $right->Seq_in_index));

        return array_values(array_map(
            static fn ($row): string => (string) $row->Column_name,
            $rows
        ));
    }
}
