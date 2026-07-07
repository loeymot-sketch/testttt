<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [P1 deferred-order Z-membership — LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT 2026-07-07]
 *
 * A DEFERRED-payment order (COUNTER_DEFERRED: kiosk Plan B, walk-in, phone)
 * receives its fiscal_sequence_no at ENCAISSEMENT, not at creation. Before this
 * fix ZReportService::aggregate() partitioned Z-membership by created_at, so an
 * order created inside Z_n's window but settled after Z_{n+1} opened fell into
 * NEITHER signed Z (fiscal NULL at Z_n close → excluded; created_at <= from at
 * Z_{n+1} → excluded) = a numbered receipt outside every Z = a silent NF525
 * gap-free violation.
 *
 * FIX: PaymentService::confirmCounterPayment stamps fiscal_dated_at = now() when
 * it allocates the sequence, and aggregate() keys the Z window on
 * COALESCE(fiscal_dated_at, created_at). The order is now sealed in the Z open at
 * encaissement time — exactly once, no gap, no double.
 */
class ZReportDeferredMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-deferred-membership-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-deferred-membership-z');
        // Treat all test Z as POST-C33 so the honest detector uses the fiscal-date
        // window (the semantics under test). Cutover far in the past.
        Config::set('fiscal.c33_cutover_at', '2020-01-01 00:00:00');
    }

    private function deferredOrder(int $branchId, float $total, Carbon $createdAt): Order
    {
        return Order::factory()->create([
            'branch_id'          => $branchId,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status'             => OrderStatus::PREPARING,
            'source_surface'     => 'pos',
            'total'              => $total,
            'subtotal'           => $total,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'fiscal_sequence_no' => null,
            'fiscal_dated_at'    => null,
            'created_at'         => $createdAt,
            'updated_at'         => $createdAt,
        ]);
    }

    /**
     * TDD (a) — the canonical bug. A deferred order CREATED in Z1's window but
     * ENCAISSÉE (fiscal allocated) after Z2 opened must be sealed in Z2 via
     * fiscal_dated_at — not lost in the gap between the two Z, not double-counted.
     */
    public function test_deferred_order_created_in_z1_but_collected_in_z2_lands_in_z2_only(): void
    {
        Event::fake([OrderPaidAtCounter::class, OrderStatusChanged::class]);

        $branch = Branch::factory()->create();
        $svc     = app(ZReportService::class);
        $payment = app(PaymentService::class);

        // ── Z1 : open 08:00, deferred order created 09:00 (still UNPAID/no fiscal), close 10:00 ──
        Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00'));
        $svc->open($branch->id);
        $order = $this->deferredOrder($branch->id, 30.00, Carbon::parse('2026-06-01 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        $z1 = $svc->close($branch->id);

        // Z1 sealed NOTHING (the deferred order had no fiscal seq yet).
        $this->assertSame(0, (int) $z1->order_count, 'Z1 must not count the still-deferred order (fiscal NULL).');
        $this->assertEqualsWithDelta(0.00, (float) $z1->total_ttc, 0.01);

        // ── Z2 : open 12:00, ENCAISSEMENT at 13:00 (allocates fiscal + stamps fiscal_dated_at), close 14:00 ──
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));
        $svc->open($branch->id);

        Carbon::setTestNow(Carbon::parse('2026-06-01 13:00:00'));
        $payment->confirmCounterPayment($order, PosPaymentMethod::CASH, 30.00);

        Carbon::setTestNow(Carbon::parse('2026-06-01 14:00:00'));
        $z2 = $svc->close($branch->id);
        Carbon::setTestNow(null);

        // The stamp itself (proves PaymentService change).
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->fiscal_sequence_no, 'encaissement must allocate the fiscal seq');
        $this->assertNotNull($fresh->fiscal_dated_at, 'encaissement must stamp fiscal_dated_at');
        $this->assertSame('2026-06-01 13:00:00', $fresh->fiscal_dated_at->format('Y-m-d H:i:s'),
            'fiscal_dated_at must be the encaissement instant (13:00), not created_at (09:00).');

        // Z2 seals the deferred order exactly once (via fiscal_dated_at ∈ (10:00, 14:00]).
        $this->assertSame(1, (int) $z2->order_count, 'Z2 must seal the deferred order via fiscal_dated_at.');
        $this->assertEqualsWithDelta(30.00, (float) $z2->total_ttc, 0.01);

        // Partition: the order is in EXACTLY one signed Z (Z2), never in Z1, never doubled.
        $this->assertEqualsWithDelta(30.00, (float) $z1->total_ttc + (float) $z2->total_ttc, 0.01,
            'The deferred sale must appear in exactly one Z (no gap, no double-count).');

        // And the honest detector agrees: no orphan.
        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    /**
     * TDD (c) — a NON-deferred order (fiscal_dated_at NULL) is unchanged: it is
     * keyed by created_at. This proves the COALESCE fallback keeps legacy
     * behaviour byte-for-byte AND that a stamped fiscal_dated_at OUTSIDE the
     * window overrides an in-window created_at (the discriminating property).
     */
    public function test_non_deferred_order_keyed_by_created_at_unchanged_and_fiscal_date_overrides(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(ZReportService::class);

        Carbon::setTestNow(Carbon::parse('2026-06-05 08:00:00'));
        $svc->open($branch->id);

        // A: normal order, fiscal_dated_at NULL, created 09:00 (in window) → counted by created_at.
        Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::DELIVERED, 'total' => 20.00, 'subtotal' => 20.00,
            'discount' => 0, 'total_tax' => 0, 'fiscal_sequence_no' => 1,
            'fiscal_dated_at' => null,
            'created_at' => Carbon::parse('2026-06-05 09:00:00'),
            'updated_at' => Carbon::parse('2026-06-05 09:00:00'),
        ]);

        // B: created 09:30 (in window) BUT fiscal_dated_at 20:00 (out of window) → must NOT be counted here.
        Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::DELIVERED, 'total' => 99.00, 'subtotal' => 99.00,
            'discount' => 0, 'total_tax' => 0, 'fiscal_sequence_no' => 2,
            'fiscal_dated_at' => Carbon::parse('2026-06-05 20:00:00'),
            'created_at' => Carbon::parse('2026-06-05 09:30:00'),
            'updated_at' => Carbon::parse('2026-06-05 09:30:00'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-05 10:00:00'));
        $z1 = $svc->close($branch->id);
        Carbon::setTestNow(null);

        // Only A (created_at-keyed, in window). B's fiscal_dated_at (20:00) is out of (−∞,10:00].
        $this->assertSame(1, (int) $z1->order_count,
            'Order A (fiscal_dated_at NULL, created in window) counted; order B (fiscal_dated_at out of window) excluded.');
        $this->assertEqualsWithDelta(20.00, (float) $z1->total_ttc, 0.01);
    }

    /**
     * TDD (d) — P3 tie-break. Two Z closed at the SAME instant. A strict
     * `closed_at < now` predecessor selection DROPPED the same-instant Z1 (its
     * closed_at is NOT < now) → Z2's lower bound fell back to null/Z0 → Z2
     * re-aggregated Z1's already-sealed sale = DOUBLE-COUNT (Z1+Z2 = 40 for a
     * single 20 sale). The (closed_at, id) tie-break (`closed_at <= now` + id)
     * selects Z1 unambiguously → Z2's window is (Z1.closed, Z1.closed] = empty →
     * the sale is sealed exactly once.
     */
    public function test_two_z_closed_same_instant_no_double_count(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(ZReportService::class);

        // Z1: open 08:00, sale A 09:00 (20,00), close 10:00 → seals A.
        Carbon::setTestNow(Carbon::parse('2026-06-10 08:00:00'));
        $svc->open($branch->id);
        Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::DELIVERED, 'total' => 20.00, 'subtotal' => 20.00,
            'discount' => 0, 'total_tax' => 0, 'fiscal_sequence_no' => 1, 'fiscal_dated_at' => null,
            'created_at' => Carbon::parse('2026-06-10 09:00:00'),
            'updated_at' => Carbon::parse('2026-06-10 09:00:00'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));
        $z1 = $svc->close($branch->id);
        $this->assertEqualsWithDelta(20.00, (float) $z1->total_ttc, 0.01, 'Z1 seals sale A.');

        // Z2: open AND close at the SAME instant 10:00 as Z1's close, NO new sale.
        // The tie-break must pick Z1 as predecessor → Z2 window empty → seals nothing.
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));
        $svc->open($branch->id);
        $z2 = $svc->close($branch->id);
        Carbon::setTestNow(null);

        // Sale A is sealed EXACTLY once. Without the tie-break Z2 would re-count A
        // (sum would be 40). With it, sum == 20.
        $this->assertEqualsWithDelta(0.00, (float) $z2->total_ttc, 0.01,
            'Z2 must NOT re-seal Z1 sale A across a same-instant close (tie-break).');
        $this->assertEqualsWithDelta(20.00, (float) $z1->total_ttc + (float) $z2->total_ttc, 0.01,
            'Sale A must be counted exactly once — no double-count across same-instant closes.');
    }
}
