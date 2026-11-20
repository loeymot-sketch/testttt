<?php

namespace App\Services\Delivery;

/**
 * Barème de livraison du Cayenne — fonction PURE (aucune I/O), source de vérité du prix.
 *
 * Entrée : distance à vol d'oiseau depuis le restaurant (km) + commune/code postal de l'adresse.
 * Sortie : montant en centimes (jamais de flottant monétaire), palier, et si la livraison est
 * possible. Au-delà du rayon maximal on ne facture pas : on REFUSE et on propose Uber Eats.
 *
 * Les montants et rayons viennent de `config/delivery.php` — aucun tarif en dur ici.
 */
class DeliveryTariff
{
    /**
     * @return array{allowed: bool, fee_cents: int, fee: float, tier: string, suggest_uber_eats: bool, reason: string}
     */
    public function quote(mixed $distanceKm, ?string $city, ?string $postalCode): array
    {
        if ($this->isHomeCity($city, $postalCode)) {
            return $this->result(true, $this->cents((float) config('delivery.home_fee')), 'home', false, 'Commune du restaurant : forfait unique.');
        }

        $km = is_numeric($distanceKm) ? (float) $distanceKm : null;
        if ($km === null || ! is_finite($km) || $km < 0) {
            return $this->result(false, 0, 'invalid', false, 'Distance illisible : adresse non vérifiée.');
        }

        $maxKm = (float) config('delivery.max_km', 10);
        if ($km > $maxKm) {
            return $this->result(false, 0, 'outside', true, "Au-delà de {$maxKm} km : livraison non proposée.");
        }

        foreach ($this->tiers() as $i => $tier) {
            if ($km <= (float) $tier['max_km']) {
                return $this->result(true, $this->cents((float) $tier['fee']), 'radius_' . ($i + 1), false, "Rayon ≤ {$tier['max_km']} km.");
            }
        }

        // Rayon max configuré plus large que le dernier palier : jamais de prix inventé.
        return $this->result(false, 0, 'outside', true, 'Aucun palier ne couvre cette distance.');
    }

    public function isHomeCity(?string $city, ?string $postalCode): bool
    {
        $postal = preg_replace('/\s+/', '', (string) $postalCode);
        if ($postal !== '' && in_array($postal, (array) config('delivery.home_postal_codes', []), true)) {
            return true;
        }

        $normalized = $this->normalize((string) $city);

        return $normalized !== '' && $normalized === $this->normalize((string) config('delivery.home_city'));
    }

    /** « Hénin–Beaumont », « HENIN BEAUMONT » et « henin-beaumont » donnent la même clé. */
    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = strtr($name, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', '–' => '-', '—' => '-', '’' => "'",
        ]);

        return trim((string) preg_replace('/[\s\-\']+/', ' ', $name));
    }

    /** @return array<int, array{max_km: float|int, fee: float|int}> */
    private function tiers(): array
    {
        $tiers = (array) config('delivery.tiers', []);
        usort($tiers, fn ($a, $b) => $a['max_km'] <=> $b['max_km']);

        return $tiers;
    }

    private function cents(float $euros): int
    {
        return (int) round($euros * 100);
    }

    private function result(bool $allowed, int $cents, string $tier, bool $suggestUberEats, string $reason): array
    {
        return [
            'allowed'           => $allowed,
            'fee_cents'         => $cents,
            'fee'               => round($cents / 100, 2),
            'tier'              => $tier,
            'suggest_uber_eats' => $suggestUberEats,
            'reason'            => $reason,
        ];
    }
}
