<?php

namespace App\Support\Menu;

use App\Models\ItemCategory;
use Illuminate\Support\Str;

/**
 * [OWNER 2026-11-11] Nombre de sauces OFFERTES dans le prix d'un article, publié aux surfaces en
 * `sauce_included` (caisse : ItemResource ; borne : KioskMenuService ; site et application :
 * NormalItemResource).
 *
 * Décision du propriétaire : « la première sauce et la deuxième sauce pour le sandwich offertes.
 * Deux sauces offertes. » Les frites gardent UNE sauce incluse, la suivante payante — elles ne
 * passent pas par ce nombre (étape « Sauce pour frites », 1 incluse, inchangée).
 *
 * Chaque surface envoie `max(0, sauces choisies − sauce_included)` unités de l'extra « Sauce
 * supplémentaire » : le prix reste celui de la base (0,50 €), facturé par PricingService (gelé,
 * inchangé). Ce fichier ne fixe AUCUN prix.
 *
 * Résolution : produit (`by_item`) puis catégorie (`by_category`) de config/pos_sauces.php
 * `included`, à défaut 1. Noms comparés sans casse ni accents.
 */
final class IncludedSauceCount
{
    public const DEFAULT = 1;

    public static function resolve(?string $itemName, ?string $categoryName): int
    {
        $rules = (array) config('pos_sauces.included', []);
        $byItem = self::index((array) ($rules['by_item'] ?? []));
        $byCategory = self::index((array) ($rules['by_category'] ?? []));

        $n = $byItem[self::key($itemName)] ?? $byCategory[self::key($categoryName)] ?? self::DEFAULT;

        return max(1, (int) $n);
    }

    /**
     * Variante pour une ressource d'article : la catégorie est lue via la relation si elle est
     * chargée, sinon dans une table id → nom chargée UNE fois (pas de requête par article dans
     * les listes de la caisse).
     */
    public static function forItem(object $item): int
    {
        $categoryName = null;
        if (method_exists($item, 'relationLoaded') && $item->relationLoaded('category')) {
            $categoryName = optional($item->category)->name;
        } else {
            // Mémorisé dans le conteneur (donc par requête, et remis à zéro entre deux tests) — jamais
            // en `static`, qui garderait des noms périmés dans un processus long.
            $key = self::class.'.category-names';
            if (! app()->bound($key)) {
                app()->instance($key, ItemCategory::query()->pluck('name', 'id')->all());
            }
            $names = app($key);
            $categoryName = $names[(int) ($item->item_category_id ?? 0)] ?? null;
        }

        return self::resolve((string) ($item->name ?? ''), $categoryName);
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
