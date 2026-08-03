<?php

namespace App\Services\Pricing;

use App\Enums\TaxType;

final class TaxCalculator
{
    /**
     * Compute tax to ADD on top of an HT (ex-tax) line subtotal.
     * Legacy behavior, used when prices are stored ex-tax.
     */
    public function lineTaxAmount(float $lineSubtotalExTax, int $taxType, float $taxRate, bool $round): float
    {
        $raw = $taxType === TaxType::FIXED
            ? $taxRate
            : ($lineSubtotalExTax * $taxRate) / 100.0;

        return $round ? round($raw, 2) : $raw;
    }

    /**
     * Extract tax already INCLUDED in a TTC (tax-inclusive) line total.
     * Used when `pricing.tax_inclusive_prices=true` (owner-confirmed FoodKing default in prod).
     *
     * Contract:
     *   - FIXED tax (e.g. cents per item) is unchanged: returned verbatim, NOT extracted.
     *     A fixed amount cannot be expressed as a percentage of the TTC line.
     *   - PERCENTAGE: HT = TTC / (1 + rate/100); tax = TTC - HT.
     *     `round(.., 2)` is applied on the EXTRACTED tax (mirrors legacy contract on `lineTaxAmount`).
     */
    public function lineTaxAmountFromTTC(float $lineTotalIncTax, int $taxType, float $taxRate, bool $round = true): float
    {
        if ($taxType === TaxType::FIXED) {
            // Fixed-amount tax is independent of TTC base; preserve legacy semantic.
            return $round ? round($taxRate, 2) : $taxRate;
        }

        if ($taxRate <= -100.0) {
            // Defensive: divisor would be zero/negative; fall back to legacy add-on-top to avoid div0.
            return $this->lineTaxAmount($lineTotalIncTax, $taxType, $taxRate, $round);
        }

        $ht = $lineTotalIncTax / (1.0 + $taxRate / 100.0);
        $raw = $lineTotalIncTax - $ht;

        return $round ? round($raw, 2) : $raw;
    }
}
