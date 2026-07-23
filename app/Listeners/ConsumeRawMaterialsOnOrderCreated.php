<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Order;
use App\Services\RawMaterials\RawMaterialConsumptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2a — B3] Décrémente le stock matière
 * THÉORIQUE à la création d'une commande.
 *
 * `ShouldQueue` (queue 'default', worker permanent) : la consommation matière
 * ne doit JAMAIS ralentir ni casser le chemin de création de commande — elle
 * s'exécute hors-ligne. En complément, `handle()` isole toute exception
 * (try/catch → log) : un souci matière n'impacte jamais la vente ni les autres
 * listeners du cascade OrderCreated (mirror de la politique WG-2
 * DecrementStockOnOrderCreated).
 *
 * Idempotent par construction : {@see RawMaterialConsumptionService} déduplique
 * par (order_item, matière) — un retry de job ne double pas la consommation.
 *
 * NF525 : ne touche PAS la chaîne fiscale (lecture des snapshots seulement).
 */
class ConsumeRawMaterialsOnOrderCreated implements ShouldQueue
{
    /** File dédiée (worker permanent). */
    public string $queue = 'default';

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        // Le contrat de l'event porte un BroadcastableOrder ; la consommation
        // matière n'a de sens que sur un vrai Order Eloquent.
        if (! $order instanceof Order) {
            return;
        }

        try {
            app(RawMaterialConsumptionService::class)->consumeForOrder($order);
        } catch (Throwable $e) {
            // Isolation : le stock matière ne casse jamais la commande.
            Log::error('[RawMaterialConsumption] consumeForOrder isolé', [
                'order_id' => $order->id ?? null,
                'branch_id' => $order->branch_id ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
