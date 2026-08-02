<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\Uber\UberClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-BASIC-PROD 2026-08-02] « Mark Order Ready for Pickup » — quand la cuisine passe une
 * commande Uber à PRÊTE (KDS/caisse → OrderStatus::PREPARED), on le signale à Uber via
 * POST /v1/delivery/order/{id}/ready : si le coursier n'est pas encore dispatché, ça
 * déclenche le dispatch immédiat ; sinon ça affine les prédictions de prep time.
 * Best-effort en queue : un échec ne bloque JAMAIS le flux cuisine (log-only).
 */
class NotifyUberOrderReady implements ShouldQueue
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        if ((int) $event->newStatus !== OrderStatus::PREPARED) {
            return;
        }
        $surface = strtolower((string) ($order->source_surface ?? ''));
        if (! in_array($surface, ['uber', 'uber_eats', 'ubereats'], true)) {
            return;
        }
        $uberOrderId = (string) str_replace('uber:', '', (string) ($order->transaction_id ?? ''));
        if ($uberOrderId === '' || (string) config('uber.client_id') === '') {
            return;
        }

        $ok = app(UberClient::class)->readyOrder($uberOrderId);
        Log::info('[Uber] order ready signalé', ['uber_order' => $uberOrderId, 'ok' => $ok]);
    }
}
