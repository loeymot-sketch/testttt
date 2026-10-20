<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Models\DeliveryStop;
use App\Services\Delivery\DeliveryWaveService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [LIVRAISON 2026-10-20 · relecture adverse P1] Une commande annulée ou refusée libère sa place dans la tournée
 * (sinon elle grignote `max_stops_per_wave` et traîne dans la liste du livreur). Un arrêt dont le livreur est déjà
 * PARTI n'est jamais supprimé : le livreur est sur la route, la trace du parcours doit rester.
 * Best-effort et idempotent : un échec est journalisé, il ne casse pas l'annulation.
 */
class ReleaseDeliveryStopOnOrderClosed
{
    public function handle(OrderCanceled|OrderStatusChanged $event): void
    {
        if ($event instanceof OrderStatusChanged && ! in_array($event->newStatus, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
            return;
        }

        try {
            $stop = DeliveryStop::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->where('order_id', $event->order->id)->whereNull('left_at')->first();
            if (! $stop) {
                return;
            }
            $branchId = (int) $stop->branch_id;
            $wave = $stop->wave_at;
            $stop->delete();
            app(DeliveryWaveService::class)->resequence($branchId, $wave);
        } catch (Throwable $e) {
            Log::error('delivery.stop.release_failed', ['order_id' => $event->order->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
