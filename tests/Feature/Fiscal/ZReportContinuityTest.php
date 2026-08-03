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
 * [C33 / LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — 2026-07-07] NF525 continuous partition.
 *
 * Before this change close() aggregated the half-open window (opened_at, closed_at].
 * A sale created in the "dead window" between the PREVIOUS Z close and the NEXT Z open
 * (e.g. a delivery/Uber order taken while no Z was open, or a cashier who forgot to
 * open the day) fell into NO signed Z → a numbered receipt in zero Z = an NF525
 * gap-free violation (surfaced by fiscal:verify-z-membership as "TROU").
 *
 * C33 fix: the aggregation lower bound is the closed_at of the PREVIOUS closed Z (null
 * for the first Z ever), so the partition is CONTINUOUS: (closed_{n-1}, closed_n]. Every
 * euro lands in exactly one Z, no gap, no double. This mirrors XReportService::defaultFrom
 * so the intraday-X window and the close-Z window are identical.
 */
class ZReportContinuityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-continuity-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-continuity-z');
    }

    private function paidSale(int $branchId, int $seq, float $total, Carbon $createdAt): Order
    {
        return Order::factory()->create([
            'branch_id'          => $branchId,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'total'              => $total,
            'subtotal'           => $total,
            'discount'           => 0,
            'total_tax'          => 0,
            'fiscal_sequence_no' => $seq,
            'created_at'         => $createdAt,
            'updated_at'         => $createdAt,
        ]);
    }

    /**
     * A sale created strictly between close(Zn) and open(Zn+1) MUST be aggregated by
     * Zn+1 (continuous partition), NOT lost in a dead window.
     */
    public function test_dead_window_order_between_close_and_next_open_lands_in_next_z(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(ZReportService::class);

        // ── Z1 : open 08:00, sale A at 09:00 (20,00), close 10:00 ──────────────
        Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00'));
        $svc->open($branch->id);
        $this->paidSale($branch->id, 1, 20.00, Carbon::parse('2026-06-01 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        $z1 = $svc->close($branch->id);

        // ── DEAD WINDOW : sale B at 11:00 (30,00) — after Z1 close, before Z2 open ─
        $this->paidSale($branch->id, 2, 30.00, Carbon::parse('2026-06-01 11:00:00'));

        // ── Z2 : open 12:00, sale C at 13:00 (40,00), close 14:00 ──────────────
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));
        $svc->open($branch->id);
        $this->paidSale($branch->id, 3, 40.00, Carbon::parse('2026-06-01 13:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-01 14:00:00'));
        $z2 = $svc->close($branch->id);
        Carbon::setTestNow(null);

        // Z1 counts only sale A.
        $this->assertSame(1, (int) $z1->order_count, 'Z1 counts only its in-window sale A.');
        $this->assertEqualsWithDelta(20.00, (float) $z1->total_ttc, 0.01);

        // Z2 counts the DEAD-WINDOW sale B (30,00) + in-window sale C (40,00).
        $this->assertSame(2, (int) $z2->order_count,
            'Dead-window sale B (created between Z1 close and Z2 open) MUST land in Z2 (C33).');
        $this->assertEqualsWithDelta(70.00, (float) $z2->total_ttc, 0.01,
            'Z2 total must include the dead-window sale B (30,00) + in-window sale C (40,00).');

        // Partition: Z1 + Z2 == ALL sales, exactly once — no gap (orphan), no double.
        $this->assertEqualsWithDelta(
            90.00,
            (float) $z1->total_ttc + (float) $z2->total_ttc,
            0.01,
            'Z1 + Z2 must partition every sale exactly once (no dead-window orphan, no double-count).'
        );
    }

    /**
     * The FIRST Z ever for a branch has a null lower bound → it absorbs the whole
     * history up to its close, including sales made before it was opened (otherwise
     * they would be orphans in no Z at all).
     */
    public function test_first_z_absorbs_sales_made_before_it_was_opened(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(ZReportService::class);

        // Sale made BEFORE any Z is opened (07:00).
        $this->paidSale($branch->id, 1, 15.00, Carbon::parse('2026-06-02 07:00:00'));

        Carbon::setTestNow(Carbon::parse('2026-06-02 08:00:00'));
        $svc->open($branch->id);
        $this->paidSale($branch->id, 2, 25.00, Carbon::parse('2026-06-02 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-02 10:00:00'));
        $z1 = $svc->close($branch->id);
        Carbon::setTestNow(null);

        $this->assertSame(2, (int) $z1->order_count,
            'First Z (null lower bound) must absorb the pre-open sale — no orphan.');
        $this->assertEqualsWithDelta(40.00, (float) $z1->total_ttc, 0.01);
    }

    /**
     * Post-Z terminal adjustment coherence under the new lower bound: an order counted
     * positively in Z1 that is CANCELED after Z1 closes must be negatively adjusted in
     * Z2 — exactly once — even though Z2's lower bound is now Z1.closed_at (not Z2.opened_at).
     */
    public function test_post_z_cancel_nets_once_under_previous_close_bound(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(ZReportService::class);

        Carbon::setTestNow(Carbon::parse('2026-06-03 08:00:00'));
        $svc->open($branch->id);
        $order = $this->paidSale($branch->id, 1, 50.00, Carbon::parse('2026-06-03 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-03 10:00:00'));
        $z1 = $svc->close($branch->id);

        // Counted in Z1.
        $this->assertSame(1, (int) $z1->order_count);
        $this->assertEqualsWithDelta(50.00, (float) $z1->total_ttc, 0.01);

        // Cancel the order AFTER Z1 closed (updated_at in Z2 window).
        Carbon::setTestNow(Carbon::parse('2026-06-03 11:00:00'));
        $order->forceFill(['status' => OrderStatus::CANCELED])->saveQuietly();

        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00'));
        $svc->open($branch->id);
        Carbon::setTestNow(Carbon::parse('2026-06-03 13:00:00'));
        $z2 = $svc->close($branch->id);
        Carbon::setTestNow(null);

        // Z2 nets the cancellation exactly once (-50,00) — created_at (09:00) <= Z1.closed_at
        // (10:00 = Z2 lower bound), updated_at (11:00) in (10:00, 13:00].
        $this->assertEqualsWithDelta(-50.00, (float) $z2->total_ttc, 0.01,
            'Post-Z cancellation must net once in Z2 under the previous-close lower bound.');
        $this->assertSame(1, (int) $z2->cancel_count);
    }
}
