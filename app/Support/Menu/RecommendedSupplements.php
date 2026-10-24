<?php

namespace App\Support\Menu;

use Illuminate\Support\Str;

/**
 * [OWNER 2026-10-21] Quels suppléments met-on en avant (« Recommandé ») pour un produit donné ?
 * Liste du produit d'abord, sinon celle de sa catégorie — voir config/menu_recommended_supplements.php.
 * Pur affichage : ne décide aucun prix et ne rend rien obligatoire.
 */
final class RecommendedSupplements
{
    public static function isRecommended(?string $itemName, ?string $categoryName, ?string $extraName): bool
    {
        $extra = self::key($extraName);
        if ($extra === '') {
            return false;
        }

        $byItem = self::index((array) config('menu_recommended_supplements.by_item', []));
        $byCategory = self::index((array) config('menu_recommended_supplements.by_category', []));

        $list = $byItem[self::key($itemName)] ?? $byCategory[self::key($categoryName)] ?? [];

        return in_array($extra, $list, true);
    }

    /** @param array<string, array<int, string>> $map @return array<string, array<int, string>> */
    private static function index(array $map): array
    {
        $out = [];
        foreach ($map as $name => $extras) {
            $out[self::key((string) $name)] = array_map(fn ($e) => self::key((string) $e), (array) $extras);
        }

        return $out;
    }

    private static function key(?string $value): string
    {
        // Str::ascii envoie « Œuf » vers « OEuf » : on compare donc sur la forme ASCII minuscule.
        return trim(mb_strtolower(Str::ascii((string) $value)));
    }
}
