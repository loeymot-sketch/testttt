<?php

namespace App\Services\Loyalty;

use Exception;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] Stable, machine-readable error
 * surface for POS cashier loyalty redemption failures.
 *
 * Each error_code maps to a stable HTTP status so the cashier Vue modal
 * can render localized hints without parsing free-form messages.
 *
 * Error codes :
 *   INVALID_POINTS          422 — points <= 0
 *   INVALID_LOYALTY_CODE    422 — empty / malformed code
 *   POINTS_NOT_MULTIPLE     422 — not a multiple of rate
 *   BELOW_MIN_REDEEM        422 — points < loyalty_min_redeem_points (même seuil que borne/site)
 *   CUSTOMER_NOT_FOUND      404 — loyalty_code unknown
 *   INSUFFICIENT_BALANCE    422 — customer.loyalty_points < points
 *   DISCOUNT_EXCEEDS_SUBTOTAL 422 — computed eur > order.subtotal
 *   ALREADY_REDEEMED        409 — second redeem attempt on same order
 *   ORDER_ALREADY_FINALIZED 409 — order is PAID or in terminal state
 */
final class PosRedemptionException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $publicMessage,
        public readonly int $httpStatus,
    ) {
        parent::__construct($publicMessage);
    }
}
