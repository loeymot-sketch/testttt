<?php

namespace App\Services\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Géocodage SERVEUR par la Base Adresse Nationale (service public français, sans clé).
 *
 * Pourquoi côté serveur : les coordonnées envoyées par le navigateur sont forgeables — un
 * client à 9 km pouvait poser lat/lng ≈ restaurant. Ici le serveur décide de la position ET de
 * la commune (qui détermine le forfait Hénin-Beaumont). Panne du fournisseur = on REFUSE
 * (fail-closed) : mieux vaut une livraison momentanément indisponible qu'un prix faux.
 */
class AddressGeocoder
{
    /**
     * Suggestions pour l'autocomplétion (aucune décision de prix n'en dépend).
     *
     * @return array<int, array{label: string, street: string, postal_code: string, city: string, latitude: float, longitude: float, score: float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }

        try {
            return $this->fetch($query, $limit, true);
        } catch (GeocodeUnavailableException) {
            return [];
        }
    }

    /**
     * Meilleure correspondance FIABLE pour une adresse saisie.
     *
     * @return array{label: string, street: string, postal_code: string, city: string, latitude: float, longitude: float, score: float}
     * @throws GeocodeUnavailableException
     */
    public function geocode(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new GeocodeUnavailableException();
        }

        $key = 'delivery.geocode.' . sha1($this->fold($text));

        $hit = Cache::remember($key, now()->addMinutes((int) config('delivery.geocoder.cache_minutes', 1440)), function () use ($text) {
            try {
                $results = $this->fetch($text, 1, false);
            } catch (GeocodeUnavailableException) {
                return null; // pas de mise en cache d'une panne : Cache::remember ne stocke pas null
            }

            $best = $results[0] ?? null;
            // [relecture adverse P1] « Hénin-Beaumont » seul se géocode au centre de la commune (score 0,95) : ce
            // n'est pas une adresse livrable. On exige au moins une rue — le client doit préciser.
            if ($best && ($best['type'] ?? '') === 'municipality') {
                return null;
            }

            return $best;
        });

        if (! is_array($hit)) {
            throw new GeocodeUnavailableException();
        }

        return $hit;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(string $query, int $limit, bool $autocomplete): array
    {
        $cfg = (array) config('delivery.geocoder');
        $minScore = $autocomplete ? 0.0 : (float) ($cfg['min_score'] ?? 0.55);

        try {
            $response = Http::timeout((int) ($cfg['timeout_seconds'] ?? 5))
                ->acceptJson()
                ->get(rtrim((string) $cfg['base_url'], '/') . '/search/', [
                    'q'            => $query,
                    'limit'        => max(1, min(10, $limit)),
                    'autocomplete' => $autocomplete ? 1 : 0,
                    // Indice de proximité : classe d'abord les adresses autour du restaurant.
                    'lat'          => $cfg['bias_lat'] ?? null,
                    'lon'          => $cfg['bias_lon'] ?? null,
                ]);
        } catch (\Throwable $e) {
            Log::warning('delivery.geocoder.unreachable', ['error' => $e->getMessage()]);
            throw new GeocodeUnavailableException();
        }

        if (! $response->successful()) {
            Log::warning('delivery.geocoder.http_error', ['status' => $response->status()]);
            throw new GeocodeUnavailableException();
        }

        $out = [];
        foreach ((array) $response->json('features', []) as $feature) {
            $p = $feature['properties'] ?? [];
            $c = $feature['geometry']['coordinates'] ?? null;
            $score = (float) ($p['score'] ?? 0);
            if (! is_array($c) || count($c) < 2 || ! is_numeric($c[0]) || ! is_numeric($c[1]) || $score < $minScore) {
                continue;
            }
            $out[] = [
                'label'       => (string) ($p['label'] ?? ''),
                'street'      => trim(($p['housenumber'] ?? '') . ' ' . ($p['street'] ?? $p['name'] ?? '')),
                'postal_code' => (string) ($p['postcode'] ?? ''),
                'city'        => (string) ($p['city'] ?? ''),
                'latitude'    => (float) $c[1],
                'longitude'   => (float) $c[0],
                'score'       => $score,
                // housenumber | street | locality | municipality — « municipality » = la commune seule, pas une adresse.
                'type'        => (string) ($p['type'] ?? ''),
            ];
        }

        if ($out === [] && ! $autocomplete) {
            throw new GeocodeUnavailableException();
        }

        return $out;
    }

    private function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[\s\-,]+/', ' ', $text));
    }
}
