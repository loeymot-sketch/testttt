<?php

namespace Tests\Feature\Fiscal;

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
}
