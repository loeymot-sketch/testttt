<?php

namespace Tests\Feature\Fiscal;

use App\Enums\TaxType;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [iter15-BUG-NF525 regression test 2026-05-10]
 *
 * Defends against a recurrence of the misconfig fixed by migration
 * 2026_05_10_030000_fix_tax_misconfig_type_fixed_to_percentage.
 *
 * Bug pattern :
 *   - Tax row with tax_rate looking like a percentage (e.g. 5.5, 10, 20)
 *   - But type stored as FIXED (=5) instead of PERCENTAGE (=10)
 *   - OrderService::posOrderStore ligne ~773 reads $taxRate as fixed amount
 *   - Result: 2€ Frite gets 20€ tax → ticket TOTAL=22€ (NF525 break)
 *
 * This test asserts there are no Tax rows in this trap configuration.
 */
class TaxTypeMisconfigDetectionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function no_tax_row_has_percentage_rate_with_fixed_type(): void
    {
        // Pattern : rate > 0 et <= 100 (pourcentage plausible) mais type=FIXED
        $misconfigured = Tax::query()
            ->where('type', TaxType::FIXED)
            ->where('tax_rate', '>', 0)
            ->where('tax_rate', '<=', 100)
            ->get();

        $this->assertCount(
            0,
            $misconfigured,
            sprintf(
                "Tax misconfig détecté : %d row(s) avec rate percentage-like mais type=FIXED. "
                . "Cf migration 2026_05_10_030000 + audit iter15-BUG-NF525. Rows: %s",
                $misconfigured->count(),
                $misconfigured->pluck('name', 'id')->toJson()
            )
        );
    }

    /** @test */
    public function tax_compute_2_eur_at_20_percent_returns_0_40_not_20(): void
    {
        // Reproduce le bug exact observé : Frite 2€ avec TVA 20%
        $tax = Tax::create([
            'name' => 'TVA 20%',
            'tax_rate' => 20.000000,
            'type' => TaxType::PERCENTAGE,
            'code' => 'TVA20-TEST',
        ]);

        $verifiedTotalPrice = 2.00;
        $taxRate = (float) $tax->tax_rate;
        $taxType = (int) $tax->type;

        // Reproduit OrderService::posOrderStore ligne ~773
        $taxPrice = round(
            $taxType === TaxType::FIXED
                ? $taxRate
                : ($verifiedTotalPrice * $taxRate) / 100,
            2
        );

        $this->assertSame(0.40, $taxPrice, 'Tax 20% sur 2€ doit donner 0.40€, pas 20€');
    }

    /** @test */
    public function tax_compute_with_fixed_type_returns_full_rate_as_amount(): void
    {
        // Defensive: si type=FIXED legitimately (ex: éco-tax fixe 0.50€), on
        // garde le comportement. Test asserts le comportement FIXED reste correct.
        $ecoTax = Tax::create([
            'name' => 'Éco-tax fixe',
            'tax_rate' => 0.50,
            'type' => TaxType::FIXED,
            'code' => 'ECO-FIXED-TEST',
        ]);

        $verifiedTotalPrice = 100.00;
        $taxRate = (float) $ecoTax->tax_rate;
        $taxType = (int) $ecoTax->type;

        $taxPrice = round(
            $taxType === TaxType::FIXED
                ? $taxRate
                : ($verifiedTotalPrice * $taxRate) / 100,
            2
        );

        // FIXED type : le rate (=0.50) est ajouté tel quel, peu importe le total
        $this->assertSame(0.50, $taxPrice, 'Tax FIXED legitimate (éco-tax 0.50€) reste 0.50€ peu importe total');
    }

    /** @test */
    public function tax_with_high_rate_above_100_with_fixed_type_is_legitimate(): void
    {
        // Defensive: tax_rate > 100 + type=FIXED = forfait fixe genre 500€
        // Pas un bug, donc ne déclenche pas le pattern detection.
        $forfait = Tax::create([
            'name' => 'Forfait fiscal',
            'tax_rate' => 500.00,
            'type' => TaxType::FIXED,
            'code' => 'FORFAIT-TEST',
        ]);

        $misconfigured = Tax::query()
            ->where('type', TaxType::FIXED)
            ->where('tax_rate', '>', 0)
            ->where('tax_rate', '<=', 100)
            ->get();

        $this->assertNotContains(
            $forfait->id,
            $misconfigured->pluck('id')->toArray(),
            'Tax FIXED rate>100 = forfait legitimate, doit pas être flagged'
        );
    }
}
