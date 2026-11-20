<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\UberDirect\UberDirectDispatchService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [E2E UBER 2026-10-24 · U-3] Le coursier Uber a pris le sac (ou a remis la commande) alors que la cuisine n'avait pas encore
 * marqué la commande PRÊTE : le webhook Uber n'a pas touché à la cuisine (consigne propriétaire : deux machines séparées).
 * Dès que la cuisine marque PRÊTE, on applique l'état de la LIVRAISON — en route ou livrée — par la machine officielle.
 */
class SyncUberCourseAfterKitchenReady
{
    public function handle(OrderStatusChanged $event): void
    {
        if ((int) $event->newStatus !== OrderStatus::PREPARED) {
            return;
        }
        try {
            app(UberDirectDispatchService::class)->catchUpAfterKitchenReady($event->order);
        } catch (Throwable $e) {
            Log::error('uber_direct.catch_up_failed', ['order_id' => $event->order->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
