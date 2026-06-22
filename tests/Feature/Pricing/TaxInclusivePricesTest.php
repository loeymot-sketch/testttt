<?php

namespace Tests\Feature\Pricing;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use App\Services\Pricing\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NF525 / TTC pricing-mode coverage.
 *
 * Owner mandate (2026-05): FoodKing displayed prices are TTC. The legacy backend
 * treated `items.price` as HT and ADDED tax on top, producing the user-visible
 * "3€ display becomes 3.60€ payment" bug. The fix introduces
 * `pricing.tax_inclusive_prices` (env: PRICING_TAX_INCLUSIVE) which, when true,
 * EXTRACTS the tax from the TTC line total instead of adding it on top.
 *
 * @see app/Services/Pricing/TaxCalculator.php::lineTaxAmountFromTTC
 * @see app/Services/Pricing/PricingService.php
 * @see config/pricing.php
 */
class TaxInclusivePricesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax20;
    private ItemCategory $category;
    private PricingService $service;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->tax20 = Tax::factory()->create([
            'name' => 'TVA 20%',
            'code' => 'TVA20',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 20.0,
            'status' => Status::ACTIVE,
        ]);
        $this->category = ItemCategory::factory()->create([
            'name' => 'TTC Test Category',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->service = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    /* -----------------------------------------------------------------
     * Unit contract — TaxCalculator::lineTaxAmountFromTTC
     * ---------------------------------------------------------------*/

    public function test_3eur_ttc_with_20pc_extracts_0_50_tax_total_remains_3eur(): void
    {
        // Owner's exact bug case: 3.00€ TTC at 20% VAT
        // HT = 3 / 1.20 = 2.50; tax = 0.50; TTC stays 3.00 (NOT 3.60).
        $calc = new TaxCalculator();
        $tax = $calc->lineTaxAmountFromTTC(3.00, TaxType::PERCENTAGE, 20.0, true);
        $this->assertSame(0.50, $tax, 'Tax extracted from 3€ TTC at 20% must be 0.50€');

        // The TTC value is the user-facing payment: it must NOT change.
        $ht = 3.00 - $tax;
        $this->assertEqualsWithDelta(2.50, $ht, 0.01, 'HT must be 2.50€');
        $this->assertEqualsWithDelta(3.00, $ht + $tax, 0.01, 'HT + tax must round-trip back to TTC');
    }

    public function test_2eur_ttc_with_20pc_extracts_0_33_tax_total_remains_2eur(): void
    {
        // 2.00 / 1.20 = 1.6666... → tax = 0.3333... → rounded to 0.33
        // After rounding, HT-from-DB (= TTC - tax) = 1.67 (display rounded) — the
        // user-facing TTC stays 2.00 in receipts.
        $calc = new TaxCalculator();
        $tax = $calc->lineTaxAmountFromTTC(2.00, TaxType::PERCENTAGE, 20.0, true);
        $this->assertSame(0.33, $tax);
    }

    public function test_fixed_tax_unchanged_in_ttc_mode_legitimate(): void
    {
        // FIXED tax (e.g. 0.10€/order eco-tax) is independent of base price; the
        // calculator must return the rate verbatim, NOT extract.
        $calc = new TaxCalculator();
        $this->assertSame(0.10, $calc->lineTaxAmountFromTTC(3.00, TaxType::FIXED, 0.10, true));
        $this->assertSame(2.00, $calc->lineTaxAmountFromTTC(999.99, TaxType::FIXED, 2.00, true));
    }

    public function test_unrounded_extraction_preserves_precision(): void
    {
        // Round=false branch: contract pinned for fiscal exports needing raw values.
        $calc = new TaxCalculator();
        $tax = $calc->lineTaxAmountFromTTC(2.00, TaxType::PERCENTAGE, 20.0, false);
        // 2.00 - (2.00 / 1.20) = 0.33333...
        $this->assertEqualsWithDelta(0.33333, $tax, 0.0001);
    }

    public function test_zero_subtotal_with_percentage_extracts_zero(): void
    {
        $calc = new TaxCalculator();
        $this->assertSame(0.0, $calc->lineTaxAmountFromTTC(0.0, TaxType::PERCENTAGE, 20.0, true));
    }

    public function test_zero_rate_extracts_zero_tax(): void
    {
        $calc = new TaxCalculator();
        $this->assertSame(0.0, $calc->lineTaxAmountFromTTC(3.00, TaxType::PERCENTAGE, 0.0, true));
    }

    /* -----------------------------------------------------------------
     * Integration — PricingService end-to-end TTC vs HT mode
     * ---------------------------------------------------------------*/

    public function test_pricing_service_ttc_mode_3eur_item_payment_stays_3eur(): void
    {
        // The owner-reported bug, rebuilt as an integration test.
        config(['pricing.tax_inclusive_prices' => true]);

        $item = $this->makeItem(3.00, $this->tax20);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1)],
            0,
            0,
            0.0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // CRITICAL NF525: total payment must equal user-displayed TTC price.
        $this->assertSame(3.00, $out->total, 'Customer pays exactly the TTC display price (3€)');
        $this->assertSame(0.50, $out->totalTax, 'Tax is EXTRACTED, not ADDED (0.50€ inside the 3€)');
        $this->assertSame(3.00, $out->accumulatedSubtotal, 'Subtotal accumulator carries TTC line totals');
    }

    public function test_pricing_service_ttc_mode_with_quantity_and_delivery(): void
    {
        config(['pricing.tax_inclusive_prices' => true]);

        // 2x 3€ TTC = 6€ TTC; tax extracted = 1.00€
        $item = $this->makeItem(3.00, $this->tax20);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 2)],
            0,
            0,
            0.0,
            5.00 /* delivery */
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame(6.00, $out->accumulatedSubtotal);
        $this->assertSame(1.00, $out->totalTax, 'Extracted tax: 6 - (6/1.2) = 1.00');
        // total = sum(TTC) + delivery - discount  (NO tax re-added)
        $this->assertSame(11.00, $out->total, 'Total = TTC subtotal + delivery (tax NOT added on top)');
    }

    public function test_config_off_falls_back_to_ht_add_legacy_behavior(): void
    {
        // Backward-compat guard: when the flag is false, NOTHING changes vs. legacy.
        // Existing fixtures (PricingServiceTest, PosOrderTaxTest, PricingIntegrityTest…)
        // were written against this exact arithmetic.
        config(['pricing.tax_inclusive_prices' => false]);

        $item = $this->makeItem(3.00, $this->tax20);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1)],
            0,
            0,
            0.0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Legacy: 3.00 HT + 0.60 tax = 3.60 total (the buggy customer-facing flow,
        // pinned here so the regression cannot silently come back).
        $this->assertSame(3.00, $out->accumulatedSubtotal);
        $this->assertSame(0.60, $out->totalTax);
        $this->assertSame(3.60, $out->total);
    }

    public function test_config_default_is_true_for_nf525_compliance(): void
    {
        // iter15-BUG-NF525 hardening (2026-05-10): the file default flipped
        // from FALSE → TRUE so a missing PRICING_TAX_INCLUSIVE env line in prod
        // cannot silently re-enable HT-add semantics. Legacy fixture-bound
        // tests must explicitly set config(['pricing.tax_inclusive_prices' => false]).
        // (Helper note: the env() resolution happens once at boot, so we re-require
        // the file directly to assert the static default and avoid env-cache surprises.)
        $defaultEnv = getenv('PRICING_TAX_INCLUSIVE');
        putenv('PRICING_TAX_INCLUSIVE');
        try {
            $config = require base_path('config/pricing.php');
            $this->assertArrayHasKey('tax_inclusive_prices', $config);
            $this->assertTrue(
                $config['tax_inclusive_prices'],
                'Default config MUST be TRUE (NF525 mandate). Set PRICING_TAX_INCLUSIVE=false explicitly to opt into legacy HT-add semantics.'
            );
        } finally {
            if ($defaultEnv !== false) {
                putenv("PRICING_TAX_INCLUSIVE={$defaultEnv}");
            }
        }
    }

    public function test_pricing_service_ttc_mode_multi_rate_cumulative_tax_is_deterministic(): void
    {
        // [abuse-heal 2026-06-18 convergence-c2] An order mixing TWO different VAT rates
        // (20% standard + 5.5% reduced food) must accumulate per-line-rounded tax
        // deterministically — no cross-rate rounding drift, TTC line totals untouched.
        // Characterization: locks the multi-rate arithmetic the single-rate tests never exercised.
        config(['pricing.tax_inclusive_prices' => true]);

        $tax55 = Tax::factory()->create([
            'name'     => 'TVA 5.5%',
            'code'     => 'TVA055',
            'type'     => TaxType::PERCENTAGE,
            'tax_rate' => 5.5,
            'status'   => Status::ACTIVE,
        ]);

        $itemStd = $this->makeItem(3.00, $this->tax20); // 3.00 TTC @ 20%  → tax 0.50  (3 - 3/1.20)
        $itemRed = $this->makeItem(5.00, $tax55);       // 5.00 TTC @ 5.5% → tax 0.26  (5 - 5/1.055 = 0.2607)

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($itemStd->id, 1), $this->line($itemRed->id, 1)],
            0,
            0,
            0.0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // TTC subtotal never inflated by tax (3 + 5).
        $this->assertSame(8.00, $out->accumulatedSubtotal, 'TTC subtotal = 3 + 5, untouched by tax');
        // Per-line extracted tax summed: 0.50 (20%) + 0.26 (5.5%) = 0.76 — deterministic, no drift.
        $this->assertSame(0.76, $out->totalTax, 'Multi-rate tax = 0.50 + 0.26 = 0.76 (per-line rounded)');
        // Customer pays exactly the sum of the TTC displays.
        $this->assertSame(8.00, $out->total, 'Total = TTC subtotal (tax extracted, not added on top)');
    }

    /* -----------------------------------------------------------------
     * Helpers (mirror PricingServiceTest patterns)
     * ---------------------------------------------------------------*/

    private function makeItem(float $price, Tax $tax): Item
    {
        return Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $tax->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function line(int $itemId, int $quantity): object
    {
        return (object) [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];
    }
}
