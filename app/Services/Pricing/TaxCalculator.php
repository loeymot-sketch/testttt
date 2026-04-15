<?php

namespace App\Services\Pricing;

use App\Enums\TaxType;

final class TaxCalculator
{
    public function lineTaxAmount(float $lineSubtotalExTax, int $taxType, float $taxRate, bool $round): float
    {
        $raw = $taxType === TaxType::FIXED
            ? $taxRate
            : ($lineSubtotalExTax * $taxRate) / 100.0;

        return $round ? round($raw, 2) : $raw;
    }
}
