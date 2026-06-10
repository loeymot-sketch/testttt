<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderStatusChanged;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

/**
 * Auto-award loyalty points when an order is completed.
 * For normal orders: trigger on DELIVERED.
 * For kiosk/takeaway orders: trigger on PREPARED or DELIVERED
 *   (kiosk flow ends at PREPARED; POS cashier can skip directly to DELIVERED).
 *
 * Idempotent: uses an atomic sentinel (-1) on orders.loyalty_points_awarded
 * to guarantee exactly-once execution even under concurrent events.
 *
 * Writes an immutable record to loyalty_transactions for full audit trail
 * and multi-surface analytics (kiosk / pos / web / mobile).
 */
class AwardLoyaltyPointsOnDelivery
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        // [AUDIT-P50-BUG10] Never award points for cancelled orders (edge case: status change event after cancel)
        $currentStatus = (int) ($order->status ?? $event->newStatus ?? -1);
        if ($currentStatus === OrderStatus::CANCELED) {
            return;
        }

        $isKiosk = in_array((int) ($order->order_type ?? 0), [OrderType::KIOSK, OrderType::TAKEAWAY], true);

        if ($isKiosk) {
            $shouldTrigger = in_array($event->newStatus, [OrderStatus::PREPARED, OrderStatus::DELIVERED], true);
        } else {
            $shouldTrigger = ($event->newStatus === OrderStatus::DELIVERED);
        }

        if (!$shouldTrigger) {
            return;
        }

        // Atomic idempotency: only one concurrent process can claim the sentinel.
        // FrontendOrder::$table = "orders" — physical table is always "orders".
        // [AUDIT-P50-BUG10] Also verify order is not cancelled at the moment of awarding
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->whereNull('loyalty_points_awarded')
            ->where('status', '!=', OrderStatus::CANCELED)
            ->update(['loyalty_points_awarded' => -1]);

        if ($updated === 0) {
            return;
        }

        // Resolve the loyalty customer.
        // On kiosk orders user_id is the machine account; the real customer is in loyalty_customer_code.
        // On POS/web orders fall back to the order owner if they have a loyalty code.
        $user = null;
        if (!empty($order->loyalty_customer_code)) {
            $user = User::where('loyalty_code', $order->loyalty_customer_code)->first();
        }
        if (!$user && $order->user_id) {
            $candidate = User::find($order->user_id);
            if ($candidate && $candidate->loyalty_code) {
                $user = $candidate;
            }
        }
        if (!$user) {
            DB::table('orders')
                ->where('id', $order->id)
                ->where('loyalty_points_awarded', -1)
                ->update(['loyalty_points_awarded' => null]);
            return;
        }

        try {
            $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_per_euro', 1);
            if ($rate <= 0) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('loyalty_points_awarded', -1)
                    ->update(['loyalty_points_awarded' => null]);
                return;
            }

            // [AUDIT-P49-BUG2] FrontendOrder (kiosk) uses 'total', Order (POS) uses 'order_amount'.
            // If we read $order->order_amount and it has a DB default of 0.00, the ?? fallback never triggers.
            // Explicitly check order_type to determine which column to use.
            $isKioskOrder = in_array((int) ($order->order_type ?? 0), [OrderType::KIOSK, OrderType::TAKEAWAY], true);
            if ($isKioskOrder) {
                $orderTotal = (float) ($order->total ?? 0);
            } else {
                $orderTotal = (float) ($order->order_amount ?? $order->total ?? 0);
            }
            // [L1 D11] round (client parity: 0,90 € → 1 pt) — was floor.
            $pointsToAward = (int) round($orderTotal * $rate);

            if ($pointsToAward <= 0) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('loyalty_points_awarded', -1)
                    ->update(['loyalty_points_awarded' => null]);
                return;
            }

            // Determine the surface that generated this order for analytics.
            $surface = $order->source_surface ?? ($isKiosk ? 'kiosk' : 'web');

            DB::transaction(function () use ($user, $pointsToAward, $order, $surface) {
                // Atomic balance increment
                User::where('id', $user->id)->increment('loyalty_points', $pointsToAward);

                // Snapshot balance after increment for the ledger
                $balanceAfter = (int) DB::table('users')
                    ->where('id', $user->id)
                    ->value('loyalty_points');

                // Immutable audit record
                DB::table('loyalty_transactions')->insert([
                    'user_id'        => $user->id,
                    'loyalty_code'   => $user->loyalty_code,
                    'order_id'       => $order->id,
                    'type'           => 'earn',
                    'points'         => $pointsToAward,
                    'balance_after'  => $balanceAfter,
                    'source_surface' => $surface,
                    'description'    => 'Commande #' . ($order->order_serial_no ?? $order->id),
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                // Finalize sentinel with actual points awarded
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['loyalty_points_awarded' => $pointsToAward]);

                // [L2 LOYALTY-SYNC] push the new balance to the bus (after-commit)
                \App\Events\LoyaltyBalanceChanged::dispatch(
                    (int) $user->id,
                    (int) ($order->branch_id ?? 1),
                    $balanceAfter,
                    $pointsToAward,
                    'earn'
                );
            });

            Log::info(sprintf(
                '[Loyalty] +%d points pour %s via %s (commande #%s, total %.2f€)',
                $pointsToAward,
                $user->name,
                $surface,
                $order->order_serial_no ?? $order->id,
                $orderTotal
            ));
        } catch (\Throwable $e) {
            // Never block order status change for loyalty failure.
            try {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('loyalty_points_awarded', -1)
                    ->update(['loyalty_points_awarded' => null]);
            } catch (\Throwable $inner) {
                Log::error('[Loyalty] Failed to revert sentinel: ' . $inner->getMessage());
            }
            Log::error('[Loyalty] AwardLoyaltyPointsOnDelivery: ' . $e->getMessage());
        }
    }
}
