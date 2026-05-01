<?php

namespace App\Services\Delivery;

class DeliveryFeeService
{
    public function fromDistanceKm(mixed $distanceKm): float
    {
        $distance = is_numeric($distanceKm) ? (float) $distanceKm : null;
        if ($distance === null || ! is_finite($distance) || $distance < 0) {
            return 0.0;
        }

        return (float) max(5, (int) ceil($distance / 5) * 5);
    }
}
