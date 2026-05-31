<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL-GOLIVE-VAT10 / F1-fix 2026-05-31 — LOCK_ZREPORT_F1_DISCOUNT_NETTING]
 *
 * F1 defect: in the frozen ZReportService, total_tva summed order->total_tax and
 * total_by_tax_rate summed order_items.tax_amount — both the PRE-discount per-line
 * tax — while total_ttc used order->total (discount-net). On a discounted order at
 * a non-zero VAT rate the signed Z therefore OVER-declares TVA (TVA computed on the
 * pre-discount base) even though TTC=HT+TVA held by construction (total_ht accessor
 * = total - total_tax). The per-rate VAT declaration was wrong.
 *
 * Fix (LOCK owner-gated): at aggregation, scale each order's per-rate TVA by
 * ratio = (subtotal - discount) / subtotal — mathematically identical to allocating
 * the discount proportionally across tax-rate buckets and recomputing TVA on the
 * post-discount (net) base. ratio = 1 when discount = 0 → every non-discount Z is
 * byte-identical (existing ZReportTaxBreakdownTest stays green).
 *
 * Reference value (documented in Vat10ZReconciliationTest): a 2,00 discount on a
 * 10,00 TTC order at 10% → net TVA 0,73 (NOT the pre-discount 0,91).
 */
class ZReportDiscountNettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-f1-netting-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-f1-netting-z');
        Config::set('pricing.tax_inclusive_prices', true);
    }

    private function insertOrderItem(int $orderId, int $branchId, string $taxRate, float $taxAmount, float $totalPrice): void
    {
        $itemId = Item::query()->value('id') ?? Item::factory()->create()->id;
        DB::table('order_items')->insert([
            'order_id'    => $orderId,
            'branch_id'   => $branchId,
            'item_id'     => $itemId,
            'quantity'    => 1,
            'discount'    => 0,
            'price'       => $totalPrice,
            'tax_rate'    => $taxRate,
            'tax_amount'  => $taxAmount,
            'total_price' => $totalPrice,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Single-rate discounted order — the documented F1 example.
     * 10,00 TTC gross, 2,00 discount → 8,00 net. Gross line TVA 0,91.
     * Correct signed Z: total_tva 0,73, total_by_tax_rate['10'] 0,73,
     * total_ttc 8,00, identity total_ht + total_tva = total_ttc.
     */
    public function test_discounted_order_z_tva_is_netted_to_post_discount_base(): void
    {
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => 10.00,
            'discount'           => 2.00,
            'total'              => 8.00,
            'total_tax'          => 0.91, // gross TVA on the 10,00 pre-discount base
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10.00', 0.91, 10.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $this->assertEqualsWithDelta(8.00, (float) $totals['total_ttc'], 0.01, 'TTC stays the discount-net total');
        $this->assertEqualsWithDelta(0.73, (float) $totals['total_tva'], 0.01, 'TVA must be netted to the post-discount base (0,73), not pre-discount 0,91');
        $this->assertEqualsWithDelta(7.27, (float) $totals['total_ht'], 0.01, 'HT = net TTC - net TVA');
        $this->assertEqualsWithDelta(
            (float) $totals['total_ttc'],
            (float) $totals['total_ht'] + (float) $totals['total_tva'],
            0.01,
            'NF525 identity TTC = HT + TVA must hold on the netted figures'
        );
        $this->assertArrayHasKey('10', $totals['total_by_tax_rate']);
        $this->assertEqualsWithDelta(0.73, (float) $totals['total_by_tax_rate']['10'], 0.01, 'Per-rate TVA must be netted, not the pre-discount 0,91');
    }

    /**
     * Multi-rate discounted order — proves the discount is allocated proportionally
     * across rate buckets (each bucket scaled by the same net/gross ratio).
     * 10% bucket gross TTC 20,00 (TVA 1,82) + 5,5% bucket gross TTC 10,00 (TVA 0,52);
     * subtotal 30,00, discount 3,00 → ratio 0,9 → 10% TVA 1,64 ; 5,5% TVA 0,47.
     */
    public function test_multi_rate_discount_allocates_proportionally(): void
    {
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => 30.00,
            'discount'           => 3.00,
            'total'              => 27.00,
            'total_tax'          => 2.34, // 1,82 + 0,52 gross
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10',  1.82, 20.00);
        $this->insertOrderItem($order->id, $branch->id, '5.5', 0.52, 10.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        // ratio = 27/30 = 0,9
        $this->assertEqualsWithDelta(1.64, (float) $totals['total_by_tax_rate']['10'], 0.02, '10% bucket TVA scaled by 0,9');
        $this->assertEqualsWithDelta(0.47, (float) $totals['total_by_tax_rate']['5.5'], 0.02, '5,5% bucket TVA scaled by 0,9');
        $this->assertEqualsWithDelta(27.00, (float) $totals['total_ttc'], 0.01);
        $this->assertEqualsWithDelta(
            (float) $totals['total_ttc'],
            (float) $totals['total_ht'] + (float) $totals['total_tva'],
            0.02,
            'Identity holds on multi-rate netted figures'
        );
    }

    /** Regression guard: a NON-discounted order is byte-identical (ratio = 1). */
    public function test_non_discounted_order_breakdown_is_unchanged(): void
    {
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => 11.00,
            'discount'           => 0,
            'total'              => 11.00,
            'total_tax'          => 1.00,
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10.00', 1.00, 11.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $this->assertEqualsWithDelta(1.00, (float) $totals['total_tva'], 0.001, 'No discount → TVA unchanged (gross == net)');
        $this->assertEqualsWithDelta(1.00, (float) $totals['total_by_tax_rate']['10'], 0.001);
    }

    /**
     * [advisor 2026-05-31] EXACT identity total_tva == Σ total_by_tax_rate must hold
     * on the signed Z payload — a signed fiscal document whose TVA total ≠ sum of
     * its rate buckets is internally inconsistent. Pre-refactor my fix rounded at
     * two levels (per-order in applyOrderToTotals + per-rate in taxBreakdownForOrders);
     * with round-half-up they could diverge by a cent on a multi-rate discounted Z.
     * Worked counter-example: order total_tax=0,04 split 0,03 (10%) + 0,01 (5,5%),
     * ratio 0,5 → naïve total_tva=round(0,02,2)=0,02; per-bucket round(0,015)=0,02 +
     * round(0,005)=0,01 → Σ buckets = 0,03 ≠ 0,02. The refactor derives total_tva
     * FROM the rounded buckets (array_sum) → identity holds by construction. This
     * test EXACTS that and uses a worked multi-rate ratio that would diverge with
     * the naïve approach.
     */
    public function test_total_tva_exactly_equals_sum_of_total_by_tax_rate(): void
    {
        $branch = Branch::factory()->create();

        // Construct the divergence case explicitly: an order whose pre-discount
        // per-rate tax_amount values would round differently from the order-level
        // pre-rounded netTVA under the naïve approach.
        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => 1.00,
            'discount'           => 0.50,    // ratio 0.5
            'total'              => 0.50,
            'total_tax'          => 0.04,    // 0.03 + 0.01
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10',  0.03, 0.50);
        $this->insertOrderItem($order->id, $branch->id, '5.5', 0.01, 0.50);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        // EXACT identity (no delta) — the signed payload must be internally consistent.
        $this->assertSame(
            round((float) array_sum($totals['total_by_tax_rate']), 2),
            (float) $totals['total_tva'],
            'EXACT: total_tva must equal sum(total_by_tax_rate) — both come from the same SSOT (the per-rate buckets)'
        );
        // And total_ttc == total_ht + total_tva.
        $this->assertSame(
            round((float) $totals['total_ht'] + (float) $totals['total_tva'], 2),
            (float) $totals['total_ttc'],
            'EXACT NF525 identity: TTC == HT + TVA on the signed payload'
        );
    }

    /**
     * [advisor 2026-05-31] The fiscal end-to-end the prior tests lacked: with
     * pos.manual_discount_enabled=true (post-F1 reactivation), simulate a discounted
     * PAID order, run the REAL aggregate() + close() + sign() pipeline, and prove
     * (a) verifySignature is green, (b) verifyChain is green, and (c) the persisted
     * Z row's identities hold (TVA=Σbuckets and TTC=HT+TVA). This exercises the
     * exact code-path that the owner's reactivation will hit on day 1 — without
     * touching the production default flag.
     */
    public function test_discounted_z_close_signs_and_chain_verifies(): void
    {
        Config::set('pos.manual_discount_enabled', true); // reactivation scenario
        Config::set('pricing.tax_inclusive_prices', true);

        $branch = Branch::factory()->create();
        $svc = app(ZReportService::class);

        // Open FIRST: the close window is (opened_at, closedAt] (strict lower bound),
        // so the discounted order must be created strictly AFTER open() — Carbon::now()
        // here can resolve to the same second as open(), so explicitly travel forward.
        $svc->open($branch->id);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        // 10,00 TTC gross at 10% (line tax 0,91), 2,00 discount → 8,00 net.
        // Expected post-fix signed Z: total_tva = 0,73 ; total_by_tax_rate['10'] = 0,73 ;
        // total_ttc = 8,00 ; total_ht = 7,27.
        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => 10.00,
            'discount'           => 2.00,
            'total'              => 8.00,
            'total_tax'          => 0.91,
            'fiscal_sequence_no' => 1,
            'status'             => OrderStatus::DELIVERED,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10.00', 0.91, 10.00);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));
        $report = $svc->close($branch->id);
        \Illuminate\Support\Carbon::setTestNow();

        // (a) signature verifies on the just-signed report
        $this->assertTrue($svc->verifySignature($report), 'Just-signed Z must verifySignature green.');

        // (b) chain verifies for the branch (the production NF525 health gate).
        // verifyChain re-derives each Z's signature from its persisted totals; if my
        // refactor produced totals inconsistent with computeSignature's payload, the
        // chain would break here even though verifySignature on a fresh row passes.
        $chain = $svc->verifyChain($branch->id);
        $this->assertTrue((bool) $chain['valid'], 'verifyChain must be valid on a chain that contains a discounted Z.');
        $this->assertSame([], $chain['errors'], 'verifyChain errors must be empty.');

        // (c) persisted identities on the signed row
        $fresh = $report->fresh();
        $byRate = (array) ($fresh->total_by_tax_rate ?? []);

        $this->assertSame(
            round((float) array_sum($byRate), 2),
            (float) $fresh->total_tva,
            'Persisted signed Z: total_tva == Σ total_by_tax_rate (EXACT).'
        );
        $this->assertSame(
            round((float) $fresh->total_ht + (float) $fresh->total_tva, 2),
            (float) $fresh->total_ttc,
            'Persisted signed Z: total_ttc == total_ht + total_tva (EXACT).'
        );

        // F1 fix values (the proof reactivation produces fiscally-correct figures)
        $this->assertEqualsWithDelta(8.00, (float) $fresh->total_ttc, 0.01);
        $this->assertEqualsWithDelta(0.73, (float) $fresh->total_tva, 0.01, 'TVA on the post-discount base (F1 fix).');
        $this->assertEqualsWithDelta(7.27, (float) $fresh->total_ht,  0.01);
        $this->assertEqualsWithDelta(0.73, (float) ($byRate['10'] ?? 0), 0.01);
    }
}
