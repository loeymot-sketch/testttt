<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Models\StockOutflow;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReleaseStockOnOrderCanceled
{
    public function handle(OrderCanceled $event): void
    {
        // [LOCK-OSM-CANCEL-AFTER-READY 2026-08-19] Marchandise DÉJÀ transformée : on ne
        // restitue RIEN. Le plat que la cuisine a déclaré prêt est à la poubelle, pas au
        // frigo — le rendre ferait remonter `on_hand` et RÉ-OUVRIRAIT la disponibilité,
        // donc la caisse et la borne proposeraient un produit qui n'existe plus (le
        // « faux disponible » que ce projet a déjà chassé dans l'autre sens).
        // Preuve terrain, commande réelle #6598 : `delta=-1 order_created` à 08:50 puis
        // `delta=+1 order_canceled` à 09:41 — 51 minutes APRÈS le bip « Prêt ».
        // Voir OrderCanceled::materialAlreadyCommitted() pour le seuil exact.
        //
        // Le stock reste donc décrémenté, ce qui est physiquement juste, et la perte est
        // inscrite explicitement dans le module de sorties hors-vente du projet — sinon
        // l'annulation ne laisserait AUCUNE trace dans le grand-livre de stock.
        if ($event->materialAlreadyCommitted()) {
            $this->recordWaste($event);

            return;
        }

        try {
            app(StockService::class)->releaseForOrder($event->order, 'order_canceled');
        } catch (Throwable $e) {
            // [GOAL-I2-HEAL-01 2026-05-24] Phase I.4 RED I4-CONCERN-01 — drop re-throw.
            //
            // Stale iter13 reasoning (Cohérence DecrementStockOnOrderCreated +
            // OrderService::changeStatus try/catch) was load-bearing-wrong : OrderCanceled
            // uses DispatchableAfterCommit, so listeners run AFTER the outer transaction
            // commits. Re-throwing :
            //   - rolls back nothing (commit done),
            //   - halts Laravel's sync dispatcher (vendor/.../Events/Dispatcher.php:233-269)
            //     → next listener ReleaseAvailabilityOnOrderCanceled NEVER runs,
            //   - divergent stock vs availability ledgers on failure.
            //
            // Mirror DecrementStockOnOrderCreated (post-WG-2) : isolate to Log::error +
            // return. Sibling availability release listener is now guaranteed to fire.
            // StockDecrementFailedEvent is NOT used here — its PHPDoc nails it to the
            // OrderCreated cascade; using it for OrderCanceled would conflate alerts.
            // V1.0.2 backlog : introduce StockReleaseFailedEvent typed hook if ops
            // alerting needs to discriminate release-failures from decrement-failures.
            Log::error('ReleaseStockOnOrderCanceled isolated (cascade continues)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * [LOCK-OSM-CANCEL-AFTER-READY 2026-08-19] Inscrit la PERTE d'une commande annulée
     * après que la cuisine l'a déclarée prête.
     *
     * Le stock n'est pas restitué (la marchandise est détruite), mais l'annulation doit
     * rester lisible dans le grand-livre : sans cette ligne, la sortie n'aurait AUCUNE
     * contrepartie et le propriétaire ne pourrait pas chiffrer ce que lui coûtent les
     * commandes abandonnées.
     *
     * `stock_decremented = true` : la décrémentation a déjà eu lieu à la CRÉATION de la
     * commande, cette ligne n'est qu'une trace — elle ne doit surtout pas décrémenter
     * une seconde fois.
     *
     * Isolé comme le reste de la cascade : une perte non inscrite ne doit jamais faire
     * échouer une annulation.
     */
    private function recordWaste(OrderCanceled $event): void
    {
        try {
            $order = $event->order;
            $items = $order->orderItems ?? collect();

            foreach ($items as $line) {
                $quantity = (int) ($line->quantity ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                StockOutflow::create([
                    'branch_id'         => (int) ($order->branch_id ?? 0),
                    'item_id'           => (int) ($line->item_id ?? 0),
                    'item_name'         => (string) ($line->name ?? optional($line->orderItem)->name ?? ''),
                    'quantity'          => $quantity,
                    'type'              => StockOutflow::TYPE_WASTE,
                    'note'              => 'Commande #'.($order->order_serial_no ?? $order->id).' annulée après préparation',
                    'user_id'           => auth()->id(),
                    'stock_decremented' => true,
                    'created_at'        => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('[LOCK-OSM-CANCEL-AFTER-READY] Trace de perte non inscrite (annulation préservée)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
