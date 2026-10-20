<?php

namespace App\Listeners;

use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Services\Delivery\DeliveryWaveService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * [LIVRAISON 2026-10-20] Toute commande de LIVRAISON entre dans une tournée de 30 minutes dès sa
 * création. Best-effort et isolé : un échec est journalisé et ne casse NI la commande NI les autres
 * écouteurs ; la commande « orpheline » est rattrapée par `delivery:assign-pending`.
 */
class AssignDeliveryWaveOnOrderCreated
{
    public function __construct(private readonly DeliveryWaveService $waves)
    {
    }

    public function handle(OrderCreated $event): void
    {
        try {
            $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($event->order->id);
            if (! $order || (int) $order->order_type !== OrderType::DELIVERY) {
                return;
            }

            // Un `delivery_slot_at` déjà posé AVANT l'attribution = créneau choisi par le client.
            $chosen = $order->delivery_slot_at;
            try {
                $this->waves->assign($order, $chosen);
            } catch (ValidationException $e) {
                // Le créneau choisi n'est plus disponible (complet entre-temps) : on bascule au plus tôt.
                Log::warning('delivery.wave.chosen_slot_lost', ['order_id' => $order->id, 'errors' => $e->errors()]);
                $order->forceFill(['delivery_slot_at' => null])->saveQuietly();
                $this->waves->assign($order->fresh(), null);
            }
        } catch (Throwable $e) {
            Log::error('delivery.wave.assign_failed', ['order_id' => $event->order->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
