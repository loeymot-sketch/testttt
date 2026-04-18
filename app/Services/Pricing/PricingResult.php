<?php

namespace App\Services\Pricing;

/**
 * Immutable order-level pricing outcome for a single cart before persistence side-effects.
 */
final class PricingResult
{
    /**
     * @param  array<int, array<string, mixed>>  $orderItemInsertRows  Rows ready for OrderItem::insert()
     * @param  PricingLineResult[]  $lines
     */
    public function __construct(
        public readonly array $orderItemInsertRows,
        public readonly array $lines,
        /** Sum of line totals before optional order-level subtotal rounding (used by kiosk loyalty cap). */
        public readonly float $accumulatedSubtotal,
        public readonly float $subtotal,
        public readonly float $totalTax,
        public readonly float $discount,
        public readonly float $deliveryCharge,
        public readonly float $total,
        /** @var  array<string, mixed>  Reserved for future side-channel metadata */
        public readonly array $meta = [],
    ) {
    }
}
