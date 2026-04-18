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
        $this->assertOrdersIdempotencyIndexIsBranchScoped();
    }

    public function test_same_key_same_branch_duplicate_rejected(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create($this->orderAttributes($branch->id, 'same-branch-key'));

        $this->expectException(QueryException::class);

        try {
            Order::factory()->create($this->orderAttributes($branch->id, 'same-branch-key'));
        } finally {
            $this->assertSame(1, Order::withoutGlobalScopes()->where('idempotency_key', 'same-branch-key')->count());
            $this->assertOrdersIdempotencyIndexIsBranchScoped();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAttributes(int $branchId, string $idempotencyKey): array
    {
        return [
            'branch_id' => $branchId,
            'user_id' => User::factory()->create(['branch_id' => $branchId])->id,
            'order_type' => OrderType::TAKEAWAY,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function assertOrdersIdempotencyIndexIsBranchScoped(): void
    {
        $indexes = $this->indexNames('orders');

        $this->assertContains('orders_branch_id_idempotency_key_unique', $indexes);
        $this->assertSame(
            ['branch_id', 'idempotency_key'],
            $this->indexColumns('orders', 'orders_branch_id_idempotency_key_unique')
        );
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
