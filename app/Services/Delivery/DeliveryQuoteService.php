<?php

namespace App\Services\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Exceptions\Delivery\OutsideDeliveryZoneException;
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

        // [GOAL ROBUSTESSE 2026-07-27 · durcissement pré-lancement — adversaire P2]
        // Les coords viennent du CLIENT (saveAddress les stocke telles quelles) : sans
        // re-géocodage serveur, un client à 10 km pouvait poser lat/lng ≈ resto pour
        // payer 4 € au lieu de 17 €. Garde ZONE : le point doit être DANS le polygone
        // de livraison de la branche (`branches.zone`, ray-casting). Zone absente/
        // invalide → garde neutre (fail-open : la zone est optionnelle, la livraison
        // reste de toute façon verrouillée serveur jusqu'au lancement). Risque résiduel
        // assumé : manipulation INTRA-zone — à fermer par re-géocodage serveur du texte
        // d'adresse au lancement (choix de provider = décision owner, cf. GOAL §G).
        if (! $this->isInsideBranchZone($branch, $addressLat, $addressLng)) {
            $this->logFailure($branchId, $address, 'outside_delivery_zone');
            throw new OutsideDeliveryZoneException();
        }

        return [
            'distance_km' => round($distanceKm, 3),
            // [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR-Z4-ARCH-01 P0] DEL-5 wire-up.
            // The loaded $branch (line 39) MUST flow into the fee calc so the
            // per-branch delivery_fee_base / per_km / minimum columns added by
            // migration 2026_05_18_100000 actually apply on the customer quote
            // path. Without this argument the branch-aware code path was dead
            // and every customer DELIVERY quote returned the legacy fallback.
            // CLAUDE.md §9 SaaS multi-tenant invariant.
            'delivery_charge' => $this->deliveryFeeService->fromDistanceKm($distanceKm, $branch),
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

    /**
     * Le point est-il dans le polygone de zone de la branche ?
     * Zone absente ou illisible → true (garde neutre — la zone est optionnelle).
     * Tolère le double-encodage historique du seeder (json d'une STRING json).
     */
    private function isInsideBranchZone(Branch $branch, float $lat, float $lng): bool
    {
        $polygon = $this->decodeZonePolygon($branch->zone);
        if ($polygon === null || count($polygon) < 3) {
            return true;
        }

        return $this->pointInPolygon($lat, $lng, $polygon);
    }

    /** @return array<int, array{lat: float, lng: float}>|null */
    private function decodeZonePolygon(mixed $zone): ?array
    {
        if ($zone === null || $zone === '') {
            return null;
        }
        $decoded = is_string($zone) ? json_decode($zone, true) : $zone;
        // Seeder historique : json_encode('[{"lat":…}]') → première passe rend une STRING.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        if (! is_array($decoded)) {
            return null;
        }

        $points = [];
        foreach ($decoded as $point) {
            $lat = $this->coordinate($point['lat'] ?? null, -90, 90);
            $lng = $this->coordinate($point['lng'] ?? null, -180, 180);
            if ($lat === null || $lng === null) {
                return null; // polygone corrompu → garde neutre (pas de faux refus)
            }
            $points[] = ['lat' => $lat, 'lng' => $lng];
        }

        return $points;
    }

    /** Ray-casting standard (pair/impair) — suffisant aux échelles d'une zone de livraison. */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = $polygon[$i]['lat'];
            $lngI = $polygon[$i]['lng'];
            $latJ = $polygon[$j]['lat'];
            $lngJ = $polygon[$j]['lng'];

            $intersects = (($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
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
