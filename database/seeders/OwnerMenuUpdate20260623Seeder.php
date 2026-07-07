<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Owner menu update 2026-06-23 — vrai menu Le Cayenne.
 *
 * Source = extraction owner (carte papier) : TACOS (M/L), BURGERS (6),
 * SANDWICHS (Cayenne/Suprême/Méga/Terminator), SAUCES (12 incluses),
 * SUPPLÉMENTS (9 @ 0,90), option Menu (frites+boisson +2,50).
 *
 * Décisions owner (AskUserQuestion 2026-06-23) :
 *   - Tailles = PRODUITS SÉPARÉS (Tacos M / Tacos L distincts, prix fixes).
 *   - Menu expérimental e2e (Bols Gourmands, Galette, variantes "Big",
 *     viandes 100% poulet) = DÉSACTIVÉ (status INACTIVE, jamais supprimé →
 *     historique fiscal NF525 intact, réversible).
 *   - Sauces + suppléments = OPTIONS du wizard (pas des produits vendables).
 *
 * Choix de viande (caisse + borne) : porté par les ItemVariation sous les
 * attributs "Viande 1" (id 1) / "Viande 2" (id 2). Le wizard legacy
 * (KioskWizardComponent + pos-wizard.js, tous deux FROZEN) rend un groupe
 * par attribut ayant des variations. Enforcement back = MultiVariationConstraint
 * via item_attributes.min_select/max_select.
 *
 * Frozen-zone : PricingService + KioskWizardComponent + pos-wizard.js
 * intouchés — 100% data DB. Idempotent (sync : soft-delete hors-cible + create manquant).
 */
class OwnerMenuUpdate20260623Seeder extends Seeder
{
    private const ATTR_VIANDE_1 = 1;
    private const ATTR_VIANDE_2 = 2;
    private const ATTR_SAUCE = 5;
    private const ATTR_PAIN = 6;
    private const ATTR_SAUCE_BOL = 8;
    private const ATTR_STYLE_FRITES = 9;

    /** Sauces bol (carte : 2 seulement). */
    private const BOL_SAUCES = ['Sauce fromagère maison', 'Sauce spicy'];

    /** group_label des suppléments bol (référencé par le composer step). */
    private const SUPP_GROUP_BOL = 'supplement_bol';

    /** 7 viandes au choix (tacos + Méga/Terminator). */
    private const MEATS = [
        'Mexicanos', 'Cordon Bleu', 'Viande Hachée', 'Nuggets',
        'Tenders', 'Fricadelle', 'Poulet mariné',
    ];

    /** 12 sauces incluses (1ère gratuite). */
    private const SAUCES = [
        'Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï',
        'Algérienne', 'Andalouse', 'Curry', 'Barbecue', 'Harissa',
        'Fromagère maison', 'Spicy maison',
    ];

    /** Garnitures gratuites (group_label = crudite). */
    private const GARNITURES = ['Salade', 'Tomate', 'Oignon'];

    /** Suppléments payants +0,90 (group_label = supplement). */
    private const SUPPLEMENTS = [
        'Oignons frits' => 0.90, 'Champignons' => 0.90, 'Jambon' => 0.90,
        'Cheddar' => 0.90, 'Raclette' => 0.90, 'Emmental' => 0.90,
        'Boursin' => 0.90, 'Œuf' => 0.90, 'Légumes sautés' => 0.90,
    ];

    private const PAINS = ['Pain', 'Galette'];

    public function run(): void
    {
        \DB::transaction(fn () => $this->apply());
    }

