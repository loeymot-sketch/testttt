<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Models\Order;
use App\Services\RawMaterials\RawMaterialConsumptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM / B-1 2026-07-23] REND le stock matière THÉORIQUE
 * quand une commande atteint un statut terminal ANNULANT (CANCELED / REJECTED /
 * RETURNED). Miroir raw-material de {@see ReleaseStockOnOrderCanceled} (qui rend
 * les compteurs stock_levels sur le MÊME event) : les deux vérités stock restent
 * cohérentes sur les annulées.
 *
 * Pourquoi OrderCanceled (et pas OrderStatusChanged) : `OrderCanceled` est
 * l'event compensatoire DÉDIÉ, dispatché aux points d'annulation pour EXACTEMENT
 * {CANCELED, REJECTED, RETURNED} — le filtre `in_array($targetStatus, [...])`
 * est déjà encodé au dispatch (OrderService.php:2536, PaymentService, webhooks,
 * cleanup). L'écouter mutualise ce filtre, colle au périmètre du replay
 * (EXCLUDED_STATUSES) et reste cohérent avec la libération stock_levels
 * existante — plutôt que de ré-implémenter le filtre sur OrderStatusChanged.
 *
 * `ShouldQueue` (worker permanent, symétrique de {@see ConsumeRawMaterialsOnOrderCreated}) :
 * la reprise matière ne doit jamais ralentir ni casser l'annulation. `handle()`
 * isole toute exception (try/catch → log) : un souci matière n'impacte NI
 * l'annulation NI les autres listeners du cascade OrderCanceled.
 *
 * Idempotent par construction : {@see RawMaterialConsumptionService::reverseForOrder}
 * ne rend que le consommé et jamais deux fois (source_type de reprise dédié).
 *
 * NF525 : ne touche PAS la chaîne fiscale (lit les mouvements, écrit un rendu
 * hors chaîne). Symétrie du guard `instanceof Order` avec la consommation :
 * seuls les Order Eloquent ont pu être consommés à la création.
 */
class ReverseRawMaterialsOnOrderCanceled implements ShouldQueue
{
    /** File dédiée (worker permanent). */
    public string $queue = 'default';

    public function handle(OrderCanceled $event): void
    {
        $order = $event->order;

        // OrderCanceled porte un Model générique ; la reprise n'a de sens que sur
        // un vrai Order (miroir du guard de ConsumeRawMaterialsOnOrderCreated).
        if (! $order instanceof Order) {
            return;
        }

        try {
            app(RawMaterialConsumptionService::class)->reverseForOrder($order);
        } catch (Throwable $e) {
            // Isolation : le stock matière ne casse jamais l'annulation.
            Log::error('[RawMaterialConsumption] reverseForOrder isolé', [
                'order_id' => $order->id ?? null,
                'branch_id' => $order->branch_id ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
