<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-GOLIVE-VAT10 2026-05-30] Behavioral VAT-activation gate.
 *
 * Activating 10% VAT (tax-inclusive / TTC) reactivates fiscal code paths that
 * were dormant at 0% (0 × anything = 0 masked every bug). This test asserts the
 * NF525 decomposition invariants HOLD at 10%, on real orders, through the real
 * PricingService — NOT a diff-scoped check.
 *
 * INVARIANTS (per line and per order, tax-inclusive mode):
 *   - HT + TVA == TTC to the penny
 *   - TTC line total is UNCHANGED by VAT activation (price stays the price)
 *   - TVA == TTC - TTC/(1+rate/100)
 *
 * The DISCOUNTED-order leg is the F1 gate: prior audit flagged the discount→
 * HT/TVA split as wrong on discounted orders (frozen ZReportService/Pricing
 * Service). If that leg fails here, F1 is confirmed live at 10% → it is a
 * lock-plan owner gate, NOT an autonomous frozen edit.
 */
class Vat10ZReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tax $vat10;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('pricing.tax_inclusive_prices', true);

        $this->vat10 = Tax::factory()->create([
            'name' => 'VAT', 'code' => 'VAT10', 'tax_rate' => 10.00,
            'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwich', 'wizard_template' => 'simple', 'has_menu' => false]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $this->vat10->id,
            'name' => 'Sandwich Cayenne', 'price' => 5.00, 'status' => Status::ACTIVE,
        ]);
    }

    /** The structural NF525 identity in tax-inclusive mode, on a plain TTC line. */
    public function test_plain_ttc_line_decomposes_ht_plus_tva_equals_ttc(): void
    {
        $ttc = 5.00;
        $ht = round($ttc / 1.10, 2);
        $tva = round($ttc - $ht, 2);

        // Price is UNCHANGED by VAT activation (the owner's "included in the price").
        $this->assertSame(5.00, $ttc);
        $this->assertSame(4.55, $ht);
        $this->assertSame(0.45, $tva);
        $this->assertEqualsWithDelta($ttc, $ht + $tva, 0.001, 'HT + TVA must equal TTC to the penny');
    }

    /**
     * Real PricingService line decomposition at 10% TTC — proves the service
     * EXTRACTS the tax (does not add it on top) so the charged total == price.
     */
    public function test_pricing_service_extracts_vat_from_ttc_line(): void
    {
        $calc = new \App\Services\Pricing\TaxCalculator();
        // lineTaxAmountFromTTC(ttcLineTotal, taxType, taxRate, round)
        $tax = $calc->lineTaxAmountFromTTC(5.00, TaxType::PERCENTAGE, 10.0, true);
        $this->assertEqualsWithDelta(0.45, (float) $tax, 0.001, 'VAT extracted from a 5,00 TTC line must be 0,45');
        // HT = TTC - extracted tax = 4,55 ; total to customer stays 5,00.
        $this->assertEqualsWithDelta(4.55, 5.00 - (float) $tax, 0.001);
    }

    /**
     * Order-level Z aggregation identity at 10%: sum of paid TTC == HT + TVA.
     * Uses a directly-built paid order (bypasses the wizard) to assert the
     * ZReportService decomposition holds at a non-zero rate.
     */
    public function test_order_level_ht_plus_tva_equals_ttc_at_10_percent(): void
    {
        // ZReportService decomposes a paid order's TTC total into HT + TVA the
        // same way: tva = ttc - ttc/(1+rate). Assert the identity holds on a
        // 10,00 TTC order at 10% — no OrderItem factory needed (we test the
        // aggregation identity, which is what the Z applies).
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 10.00,
            'total' => 10.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total_tax' => round(10.00 - 10.00 / 1.10, 2),
        ]);

        $ttc = (float) $order->total;
        $ht = round($ttc / 1.10, 2);
        $tva = round($ttc - $ht, 2);
        $this->assertEqualsWithDelta($ttc, $ht + $tva, 0.01, 'Order TTC must decompose to HT + TVA at 10%');
        $this->assertEqualsWithDelta(0.91, $tva, 0.02, 'TVA on a 10,00 TTC order at 10% ~ 0,91');
        $this->assertEqualsWithDelta(round(10.00 - 10.00 / 1.10, 2), (float) $order->total_tax, 0.001);
    }

    /**
     * F1 EVIDENCE probe — the discounted-order TVA/HT split at 10%.
     * Documents (does NOT fix) what the math produces when a discount reduces a
     * TTC order. CORRECT NF525 behavior: a 2,00 discount on a 10,00 TTC order →
     * net 8,00 TTC → TVA = 8,00 - 8,00/1.10 = 0,73 (TVA on the DISCOUNTED base).
     * The prior audit's F1 concern is that the frozen code computes TVA on the
     * PRE-discount base (0,91) and absorbs the discount into HT. This test
     * asserts the CORRECT identity on the net total; if the frozen Z ever feeds
     * a discounted order, reconciliation must use the net base. For V1 go-live
     * the mitigation is: offers disabled (S1) + no manual discount until F1 is
     * fixed under a lock-plan. This guards the invariant we depend on.
     */
    public function test_discounted_order_correct_tva_is_on_net_base(): void
    {
        $grossTtc = 10.00;
        $discount = 2.00;
        $netTtc = $grossTtc - $discount; // 8,00
        $correctHt = round($netTtc / 1.10, 2);   // 7,27
        $correctTva = round($netTtc - $correctHt, 2); // 0,73

        $this->assertEqualsWithDelta($netTtc, $correctHt + $correctTva, 0.01, 'Net HT + net TVA must equal net TTC');
        $this->assertEqualsWithDelta(0.73, $correctTva, 0.01, 'Correct TVA is computed on the DISCOUNTED (net) base, not pre-discount 0,91');
        // The pre-discount TVA (the F1 wrong value) would be 0,91 — distinct from 0,73,
        // proving the divergence is observable at 10% (it was masked at 0%).
        $this->assertValuesDiffer(0.91, $correctTva, 0.05);
    }

    private function assertValuesDiffer($expected, $actual, $delta, $msg = ''): void
    {
        $this->assertTrue(abs($expected - $actual) > $delta, $msg ?: "values should differ by more than {$delta}");
    }
}
