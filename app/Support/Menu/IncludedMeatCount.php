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

        $rule = self::rule($itemName, $categoryName);

        if ($rule === null) {
            return $slots;
        }

        // Jamais plus de viandes que d'emplacements : la borne jetterait en silence la viande sans attribut.
        return min($rule, $slots);
    }

    /**
     * [VG-2026-10-21 C3] Règle du propriétaire pour cet article (produit, puis catégorie), ou null s'il n'y en a pas.
     * Lue aussi par WizardPageMaterializer : une publication de wizard ne rallume jamais un emplacement gratuit au-delà.
     */
    public static function rule(?string $itemName, ?string $categoryName): ?int
    {
        $byItem = self::index((array) config('menu_meat_rules.by_item', []));
        $byCategory = self::index((array) config('menu_meat_rules.by_category', []));
        $rule = $byItem[self::key($itemName)] ?? $byCategory[self::key($categoryName)] ?? null;

        return ($rule === null || $rule < 1) ? null : $rule;
    }

    /** [VG-2026-10-21 C3] Un attribut est-il un emplacement viande (« Viande N » ou « meat ») ? */
    public static function isMeatSlot(?string $attributeName): bool
    {
        $n = mb_strtolower((string) $attributeName);

        return $n !== '' && (str_contains($n, 'viande') || str_contains($n, 'meat'));
    }

    /** Emplacements viande = attributs « Viande N » (ou « meat ») distincts. */
    private static function meatSlots(iterable $attributeNames): int
    {
        $distinct = [];
        foreach ($attributeNames as $name) {
            if (self::isMeatSlot($name)) {
                $distinct[mb_strtolower((string) $name)] = true;
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
