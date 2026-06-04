<?php

namespace App\Listeners;

use App\Events\RefundCreated;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [GOAL-J2-HEAL-07 2026-05-24] Phase J-ADV-3 L3 P1 CONFIRMED:
 * Earned loyalty points were NOT clawed back on refund. With 10
 * pts/€ default rate, a 30€ refunded-but-DELIVERED order left 300
 * pts (= 3€) on the customer balance — repeatable cash + points
 * double-dip exploit.
 *
 * Fix: subtract awarded points from user balance when an order is
 * refunded. Mirrors {@see AwardLoyaltyPointsOnDelivery} pattern in
 * inverse, and uses the same loyalty_customer_code → loyalty_code
 * resolver so kiosk orders (where user_id is the machine account)
 * still claw back from the real customer.
 *
 * Resilience: wrapped in try/catch + Log::error mirroring
 * {@see ReleaseStockOnRefundCreated} HEAL-I.4 cascade-isolation
 * pattern. EventServiceProvider lists this listener LAST in the
 * RefundCreated cascade so a Throwable here cannot halt the
 * Persist / Stock / Availability cascade that must precede it.
 *
 * Partial-refund note (V1.0.2 backlog): when
 * $event->refundedItems is non-empty (partial), V1 currently claws
 * back the FULL awarded amount — same shape as refundPoints which
 * reverses the full redemption. Pro-rated clawback is deferred to
 * V1.0.2 once partial-refund semantics are finalized across the
 * cash-trail.
 */
class ClawbackLoyaltyPointsOnRefund
{
    public function __construct(private LoyaltyService $loyaltyService)
    {
    }

    public function handle(RefundCreated $event): void
    {
        try {
            $order = $event->order;
            if (!$order) {
                return;
            }

            $awarded = (int) ($order->loyalty_points_awarded ?? 0);
            if ($awarded <= 0) {
                // null = never awarded · 0 = ineligible · -1 = in-flight sentinel
                return;
            }

            // Resolve the loyalty customer via the same path as
            // AwardLoyaltyPointsOnDelivery (lines 65-74): loyalty_customer_code
            // → User::where('loyalty_code', ...), fallback to user_id only when
            // that user has a loyalty_code. This is essential for kiosk orders
            // where user_id is the machine account, not the customer.
            $user = null;
            if (!empty($order->loyalty_customer_code)) {
                $user = User::where('loyalty_code', $order->loyalty_customer_code)->first();
            }
            if (!$user && !empty($order->user_id)) {
                $candidate = User::find($order->user_id);
                if ($candidate && $candidate->loyalty_code) {
                    $user = $candidate;
                }
            }
            if (!$user) {
                return;
            }

            $this->loyaltyService->clawbackEarnedPoints(
                $user->id,
                $awarded,
                $order->id,
                'Clawback fidélité suite remboursement'
            );
        } catch (Throwable $e) {
            // [GOAL-I2-HEAL-01 cascade-isolation pattern] Never propagate —
            // a refund must not be blocked by a loyalty-service failure.
            // Sibling listeners (Persist / Stock / Availability) have already
            // run; we MUST swallow to keep refund flow intact.
            Log::error('[ClawbackLoyaltyPointsOnRefund] clawback failed (isolated)', [
                'order_id' => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
