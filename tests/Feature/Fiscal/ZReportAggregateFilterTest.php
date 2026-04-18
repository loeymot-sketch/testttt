<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use App\Services\Fiscal\XReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [POS-9-H.2.4 / F-C4] + [POS-9-H.2.5 / F-B5]
 *
 * ZReportService::aggregate() and XReportService::snapshot() must
 * aggregate ONLY orders that received a fiscal_sequence_no. Two
 * failure modes this closes:
 *
 * 1. Legacy orders created before POS-9.4 (no fiscal_sequence_no) used
 *    to leak into the first Z report after the migration, producing
 *    aggregates that could not be traced back to a sequential
 *    receipt — a hard NF525 violation.
 *
 * 2. Soft-deleted orders used to leak in because the previous
 *    `withoutGlobalScopes()` dropped BOTH BranchScope AND the Eloquent
 *    SoftDeletingScope. `withoutGlobalScope(BranchScope::class)` now
 *    keeps soft-delete exclusion.
 */
class ZReportAggregateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-aggregate-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-aggregate-z');
    }

    public function test_orders_without_fiscal_sequence_are_excluded(): void
    {
        $branch = Branch::factory()->create();

        $legacy = Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 99.99,
            'total_tax'          => 9.99,
            'fiscal_sequence_no' => null,            // legacy, pre-POS-9.4
        ]);

        $current = Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 42.00,
            'total_tax'          => 4.20,
            'fiscal_sequence_no' => 1,               // post-POS-9.4
        ]);

        $totals = app(ZReportService::class)->aggregate(
            $branch->id,
            now()->subDay(),
            now()->addMinute(),
        );

        $this->assertSame(1, $totals['order_count'],
            'Only the order with fiscal_sequence_no should be counted.');
        $this->assertEqualsWithDelta(42.00, (float) $totals['total_ttc'], 0.01);
    }

    public function test_soft_deleted_orders_are_excluded(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 10.00,
            'fiscal_sequence_no' => 1,
        ]);

        $soft = Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 999.00,
            'fiscal_sequence_no' => 2,
        ]);
        $soft->delete();                            // soft-delete

        $totals = app(ZReportService::class)->aggregate(
            $branch->id,
            now()->subDay(),
            now()->addMinute(),
        );

        $this->assertSame(1, $totals['order_count']);
        $this->assertEqualsWithDelta(10.00, (float) $totals['total_ttc'], 0.01);
    }

    public function test_x_report_inherits_same_filter(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 50.00,
            'fiscal_sequence_no' => null,           // excluded
        ]);
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 25.00,
            'fiscal_sequence_no' => 3,              // counted
        ]);

        $snap = app(XReportService::class)->snapshot($branch->id, now()->subDay(), now()->addMinute());
        $this->assertSame(1, $snap['totals']['order_count']);
        $this->assertEqualsWithDelta(25.00, (float) $snap['totals']['total_ttc'], 0.01);
    }
}
