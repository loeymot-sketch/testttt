<?php

namespace App\Support\Labels;

use App\Models\DlcLabelProduct;

/**
 * [ETIQUETTES 2026-10-17 · owner « rien n'est préconfiguré, ça doit l'être avec intelligence »]
 *
 * Catalogue RECOMMANDÉ de produits à étiqueter en restauration rapide (légumes et crudités à couper, conserves ouvertes,
 * viandes, marinades, charcuterie, fromages, sauces, plats cuits, pains). Chaque produit arrive avec ses durées, sa
 * conservation et, quand la fiche est sans ambiguïté, ses allergènes : on imprime sans rien configurer.
 *
 * LES DURÉES SONT PRUDENTES, et courtes à dessein : aucune ne dépend d'une DLC fabricant (aucun produit ne demande
 * « DLC du fabricant » ici, pour qu'un appui suffise) — elles restent donc en dessous de ce que les emballages
 * professionnels portent d'ordinaire. Références : GBPH restauration commerciale (viande hachée : 0 à +2 °C, riz cuit :
 * 24 h, produits décongelés : jamais recongelés), arrêté du 21/12/2009. Ce sont des valeurs de DÉPART : le plan de
 * maîtrise sanitaire du restaurant fait foi, et chaque fiche reste modifiable (Gérer › Modifier).
 *
 * ALLERGÈNES : déclarés seulement quand le produit les impose (fromage = lait, pain = gluten, poisson…) ou qu'il n'en
 * porte aucun (légume nu). Une recette variable (marinade, sauce maison, plat préparé) est laissée « à renseigner » :
 * mieux vaut un écran qui le dit qu'une étiquette qui affirme à tort.
 */
final class RecommendedDlcCatalog
{
    public const FRIDGE = '0 à +4 °C';
    public const COLD = '0 à +2 °C';
    public const FROZEN = '-18 °C';

