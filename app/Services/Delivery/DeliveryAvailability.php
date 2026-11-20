<?php

namespace App\Services\Delivery;

use App\Enums\Activity;
use Smartisan\Settings\Facades\Settings;

/**
 * Interrupteur « livraison active / suspendue » du restaurant (réglage `order_setup_delivery`).
 * Une seule source pour le site, la caisse et les gardes serveur : la caisse peut suspendre la
 * livraison d'un appui et la remettre, sans passer par les Réglages.
 */
class DeliveryAvailability
{
    public function isEnabled(): bool
    {
        return (int) Settings::group('order_setup')->get('order_setup_delivery') === Activity::ENABLE;
    }

    public function set(bool $enabled): void
    {
        Settings::group('order_setup')->set([
            'order_setup_delivery' => $enabled ? Activity::ENABLE : Activity::DISABLE,
        ]);
    }

    /** Barème affichable (jamais utilisé pour facturer : la facturation reste côté DeliveryTariff). */
    public function publicGrid(): array
    {
        return [
            'home_city'  => (string) config('delivery.home_city'),
            'home_fee'   => (float) config('delivery.home_fee'),
            'tiers'      => array_map(fn ($t) => ['max_km' => (float) $t['max_km'], 'fee' => (float) $t['fee']], (array) config('delivery.tiers')),
            'max_km'     => (float) config('delivery.max_km'),
        ];
    }
}
