<?php

namespace App\Services\Pricing;

/**
 * Immutable per-line pricing snapshot (mirrors persisted OrderItem financial fields).
 */
final class PricingLineResult
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $quantity,
        public readonly float $unitItemPrice,
        public readonly float $variationTotal,
        public readonly float $extraTotal,
        public readonly float $lineSubtotalExTax,
        public readonly ?string $taxName,
        public readonly float $taxRate,
        public readonly int $taxType,
        public readonly float $taxAmount,
        public readonly string $variationsJson,
        public readonly string $extrasJson,
        public readonly ?string $instruction,
        public readonly float $addonTotal = 0.0,
    ) {
    }
}
