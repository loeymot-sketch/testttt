<?php

namespace App\Support\Menu;

use Illuminate\Support\Str;

/**
 * [VG-2026-10-21 M5-001b M3-101 M5-001 M1-004] Combien de viandes sont INCLUSES dans le prix d'un article ?
 * Calcul UNIQUE des deux projections qui publient `viande_count` (KioskMenuService pour la borne, NormalItemResource
 * pour le site et la fiche produit). Règles du propriétaire : config/menu_meat_rules.php.
 */
final class IncludedMeatCount
{
    /**
     * @param  iterable<int, string|null>  $attributeNames  noms des attributs portant au moins une variation active et
     *                                                      visible sur la surface servie (doublons tolérés)
     */
    public static function resolve(?string $itemName, ?string $categoryName, iterable $attributeNames): int
    {
        $slots = self::meatSlots($attributeNames);
        if ($slots === 0) {
            return 0;
        }

        $byItem = self::index((array) config('menu_meat_rules.by_item', []));
        $byCategory = self::index((array) config('menu_meat_rules.by_category', []));
        $rule = $byItem[self::key($itemName)] ?? $byCategory[self::key($categoryName)] ?? null;

        if ($rule === null || $rule < 1) {
            return $slots;
        }

        // Jamais plus de viandes que d'emplacements : la borne jetterait en silence la viande sans attribut.
        return min($rule, $slots);
    }

    /** Emplacements viande = attributs « Viande N » (ou « meat ») distincts. */
    private static function meatSlots(iterable $attributeNames): int
    {
        $distinct = [];
        foreach ($attributeNames as $name) {
            $n = mb_strtolower((string) $name);
            if ($n !== '' && (str_contains($n, 'viande') || str_contains($n, 'meat'))) {
                $distinct[$n] = true;
            }
        }

        return count($distinct);
    }

    /** @param array<string, mixed> $map @return array<string, int> */
    private static function index(array $map): array
    {
        $out = [];
        foreach ($map as $name => $count) {
            $out[self::key((string) $name)] = (int) $count;
        }

        return $out;
    }

    private static function key(?string $value): string
    {
        return trim(mb_strtolower(Str::ascii((string) $value)));
    }
}
