<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Services\Menu\AvailabilityService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [F-01] Compensating release of branch-scoped stock counters when an order
 * is canceled. Idempotent via the {@see AvailabilityService::releaseForOrderItems}
 * `released_qty` ledger.
 *
 * [GOAL-I2-HEAL-01 2026-05-24] Phase I.4 RED I4-CONCERN-01 — defense-in-depth.
 * Currently LAST in OrderCanceled cascade (EventServiceProvider:167-170), so a
 * Throwable here doesn't skip any sibling today. We still wrap to (a) mirror the
 * isolation policy now applied to ReleaseStockOnOrderCanceled (which dropped
 * its re-throw in this same heal), and (b) prevent a future reorder from
 * silently reintroducing the cascade-halt class of bug.
 */
class ReleaseAvailabilityOnOrderCanceled
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function handle(OrderCanceled $event): void
    {
        try {
            $order = $event->order;
            if (! method_exists($order, 'orderItems')) {
                return;
            }

            // [DEEP-R2 2026-07-15 / P2 temps-minuit] Le quota (daily_consumed_qty) est
            // JOURNALIER : annuler une commande d'un jour PRÉCÉDENT ne doit pas créditer
            // le compteur du jour courant (sa consommation appartient à un compteur déjà
            // remis à zéro) — sinon sur-vente du quota d'aujourd'hui.
            if ($order->created_at !== null && ! $order->created_at->isToday()) {
                return;
            }

            $order->loadMissing('orderItems');

            $lineItems = $order->orderItems->map(static function ($orderItem): array {
                return [
                    'order_item_id' => (int) $orderItem->id,
                    'item_id'       => (int) $orderItem->item_id,
                    'branch_id'     => (int) $orderItem->branch_id,
                    'qty'           => (int) $orderItem->quantity,
                ];
            })->all();

            if ($lineItems === []) {
                return;
            }

            $this->availabilityService->releaseForOrderItems($lineItems);
        } catch (Throwable $e) {
            Log::error('ReleaseAvailabilityOnOrderCanceled isolated (cascade continues)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
