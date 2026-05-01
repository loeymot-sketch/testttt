<?php

/**
 * ============================================================================
 * FOODKING MENU CONFIGURATION - SINGLE SOURCE OF TRUTH
 * ============================================================================
 *
 * This file is the ONLY authorized source for menu configuration.
 *
 * Restaurant: Le Cayenne
 * Locale: French (fr)
 * Currency: Euro (EUR)
 * Timezone: Europe/Paris
 * ============================================================================
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Restaurant Identity
    |--------------------------------------------------------------------------
    */
    'restaurant' => [
        'name'        => 'Le Cayenne',
        'slug'        => 'le-cayenne',
        'description' => 'Restaurant de burgers, tacos et grillades',
        'address'     => 'Paris, France',
        'phone'       => '+33 1 23 45 67 89',
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization Settings
    |--------------------------------------------------------------------------
    */
    'locale'          => 'fr',
    'currency'        => 'EUR',
    'currency_symbol' => '€',
    'timezone'        => 'Europe/Paris',

    /*
    |--------------------------------------------------------------------------
    | Menu Categories
    |--------------------------------------------------------------------------
    */
    'categories' => [
        // [BUG-4 FIX] Added wizard_template and has_menu for proper POS wizard flow
        ['name' => 'Nos Tacos', 'sort' => 1, 'description' => 'Nos délicieux tacos avec viandes au choix', 'wizard_template' => 'tacos', 'has_menu' => true],
        ['name' => 'Nos Sandwichs', 'sort' => 2, 'description' => 'Sandwichs gourmands et généreux', 'wizard_template' => 'sandwich', 'has_menu' => true],
        ['name' => 'Nos Burgers', 'sort' => 3, 'description' => 'Burgers maison 100% frais', 'wizard_template' => 'burger', 'has_menu' => true],
        ['name' => 'Nos Assiettes', 'sort' => 4, 'description' => 'Assiettes complètes avec garnitures', 'wizard_template' => 'assiette', 'has_menu' => false],
        ['name' => 'Ojja', 'sort' => 5, 'description' => 'Ojja traditionnelle', 'wizard_template' => 'simple', 'has_menu' => false],
        ['name' => 'Omelettes', 'sort' => 6, 'description' => 'Omelettes faites maison', 'wizard_template' => 'omelette', 'has_menu' => false],
        ['name' => 'Nos Salades', 'sort' => 7, 'description' => 'Salades fraîches et légères', 'wizard_template' => 'salade', 'has_menu' => false],
        ['name' => 'Poulet croustillant', 'slug' => 'chicken-tenders', 'sort' => 8, 'description' => 'Ailes et filets de poulet croustillants', 'wizard_template' => 'snacking', 'has_menu' => false],
        ['name' => 'Nos Menus Enfants', 'sort' => 9, 'description' => 'Pour les petits gourmands', 'wizard_template' => 'simple', 'has_menu' => false],
        ['name' => 'Frites & Accompagnements', 'sort' => 10, 'description' => 'Frites et accompagnements', 'wizard_template' => 'simple', 'has_menu' => false],
        ['name' => 'Nos Desserts', 'sort' => 11, 'description' => 'Desserts gourmands', 'wizard_template' => 'simple', 'has_menu' => false],
        ['name' => 'Nos Boissons', 'sort' => 12, 'description' => 'Boissons fraîches', 'wizard_template' => 'simple', 'has_menu' => false],
        ['name' => 'Suppléments', 'sort' => 13, 'description' => 'Suppléments et extras commandables séparément', 'wizard_template' => 'simple', 'has_menu' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Menu Configuration
    |--------------------------------------------------------------------------
    */
    'settings' => [
        'tax_rate'              => 10.00,
        'default_tax_id'        => 1,
        'status_active'         => \App\Enums\Status::ACTIVE,
        'featured_default'      => true,
        'currency_decimals'     => 2,
        'price_format'          => '%s €',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meat Options (from Image 2)
    |--------------------------------------------------------------------------
    */
    'meats' => [
        'Merguez',
        'Kefta',
        'Mexicain',
        'Cordon Bleu',
        'Viande Hachée',
        'Nuggets',
        'Escalope de poulet',
        'Tenders',
        'Fricandelle',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sauce Options (from Image 2)
    |--------------------------------------------------------------------------
    */
    'sauces' => [
        'Ketchup',
        'Mayonnaise',
        'Algérienne',
        'Curry',
        'Andalouse',
        'Burger',
        'Samouraï',
        'Barbecue',
        'Cocktail',
        'Américaine',
        'Hannibal',
        'Harissa',
        'Blanche',
        'Poivre',
        'Sans Sauce',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crudité Options (Atomiques - Sprint 23 Fix)
    | Chaque crudité est un élément individuel toggle-able (vert/rouge)
    |--------------------------------------------------------------------------
    */
    'crudites' => [
        'Salade',
        'Tomate',
        'Oignon',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplement Options (from Image 2)
    |--------------------------------------------------------------------------
    */
    'supplements' => [
        'Jambon de dinde'           => 1.00,
        'Boursin'                   => 1.00,
        'Fromage a raclette'        => 1.00,
        'Œuf'                       => 1.00,
        'Fromage'                   => 1.00,
        'Galette pommes de terre'   => 1.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplement Sauce Price (from Image 2)
    |--------------------------------------------------------------------------
    */
    'supplement_sauce_price' => 0.50,

    /*
    |--------------------------------------------------------------------------
    | Menu Items
    |--------------------------------------------------------------------------
    */
    'items' => [

        // =========================================================================
        // TACOS
        // =========================================================================
        'nos-tacos' => [
            [
                'name'        => 'Tacos M (1 Viande)',
                'price'       => 6.50,
                'description' => '1 Viande au choix',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Tacos L (2 Viandes)',
                'price'       => 8.50,
                'description' => '2 Viandes au choix',
                'viandes'     => 2,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Tacos XL (3 Viandes)',
                'price'       => 10.50,
                'description' => '3 Viandes au choix',
                'viandes'     => 3,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Tacos XXL (4 Viandes)',
                'price'       => 12.50,
                'description' => '4 Viandes au choix',
                'viandes'     => 4,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
        ],

        // =========================================================================
        // SANDWICHS
        // =========================================================================
        'nos-sandwichs' => [
            [
                'name'        => 'Le Méga',
                'price'       => 8.00,
                'description' => '2 viandes au choix + Cheddar + Oeuf',
                'viandes'     => 2,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Le Terminator',
                'price'       => 9.00,
                'description' => '2 viandes au choix + 2 Cheddar + Oeuf + Jambon de dinde',
                'viandes'     => 2,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Le Suprême',
                'price'       => 7.00,
                'description' => 'Steak + Cordon Bleu + Cheddar',
                'viandes'     => 1, // Only fixed meats but let's allow customization or not. It says "Steak + Cordon Bleu" directly. I'll limit to 0 custom meats since it's fixed.
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Le Cayenne',
                'price'       => 7.00,
                'description' => 'Viande hachée ou chicken + mozzarella + cheddar + crème fraîche',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Sandwich Froid',
                'price'       => 4.50,
                'description' => 'Sandwich au Thon',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Panini',
                'price'       => 5.00,
                'description' => 'Thon - Jambon - Viande hachée - Fromage de chèvre - Saumon - Escalope de poulet',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Sandwich Classique (Pain)',
                'price'       => 6.50,
                'description' => '1 Viande au choix dans un pain classique',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Sandwich Classique (Galette)',
                'price'       => 6.50,
                'description' => '1 Viande au choix dans une galette',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
        ],

        // =========================================================================
        // BURGERS
        // =========================================================================
        'nos-burgers' => [
            [
                'name'        => 'Burger Poulet',
                'slug'        => 'chicken-burger',
                'price'       => 6.00,
                'description' => 'Poulet pané + Cheddar',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Cheese Burger',
                'price'       => 6.00,
                'description' => '1 Steak + 1 Cheddar',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Fish Burger',
                'price'       => 6.00,
                'description' => 'Poisson pané + Cheddar',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Double Cheese',
                'price'       => 7.00,
                'description' => '2 Steaks + 2 Cheddars',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Big Burger',
                'price'       => 9.00,
                'description' => '3 Steaks + 3 Cheddar + 2 Jambon de dinde',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Grill Burger',
                'price'       => 8.00,
                'description' => '2 Steaks + 2 Cheddars + Jambon de dinde',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
        ],

        // =========================================================================
        // ASSIETTES
        // =========================================================================
        'nos-assiettes' => [
            [
                'name'        => 'Assiette Poulet',
                'price'       => 12.50,
                'description' => 'Poulet (Nature - Curry - Paprika) + Frites + Salade + Pain + Sauce',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Assiette Kefta',
                'price'       => 12.50,
                'description' => 'Kefta + Frites + Salade + Pain + Sauce',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Assiette Merguez',
                'price'       => 12.50,
                'description' => 'Merguez + Frites + Salade + Pain + Sauce',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Assiette Mixte',
                'price'       => 14.50,
                'description' => 'Poulet - Kefta - Merguez + Frites + Salade + Pain + Sauce',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // OJJA
        // =========================================================================
        'ojja' => [
            [
                'name'        => 'Ojja Bœuf',
                'price'       => 13.50,
                'description' => 'Ojja avec Bœuf + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Ojja Poulet',
                'price'       => 13.50,
                'description' => 'Ojja avec Poulet + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Ojja Viande Hachée',
                'price'       => 13.50,
                'description' => 'Ojja avec Viande Hachée + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Ojja Merguez',
                'price'       => 13.50,
                'description' => 'Ojja avec Merguez + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // OMELETTES
        // =========================================================================
        'omelettes' => [
            [
                'name'        => 'Omelette Nature',
                'price'       => 7.50,
                'description' => 'Omelette classique + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Omelette Fromage',
                'price'       => 8.50,
                'description' => 'Omelette avec Fromage + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Omelette Champignons Fromage',
                'price'       => 9.50,
                'description' => 'Omelette avec Champignons et Fromage + Frites + Pain',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // SALADES
        // =========================================================================
        'nos-salades' => [
            [
                'name'        => 'Salade Chèvre',
                'price'       => 7.50,
                'description' => 'Laitue - Tomate - Chèvre - Croûtons - Vinaigrette - Maïs',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade Royale',
                'price'       => 7.50,
                'description' => 'Laitue - Tomate - Maïs - Poulet - Olives',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade Saumon',
                'price'       => 7.50,
                'description' => 'Laitue - Tomate - Maïs - Saumon - Olives',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade Tunisienne',
                'price'       => 7.50,
                'description' => 'Concombre - Tomate - Oignon - Poivrons - Thon - Olives - Huile D\'Olive',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // CHICKEN & TENDERS
        // =========================================================================
        'chicken-tenders' => [
            [
                'name'        => 'Ailes de poulet (6 pièces)',
                'slug'        => 'chicken-wings-6-pieces',
                'price'       => 6.00,
                'description' => '6 ailes de poulet croustillantes',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Ailes de poulet (12 pièces)',
                'slug'        => 'chicken-wings-12-pieces',
                'price'       => 10.50,
                'description' => '12 ailes de poulet croustillantes',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Filets de poulet croustillants (6 pièces)',
                'slug'        => 'tenders-6-pieces',
                'price'       => 7.50,
                'description' => '6 filets de poulet croustillants',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Filets de poulet croustillants (12 pièces)',
                'slug'        => 'tenders-12-pieces',
                'price'       => 13.50,
                'description' => '12 filets de poulet croustillants',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // MENUS ENFANTS
        // =========================================================================
        'nos-menus-enfants' => [
            [
                'name'        => 'Menu Cheese Burger (Enfant)',
                'price'       => 6.00,
                'description' => '1 steak + 1 cheddar + frites + Capri sun',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Menu Nuggets (Enfant)',
                'price'       => 6.00,
                'description' => '6 Nuggets de poulet + frites + Capri sun',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // FRITES & ACCOMPAGNEMENTS
        // =========================================================================
        'frites-accompagnements' => [
            [
                'name'        => 'Frites Moyenne',
                'price'       => 2.50,
                'description' => 'Portion moyenne de frites',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
                'is_frites'   => true,
            ],
            [
                'name'        => 'Frites Grande',
                'price'       => 4.00,
                'description' => 'Grande portion de frites',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
                'is_frites'   => true,
            ],
        ],

        // =========================================================================
        // DESSERTS
        // =========================================================================
        'nos-desserts' => [
            [
                'name'        => 'Glace',
                'price'       => 3.80,
                'description' => 'Glace',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Tarte Daim',
                'price'       => 3.80,
                'description' => 'Tarte au Daim',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Tiramisu',
                'price'       => 3.80,
                'description' => 'Tiramisu',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // BOISSONS
        // =========================================================================
        'nos-boissons' => [
            [
                'name'        => 'Coca-Cola 33cl',
                'price'       => 1.50,
                'description' => 'Coca-Cola original',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Coca-Cola Zero 33cl',
                'price'       => 1.50,
                'description' => 'Coca-Cola sans sucre',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Fanta Orange 33cl',
                'price'       => 1.50,
                'description' => 'Fanta Orange',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Sprite 33cl',
                'price'       => 1.50,
                'description' => 'Sprite',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Oasis Tropical 33cl',
                'price'       => 1.50,
                'description' => 'Oasis Tropical',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Orangina 33cl',
                'price'       => 1.50,
                'description' => 'Orangina',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Eau Plate 50cl',
                'price'       => 1.00,
                'description' => 'Eau minérale',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Capri-Sun',
                'price'       => 1.50,
                'description' => 'Capri-Sun 20cl',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
        ],

        // =========================================================================
        // SUPPLÉMENTS (items commandables séparément au POS)
        // =========================================================================
        'supplements' => [
            [
                'name'        => 'Sauce supplémentaire',
                'price'       => 0.50,
                'description' => 'Sauce au choix en supplément',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Fromage supplémentaire',
                'price'       => 1.00,
                'description' => 'Fromage en supplément',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Jambon de dinde',
                'price'       => 1.00,
                'description' => 'Supplément jambon de dinde',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Boursin',
                'price'       => 1.00,
                'description' => 'Supplément Boursin',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Fromage à raclette',
                'price'       => 1.00,
                'description' => 'Supplément fromage à raclette',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Œuf',
                'price'       => 1.00,
                'description' => 'Supplément œuf',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Galette pommes de terre',
                'price'       => 1.00,
                'description' => 'Supplément galette pommes de terre',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade verte',
                'price'       => 2.00,
                'description' => 'Salade verte en accompagnement',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Addons (Upsell Items)
    |--------------------------------------------------------------------------
    */
    'addons' => [
        [
            'name'  => 'Menu (Frites + Boisson)',
            'price' => 3.00,
        ],
        [
            'name'  => 'Frites Seules',
            'price' => 2.00,
        ],
        [
            'name'  => 'Boisson Seule',
            'price' => 2.00,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Protection Settings
    |--------------------------------------------------------------------------
    */
    'protection' => [
        'block_english_items'     => true,
        'block_non_eur_currency'  => true,
        'require_french_locale'   => true,
        'verify_on_seed'          => true,
    ],

];
