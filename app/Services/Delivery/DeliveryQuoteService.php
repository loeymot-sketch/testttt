<?php

namespace App\Services\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Models\Address;
use App\Models\Branch;
use Illuminate\Support\Facades\Log;

class DeliveryQuoteService
{
    public function __construct(private readonly DeliveryTariff $tariff)
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

    /**
     * Devis pour la CAISSE : l'adresse appartient au client de la commande (`customer_id`), pas au
     * caissier connecté. Branche = celle du payload, sinon celle du caissier, sinon la première.
     *
     * @return array<string, mixed>
     */
    public function quoteForPos(?int $payloadBranchId, ?int $cashierBranchId, int $addressId, int $customerId): array
    {
        $branchId = $payloadBranchId > 0 ? (int) $payloadBranchId : ($cashierBranchId > 0 ? (int) $cashierBranchId : (int) Branch::query()->orderBy('id')->value('id'));

        return $this->quoteForSavedAddress($branchId, $addressId, $customerId);
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
        // [LIVRAISON 2026-10-20] Ce polygone (`branches.zone`) datait d'AVANT le géocodage serveur : il servait à contrer
        // des coordonnées forgées par le navigateur. Les coordonnées viennent désormais du serveur, et la ZONE de livraison
        // est celle du propriétaire : Hénin-Beaumont + rayon de 10 km (DeliveryTariff). Laisser le polygone actif
        // contredisait la règle — constaté en navigateur : l'adresse du restaurant elle-même était refusée (polygone de
        // test sur Paris), et même le polygone du seeder (boîte ≈ 6 × 5 km) aurait refusé Oignies, pourtant dans le rayon.
        // Désactivé par défaut ; réactivable explicitement (`delivery.use_branch_zone_polygon`) pour une zone non circulaire.
        if (config('delivery.use_branch_zone_polygon', false) && ! $this->isInsideBranchZone($branch, $addressLat, $addressLng)) {
            $this->logFailure($branchId, $address, 'outside_delivery_zone');
            throw new OutsideDeliveryZoneException();
        }

        // [LIVRAISON 2026-10-20 · relecture adverse P1] Une adresse ENREGISTRÉE AVANT le géocodage serveur n'a ni
        // commune ni code postal, et ses coordonnées venaient du navigateur (ou d'un pin « cosmétique » posé sur
        // le restaurant) : la facturer revient à croire un client. Elle doit être ressaisie (donc re-géocodée).
        if (config('delivery.geocoder.enabled', true) && blank($address['city'] ?? null) && blank($address['postal_code'] ?? null)) {
            $this->logFailure($branchId, $address, 'address_not_server_verified');
            throw new GeocodeUnavailableException();
        }

        // [LIVRAISON 2026-10-20] Le prix vient du barème propriétaire (commune + rayon), calculé sur
        // la commune STRUCTURÉE posée par le géocodeur serveur — jamais sur du texte libre client.
        $tariff = $this->tariff->quote($distanceKm, $address['city'] ?? null, $address['postal_code'] ?? null);
        if (! $tariff['allowed']) {
            $this->logFailure($branchId, $address, 'beyond_radius_' . $tariff['tier']);
            throw new OutsideDeliveryZoneException(null, $tariff['suggest_uber_eats']);
        }

        return [
            'distance_km'       => round($distanceKm, 3),
            'delivery_charge'   => $tariff['fee'],
            'delivery_tier'     => $tariff['tier'],
            'city'              => $address['city'] ?? null,
            'postal_code'       => $address['postal_code'] ?? null,
            'travel_minutes'    => $this->travelMinutes($distanceKm),
            'suggest_uber_eats' => false,
        ];
    }

    /** Distance à vol d'oiseau restaurant → point (km). */
    public function distanceFromBranch(Branch $branch, float $lat, float $lng): float
    {
        $bLat = $this->coordinate($branch->latitude, -90, 90);
        $bLng = $this->coordinate($branch->longitude, -180, 180);
        if ($bLat === null || $bLng === null) {
            throw new GeocodeUnavailableException();
        }

        return $this->distanceKm($bLat, $bLng, $lat, $lng);
    }

    /** Estimation de trajet (client) : distance / vitesse urbaine + marge fixe. La vraie durée = Waze côté livreur. */
    public function travelMinutes(float $distanceKm): int
    {
        $speed = max(5.0, (float) config('delivery.average_speed_kmh', 28));

        // Distance à vol d'oiseau × 1,3 ≈ distance routière urbaine.
        return (int) ceil(($distanceKm * 1.3 / $speed) * 60 + (float) config('delivery.travel_buffer_minutes', 3));
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
