<?php

namespace App\Services\Pricing;

/**
 * Input for server-side order pricing (immutable).
 *
 * @param  array<int, object>  $requestItems  Decoded JSON line items (stdClass from safeJsonDecode)
 */
final class PricingRequest
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $branchId,
        public readonly array $requestItems,
        public readonly string $context,
        public readonly int $couponId,
        public readonly int $couponCustomerUserId,
        public readonly float $manualDiscountRequest,
        public readonly float $deliveryCharge,
        public readonly bool $enforceCrossItemGuards,
        public readonly bool $roundLineTotals,
        public readonly bool $roundLineTax,
        public readonly bool $roundOrderTotalTax,
        public readonly bool $roundFinalOrderTotal,
        public readonly bool $roundSubtotal,
    ) {
    }

    public static function forWeb(int $orderId, int $branchId, array $requestItems, int $couponId, int $customerId, float $deliveryCharge): self
    {
        return new self(
            $orderId,
            $branchId,
            $requestItems,
            'web',
            $couponId,
            $customerId,
            0.0,
            $deliveryCharge,
            enforceCrossItemGuards: true,
            roundLineTotals: false,
            roundLineTax: false,
            roundOrderTotalTax: false,
            roundFinalOrderTotal: false,
            roundSubtotal: false,
        );
    }

    public static function forPos(int $orderId, int $branchId, array $requestItems, int $couponId, int $customerId, float $manualDiscount, float $deliveryCharge): self
    {
        return new self(
            $orderId,
            $branchId,
            $requestItems,
            'pos',
            $couponId,
            $customerId,
            $manualDiscount,
            $deliveryCharge,
            enforceCrossItemGuards: true,
            roundLineTotals: true,
            roundLineTax: true,
            roundOrderTotalTax: true,
            roundFinalOrderTotal: true,
            roundSubtotal: true,
        );
    }

    public static function forTable(int $orderId, int $branchId, array $requestItems, int $couponId, int $customerId, float $manualDiscount, float $deliveryCharge): self
    {
        return new self(
            $orderId,
            $branchId,
            $requestItems,
            'table',
            $couponId,
            $customerId,
            $manualDiscount,
            $deliveryCharge,
            enforceCrossItemGuards: true,
            roundLineTotals: false,
            roundLineTax: false,
            roundOrderTotalTax: false,
            roundFinalOrderTotal: false,
            roundSubtotal: false,
        );
    }

    public static function forKiosk(int $orderId, int $branchId, array $requestItems, int $couponId, int $customerUserId, float $deliveryCharge): self
    {
        return new self(
            $orderId,
            $branchId,
            $requestItems,
            'kiosk',
            $couponId,
            $customerUserId,
            0.0,
            $deliveryCharge,
            enforceCrossItemGuards: true,
            roundLineTotals: true,
            roundLineTax: true,
            roundOrderTotalTax: true,
            roundFinalOrderTotal: true,
            roundSubtotal: true,
        );
    }
}
