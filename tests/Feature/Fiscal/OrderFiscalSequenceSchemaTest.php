<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [POS-9.4.1 / POS-GA-F-38]
 *
 * Locks the shape of the fiscal sequence column introduced for NF525:
 *  - column exists on `orders`;
 *  - duplicates of `(branch_id, fiscal_sequence_no)` are rejected by DB.
 *
 * The sequence allocation (no-gap, atomic) is tested in FiscalSequenceTest.
 */
class OrderFiscalSequenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_sequence_column_exists_on_orders(): void
    {
        $this->assertTrue(
            Schema::hasColumn('orders', 'fiscal_sequence_no'),
            'orders.fiscal_sequence_no is required by NF525 (POS-9.4.1).'
        );
    }

    public function test_unique_branch_fiscal_sequence_is_enforced(): void
    {
        $branch = Branch::factory()->create();

        $first = Order::factory()->create([
            'branch_id'          => $branch->id,
            'fiscal_sequence_no' => 1,
        ]);
        $this->assertDatabaseHas('orders', [
            'id'                 => $first->id,
            'fiscal_sequence_no' => 1,
        ]);

        $this->expectException(QueryException::class);
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'fiscal_sequence_no' => 1,
        ]);
    }

    public function test_same_sequence_allowed_on_different_branches(): void
    {
        $a = Branch::factory()->create();
        $b = Branch::factory()->create();

        Order::factory()->create(['branch_id' => $a->id, 'fiscal_sequence_no' => 7]);
        Order::factory()->create(['branch_id' => $b->id, 'fiscal_sequence_no' => 7]);

        $this->assertSame(
            1,
            Order::query()->where('branch_id', $a->id)->where('fiscal_sequence_no', 7)->count()
        );
        $this->assertSame(
            1,
            Order::query()->where('branch_id', $b->id)->where('fiscal_sequence_no', 7)->count()
        );
    }
}
