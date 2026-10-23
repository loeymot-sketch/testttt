<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Durées de trajet ROUTIÈRES réelles (itinéraire sur le réseau routier, pas des kilomètres à vol d'oiseau).
 *
 * Sert à deux usages distincts, qu'il ne faut pas confondre (décision propriétaire 2026-10-23) :
 *  · CLIENT / prix / site : trajet ALLER SIMPLE (restaurant → adresse). Le prix ne dépend jamais d'ici.
 *  · GESTION LIVREUR : tournée ALLER-RETOUR (restaurant → arrêts → restaurant) = temps réel que le livreur est parti.
 *
 * Fournisseur : OSRM (service public, sans clé). Une panne ou un délai dépassé ne casse JAMAIS rien : on retombe sur
 * l'estimation à vol d'oiseau × 1,3 (la formule historique), et la réponse le DIT (`source = estimate`) pour que l'écran
 * n'affiche jamais une estimation comme un itinéraire réel. Une panne est mémorisée 60 s pour ne pas attendre le délai à
 * chaque appel. Les itinéraires réussis sont mémorisés (le réseau routier ne bouge pas d'une minute à l'autre).
 */
class RoadRouteService
{
    public const SOURCE_ROAD = 'road';
    public const SOURCE_ESTIMATE = 'estimate';
    private const OUTAGE_KEY = 'delivery.route.outage';

    /**
     * @param  list<array{0: float, 1: float}>  $points  [lat, lng] dans l'ordre du parcours
     * @param  bool  $network  false = AUCUN appel réseau : itinéraire déjà en mémoire s'il existe, sinon estimation. Obligatoire
     *                         sur le chemin de création d'une commande (transaction, verrous) : on n'y attend jamais un tiers.
     * @return array{legs: list<array{seconds: float, km: float}>, source: string}
     *         `legs[i]` = trajet points[i] → points[i+1]. `seconds` est la durée de conduite brute, sans marge.
     */
    public function legs(array $points, bool $network = true): array
    {
        $points = array_values($points);
        if (count($points) < 2) {
            return ['legs' => [], 'source' => self::SOURCE_ESTIMATE];
        }

        $road = $this->roadLegs($points, $network);
        if ($road !== null) {
            return ['legs' => $road, 'source' => self::SOURCE_ROAD];
        }

        return ['legs' => $this->estimatedLegs($points), 'source' => self::SOURCE_ESTIMATE];
    }

    /** @return list<array{seconds: float, km: float}>|null */
    private function roadLegs(array $points, bool $network): ?array
    {
        if (! config('delivery.routing.enabled', true)) {
            return null;
        }

        $coords = implode(';', array_map(fn (array $p) => round($p[1], 5) . ',' . round($p[0], 5), $points));
        $key = 'delivery.route.' . sha1($coords);

        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit['legs'] ?? null;
        }
        if ($hit === 'down' || ! $network) {
            return null;
        }
        // Coupe-circuit GLOBAL : une panne du fournisseur est mémorisée pour TOUS les itinéraires pendant 60 s. Sans lui, chaque
        // tournée (clé de cache différente) attendrait son propre délai : N tournées × 3 s à chaque rafraîchissement de la planche.
        if (Cache::get(self::OUTAGE_KEY)) {
            return null;
        }

        $legs = $this->fetch($coords, count($points) - 1);
        if ($legs === null) {
            Cache::put(self::OUTAGE_KEY, true, 60);
            Cache::put($key, 'down', 60);

            return null;
        }
        Cache::put($key, ['legs' => $legs], now()->addMinutes((int) config('delivery.routing.cache_minutes', 360)));

        return $legs;
    }

    /** @return list<array{seconds: float, km: float}>|null */
    private function fetch(string $coords, int $expectedLegs): ?array
    {
        $base = rtrim((string) config('delivery.routing.base_url', 'https://router.project-osrm.org'), '/');
        try {
            $response = Http::timeout((int) config('delivery.routing.timeout_seconds', 3))
                ->acceptJson()
                ->get($base . '/route/v1/driving/' . $coords, ['overview' => 'false', 'steps' => 'false']);
        } catch (\Throwable $e) {
            Log::warning('delivery.route.unavailable', ['reason' => get_class($e)]);

            return null;
        }

        $legs = $response->successful() ? $response->json('routes.0.legs') : null;
        if (! is_array($legs) || count($legs) !== $expectedLegs) {
            Log::warning('delivery.route.unusable', ['status' => $response->status()]);

            return null;
        }

        $out = [];
        foreach ($legs as $leg) {
            $seconds = $leg['duration'] ?? null;
            $meters = $leg['distance'] ?? null;
            if (! is_numeric($seconds) || ! is_numeric($meters) || $seconds < 0 || $meters < 0) {
                return null;
            }
            $out[] = ['seconds' => (float) $seconds, 'km' => (float) $meters / 1000];
        }

        return $out;
    }

    /**
     * Repli : vol d'oiseau × 1,3 (détour urbain moyen) à la vitesse urbaine configurée — la formule historique.
     *
     * @return list<array{seconds: float, km: float}>
     */
    private function estimatedLegs(array $points): array
    {
        $speed = max(5.0, (float) config('delivery.average_speed_kmh', 28));
        $legs = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            $km = $this->crowKm($points[$i], $points[$i + 1]) * 1.3;
            $legs[] = ['seconds' => $km / $speed * 3600, 'km' => $km];
        }

        return $legs;
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    private function crowKm(array $a, array $b): float
    {
        $dLat = deg2rad($b[0] - $a[0]);
        $dLng = deg2rad($b[1] - $a[1]);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}
