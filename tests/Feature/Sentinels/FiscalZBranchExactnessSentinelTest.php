<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-010/FK-062 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M08-FISCAL-Z-NF525 | @reason Z aggregation for one branch must not include paid orders from another branch.
 */
class FiscalZBranchExactnessSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_z_report_aggregate_counts_only_requested_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        Order::factory()->create([
            'branch_id' => $branchA->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 100.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 1,
            'created_at' => now(),
        ]);

        Order::factory()->create([
            'branch_id' => $branchB->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 900.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 1,
            'created_at' => now(),
        ]);

        $aggregate = app(ZReportService::class)->aggregate($branchA->id, now()->subHour(), now()->addHour());

        $this->assertSame(1, (int) $aggregate['order_count']);
        $this->assertEqualsWithDelta(100.00, (float) $aggregate['total_ttc'], 0.01);
    }
}
