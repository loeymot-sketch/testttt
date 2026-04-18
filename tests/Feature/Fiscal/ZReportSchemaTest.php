<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\ZReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [POS-9.4.6 / POS-GA-F-01]
 *
 * Locks the `z_reports` schema:
 *  - table present with the required shape;
 *  - `(branch_id, sequence_no)` is unique (NF525 — no duplicate number);
 *  - JSON aggregates round-trip through casts.
 */
class ZReportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('z_reports'));

        foreach ([
            'id', 'branch_id', 'sequence_no',
            'opened_at', 'closed_at', 'opened_by', 'closed_by',
            'total_ht', 'total_ttc', 'total_tva',
            'total_by_method', 'total_by_tax_rate',
            'order_count', 'cancel_count', 'refund_count',
            'prev_hash', 'signature', 'status', 'archived_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('z_reports', $col),
                "z_reports is missing required column '{$col}'."
            );
        }
    }

    public function test_branch_sequence_unique(): void
    {
        $branch = Branch::factory()->create();

        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => Carbon::now(),
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $this->expectException(QueryException::class);
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => Carbon::now(),
            'status'      => ZReport::STATUS_OPEN,
        ]);
    }

    public function test_aggregates_round_trip_through_json_casts(): void
    {
        $branch = Branch::factory()->create();

        $row = ZReport::create([
            'branch_id'         => $branch->id,
            'sequence_no'       => 1,
            'opened_at'         => Carbon::now(),
            'status'            => ZReport::STATUS_OPEN,
            'total_by_method'   => ['cash' => 42.50, 'card' => 17.25],
            'total_by_tax_rate' => ['10' => 5.00, '20' => 3.00],
        ]);

        $fresh = ZReport::find($row->id);
        $this->assertSame(42.50, $fresh->total_by_method['cash']);
        $this->assertSame(17.25, $fresh->total_by_method['card']);
        // JSON integer → PHP int after a round-trip; the point of the test
        // is that the key survives and the value is numeric, not its PHP type.
        $this->assertEqualsWithDelta(5.00, (float) $fresh->total_by_tax_rate['10'], 0.0001);
    }
}
