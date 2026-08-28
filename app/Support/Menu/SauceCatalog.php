<?php

namespace App\Support\Menu;

/**
 * [GOAL WIZARD-CAISSE 2026-08-28 · owner] Lecture du catalogue canonique des
 * sauces (`config/pos_sauces.php`).
 *
 * Deux usages, un seul SSOT :
 *  · TRI — `sortVariations()` impose le MÊME ordre de sauces sur toutes les
 *    surfaces (caisse + borne), quel que soit l'ordre d'insertion en base.
 *    C'est ce qui répare « pas le même ordre d'un sandwich à l'autre » sans
 *    réécrire les identifiants de lignes existantes.
 *  · RÉPARATION — `foodking:sauces:sync` s'en sert pour compléter les listes
 *    incomplètes et normaliser les libellés alias.
 *
 * Le tri est STABLE et ne touche QUE les variations d'un attribut sauce :
 * viandes, pains et bases bols gardent leur ordre d'origine.
 */
class SauceCatalog
{
    /** @var array<string,array>|null */
    private static ?array $byAlias = null;

    /** @var array<string,int>|null */
    private static ?array $rankByAlias = null;

    /** Liste canonique brute, dans l'ordre d'affichage. */
    public static function all(): array
    {
        return (array) config('pos_sauces.catalog', []);
    }

    /** Identifiants connus des attributs porteurs de sauce (5 = sandwich/tacos, 8 = bol). */
    public static function attributeIds(): array
    {
        return array_map('intval', (array) config('pos_sauces.attribute_ids', []));
    }

    /**
     * Un attribut est-il un attribut de SAUCE ?
     *
     * Le nom fait foi, l'identifiant n'est qu'un repli. Se fier aux seules clés
     * primaires [5, 8] rendait le tri muet dès que les identifiants changeaient
     * — base fraîchement semée, jeu de tests, ou simple ajout d'un nouvel
     * attribut sauce par l'admin : les sauces repartaient en ordre d'insertion
     * SANS AUCUNE ERREUR VISIBLE. Même critère de nom que le wizard caisse
     * (`attrName.includes('sauce') || includes('assaisonnement')`), pour que
     * backend et front s'accordent sur ce qu'est une sauce.
     */
    public static function isSauceAttribute(?int $attributeId, ?string $attributeName = null): bool
    {
        if ($attributeName !== null && $attributeName !== '') {
            $n = self::normalize($attributeName);
            if (str_contains($n, 'sauce') || str_contains($n, 'assaisonnement')) {
                return true;
            }
        }

        return $attributeId !== null && in_array($attributeId, self::attributeIds(), true);
    }

    /** Nom de l'attribut d'une variation, si la relation est chargée. */
    private static function attributeNameOf($variation): ?string
    {
        if (method_exists($variation, 'relationLoaded') && $variation->relationLoaded('itemAttribute')) {
            return $variation->itemAttribute->name ?? null;
        }

        return null;
    }

