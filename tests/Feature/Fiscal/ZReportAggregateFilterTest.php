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
 * [POS-9-H.2.4 / F-C4] + [POS-9-H.2.5 / F-B5] + [P0-FIX-1/2 iter15 G0-A]
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
 * 2. [iter15 owner G0-A — Option A] Post-allocation soft-deleted
 *    orders MUST be aggregated. Earlier behaviour (POS-9-H.2.5)
 *    excluded them via the SoftDeletingScope, which broke the
 *    "every fiscally sequenced receipt appears in exactly one Z"
 *    NF525 invariant once iter6 Q2=B introduced the archive-then-
 *    delete migration workflow. ZReportService now uses
 *    `withTrashed()` so soft-delete is no longer a fiscal exclusion
 *    criterion ; logical exclusion stays handled by terminal
 *    statuses (CANCELED/REJECTED/RETURNED).
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

    /**
     * [P0-FIX-1/2 iter15 owner G0-A — Option A]
     *
     * Post-allocation soft-deleted orders MUST appear in Z totals.
     *
     * Previous expectation (pre-iter15): soft-deletes were excluded
     * to avoid double-counting cancel-then-restore flows. That logic
     * was unsafe once iter6 Q2=B introduced archive-then-delete
     * recoverable migrations: a fiscally sequenced order that is
     * soft-deleted post-allocation would silently disappear from its
     * Z aggregate, leaving a gap in the NF525 sequential trail.
     *
     * New contract:
     *   - withTrashed() pulls every fiscally sequenced order
     *     regardless of soft-delete state.
     *   - terminal-status whitelist (CANCELED/REJECTED/RETURNED)
     *     remains the only logical exclusion gate. Soft-deleting an
     *     order without flipping its status is now treated as
     *     archival, NOT cancellation.
     */
    public function test_soft_deleted_post_allocation_orders_are_counted(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 10.00,
            'fiscal_sequence_no' => 1,
        ]);

        // Soft-delete AFTER fiscal sequence allocation: must still
        // appear in the Z aggregate per NF525 fiscal continuity.
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

        $this->assertSame(
            2,
            $totals['order_count'],
            'Soft-deleted post-allocation orders must be counted (NF525 fiscal continuity).'
        );
        $this->assertEqualsWithDelta(
            1009.00,
            (float) $totals['total_ttc'],
            0.01,
            'Z total_ttc must include soft-deleted post-allocation revenue.'
        );
    }

    /**
     * Companion test: terminal-status orders (CANCELED/REJECTED/RETURNED)
     * stay excluded even when withTrashed() now pulls them through the
     * base query. This guards the regression "soft-delete fix accidentally
     * lets cancelled rows into Z totals".
     */
    public function test_terminal_status_orders_remain_excluded_even_when_soft_deleted(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 10.00,
            'fiscal_sequence_no' => 10,
        ]);

        $cancelled = Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::CANCELED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 500.00,
            'fiscal_sequence_no' => 11,
        ]);
        $cancelled->delete();                       // soft-delete on top of CANCELED

        $totals = app(ZReportService::class)->aggregate(
            $branch->id,
            now()->subDay(),
            now()->addMinute(),
        );

        $this->assertSame(
            1,
            $totals['order_count'],
            'CANCELED/REJECTED/RETURNED orders must stay out of Z totals — soft-delete state irrelevant.'
        );
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
