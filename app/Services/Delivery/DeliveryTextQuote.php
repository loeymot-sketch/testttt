<?php

namespace App\Services\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Models\Branch;

/**
 * Devis de livraison pour une adresse TAPÉE : le serveur la géocode, mesure la distance depuis le restaurant, applique
 * le barème et propose les créneaux. Une seule implémentation, deux portes : l'API publique (site / application,
 * limitée par adresse IP) et celle de la caisse (authentifiée, limitée par utilisateur — la caisse ne doit pas
 * partager le quota d'une IP publique).
 */
class DeliveryTextQuote
{
    public function __construct(
        private readonly DeliveryAvailability $availability,
        private readonly AddressGeocoder $geocoder,
        private readonly DeliveryTariff $tariff,
        private readonly DeliveryQuoteService $quotes,
        private readonly DeliveryWaveService $waves,
    ) {
    }

    /** @throws GeocodeUnavailableException adresse introuvable, trop vague ou fournisseur en panne */
    public function build(string $text): array
    {
        if (! $this->availability->isEnabled()) {
            return ['status' => true, 'enabled' => false, 'allowed' => false, 'reason' => 'suspended', 'suggest_uber_eats' => true];
        }

        $branch = Branch::query()->orderBy('id')->first();
        if (! $branch) {
            throw new GeocodeUnavailableException();
        }

        $geo = $this->geocoder->geocode($text);
        $distance = $this->quotes->distanceFromBranch($branch, $geo['latitude'], $geo['longitude']);
        $tariff = $this->tariff->quote($distance, $geo['city'], $geo['postal_code']);

        return [
            'status'            => true,
            'enabled'           => true,
            'allowed'           => $tariff['allowed'],
            'fee'               => $tariff['fee'],
            'tier'              => $tariff['tier'],
            'suggest_uber_eats' => $tariff['suggest_uber_eats'],
            'address'           => ['label' => $geo['label'], 'street' => $geo['street'], 'city' => $geo['city'], 'postal_code' => $geo['postal_code']],
            'distance_km'       => round($distance, 2),
            // [2026-10-24] Préparation annoncée (config/delivery.php 'prep_minutes') : « Préparation : 15 à 20 min ».
            'prep_minutes_low'  => (int) config('delivery.prep_minutes.low', 15),
            'prep_minutes_high' => (int) config('delivery.prep_minutes.high', 20),
            'travel_minutes'    => $tariff['allowed'] ? $this->quotes->travelMinutes($distance) : null,
            'waves'             => $tariff['allowed'] ? $this->waves->availableWaves((int) $branch->id, null, 10) : [],
        ];
    }
}
