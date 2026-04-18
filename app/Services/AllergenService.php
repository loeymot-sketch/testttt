<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\Item;

class AllergenService
{
    public function projectFlags(Item $item): void
    {
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
