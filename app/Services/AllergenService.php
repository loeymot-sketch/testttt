<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\Item;

class AllergenService
{
    public function projectFlags(Item $item): void
    {
        /*
         * [ONB 2026-08-28] Un tableau VIDE n'est pas une absence d'information.
         *
         * Plus bas, la branche « drapeaux vides » relit le pivot et le recopie dans
         * `allergen_flags`. Elle a une raison d'etre : hydrater les articles anciens
         * dont les drapeaux JSON n'ont jamais ete remplis alors que le pivot l'etait.
         * Mais elle confondait deux etats opposes :
         *
         *     null  -> « personne ne s'est jamais prononce » -> relire le pivot : juste
         *     []    -> « le commercant dit : AUCUN »         -> relire le pivot : faux
         *
         * Resultat mesure : passer de 3 allergenes a 2 fonctionnait, passer a ZERO
         * etait impossible — y compris par l'API. Le dernier etat restait colle a vie.
         *
         * Or c'est precisement le geste qui compte : `LeCayenneAllergenSeeder` a pose
         * des correspondances qu'il qualifie lui-meme de « guessed mappings ». Un
         * commercant qui decouvre « arachides » sur un plat qui n'en contient pas ne
         * pouvait pas la retirer. Une declaration FAUSSE coute plus cher qu'une
         * absente : elle ecarte un client d'un plat qu'il pouvait manger.
         */
        if (is_array($item->allergen_flags) && $item->allergen_flags === []) {
            if ($item->exists) {
                $item->allergens()->sync([]);
            }

            return;
        }

        $jsonCodes = $this->extractCodes($item->allergen_flags);
        if ($jsonCodes !== []) {
            $allergens = Allergen::query()
                ->whereIn('code', $jsonCodes)
                ->orderBy('sort')
                ->get(['id', 'code']);

            $resolvedCodes = $allergens->pluck('code')->values()->all();
            $item->allergen_flags = $resolvedCodes;

            if ($item->exists) {
                $syncPayload = $allergens
                    ->pluck('id')
                    ->mapWithKeys(fn (int $id): array => [$id => ['is_trace' => false]])
                    ->all();

                $item->allergens()->sync($syncPayload);
            }

            return;
        }

        if (!$item->exists) {
            return;
        }

        $pivotCodes = $item->relationLoaded('allergens')
            ? $item->allergens->pluck('code')->filter()->values()->all()
            : $item->allergens()->orderBy('sort')->pluck('code')->filter()->values()->all();

        if ($pivotCodes !== []) {
            $item->allergen_flags = array_values($pivotCodes);
        }
    }

    /**
     * @param  mixed  $flags
     * @return array<int, string>
     */
    private function extractCodes(mixed $flags): array
    {
        if (!is_array($flags) || $flags === []) {
            return [];
        }

        $isSequential = array_keys($flags) === range(0, count($flags) - 1);
        if ($isSequential) {
            return collect($flags)
                ->filter(fn ($code) => is_string($code) && $code !== '')
                ->map(fn (string $code): string => trim($code))
                ->unique()
                ->values()
                ->all();
        }

        return collect($flags)
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->map(fn (string $code): string => trim($code))
            ->unique()
            ->values()
            ->all();
    }
}
