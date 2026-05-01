<?php

namespace App\Services\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Models\Address;
use App\Models\Branch;
use Illuminate\Support\Facades\Log;

class DeliveryQuoteService
{
    public function __construct(private readonly DeliveryFeeService $deliveryFeeService)
    {
    }

    public function quoteForSavedAddress(int $branchId, int $addressId, int $userId): array
    {
        $address = Address::query()
            ->where('id', $addressId)
            ->where('user_id', $userId)
            ->first();

        if (! $address) {
            $this->logFailure($branchId, ['address_id' => $addressId], 'address_not_found_or_not_owned');
            throw new GeocodeUnavailableException();
        }

        return $this->quoteForAddress($branchId, $address->toArray());
    }

    public function quoteForAddress(int $branchId, array $address): array
    {
        $status = strtoupper((string) ($address['geocode_status'] ?? 'OK'));
        if ($status !== 'OK') {
            $this->logFailure($branchId, $address, 'geocode_status_' . strtolower($status));
            throw new GeocodeUnavailableException();
        }

        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            $this->logFailure($branchId, $address, 'branch_not_found');
            throw new GeocodeUnavailableException();
        }

        $addressLat = $this->coordinate($address['latitude'] ?? null, -90, 90);
        $addressLng = $this->coordinate($address['longitude'] ?? null, -180, 180);
        $branchLat = $this->coordinate($branch->latitude, -90, 90);
        $branchLng = $this->coordinate($branch->longitude, -180, 180);

        if ($addressLat === null || $addressLng === null || $branchLat === null || $branchLng === null) {
            $this->logFailure($branchId, $address, 'missing_or_invalid_coordinates');
            throw new GeocodeUnavailableException();
        }

        $distanceKm = $this->distanceKm($addressLat, $addressLng, $branchLat, $branchLng);
        if (! is_finite($distanceKm) || $distanceKm < 0) {
            $this->logFailure($branchId, $address, 'invalid_distance');
            throw new GeocodeUnavailableException();
        }

        return [
            'distance_km' => round($distanceKm, 3),
            'delivery_charge' => $this->deliveryFeeService->fromDistanceKm($distanceKm),
        ];
    }

    private function coordinate(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        if (! is_finite($coordinate) || $coordinate < $min || $coordinate > $max) {
            return null;
        }

        return $coordinate;
    }

    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function logFailure(int $branchId, array $address, string $reason): void
    {
        Log::warning('delivery.geocode.fail', [
            'branch_id' => $branchId,
            'address_id' => $address['id'] ?? null,
            'reason' => $reason,
        ]);
    }
}
