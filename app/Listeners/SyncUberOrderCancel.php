<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\Uber\UberClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-BASIC-PROD 2026-08-02] « Cancel Order » sortant — quand la caisse annule une commande
 * Uber (CANCELED côté FoodKing), on le signale à Uber via POST /v1/delivery/order/{id}/cancel
 * pour que le client soit prévenu/remboursé et que le coursier soit rappelé.
 * Garde anti-écho : les annulations ORIGINAIRES d'Uber (webhook orders.cancel) posent le
 * marqueur cache `uber.cancel_origin.<order_id>` et ne sont PAS renvoyées.
 */
class SyncUberOrderCancel implements ShouldQueue
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        if ((int) $event->newStatus !== OrderStatus::CANCELED) {
            return;
        }
        $surface = strtolower((string) ($order->source_surface ?? ''));
        if (! in_array($surface, ['uber', 'uber_eats', 'ubereats'], true)) {
            return;
        }
        if (Cache::pull('uber.cancel_origin.' . $order->id)) {
            return; // annulation initiée par Uber — ne pas boucler.
        }
        $uberOrderId = (string) str_replace('uber:', '', (string) ($order->transaction_id ?? ''));
        if ($uberOrderId === '' || (string) config('uber.client_id') === '') {
            return;
        }

        // [UBER-VALIDATION 2026-08-02] cancellation_reason objet REQUIS (schéma sourcé :
        // {type Required, info opt}). Type concret, jamais OTHER/UNKNOWN (règle Uber < 10 %).
        $ok = app(UberClient::class)->cancelOrder($uberOrderId, [
            'cancellation_reason' => [
                'type' => 'ITEM_ISSUE',
                'info' => 'Canceled by the restaurant POS (Le Cayenne)',
            ],
        ]);
        Log::info('[Uber] cancel sortant signalé', ['uber_order' => $uberOrderId, 'ok' => $ok]);
    }
}
