<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * FISC-EXH-01 / NF-1 late-salvage (GOAL 2.0 Phase 2 — frozen ZReportService edit under
 * LOCK_FISC-EXH-01_ZREPORT_LATE_SALVAGE_2026-06-15).
 *
 * A realized PAID sale whose fiscal_sequence_no was allocated AFTER its created_at
 * window's Z closed (e.g. a COD seq allocated by the retry-cron post-midnight) would
 * otherwise appear in NO Z. The late-salvage block sweeps it into the window in which it
 * was actually allocated (keyed on fiscal_seq_allocated_at), exactly once — without
 * double-counting normal in-window or already-allocated rows.
 */
class ZReportLateSalvageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret', 'unit-test-secret-ls-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-ls-z');
    }

    private function paidOrder(int $branchId, float $total, int $seq, Carbon $createdAt, Carbon $allocatedAt): void
    {
        Order::factory()->create([
            'branch_id'               => $branchId,
            'payment_status'          => PaymentStatus::PAID,
            'status'                  => OrderStatus::DELIVERED,
            'total'                   => $total,
            'fiscal_sequence_no'      => $seq,
            'created_at'              => $createdAt,
            'fiscal_seq_allocated_at' => $allocatedAt, // explicit → saving-hook keeps it
        ]);
    }

    public function test_late_allocated_sequence_lands_in_the_current_z_exactly_once(): void
    {
        $branch = Branch::factory()->create();
        $boundary   = Carbon::parse('2026-04-22 23:59:59'); // window-1 close == window-2 open ($from)
        $window2End = Carbon::parse('2026-04-23 23:59:59');

        // Placed in window 1 (created_at before boundary) but seq allocated post-midnight
        // (window 2). When window 1 closed its seq was NULL → excluded there; late-salvage
        // must sweep it into window 2.
        $this->paidOrder($branch->id, 50.00, 100, $boundary->copy()->subHours(3), $boundary->copy()->addMinutes(2));
        // A normal window-2 sale (created + allocated in window 2).
        $this->paidOrder($branch->id, 30.00, 101, $boundary->copy()->addHour(), $boundary->copy()->addHour());

        $totals = app(ZReportService::class)->aggregate($branch->id, $boundary, $window2End);

        $this->assertSame(2, $totals['order_count'], 'late-salvaged + normal row both counted in this Z, once each');
        $this->assertEqualsWithDelta(80.00, (float) $totals['total_ttc'], 0.01, 'total = 50 (late) + 30 (normal)');
    }

    public function test_in_window_allocation_is_not_late_salvaged_into_next_z(): void
    {
        $branch = Branch::factory()->create();
        $boundary   = Carbon::parse('2026-04-22 23:59:59');
        $window2End = Carbon::parse('2026-04-23 23:59:59');

        // Placed AND allocated in window 1 (both before boundary) — already in window 1's Z.
        // allocated_at <= $from must exclude it from window 2's late-salvage (no double-count).
        $this->paidOrder($branch->id, 99.00, 200, $boundary->copy()->subHours(3), $boundary->copy()->subHours(3));

        $totals = app(ZReportService::class)->aggregate($branch->id, $boundary, $window2End);

        $this->assertSame(0, $totals['order_count'], 'a window-1 allocation must NOT be swept into window 2');
        $this->assertEqualsWithDelta(0.00, (float) $totals['total_ttc'], 0.01);
    }

    public function test_first_z_null_from_has_no_late_salvage(): void
    {
        $branch = Branch::factory()->create();
        $to = Carbon::parse('2026-04-22 23:59:59');

        // On the first-ever Z ($from null) there is no prior window — a normal in-history
        // row is counted by $orders; late-salvage is inert (gated on $from).
        $this->paidOrder($branch->id, 12.00, 300, $to->copy()->subDays(2), $to->copy()->subDays(2));

        $totals = app(ZReportService::class)->aggregate($branch->id, null, $to);

        $this->assertSame(1, $totals['order_count']);
        $this->assertEqualsWithDelta(12.00, (float) $totals['total_ttc'], 0.01);
    }
}
