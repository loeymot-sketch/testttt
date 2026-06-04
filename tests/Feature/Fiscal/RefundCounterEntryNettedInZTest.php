<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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

    /**
     * [advisor 2026-05-29] Integration test through the REAL
     * RefundWithCounterEntryService — NOT a hand-modeled mirror. This closes the
     * exact gap that fooled 3 fixes this session (a synthetic test passing while
     * the real path differed). For signed NF525 code, the real refund flow must
     * demonstrably net into the Z.
     */
    public function test_real_refund_service_mirror_nets_into_signed_z(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        // Window 1 — sealed (closed Z). Parent sold + sealed inside it.
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 50.00,
            'total'          => 50.00,
            'total_tax'      => 0,
            'created_at'     => $opened->copy()->addHours(2),
        ]);
        $parent->fiscal_sequence_no = 10;
        $parent->save();

        // The REAL service mints the mirror (created_at = now, pre-negated total,
        // parent_order_id, RETURNED, fresh seq). Service rejects pre-Z parents, so
        // the mirror is ALWAYS cross-window — no same-window double-handling.
        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'integration refund net check');

        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);
        $this->assertNotNull($mirror->parent_order_id);
        // The real service must NOT mutate the parent (immutable per its contract).
        $this->assertSame(OrderStatus::ACCEPT, (int) $parent->fresh()->status);

        // Aggregate window 2: from window-1 close to just after the mirror's
        // created_at (now). Captures the mirror, excludes the backdated parent.
        $from = $closed;
        $to = Carbon::now()->addMinute();
        $aggregate = app(ZReportService::class)->aggregate($branch->id, $from, $to);

        $this->assertEqualsWithDelta(
            -50.00,
            (float) $aggregate['total_ttc'],
            0.01,
            'The REAL counter-entry refund mirror MUST net into the signed Z total_ttc (NF525).'
        );
        $this->assertSame(0, (int) $aggregate['order_count']);
        $this->assertSame(1, (int) $aggregate['refund_count']);
    }
}
