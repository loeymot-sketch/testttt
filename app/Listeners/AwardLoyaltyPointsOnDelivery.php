<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderStatusChanged;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

/**
 * [SPLASH LOYALTY] Auto-award loyalty points when an order is completed.
 * For normal orders: trigger on DELIVERED (13).
 * For kiosk orders: trigger on PREPARED (8) — the terminal flow has no DELIVERED step.
 * Points are awarded exactly once per order (idempotent: checks order.loyalty_points_awarded).
 */
class AwardLoyaltyPointsOnDelivery
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        $isKiosk = (int) ($order->order_type ?? 0) === OrderType::KIOSK;
        $triggerStatus = $isKiosk ? OrderStatus::PREPARED : OrderStatus::DELIVERED;

        if ($event->newStatus !== $triggerStatus) {
            return;
        }

        // Idempotency: if already awarded, skip silently
        if (!empty($order->loyalty_points_awarded)) {
            return;
        }

        // The order must belong to a real customer (not guest / kiosk machine itself)
        if (!$order->user_id) {
            return;
        }

        $user = User::find($order->user_id);
        if (!$user || !$user->loyalty_code) {
            return;
        }

        try {
            // Configurable rate: loyalty_points_per_euro (default: 10 points per €)
            $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_per_euro', 10);
            if ($rate <= 0) {
                return;
            }

            $orderTotal = (float) ($order->order_amount ?? $order->total ?? 0);
            $pointsToAward = (int) floor($orderTotal * $rate);

            if ($pointsToAward <= 0) {
                return;
            }

            // Atomic increment to avoid race condition if event fires twice
            User::where('id', $user->id)
                ->where('loyalty_code', $user->loyalty_code)
                ->increment('loyalty_points', $pointsToAward);

            // Mark order as awarded to prevent double-award
            $order->update(['loyalty_points_awarded' => $pointsToAward]);

            Log::info(sprintf(
                '[Loyalty] +%d points pour %s (commande #%s, total %.2f€)',
                $pointsToAward,
                $user->name,
                $order->order_serial_no ?? $order->id,
                $orderTotal
            ));
        } catch (\Throwable $e) {
            // Never block order status change for loyalty failure
            Log::error('[Loyalty] AwardLoyaltyPointsOnDelivery: ' . $e->getMessage());
        }
    }
}