    /**
     * Normalise un libellé pour comparaison : minuscules, sans accents, sans
     * ponctuation, espaces réduits. « Sauce  Fromagère maison » → « sauce fromagere maison ».
     */
    public static function normalize(?string $name): string
    {
        $n = mb_strtolower(trim((string) $name), 'UTF-8');
        $n = strtr($n, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? '';

        return trim(preg_replace('/\s+/', ' ', $n) ?? '');
    }

    /** Index alias normalisé → entrée du catalogue. */
    private static function aliasIndex(): array
    {
        if (self::$byAlias !== null) {
            return self::$byAlias;
        }
        $index = [];
        foreach (self::all() as $entry) {
            foreach ((array) ($entry['aliases'] ?? []) as $alias) {
                $index[self::normalize($alias)] = $entry;
            }
            $index[self::normalize($entry['name'] ?? '')] = $entry;
        }

        return self::$byAlias = $index;
    }

    /** Index alias normalisé → rang d'affichage (0 = premier). */
    private static function rankIndex(): array
    {
        if (self::$rankByAlias !== null) {
            return self::$rankByAlias;
        }
        $ranks = [];
        foreach (array_values(self::all()) as $rank => $entry) {
            foreach ((array) ($entry['aliases'] ?? []) as $alias) {
                $ranks[self::normalize($alias)] = $rank;
            }
            $ranks[self::normalize($entry['name'] ?? '')] = $rank;
        }

        return self::$rankByAlias = $ranks;
    }

    /** Entrée canonique correspondant à un libellé libre, ou null si inconnu. */
    public static function match(?string $name): ?array
    {
        return self::aliasIndex()[self::normalize($name)] ?? null;
    }

    /**
     * Rang d'affichage d'un libellé. Une sauce hors catalogue est renvoyée en
     * fin de liste (mais AVANT rien : elle reste visible, jamais masquée).
     */
    public static function rank(?string $name): int
    {
        return self::rankIndex()[self::normalize($name)] ?? PHP_INT_MAX;
    }

    /**
     * Trie une collection de variations : les sauces (attributs 5/8) passent
     * dans l'ordre canonique, tout le reste garde son ordre d'origine.
     *
     * Tri stable : on décore avec l'index de départ avant de comparer, sinon
     * `usort()` peut permuter deux éléments de même rang (viandes notamment).
     *
     * @param  \Illuminate\Support\Collection  $variations
     * @return \Illuminate\Support\Collection
     */
    public static function sortVariations($variations)
    {
        if ($variations->isEmpty()) {
            return $variations;
        }

        // Tri PAR GROUPE d'attribut, jamais globalement.
        //
        // Un comparateur global mélangeant « rang canonique » (entre sauces d'un
        // même attribut) et « position d'origine » (partout ailleurs) n'est PAS
        // une relation d'ordre : les lignes d'un attribut ne sont pas contiguës
        // en base (les sauces ajoutées plus tard ont des id plus hauts que les
        // viandes), donc on obtient Curry < Pain < Barbecue < Curry. `usort` sur
        // un comparateur intransitif rend un ordre arbitraire — c'est ce qui
        // laissait « Barbecue » à sa mauvaise place sur Cayenne et Tacos M.
        //
        // On regroupe donc par attribut, on trie l'intérieur des seuls groupes
        // sauce, et on réassemble les groupes dans leur ordre de PREMIÈRE
        // APPARITION (ItemResource::itemAttributeList en dépend pour l'ordre des
        // étapes du wizard).
        return $variations
            ->values()
            ->groupBy(fn ($variation) => (int) ($variation->item_attribute_id ?? 0))
            ->map(function ($group) {
                $first = $group->first();
                $isSauce = self::isSauceAttribute(
                    (int) ($first->item_attribute_id ?? 0),
                    self::attributeNameOf($first)
                );
                if (!$isSauce) {
                    return $group->values();
                }

                // sortBy est stable : deux sauces hors catalogue (rang égal)
                // gardent leur ordre d'origine au lieu de permuter.
                return $group->sortBy(fn ($variation) => self::rank($variation->name ?? ''))->values();
            })
            ->flatten(1)
            ->values();
    }

    /**
     * Projection destinée au front (wizard caisse vanilla JS + borne) :
     * `{ "ketchup": {name, emoji, bg, fg, aliases:[...]}, ... }` plus l'ordre.
     */
    public static function frontPayload(): array
    {
        $entries = [];
        foreach (array_values(self::all()) as $rank => $entry) {
            $entries[] = [
                'key'     => $entry['key'] ?? '',
                'name'    => $entry['name'] ?? '',
                'emoji'   => $entry['emoji'] ?? '',
                'bg'      => $entry['bg'] ?? '#FFFFFF',
                'fg'      => $entry['fg'] ?? '#1B1B3A',
                'rank'    => $rank,
                'aliases' => array_values(array_unique(array_map(
                    [self::class, 'normalize'],
                    array_merge((array) ($entry['aliases'] ?? []), [$entry['name'] ?? ''])
                ))),
            ];
        }

        return $entries;
    }
}
