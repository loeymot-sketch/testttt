<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\ItemCategory;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemExtra;
use App\Models\ItemAddon;

/**
 * Seeder officiel "Le Grill House"
 * Intègre la logique métier avancée :
 * - STO (Salade Tomate Oignon) via ItemAttribute (Radio) -> Kiosk CategoryHelper (AttributeSlotType.garnish)
 * - Choix Viandes (1 à 3) via ItemAttribute (Radio) -> Kiosk (AttributeSlotType.meat)
 * - Sauce 1 Gratuite via ItemAttribute (Radio) -> Kiosk (AttributeSlotType.sauce)
 * - Suppléments Sauces & Ingrédients via ItemExtra (Checkboxes continues)
 * - Menu (Formule +3€) via ItemAddon -> Kiosk (Addons tunnel)
 */
class GrillHouseMenuSeeder extends Seeder
{
    public function run()
    {
        // === 1. PURGE COMPLETE ===
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('item_categories')->truncate();
        DB::table('items')->truncate();
        DB::table('item_attributes')->truncate();
        DB::table('item_variations')->truncate();
        DB::table('item_extras')->truncate();
        DB::table('item_addons')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Helpers locaux pour le champ slug requis
        $createCat = function ($name, $sort) {
            return ItemCategory::create(['name' => $name, 'slug' => Str::slug($name), 'status' => 1, 'sort' => $sort]);
        };
        $createItem = function ($name, $catId, $price, $desc = '') {
            return Item::create(['name' => $name, 'slug' => Str::slug($name), 'item_category_id' => $catId, 'price' => $price, 'description' => $desc, 'status' => 1]);
        };

        // === 2. CATEGORIES ===
        $catTacos = $createCat('Nos Tacos', 1);
        $catSandwich = $createCat('Nos Sandwichs', 2);
        $catBurger = $createCat('Nos Burgers', 3);
        $catAssiette = $createCat('Nos Assiettes', 4);
        $catSalades = $createCat('Nos Salades & Omelettes', 5);
        $catSnacking = $createCat('Snacking', 6);
        $catDessert = $createCat('Nos Desserts', 7);
        $catBoisson = $createCat('Nos Boissons', 8);

        // === 3. ITEMS ADDONS (Upsell: Menu, Frites, Boissons) ===
        $itemMenuUpsell = $createItem('En Menu (Frites + Boisson)', $catSnacking->id, 3.00);
        $itemFritesUpsell = $createItem('Frites Seules', $catSnacking->id, 1.50);
        $itemBoissonUpsell = $createItem('Boisson Seule', $catBoisson->id, 1.50);

        // === 3.5. ATTRIBUTS GLOBAUX ===
        $attrViande1 = ItemAttribute::create(['name' => 'Viande 1', 'status' => 1]);
        $attrViande2 = ItemAttribute::create(['name' => 'Viande 2', 'status' => 1]);
        $attrViande3 = ItemAttribute::create(['name' => 'Viande 3', 'status' => 1]);
        $attrSauce = ItemAttribute::create(['name' => 'Sauce (1 Gratuite)', 'status' => 1]);
        $attrCrudite = ItemAttribute::create(['name' => 'Crudités', 'status' => 1]);

        $attrsViande = [$attrViande1, $attrViande2, $attrViande3];

        // Listes de variations statiques
        $viandes = ['Poulet', 'Cordon Bleu', 'Kebab', 'Viande Hachée', 'Merguez', 'Nuggets', 'Tenders'];
        $sauces = ['Algérienne', 'Samouraï', 'Big Burger', 'Mayo', 'Ketchup', 'Harissa', 'Blanche', 'Andalouse', 'Fish', 'Sans Sauce'];
        $crudites = ['Complet (Salade, Tomate, Oignon)', 'Sans Oignon', 'Sans Tomate', 'Sans Salade', 'Aucune Crudité'];

        // Suppléments (Payants, Checkboxes multiples)
        $supplements = [
            'Supplément Cheddar' => 1.00,
            'Supplément Jambon' => 1.00,
            'Supplément Poulet' => 2.00,
            'Supplément Kebab' => 2.00,
            'Supplément Viande Hachée' => 2.00,
            'Supplément Oeuf' => 1.00,
            'Supplément Raclette' => 1.00,
            'Supplément Boursin' => 1.00,
            'Supplément Chèvre' => 1.00,
            'Sauce supplémentaire: Algérienne' => 0.50,
            'Sauce supplémentaire: Samouraï' => 0.50,
            'Sauce supplémentaire: Big Burger' => 0.50,
            'Sauce supplémentaire: Blanche' => 0.50,
            'Sauce supplémentaire: Ketchup' => 0.50,
            'Sauce supplémentaire: Mayo' => 0.50,
        ];

        // LOGIQUE D'ATTACHEMENT (Magie du configurateur)
        $attachLogic = function ($item, $nbViandes = 0, $hasSauceGratuite = false, $hasCrudites = false) use ($viandes, $sauces, $crudites, $supplements, $itemMenuUpsell, $itemFritesUpsell, $itemBoissonUpsell, $attrsViande, $attrSauce, $attrCrudite) {

            // 1. ADDONS (Proposition du Menu à +3€, ou frites à +1.50€)
            ItemAddon::create(['item_id' => $item->id, 'addon_item_id' => $itemMenuUpsell->id]);
            ItemAddon::create(['item_id' => $item->id, 'addon_item_id' => $itemFritesUpsell->id]);
            ItemAddon::create(['item_id' => $item->id, 'addon_item_id' => $itemBoissonUpsell->id]);

            // 2. CHOIX VIANDES (Requis)
            for ($i = 0; $i < $nbViandes; $i++) {
                foreach ($viandes as $v) {
                    ItemVariation::create([
                        'item_id' => $item->id,
                        'item_attribute_id' => $attrsViande[$i]->id,
                        'name' => $v,
                        'price' => 0.00,
                        'status' => 1
                    ]);
                }
            }

            // 3. SAUCE GRATUITE (Requis)
            if ($hasSauceGratuite) {
                foreach ($sauces as $s) {
                    ItemVariation::create([
                        'item_id' => $item->id,
                        'item_attribute_id' => $attrSauce->id,
                        'name' => $s,
                        'price' => 0.00,
                        'status' => 1
                    ]);
                }
            }

            // 4. CRUDITÉS (Requis: Salade Tomate Oignon)
            if ($hasCrudites) {
                foreach ($crudites as $c) {
                    ItemVariation::create([
                        'item_id' => $item->id,
                        'item_attribute_id' => $attrCrudite->id,
                        'name' => $c,
                        'price' => 0.00,
                        'status' => 1
                    ]);
                }
            }

            // 5. EXTRAS / SUPPLÉMENTS (Optionnels)
            foreach ($supplements as $supName => $supPrice) {
                ItemExtra::create([
                    'item_id' => $item->id,
                    'name' => $supName,
                    'price' => $supPrice,
                    'status' => 1
                ]);
            }
        };

        // === 5. CRÉATION DES PRODUITS DE LA CARTE ===

        // -- Tacos (Ont droit aux crudités, sauces gratuites et viandes) --
        $tM = $createItem('Tacos M (1 Viande)', $catTacos->id, 6.50);
        $attachLogic($tM, 1, true, true);
        $tL = $createItem('Tacos L (2 Viandes)', $catTacos->id, 8.50);
        $attachLogic($tL, 2, true, true);
        $tXL = $createItem('Tacos XL (3 Viandes)', $catTacos->id, 10.50);
        $attachLogic($tXL, 3, true, true);

        // -- Sandwichs --
        $terminator = $createItem('Le Terminator (2 Viandes)', $catSandwich->id, 9.00, '2 Viandes au choix + 1 Œuf + Jambon de dinde');
        $attachLogic($terminator, 2, true, true);
        $mega = $createItem('Le Méga (2 Viandes)', $catSandwich->id, 8.50, '2 Viandes au choix + Double cheddar');
        $attachLogic($mega, 2, true, true);
        $suprême = $createItem('Le Suprême (1 Viande)', $catSandwich->id, 7.00, '1 Viande au choix + Boursin + Cheddar + Œuf');
        $attachLogic($suprême, 1, true, true);
        $cayenne = $createItem('Le Cayenne (1 Viande)', $catSandwich->id, 6.50, '1 Viande au choix + Cheddar');
        $attachLogic($cayenne, 1, true, true);
        $panini = $createItem('Panini', $catSandwich->id, 5.00, '1 Viande au choix + Cheddar');
        $attachLogic($panini, 1, true, true);

        // -- Burgers (Pas de choix de viande, mais Sauces et STO oui) --
        $bDoubleCheese = $createItem('Double Cheese', $catBurger->id, 5.50);
        $attachLogic($bDoubleCheese, 0, true, true);
        $bFish = $createItem('Fish Burger', $catBurger->id, 5.50);
        $attachLogic($bFish, 0, true, true);
        $bChicken = $createItem('Chicken Burger', $catBurger->id, 5.50);
        $attachLogic($bChicken, 0, true, true);
        $bGrill = $createItem('Grill Burger', $catBurger->id, 7.00);
        $attachLogic($bGrill, 0, true, true);
        $bBig = $createItem('Big Burger', $catBurger->id, 6.50);
        $attachLogic($bBig, 0, true, true);

        // -- Assiettes & Salades --
        $aMixte = $createItem('Assiette Mixte (3 Viandes)', $catAssiette->id, 14.50);
        $attachLogic($aMixte, 3, true, true);

        $saladeCesar = $createItem('Salade César', $catSalades->id, 7.50);
        ItemVariation::create(['item_id' => $saladeCesar->id, 'item_attribute_id' => $attrSauce->id, 'name' => 'Sauce César', 'price' => 0.00, 'status' => 1]);
        ItemVariation::create(['item_id' => $saladeCesar->id, 'item_attribute_id' => $attrSauce->id, 'name' => 'Sans Sauce', 'price' => 0.00, 'status' => 1]);

        // -- Frites --
        $grandeFrite = $createItem('Frites (Grande)', $catSnacking->id, 3.00);
        foreach ($sauces as $s) {
            ItemExtra::create(['item_id' => $grandeFrite->id, 'name' => 'Sauce ' . $s, 'price' => 0.50, 'status' => 1]);
        }
        ItemExtra::create(['item_id' => $grandeFrite->id, 'name' => 'Cheddar fondu', 'price' => 1.50, 'status' => 1]);

        // -- Desserts -- 
        $createItem('Tiramisu Speculoos', $catDessert->id, 3.00);
        $createItem('Tarte au Daim', $catDessert->id, 3.50);

        // -- Boissons --
        $createItem('Coca-Cola 33cl', $catBoisson->id, 1.50);
        $createItem('Oasis Tropical 33cl', $catBoisson->id, 1.50);
    }
}