    /**
     * @return array<int, array<string, mixed>>  clés : name, category, actions[{key,value,unit}], storage, thawed_storage?,
     *                                             allergens[], no_allergens, default_copies, sort_order
     */
    public static function all(): array
    {
        $h = fn (int $v): array => ['value' => $v, 'unit' => DlcLabelProduct::UNIT_HOURS];
        $d = fn (int $v): array => ['value' => $v, 'unit' => DlcLabelProduct::UNIT_DAYS];
        $rows = [];

        // $kind : opened | prepared | cooked | thawed — $dur : $h(24) / $d(3)
        $add = function (string $category, string $name, string $kind, array $dur, array $opts = []) use (&$rows): void {
            $rows[] = [
                'name' => $name,
                'category' => $category,
                'actions' => [['key' => $kind] + $dur],
                'storage' => $opts['storage'] ?? self::FRIDGE,
                'thawed_storage' => $opts['thawed_storage'] ?? null,
                'allergens' => $opts['allergens'] ?? [],
                // vrai = « aucun des 14 majeurs » (légume nu…) ; faux avec liste vide = allergènes à renseigner
                'no_allergens' => (bool) ($opts['none'] ?? false),
                'default_copies' => 1,
            ];
        };

        // ── Légumes & crudités (à couper) ───────────────────────────────────────────────────────────────
        $veg = 'Légumes & crudités';
        $add($veg, 'Salade iceberg coupée', 'prepared', $h(24), ['none' => true]);
        $add($veg, 'Salade verte lavée', 'prepared', $h(24), ['none' => true]);
        $add($veg, 'Mesclun / roquette', 'prepared', $h(24), ['none' => true]);
        $add($veg, 'Tomates tranchées', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Oignons rouges émincés', 'prepared', $d(3), ['none' => true]);
        $add($veg, 'Oignons blancs émincés', 'prepared', $d(3), ['none' => true]);
        $add($veg, 'Oignons ciselés', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Poivrons coupés', 'prepared', $d(3), ['none' => true]);
        $add($veg, 'Concombre tranché', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Carottes râpées', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Chou émincé', 'prepared', $d(3), ['none' => true]);
        $add($veg, 'Champignons frais émincés', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Pommes de terre épluchées', 'prepared', $h(24), ['none' => true]);
        $add($veg, 'Avocat / guacamole', 'prepared', $h(24), ['none' => true]);
        $add($veg, 'Citron coupé', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Persil / ciboulette ciselés', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Ail haché', 'prepared', $h(48), ['none' => true]);
        $add($veg, 'Légumes grillés', 'cooked', $d(3), ['none' => true]);

        // ── Conserves & bocaux (après ouverture) ────────────────────────────────────────────────────────
        $can = 'Conserves & bocaux';
        $add($can, 'Olives (boîte ouverte)', 'opened', $d(3), ['none' => true]);
        $add($can, 'Champignons de Paris (boîte ouverte)', 'opened', $d(3), ['none' => true]);
        $add($can, 'Maïs (boîte ouverte)', 'opened', $d(3), ['none' => true]);
        $add($can, 'Cornichons (bocal ouvert)', 'opened', $d(7), ['none' => true]);
        $add($can, 'Jalapeños (bocal ouvert)', 'opened', $d(7), ['none' => true]);
        $add($can, 'Poivrons marinés (ouverts)', 'opened', $d(5), ['none' => true]);
        $add($can, 'Tomates séchées (ouvertes)', 'opened', $d(7), ['none' => true]);
        $add($can, 'Ananas (boîte ouverte)', 'opened', $d(3), ['none' => true]);
        $add($can, 'Conserve de légumes (ouverte)', 'opened', $d(3), ['none' => true]);

        // ── Viandes & volailles ─────────────────────────────────────────────────────────────────────────
        $meat = 'Viandes & volailles';
        $add($meat, 'Viande hachée façonnée', 'prepared', $h(24), ['storage' => self::COLD, 'none' => true]);
        $add($meat, 'Steak haché décongelé', 'thawed', $h(24), ['storage' => self::FROZEN, 'thawed_storage' => self::COLD, 'none' => true]);
        $add($meat, 'Poulet cru découpé', 'prepared', $h(24), ['storage' => self::COLD, 'none' => true]);
        $add($meat, 'Poulet mariné', 'prepared', $d(3), ['storage' => self::COLD]);
        $add($meat, 'Viande marinée (bœuf / agneau)', 'prepared', $d(3), ['storage' => self::COLD]);
        $add($meat, 'Poulet cuit émincé', 'cooked', $d(3), ['none' => true]);
        $add($meat, 'Viande cuite (kebab, grillée)', 'cooked', $h(48), ['none' => true]);
        $add($meat, 'Viande hachée cuite', 'cooked', $h(48), ['none' => true]);
        $add($meat, 'Poulet décongelé', 'thawed', $h(24), ['storage' => self::FROZEN, 'thawed_storage' => self::COLD, 'none' => true]);
        $add($meat, 'Merguez / saucisses décongelées', 'thawed', $h(24), ['storage' => self::FROZEN, 'thawed_storage' => self::COLD, 'none' => true]);

        // ── Charcuterie (après ouverture) ───────────────────────────────────────────────────────────────
        $cold = 'Charcuterie';
        $add($cold, 'Bacon (ouvert)', 'opened', $d(5), ['none' => true]);
        $add($cold, 'Lardons (ouverts)', 'opened', $d(3), ['none' => true]);
        $add($cold, 'Jambon blanc (ouvert)', 'opened', $d(3), ['none' => true]);
        $add($cold, 'Jambon / dinde fumée (ouvert)', 'opened', $d(3), ['none' => true]);
        $add($cold, 'Pepperoni / chorizo (ouvert)', 'opened', $d(7), ['none' => true]);
        $add($cold, 'Saucisses / merguez crues (ouvertes)', 'opened', $d(2), ['storage' => self::COLD, 'none' => true]);

        // ── Poissons ────────────────────────────────────────────────────────────────────────────────────
        $fish = 'Poissons';
        $add($fish, 'Poisson frais (filet)', 'opened', $h(24), ['storage' => self::COLD, 'allergens' => ['poisson']]);
        $add($fish, 'Poisson pané décongelé', 'thawed', $h(24), ['storage' => self::FROZEN, 'thawed_storage' => self::COLD, 'allergens' => ['poisson', 'gluten']]);
        $add($fish, 'Thon (boîte ouverte)', 'opened', $h(48), ['allergens' => ['poisson']]);
        $add($fish, 'Saumon fumé (ouvert)', 'opened', $d(3), ['storage' => self::COLD, 'allergens' => ['poisson']]);

        // ── Fromages & produits laitiers ────────────────────────────────────────────────────────────────
        $cheese = 'Fromages & produits laitiers';
        $add($cheese, 'Cheddar tranché (ouvert)', 'opened', $d(5), ['allergens' => ['lait']]);
        $add($cheese, 'Cheddar râpé (ouvert)', 'opened', $d(3), ['allergens' => ['lait']]);
        $add($cheese, 'Emmental râpé (ouvert)', 'opened', $d(3), ['allergens' => ['lait']]);
        $add($cheese, 'Mozzarella (ouverte)', 'opened', $d(3), ['allergens' => ['lait']]);
        $add($cheese, 'Raclette tranchée (ouverte)', 'opened', $d(5), ['allergens' => ['lait']]);
        $add($cheese, 'Chèvre (ouvert)', 'opened', $d(5), ['allergens' => ['lait']]);
        $add($cheese, 'Boursin / fromage frais (ouvert)', 'opened', $d(3), ['allergens' => ['lait']]);
        $add($cheese, 'Fromage bleu (ouvert)', 'opened', $d(5), ['allergens' => ['lait']]);
        $add($cheese, 'Crème fraîche (ouverte)', 'opened', $d(3), ['allergens' => ['lait']]);

        // ── Sauces ──────────────────────────────────────────────────────────────────────────────────────
        $sauce = 'Sauces';
        $add($sauce, 'Sauce blanche maison', 'prepared', $h(48));
        $add($sauce, 'Sauce fromagère maison', 'prepared', $h(48), ['allergens' => ['lait']]);
        $add($sauce, 'Sauce tomate maison', 'prepared', $d(3), ['none' => true]);
        $add($sauce, 'Vinaigrette maison', 'prepared', $d(3));
        $add($sauce, 'Sauce maison (autre)', 'prepared', $h(48));
        $add($sauce, 'Sauce du commerce (ouverte)', 'opened', $d(14));
        $add($sauce, 'Mayonnaise (pot ouvert)', 'opened', $d(14), ['allergens' => ['oeufs', 'moutarde']]);
        $add($sauce, 'Ketchup (ouvert)', 'opened', $d(30));
        $add($sauce, 'Moutarde (ouverte)', 'opened', $d(30), ['allergens' => ['moutarde']]);
        $add($sauce, 'Sauce barbecue (ouverte)', 'opened', $d(21));

        // ── Plats cuits ─────────────────────────────────────────────────────────────────────────────────
        $meal = 'Plats cuits';
        $add($meal, 'Riz cuit', 'cooked', $h(24), ['none' => true]);
        $add($meal, 'Pâtes cuites', 'cooked', $d(3), ['allergens' => ['gluten']]);
        $add($meal, 'Œufs durs', 'cooked', $d(3), ['allergens' => ['oeufs']]);
        $add($meal, 'Plat cuisiné maison', 'cooked', $d(3));

        // ── Pains & desserts ────────────────────────────────────────────────────────────────────────────
        $bread = 'Pains & desserts';
        $add($bread, 'Pains burger (sachet ouvert)', 'opened', $h(48), ['storage' => 'Ambiante', 'allergens' => ['gluten']]);
        $add($bread, 'Wraps / tortillas (paquet ouvert)', 'opened', $d(3), ['storage' => 'Ambiante', 'allergens' => ['gluten']]);
        $add($bread, 'Pain décongelé', 'thawed', $h(24), ['storage' => self::FROZEN, 'thawed_storage' => 'Ambiante', 'allergens' => ['gluten']]);
        $add($bread, 'Dessert maison (tiramisu, mousse…)', 'prepared', $d(3), ['allergens' => ['lait', 'oeufs', 'gluten']]);

        foreach ($rows as $i => &$r) {
            $r['sort_order'] = ($i + 1) * 10;
        }
        unset($r);

        return $rows;
    }
}
