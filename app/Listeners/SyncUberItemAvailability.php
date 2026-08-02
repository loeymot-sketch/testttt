<?php

namespace App\Listeners;

use App\Events\ItemAvailabilityChanged;
use App\Services\Uber\UberClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-BASIC-PROD 2026-08-02] « Mark Item as Out of Stock » — synchronise le 86 (rupture)
 * vers Uber Eats. Quand un item passe indisponible (rupture caisse/KDS/auto-86) on le
 * suspend sur le menu Uber (POST /menus/items/{id} suspension_info) ; quand il revient,
 * on lève la suspension. IDs alignés sur UberMenuBuilder ("item-<id interne>").
 * Best-effort en queue — jamais bloquant pour le flux caisse/stock.
 */
class SyncUberItemAvailability implements ShouldQueue
{
    public function handle(ItemAvailabilityChanged $event): void
    {
        if ((string) config('uber.client_id') === '' || (string) config('uber.store_id') === '') {
            return;
        }

        // Disponibilité effective : mode branch (86) → isAvailable ; mode global → status actif.
        $available = $event->isAvailable ?? ((int) $event->status === \App\Enums\Status::ACTIVE);

        $body = [
            'suspension_info' => [
                'suspension' => [
                    // suspend_until loin dans le futur = suspendu ; 0 = actif (levée de suspension).
                    'suspend_until' => $available ? 0 : now()->addYears(10)->timestamp,
                ],
            ],
        ];

        $ok = app(UberClient::class)->updateMenuItem('item-' . $event->itemId, $body);
        Log::info('[Uber] item 86 sync', ['item' => $event->itemId, 'available' => $available, 'ok' => $ok]);
    }
}
