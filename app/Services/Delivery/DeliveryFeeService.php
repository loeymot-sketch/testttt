<?php

namespace App\Services\Delivery;

use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Models\Branch;

class DeliveryFeeService
{
    public function __construct(private readonly ?DeliveryTariff $tariff = null)
    {
    }

    /**
     * Frais de livraison (euros) depuis une distance à vol d'oiseau et la commune d'arrivée.
     *
     * [LIVRAISON 2026-10-20 · décision propriétaire] Le barème n'est plus une formule par km portée
     * par les colonnes de la branche : c'est `DeliveryTariff` (config/delivery.php) — Hénin-Beaumont
     * 3,90 €, puis 4,90 / 5,90 / 6,90 € par rayon de 5 / 8 / 10 km, refus au-delà. Les colonnes
     * `branches.delivery_fee_*` restent en base mais ne pilotent plus le prix.
     *
     * @throws OutsideDeliveryZoneException au-delà du rayon maximal (ou distance illisible)
     */
    public function fromDistanceKm(mixed $distanceKm, ?Branch $branch = null, ?string $city = null, ?string $postalCode = null): float
    {
        $quote = ($this->tariff ?? new DeliveryTariff())->quote($distanceKm, $city, $postalCode);
        if (! $quote['allowed']) {
            throw new OutsideDeliveryZoneException(null, $quote['suggest_uber_eats']);
        }

        return $quote['fee'];
    }
}