    private function apply(): void
    {
        // 1) Attributs viande : exiger exactement 1 par groupe présent.
        $this->setAttribute(self::ATTR_VIANDE_1, 1, 1);
        $this->setAttribute(self::ATTR_VIANDE_2, 1, 1);
        $this->setAttribute(self::ATTR_SAUCE, 1, 1);
        $this->setAttribute(self::ATTR_PAIN, 1, 1);

        // 2) Catégorie Sandwichs (réutilise cat 1 "Sandwich Cayenne").
        $this->renameCategory(1, 'Sandwichs');

        // 3) TACOS (cat 5)
        $tacosM = $this->upsertItem(26, 'Tacos M', 6.90, 5, 'Galette de blé, 1 viande au choix, frites maison et sauce.');
        $this->wireMeatChoice($tacosM, 1);      // 1 viande
        $this->wireSauces($tacosM);
        $this->wireGarnitures($tacosM);         // [owner 2026-07-07] tacos AVEC crudités (revert du « sans crudités » 2026-06-23) : la borne n'affichait aucun choix de crudité → aligné sur sandwichs/burgers (Salade/Tomate/Oignon + Oignons cuits via OnionCuitExtra20260706Seeder)
        $this->wireSupplements($tacosM);

        $tacosL = $this->upsertItem(null, 'Tacos L', 7.90, 5, 'Galette de blé, 2 viandes au choix, frites maison et sauce.');
        $this->wireMeatChoice($tacosL, 2);      // 2 viandes
        $this->wireSauces($tacosL);
        $this->wireGarnitures($tacosL);         // [owner 2026-07-07] tacos AVEC crudités (revert du « sans crudités » 2026-06-23)
        $this->wireSupplements($tacosL);

        // 4) BURGERS (cat 4) — compositions fixes, PAS de choix de viande.
        $burgers = [
            [38, 'Chicken Burger', 4.90, 'Salade, tomate, oignon, sauce.'],
            [null, 'Cheese Burger', 6.00, 'Steak, cheddar, salade, tomate, oignon, sauce.'],
            [null, 'Double Cheese', 7.00, '2 steaks, 2 cheddars, salade, tomate, oignon, sauce.'],
            [null, 'Fish Burger', 6.00, 'Poisson pané, cheddar, salade, tomate, oignon, sauce.'],
            [null, 'Big Burger', 9.00, '3 steaks, 3 cheddars, 2 jambons de dinde, salade, tomate, oignon, sauce.'],
            [null, 'Grill Burger', 8.00, '2 steaks, 2 cheddars, jambon de dinde, salade, tomate, oignon, sauce.'],
        ];
        foreach ($burgers as [$id, $name, $price, $desc]) {
            $b = $this->upsertItem($id, $name, $price, 4, $desc);
            $this->clearMeatChoice($b);          // burgers = pas de viande au choix
            $this->wireSauces($b);
            $this->wireGarnitures($b);
            $this->wireSupplements($b);
        }

        // 5) SANDWICHS (cat 1) — pain au choix ; viande au choix sur Méga/Terminator.
        $cayenne = $this->upsertItem(22, 'Cayenne', 7.40, 1, 'Poulet mariné, cheddar, jambon, oignons rouges, tomates, salade, sauce fromagère maison.');
        $this->clearMeatChoice($cayenne);        // viande fixe (poulet mariné)
        $this->wirePain($cayenne);
        $this->wireSauces($cayenne);
        $this->wireGarnitures($cayenne);
        $this->wireSupplements($cayenne);

        $supreme = $this->upsertItem(null, 'Suprême', 7.00, 1, 'Steak haché, cordon bleu, cheddar, oignons rouges, tomates, salade, sauce au choix.');
        $this->clearMeatChoice($supreme);        // viandes fixes
        $this->wirePain($supreme);
        $this->wireSauces($supreme);
        $this->wireGarnitures($supreme);
        $this->wireSupplements($supreme);

        $mega = $this->upsertItem(null, 'Méga', 8.00, 1, '2 viandes au choix, cheddar, œuf, oignons rouges, tomates, salade, sauce au choix.');
        $this->wireMeatChoice($mega, 2);         // 2 viandes au choix
        $this->wirePain($mega);
        $this->wireSauces($mega);
        $this->wireGarnitures($mega);
        $this->wireSupplements($mega);

        $terminator = $this->upsertItem(null, 'Terminator', 9.00, 1, '2 viandes au choix, 2 cheddars, œuf, jambon de dinde, oignons rouges, tomates, salade, sauce au choix.');
        $this->wireMeatChoice($terminator, 2);   // 2 viandes au choix
        $this->wirePain($terminator);
        $this->wireSauces($terminator);
        $this->wireGarnitures($terminator);
        $this->wireSupplements($terminator);

        // 6) Option Menu (frites + boisson) +2,50.
        if ($menu = Item::withoutGlobalScopes()->find(1)) {
            $menu->price = 2.50;
            $menu->save();
        }
        // Câbler l'addon formule (menu_component=1 +2,50, side=2, drink=3) sur
        // tous les plats actifs des catégories Sandwichs/Burgers/Tacos.
        $foods = Item::withoutGlobalScopes()
            ->whereIn('item_category_id', [1, 4, 5])
            ->where('status', 5)
            ->pluck('id');
        foreach ($foods as $foodId) {
            $this->wireMenuFormula((int) $foodId);
        }

        // 7) BOLS (cat 6) — carte 2026-06-24 : EXACTEMENT 2 produits @ 7,90,
        //    viande au choix (1), sauce bol (2), suppléments, gratiné (riz).
        //    Les 8 bols expérimentaux « Bowl Poulet <type> » @ 8,90 sont
        //    remplacés : on repurpose 41→Bol Frites, 45→Bol Riz et on
        //    désactive les 6 autres. Choix viande = chemin COMPOSER (profile),
        //    composer-aware ON ; le wizard frozen legacy ne sait pas détecter
        //    « bol » (Bol Frites matcherait « frites »→snacking sans viande).
        $this->renameCategory(6, 'Bols');
        $bolFrites = $this->upsertItem(41, 'Bol Frites', 7.90, 6, 'Frites maison, viande au choix, sauce et suppléments.');
        $this->wireBol($bolFrites, false);
        $bolRiz = $this->upsertItem(45, 'Bol Riz', 7.90, 6, 'Riz, viande au choix, sauce et suppléments. Option gratiné.');
        $this->wireBol($bolRiz, true);
        $this->deactivateItems([42, 43, 44, 46, 47, 48]);
        $this->unpublishProfiles([42, 43, 44, 46, 47, 48]);

        // 8) MENU ENFANT (cat 11) — 2 produits @ 4,90 (Nuggets / Burger),
        //    frites + Capri-Sun inclus. Produits SÉPARÉS : le renderer wizard
        //    legacy/frozen (renderSinglePage) ne sait rendre qu'un attribut
        //    'viande'/'sauce', pas un choix générique « Plat enfant » → 2 tuiles
        //    = plus robuste + plus rapide en caisse (cohérent avec Tacos M/L).
        $meNuggets = $this->upsertItem(40, 'Menu Enfant Nuggets', 4.90, 11, '6 nuggets, frites et Capri-Sun.');
        $this->clearMeatChoice($meNuggets);
        $this->clearAllVariations($meNuggets->id);
        $this->clearAddons($meNuggets->id);
        $this->unpublishProfiles([40]);
        $meBurger = $this->upsertItem(null, 'Menu Enfant Burger', 4.90, 11, 'Burger, frites et Capri-Sun.');
        $this->clearMeatChoice($meBurger);
        $this->clearAllVariations($meBurger->id);
        $this->clearAddons($meBurger->id);

        // 9) DESSERTS (cat 9) — carte : 3,50 (DB portait 3,80).
        foreach ([49, 50, 51] as $dessertId) {
            $this->setItemPrice($dessertId, 3.50);
        }

        // 9bis) DÉCISIONS OWNER 2026-06-24 (AskUserQuestion post-audit).
        // (1) Boisson canette -> 1,90 (carte écran). Eau(58)/Capri(59) inchangés.
        foreach ([52, 53, 54, 55, 56, 57] as $drinkId) {
            $this->setItemPrice($drinkId, 1.90);
        }
        // (2) Galette (23/24) : aligner les viandes sur les 7 canon (étaient poulet-only off-carte).
        foreach ([23, 24] as $galetteId) {
            $this->syncVariations($galetteId, self::ATTR_VIANDE_1, self::MEATS, 0.0);
        }
        // (3) Frites (33/34) : « garder + choisissables » -> on retire l'attribut requis
        //     « Style frites » (attr9, non rendu par le wizard frozen) et on expose les
        //     toppings comme EXTRAS facturés (Nature=base ; Cheddar fondu +1 ; Cheddar +
        //     Oignons frits +2) — rendus + facturés via le pont extras (non-frozen).
        // Base Petite/Grande = SIMPLES (board) : on retire l'attribut « Style frites »
        // requis + les extras + le composer profile (évite tout fantôme display≠charge).
        foreach ([33, 34] as $friteId) {
            $this->syncVariations($friteId, self::ATTR_STYLE_FRITES, [], 0.0);
            foreach (ItemExtra::withoutGlobalScopes()->where('item_id', $friteId)->whereNull('deleted_at')->get() as $oldExtra) {
                $oldExtra->delete();
            }
            $this->unpublishProfiles([$friteId]);
        }
        // « Garder + rendre choisissables » (décision owner) : les styles en PRODUITS
        // séparés à prix bakés — le flux wizard 'snacking' des frites ne sérialise pas
        // les suppléments (frozen), donc des SKU = facturation fiable (pattern Bol/Menu enfant).
        foreach ([
            ['Petite Frites Cheddar fondu', 3.50],
            ['Petite Frites Cheddar + Oignons frits', 4.50],
            ['Grande Frites Cheddar fondu', 5.00],
            ['Grande Frites Cheddar + Oignons frits', 6.00],
        ] as [$fName, $fPrice]) {
            $fv = $this->upsertItem(null, $fName, $fPrice, 7, $fName . ' maison.');
            $this->clearMeatChoice($fv);
            $this->clearAllVariations($fv->id);
            $this->clearAddons($fv->id);
            $this->unpublishProfiles([$fv->id]);
        }
        // (4) Viande supplémentaire +2,50 : extra facturé proposable sur TOUS les plats
        //     (décision owner « on peut proposer des suppléments de viande partout »),
        //     y compris burgers/Cayenne/Suprême (viande fixe mais viande EN PLUS possible)
        //     + galettes. Rendu en étape suppléments sur caisse ET borne (group 'supplement').
        $viandeSuppItems = [
            22, 103, 104, 105,   // Sandwichs : Cayenne, Suprême, Méga, Terminator
            23, 24,              // Galettes
            38, 98, 99, 100, 101, 102, // Burgers (viande fixe + viande sup possible)
            26, 97,             // Tacos M/L
        ];
        foreach ($viandeSuppItems as $meatItemId) {
            $this->addExtraToGroup($meatItemId, 'supplement', 'Viande supplémentaire', 2.50);
        }
        foreach ([41, 45] as $bolId) {
            $this->addExtraToGroup($bolId, self::SUPP_GROUP_BOL, 'Viande supplémentaire', 2.50);
        }

        // (5) Catégorie « Suppléments » (cat 8) MASQUÉE de la caisse/borne : les
        //     suppléments sont des OPTIONS de wizard (item_extras), pas des produits
        //     vendables seuls (« un Cheddar nu » au comptoir = absurde + tickets faux).
        //     Réversible (status). Les item_extras des wizards sont indépendants.
        $this->hideCategories([8]);

        // 10) Désactiver le menu hors-carte (jamais supprimé). Décision owner
        //    2026-06-24 : caisse = EXACTEMENT la carte. Galette CONSERVÉE
        //    comme catégorie (owner gate). Pain/Galette reste AUSSI un choix
        //    de pain dans le wizard des 4 sandwiches (attr 6).
        $this->deactivateItems([
            27,                                  // Big Tacos
            39,                                  // Big Chicken
            36,                                  // Big Cayenne
            25, 37,                              // Sandwich Classique / Big Classique
            76,                                  // Tacos Signature XL
        ]);
        // Masquer les catégories hors-carte. Galette (cat 2) + Bols (cat 6)
        // CONSERVÉES (owner gate 2026-06-24 : « manque les bols »).
        $this->hideCategories([3, 21]);          // Sandwich Classique, Tacos Signature
        // S'assurer que Galette + Bols restent ACTIVES (les 2 bols repurposés).
        ItemCategory::withoutGlobalScopes()->whereIn('id', [2, 6])->update(['status' => 5]);
        Item::withoutGlobalScopes()->whereIn('id', [23, 24])->update(['status' => 5, 'is_available' => 1]);
        Item::withoutGlobalScopes()->whereIn('id', [41, 45])->update(['status' => 5, 'is_available' => 1]);
        // Aligner les sauces des Galettes (Normale 23, Cayenne 24) sur les 12
        // canoniques — elles portaient des sauces legacy (Spicy/Tandoori…).
        foreach ([23, 24] as $galetteId) {
            $this->syncVariations($galetteId, self::ATTR_SAUCE, self::SAUCES, 0.0);
        }

        $this->command->info('OwnerMenuUpdate20260623Seeder: menu Le Cayenne appliqué (TACOS/BURGERS/SANDWICHS/BOLS/MENU ENFANT/DESSERTS + viandes + sauces + suppléments).');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function setAttribute(int $attrId, int $min, int $max): void
    {
        if ($a = ItemAttribute::withoutGlobalScopes()->find($attrId)) {
            $a->min_select = $min;
            $a->max_select = $max;
            $a->allow_repeat = false;
            $a->save();
        }
    }

    private function renameCategory(int $catId, string $name): void
    {
        if ($c = ItemCategory::withoutGlobalScopes()->find($catId)) {
            $c->name = $name;
            if (\Schema::hasColumn('item_categories', 'slug')) {
                $c->slug = Str::slug($name);
            }
            $c->save();
        }
    }

    /** Crée ou met à jour un item (par id si fourni, sinon par name+cat). */
    private function upsertItem(?int $id, string $name, float $price, int $catId, string $desc): Item
    {
        $item = $id ? Item::withoutGlobalScopes()->find($id) : null;
        if (! $item) {
            $item = Item::withoutGlobalScopes()
                ->where('name', $name)
                ->where('item_category_id', $catId)
                ->first();
        }
        if (! $item) {
            $item = new Item();
            $item->item_type = 10;
            $item->tax_id = 3;
        }
        $item->name = $name;
        $item->price = $price;
        $item->item_category_id = $catId;
        $item->description = $desc;
        $item->status = 5;          // ACTIVE
        $item->is_available = 1;
        $item->slug = $this->uniqueSlug($name, $item->id);
        if ($item->item_type === null) {
            $item->item_type = 10;
        }
        if ($item->tax_id === null) {
            $item->tax_id = 3;
        }
        $item->save();

        return $item;
    }

    private function uniqueSlug(string $name, ?int $selfId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $n = 1;
        while (Item::withoutGlobalScopes()->where('slug', $slug)
            ->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))
            ->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    /** Synchronise les viandes sur N attributs (Viande 1..N). */
    private function wireMeatChoice(Item $item, int $count): void
    {
        $attrs = [self::ATTR_VIANDE_1, self::ATTR_VIANDE_2];
        for ($i = 0; $i < count($attrs); $i++) {
            if ($i < $count) {
                $this->syncVariations($item->id, $attrs[$i], self::MEATS, 0.0);
            } else {
                $this->syncVariations($item->id, $attrs[$i], [], 0.0);
            }
        }
    }

    private function clearMeatChoice(Item $item): void
    {
        $this->syncVariations($item->id, self::ATTR_VIANDE_1, [], 0.0);
        $this->syncVariations($item->id, self::ATTR_VIANDE_2, [], 0.0);
    }

    private function wireSauces(Item $item): void
    {
        $this->syncVariations($item->id, self::ATTR_SAUCE, self::SAUCES, 0.0);
    }

    private function wirePain(Item $item): void
    {
        $this->syncVariations($item->id, self::ATTR_PAIN, self::PAINS, 0.0);
    }

    private function wireGarnitures(Item $item): void
    {
        $this->syncExtras($item->id, 'crudite', array_fill_keys(self::GARNITURES, 0.0));
    }

    private function wireSupplements(Item $item): void
    {
        $this->syncExtras($item->id, 'supplement', self::SUPPLEMENTS);
    }

    /**
     * Sync des ItemVariation pour un (item, attribut) : crée les manquantes,
     * réactive/reprice les présentes, soft-delete celles hors-cible.
     *
     * @param  list<string>  $names
     */
    private function syncVariations(int $itemId, int $attrId, array $names, float $price): void
    {
        $existing = ItemVariation::withoutGlobalScopes()
            ->where('item_id', $itemId)
            ->where('item_attribute_id', $attrId)
            ->withTrashed()
            ->get();

        foreach ($existing as $v) {
            if (! in_array($v->name, $names, true)) {
                if ($v->deleted_at === null) {
                    $v->delete();
                }
            }
        }
        foreach ($names as $name) {
            $row = $existing->firstWhere('name', $name);
            if ($row) {
                $row->price = $price;
                $row->status = 5;
                $row->deleted_at = null;
                $row->save();
            } else {
                ItemVariation::create([
                    'item_id'           => $itemId,
                    'item_attribute_id' => $attrId,
                    'name'              => $name,
                    'price'             => $price,
                    'status'            => 5,
                    'visible_on'        => null,
                ]);
            }
        }
    }

    /**
     * Sync des ItemExtra pour un (item, group_label).
     *
     * @param  array<string, float>  $nameToPrice
     */
    private function syncExtras(int $itemId, string $group, array $nameToPrice): void
    {
        $existing = ItemExtra::withoutGlobalScopes()
            ->where('item_id', $itemId)
            ->where('group_label', $group)
            ->withTrashed()
            ->get();

        foreach ($existing as $e) {
            if (! array_key_exists($e->name, $nameToPrice)) {
                if ($e->deleted_at === null) {
                    $e->delete();
                }
            }
        }
        foreach ($nameToPrice as $name => $price) {
            $row = $existing->firstWhere('name', $name);
            if ($row) {
                $row->price = $price;
                $row->status = 5;
                $row->deleted_at = null;
                $row->save();
            } else {
                ItemExtra::create([
                    'item_id'     => $itemId,
                    'name'        => $name,
                    'price'       => $price,
                    'group_label' => $group,
                    'status'      => 5,
                    'visible_on'  => null,
                ]);
            }
        }
    }

    /** Câble l'addon formule menu (menu_component + side + drink) si absent. */
    private function wireMenuFormula(int $itemId): void
    {
        $roles = [1 => 'menu_component', 2 => 'side', 3 => 'drink'];
        foreach ($roles as $addonItemId => $role) {
            $exists = ItemAddon::withoutGlobalScopes()
                ->where('item_id', $itemId)
                ->where('addon_item_id', $addonItemId)
                ->whereNull('deleted_at')
                ->exists();
            if (! $exists) {
                ItemAddon::create([
                    'item_id'              => $itemId,
                    'addon_item_id'        => $addonItemId,
                    'addon_item_variation' => null,
                    'role'                 => $role,
                ]);
            }
        }
    }

    private function deactivateItems(array $ids): void
    {
        Item::withoutGlobalScopes()->whereIn('id', $ids)->update([
            'status'       => 10, // INACTIVE
            'is_available' => 0,
        ]);
    }

    private function hideCategories(array $ids): void
    {
        ItemCategory::withoutGlobalScopes()->whereIn('id', $ids)->update([
            'status' => 10, // INACTIVE
        ]);
    }

    // ─── BOLS / MENU ENFANT / composer ───────────────────────────────────

    /** Câble un bol : 1 viande (attr1), 2 sauces bol (attr8), suppléments, gratiné (riz), composer profile. */
    private function wireBol(Item $item, bool $withGratine): void
    {
        // Viande au choix (1) — attr1 ; pas d'attr2 (1 seule viande -> évite le P1 2-viandes).
        $this->syncVariations($item->id, self::ATTR_VIANDE_1, self::MEATS, 0.0);
        $this->syncVariations($item->id, self::ATTR_VIANDE_2, [], 0.0);
        // Sauce bol (attr8) — 2 sauces de la carte.
        $this->syncVariations($item->id, self::ATTR_SAUCE_BOL, self::BOL_SAUCES, 0.0);
        // Suppléments bol (9 @ 0,90) + gratiné +2,00 (riz uniquement).
        $supps = self::SUPPLEMENTS;
        if ($withGratine) {
            $supps['Option Gratiné'] = 2.00;
        }
        $this->syncExtras($item->id, self::SUPP_GROUP_BOL, $supps);
        // PAS la formule menu +2,50 (les bols ont déjà frites/riz) — MAIS une BOISSON
        // OPTIONNELLE (décision owner « s'il veut une boisson pourquoi pas »). On câble
        // SEULEMENT l'addon role 'drink' (boisson seule), pas menu_component/side, et on
        // NE met PAS cat6.has_menu=true (sinon la borne déclencherait la formule +2,50).
        $this->clearAddons($item->id);
        $this->wireDrinkAddon($item->id);
        // Composer profile : viande -> sauce -> suppléments -> boisson (optionnelle).
        // Le step boisson (source_type=addon, addon_role=drink, min0) rend un drink
        // optionnel sur la CAISSE (COMPOSER_ADDON_ROLE_MAP['drink']='menu') ET la BORNE
        // (shouldShowComposerStep menu+addon_role) SANS toucher has_menu → pas de +2,50.
        $this->setComposerProfile($item, 6, [
            ['step_key' => 'viande',      'label' => 'Choix de la viande', 'source_type' => 'item_attribute', 'source_ref' => 'Viande 1',      'attr_id' => self::ATTR_VIANDE_1,  'min' => 1, 'max' => 1, 'addon_role' => null],
            ['step_key' => 'sauce',       'label' => 'Choix de la sauce',  'source_type' => 'item_attribute', 'source_ref' => 'sauce bol',     'attr_id' => self::ATTR_SAUCE_BOL, 'min' => 1, 'max' => 1, 'addon_role' => null],
            ['step_key' => 'supplements', 'label' => 'Suppléments',        'source_type' => 'extra_group',    'source_ref' => self::SUPP_GROUP_BOL, 'attr_id' => null,            'min' => 0, 'max' => 5, 'addon_role' => null],
            ['step_key' => 'boisson',     'label' => 'Boisson (optionnel)', 'source_type' => 'addon',         'source_ref' => 'drink',         'attr_id' => null,            'min' => 0, 'max' => 1, 'addon_role' => 'drink'],
        ]);
    }

    /** Soft-delete toutes les variations actives d'un item (produit simple). */
    private function clearAllVariations(int $itemId): void
    {
        foreach (ItemVariation::withoutGlobalScopes()->where('item_id', $itemId)->whereNull('deleted_at')->get() as $v) {
            $v->delete();
        }
    }

    /**
     * Crée/met à jour le composer profile publié d'un item avec les steps fournis (remplacement idempotent).
     *
     * @param  list<array{step_key:string,label:string,source_type:string,source_ref:string,attr_id:?int,min:int,max:int,addon_role:?string}>  $steps
     */
    private function setComposerProfile(Item $item, int $categoryId, array $steps): void
    {
        $profile = ItemWizardProfile::withoutGlobalScopes()
            ->where('item_id', $item->id)
            ->orderByDesc('id')
            ->first();
        if (! $profile) {
            $profile = new ItemWizardProfile();
            $profile->item_id = $item->id;
            $profile->version = 0;
        }
        // XOR DB constraint: un profil item-scopé a item_category_id = NULL.
        $profile->item_id = $item->id;
        $profile->item_category_id = null;
        $profile->template = 'custom';
        $profile->branch_id_scope = null;
        $profile->is_published = true;
        $profile->version = (int) ($profile->version ?? 0) + 1;
        $profile->published_at = now();
        $profile->save();

        // Remplace tous les steps (idempotent).
        ItemWizardStep::where('profile_id', $profile->id)->delete();
        $pos = 0;
        foreach ($steps as $s) {
            ItemWizardStep::create([
                'profile_id'               => $profile->id,
                'step_key'                 => $s['step_key'],
                'label'                    => $s['label'],
                'source_type'              => $s['source_type'],
                'source_ref'               => $s['source_ref'],
                'source_item_attribute_id' => $s['attr_id'],
                'min_select'               => $s['min'],
                'max_select'               => $s['max'],
                'allow_repeat'             => false,
                'visible_on'               => ['pos', 'kiosk'],
                'stockable_choices'        => false,
                'position'                 => $pos++,
                'is_active'                => true,
                'addon_role'               => $s['addon_role'],
            ]);
        }
    }

    /** Dépublie les composer profiles d'items hors-carte (ne plus projeter). */
    private function unpublishProfiles(array $itemIds): void
    {
        ItemWizardProfile::withoutGlobalScopes()
            ->whereIn('item_id', $itemIds)
            ->update(['is_published' => false]);
    }

    private function findOrCreateAttribute(string $name, int $min, int $max): int
    {
        $attr = ItemAttribute::withoutGlobalScopes()->where('name', $name)->first();
        if (! $attr) {
            $attr = new ItemAttribute();
            $attr->name = $name;
        }
        $attr->min_select = $min;
        $attr->max_select = $max;
        $attr->allow_repeat = false;
        $attr->status = 5;
        if (\Schema::hasColumn('item_attributes', 'is_available')) {
            $attr->is_available = 1;
        }
        $attr->save();

        return (int) $attr->id;
    }

    private function clearAddons(int $itemId): void
    {
        ItemAddon::withoutGlobalScopes()->where('item_id', $itemId)->delete();
    }

    /**
     * Retire les crudités (group 'crudite') d'un item. Conservé comme helper de
     * réversion (les tacos ont porté « sans crudités » du 2026-06-23 au 2026-07-07 ;
     * si l'owner re-décide « tacos sans crudités », rappeler clearGarnitures ici).
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function clearGarnitures(Item $item): void
    {
        $this->syncExtras($item->id, 'crudite', []);
    }

    /** Câble une boisson OPTIONNELLE (addon role 'drink' = Boisson Seule #3), sans formule menu. */
    private function wireDrinkAddon(int $itemId): void
    {
        $exists = ItemAddon::withoutGlobalScopes()
            ->where('item_id', $itemId)
            ->where('addon_item_id', 3)
            ->whereNull('deleted_at')
            ->exists();
        if (! $exists) {
            ItemAddon::create([
                'item_id'              => $itemId,
                'addon_item_id'        => 3,        // Boisson Seule
                'addon_item_variation' => null,
                'role'                 => 'drink',
            ]);
        }
    }

    private function setItemPrice(int $itemId, float $price): void
    {
        if ($it = Item::withoutGlobalScopes()->find($itemId)) {
            $it->price = $price;
            $it->status = 5;
            $it->is_available = 1;
            $it->save();
        }
    }

    /** Ajoute/maj un seul extra dans un groupe sans toucher aux autres (additif idempotent). */
    private function addExtraToGroup(int $itemId, string $group, string $name, float $price): void
    {
        $row = ItemExtra::withoutGlobalScopes()
            ->where('item_id', $itemId)
            ->where('group_label', $group)
            ->where('name', $name)
            ->withTrashed()
            ->first();
        if ($row) {
            $row->price = $price;
            $row->status = 5;
            $row->deleted_at = null;
            $row->save();
        } else {
            ItemExtra::create([
                'item_id'     => $itemId,
                'name'        => $name,
                'price'       => $price,
                'group_label' => $group,
                'status'      => 5,
                'visible_on'  => null,
            ]);
        }
    }
}
