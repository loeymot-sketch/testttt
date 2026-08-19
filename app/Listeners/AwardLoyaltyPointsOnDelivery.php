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

        // [AUDIT-P50-BUG10 · P1 RED-CUMUL 2026-08-04] Never award points for a TERMINAL order.
        // Étendu CANCELED → [CANCELED, REJECTED, RETURNED] : un event DELIVERED différé (bump
        // cuisine dispatché après-commit) arrivant APRÈS un remboursement (RETURNED) ou un rejet
        // (REJECTED) créditait quand même 300 pts sur une commande remboursée, clawback déjà passé.
        $currentStatus = (int) ($order->status ?? $event->newStatus ?? -1);
        if (in_array($currentStatus, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
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

        $this->award($order);
    }

    /**
     * [FIDÉLITÉ 2026-08-19] LE CRÉDIT LUI-MÊME, APPELABLE SANS ÉVÉNEMENT.
     *
     * ── POURQUOI CETTE MÉTHODE A ÉTÉ DÉTACHÉE ────────────────────────────────────────────────
     * Le crédit ne se déclenchait que sur un CHANGEMENT DE STATUT. Or une vente de comptoir naît
     * déjà au statut « en préparation » : aucun changement n'a lieu, donc aucun crédit. Le client
     * paie, s'en va, et n'obtient ses points QUE si la cuisine bumpe sa commande — ce qui
     * n'arrive jamais pour une boisson ou un produit sans étape cuisine. Mesuré sur la base
     * réelle le 2026-08-19 : **307 ventes de caisse immobilisées à ce statut**, dont aucune n'a
     * pu créditer qui que ce soit.
     *
     * Attendre la cuisine pour récompenser un achat est un héritage du modèle « livraison ». Au
     * comptoir, le fait générateur est le PAIEMENT : l'argent est dans le tiroir, la vente est
     * scellée fiscalement. `OrderService` appelle donc cette méthode dès qu'une vente de caisse
     * est payée.
     *
     * C'est sûr parce que l'annulation reprend ce qui a été donné : `clawbackEarnedPoints`
     * (points gagnés) et `refundPoints` (points dépensés) sont tous deux câblés sur le chemin
     * d'annulation. Créditer tôt n'est donc pas créditer à l'aveugle.
     *
     * L'idempotence est portée par la sentinelle atomique `orders.loyalty_points_awarded` : un
     * bump cuisine ultérieur, ou un second appel, ne crédite pas une deuxième fois.
     *
     * @param \App\Models\Order|\App\Models\FrontendOrder $order
     */
    public function award($order): void
    {
        // Re-contrôle défensif : cette méthode est désormais appelable hors événement, elle ne
        // peut donc plus supposer que l'appelant a déjà écarté les états terminaux.
        $statutCourant = (int) ($order->status ?? -1);
        if (in_array($statutCourant, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
            return;
        }

        $isKiosk = in_array((int) ($order->order_type ?? 0), [OrderType::KIOSK, OrderType::TAKEAWAY], true);

        // Atomic idempotency: only one concurrent process can claim the sentinel.
        // FrontendOrder::$table = "orders" — physical table is always "orders".
        // [AUDIT-P50-BUG10] Also verify order is not cancelled at the moment of awarding
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->whereNull('loyalty_points_awarded')
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
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
            $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_per_euro', 10);
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
            $pointsToAward = (int) floor($orderTotal * $rate);

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
