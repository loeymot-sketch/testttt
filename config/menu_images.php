<?php

/**
 * ============================================================================
 * MENU IMAGES - Mapping des images pour tous les éléments du menu
 * ============================================================================
 *
 * Images stockées dans public/images/menu/
 * Utilisées par: Items, Addons, Sauces, Suppléments, Garnitures
 *
 * ============================================================================
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Chemin de base des images menu
    |--------------------------------------------------------------------------
    */
    'base_path' => 'images/menu',

    /*
    |--------------------------------------------------------------------------
    | Images des Catégories (onglets POS)
    |--------------------------------------------------------------------------
    | Clé = slug de la catégorie
    */
    'categories' => [
        'nos-tacos'                 => 'tacos.png',
        'nos-sandwichs'             => 'sandwich_terminator.png',
        'nos-burgers'               => 'cheeseburger.png',
        'nos-assiettes'             => 'assiette_poulet.png',
        'ojja'                      => 'ojja.png',
        'omelettes'                 => 'omelette.png',
        'nos-salades'               => 'salade_cesar.png',
        'chicken-tenders'           => 'chicken_wings.png',
        'frites-accompagnements'    => 'frites.png',
        'nos-desserts'              => 'tiramisu.png',
        'nos-boissons'              => 'coca_cola.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images des Items (produits principaux)
    |--------------------------------------------------------------------------
    | Clé = slug de l'item (Str::slug du nom)
    */
    'items' => [
        // Tacos
        'tacos-m-1-viande'   => 'tacos.png',
        'tacos-l-2-viandes'  => 'tacos.png',
        'tacos-xl-3-viandes' => 'tacos.png',
        'tacos-xxl-4-viandes'=> 'tacos.png',

        // Sandwichs
        'le-terminator-2-viandes' => 'sandwich_terminator.png',
        'le-mega-2-viandes'      => 'sandwich_mega.png',
        'le-supreme-1-viande'    => 'sandwich_supreme.png',
        'le-cayenne-1-viande'    => 'sandwich_cayenne.png',
        'panini-1-viande'        => 'panini.png',

        // Burgers
        'cheese-burger'           => 'cheeseburger.png',
        'double-cheese'           => 'double_cheese.png',
        'fish-burger'             => 'fish_burger.png',
        'chicken-burger'          => 'chicken_burger.png',
        'grill-burger'            => 'grill_burger.png',
        'big-burger'              => 'big_burger.png',

        // Assiettes
        'assiette-poulet'         => 'assiette_poulet.png',
        'assiette-kefta'          => 'assiette_kefta.png',
        'assiette-merguez'        => 'assiette_merguez.png',
        'assiette-mixte-3-viandes'=> 'assiette_mixte.png',

        // Ojja
        'ojja-boeuf'              => 'ojja.png',
        'ojja-poulet'             => 'ojja.png',
        'ojja-viande-hachee'     => 'ojja.png',
        'ojja-merguez'            => 'ojja.png',

        // Omelettes
        'omelette-nature'         => 'omelette.png',
        'omelette-fromage'        => 'omelette_fromage.png',
        'omelette-champignons-fromage' => 'omelette_champignons.png',

        // Salades
        'salade-cesar'            => 'salade_cesar.png',
        'salade-chevre'           => 'salade_chevre.png',
        'salade-royale'           => 'salade_royale.png',
        'salade-saumon'           => 'salade_saumon.png',
        'salade-tunisienne'       => 'salade_tunisienne.png',

        // Chicken & Tenders
        'chicken-wings-6-pieces'     => 'chicken_wings.png',
        'chicken-wings-12-pieces'    => 'chicken_wings.png',
        'tenders-6-pieces'           => 'tenders.png',
        'tenders-12-pieces'          => 'tenders.png',

        // Frites
        'frites-moyenne'         => 'frites.png',
        'frites-grande'          => 'frites.png',

        // Desserts
        'glace'                  => 'glace.png',
        'tiramisu-speculoos'     => 'tiramisu.png',
        'tarte-au-daim'          => 'tarte_daim.png',

        // Boissons
        'coca-cola-33cl'         => 'coca_cola.png',
        'coca-cola-zero-33cl'    => 'coca_zero.png',
        'oasis-tropical-33cl'    => 'oasis_tropical.png',
        'oasis-pomme-cassis-33cl'=> 'oasis_pomme.png',
        'fanta-orange-33cl'      => 'fanta.png',
        'sprite-33cl'            => 'sprite.png',
        'eau-plate-50cl'         => 'eau.png',
        'eau-gazeuse-50cl'       => 'eau_gazeuse.png',
        'orangina-33cl'          => 'orangina.png',
        'capri-sun'              => 'capri_sun.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images des Addons (Menu, Frites, Boisson)
    |--------------------------------------------------------------------------
    */
    'addons' => [
        'en-menu-frites-boisson' => 'menu_complet.png',  // Frites + Boisson
        'frites-seules'          => 'frites.png',
        'boisson-seule'          => 'boisson.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images des Sauces
    |--------------------------------------------------------------------------
    */
    'sauces' => [
        'Algérienne' => 'sauce_algerienne.png',
        'Samouraï'   => 'sauce_samourai.png',
        'Big Burger' => 'sauce_burger.png',
        'Mayo'       => 'sauce_mayo.png',
        'Ketchup'    => 'sauce_ketchup.png',
        'Harissa'    => 'sauce_harissa.png',
        'Blanche'    => 'sauce_blanche.png',
        'Andalouse'  => 'sauce_andalouse.png',
        'Fish'       => 'sauce_fish.png',
        'Sans Sauce' => 'sauce_sans.png',
        'Curry'      => 'sauce_curry.png',
        'Poivre'     => 'sauce_poivre.png',
        'Sauce César'=> 'sauce_cesar.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images des Suppléments
    |--------------------------------------------------------------------------
    */
    'supplements' => [
        'Supplément Cheddar'       => 'supplement_cheddar.png',
        'Supplément Jambon'       => 'supplement_jambon.png',
        'Supplément Poulet'       => 'supplement_poulet.png',
        'Supplément Kebab'        => 'supplement_kebab.png',
        'Supplément Viande Hachée'=> 'supplement_viande.png',
        'Supplément Œuf'          => 'supplement_oeuf.png',
        'Supplément Raclette'     => 'supplement_raclette.png',
        'Supplément Boursin'      => 'supplement_boursin.png',
        'Supplément Chèvre'      => 'supplement_chevre.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images des Garnitures (Crudités)
    |--------------------------------------------------------------------------
    */
    'crudites' => [
        'Complet (Salade, Tomate, Oignon)' => 'crudites_complet.png',
        'Sans Oignon'                      => 'crudites_sans_oignon.png',
        'Sans Tomate'                      => 'crudites_sans_tomate.png',
        'Sans Salade'                      => 'crudites_sans_salade.png',
        'Aucune Crudité'                   => 'crudites_aucune.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images des Viandes
    |--------------------------------------------------------------------------
    */
    'viandes' => [
        'Poulet'       => 'viande_poulet.png',
        'Cordon Bleu'  => 'viande_cordon.png',
        'Kebab'        => 'viande_kebab.png',
        'Viande Hachée'=> 'viande_hachee.png',
        'Merguez'      => 'viande_merguez.png',
        'Nuggets'      => 'viande_nuggets.png',
        'Tenders'      => 'viande_tenders.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Image par défaut
    |--------------------------------------------------------------------------
    */
    'default' => 'item-default.png',

];
