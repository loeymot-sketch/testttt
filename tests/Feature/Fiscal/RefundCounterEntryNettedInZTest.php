<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [LOCK_ZREPORT_REFUND_NETTING / P0 #2 — 2026-05-29] NF525.
 *
 * The real refund flow (RefundWithCounterEntryService) creates a SEPARATE mirror
 * Order: status=RETURNED, total = -1×parent.total (pre-negated), parent_order_id
 * set, fiscal_sequence_no fresh, created_at = NOW (current window).
 *
 * That shape misses BOTH paths in ZReportService::aggregate:
 *  - the positive $orders loop excludes RETURNED;
 *  - the post-Z adjustment block requires created_at <= $from (the mirror is
 *    in-window, created_at > $from).
 * → the refund's negative reached the signed total_ttc NOWHERE → every post-Z
 * counter-entry refund overstated the signed daily Z by the refund amount.
 *
 * This test is the regression lock. It MUST fail before the fix (asserts the bug)
 * and pass after. It complements RefundPostZTest, which covers the OTHER shape
 * (status-flip-in-place, created_at in a prior window, NO parent_order_id).
 */
class RefundCounterEntryNettedInZTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_window_counter_entry_refund_mirror_nets_into_signed_z(): void
    {
        $branch = Branch::factory()->create();
        $from = Carbon::parse('2026-04-25 08:00:00');
        $to   = Carbon::parse('2026-04-25 20:00:00');

        // Parent sold + sealed in a PRIOR window (counted in that window's Z).
        $parent = Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 55.00,
            'subtotal'           => 55.00,
            'total_tax'          => 0,
            'fiscal_sequence_no' => 1,
            'created_at'         => $from->copy()->subDay(),
            'updated_at'         => $from->copy()->subDay(),
        ]);

        // Counter-entry refund MIRROR created IN the current window (faithful to
        // RefundWithCounterEntryService: pre-negated total, parent_order_id,
        // fresh seq, REFUNDED/RETURNED, created_at in (from, to]).
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'parent_order_id'    => $parent->id,
            'status'             => OrderStatus::RETURNED,
            'payment_status'     => PaymentStatus::REFUNDED,
            'total'              => -55.00,
            'subtotal'           => -55.00,
            'total_tax'          => 0,
            'fiscal_sequence_no' => 2,
            'created_at'         => $from->copy()->addHour(),
            'updated_at'         => $from->copy()->addHour(),
        ]);

        $aggregate = app(ZReportService::class)->aggregate($branch->id, $from, $to);

        // The refund must net into the signed total (was 0.00 pre-fix → bug).
        $this->assertEqualsWithDelta(
            -55.00,
            (float) $aggregate['total_ttc'],
            0.01,
            'In-window counter-entry refund mirror MUST net into the signed Z total_ttc (NF525).'
        );
        // It is a refund, not positive revenue.
        $this->assertSame(0, (int) $aggregate['order_count']);
        $this->assertSame(1, (int) $aggregate['refund_count']);
    }
}
