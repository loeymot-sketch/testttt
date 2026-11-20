<?php

namespace App\Services\Delivery;

use App\Models\Address;

/**
 * [LIVRAISON 2026-10-20] Point UNIQUE par où passe toute adresse de livraison enregistrée (site,
 * application, caisse) : le serveur la géocode (Base Adresse Nationale) et pose lui-même position,
 * commune et code postal. Les coordonnées envoyées par un navigateur ne sont jamais crues.
 */
class AddressNormalizer
{
    public function __construct(private readonly AddressGeocoder $geocoder)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws \App\Exceptions\Delivery\GeocodeUnavailableException adresse introuvable ou fournisseur en panne
     */
    public function normalize(array $payload): array
    {
        unset($payload['city'], $payload['postal_code']); // jamais fournis par le client

        if (config('delivery.geocoder.enabled', true)) {
            $geo = $this->geocoder->geocode((string) $payload['address']);
            // L'adresse enregistrée = celle que la BAN a comprise et que le serveur a chiffrée (rue, code postal, commune) :
            // le point facturé est ainsi EXACTEMENT celui que reçoit le livreur. Le complément (étage, code) vit dans `apartment`.
            $payload['address']        = mb_substr($geo['label'] !== '' ? $geo['label'] : (string) $payload['address'], 0, 500);
            $payload['latitude']       = (string) $geo['latitude'];
            $payload['longitude']      = (string) $geo['longitude'];
            $payload['city']           = $geo['city'];
            $payload['postal_code']    = $geo['postal_code'];
            $payload['geocode_status'] = 'OK';

            return $payload;
        }

        $payload['geocode_status'] = \App\Services\AddressService::deriveGeocodeStatus($payload['latitude'] ?? null, $payload['longitude'] ?? null);

        return $payload;
    }

    /** « Enregistrée une seule fois » : même point + même complément = même adresse. */
    public function findSamePlace(int $userId, array $payload): ?Address
    {
        if (empty($payload['latitude']) || empty($payload['longitude'])) {
            return null;
        }

        return Address::query()
            ->where('user_id', $userId)
            // Écart absolu (≈ 1 m) plutôt qu'égalité de flottants : identique sous MySQL et SQLite.
            ->whereRaw('ABS(CAST(latitude AS DECIMAL(10,6)) - ?) < 0.00001', [(float) $payload['latitude']])
            ->whereRaw('ABS(CAST(longitude AS DECIMAL(10,6)) - ?) < 0.00001', [(float) $payload['longitude']])
            ->where(function ($q) use ($payload) {
                $apt = trim((string) ($payload['apartment'] ?? ''));
                $apt === '' ? $q->whereNull('apartment')->orWhere('apartment', '') : $q->where('apartment', $apt);
            })
            ->first();
    }
}
