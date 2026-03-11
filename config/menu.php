<?php

/**
 * ============================================================================
 * FOODKING MENU CONFIGURATION - SINGLE SOURCE OF TRUTH
 * ============================================================================
 *
 * This file is the ONLY authorized source for menu configuration.
 * DO NOT MODIFY WITHOUT ARCHITECT APPROVAL.
 *
 * Restaurant: Le Grill House
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
    |
    | Core restaurant information. These values define the restaurant
    | branding and are used throughout the application.
    |
    */

    'restaurant' => [
        'name'        => 'Le Grill House',
        'slug'        => 'le-grill-house',
        'description' => 'Restaurant de burgers, tacos et grillades',
        'address'     => 'Paris, France',
        'phone'       => '+33 1 23 45 67 89',
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization Settings
    |--------------------------------------------------------------------------
    |
    | CRITICAL: These settings are MANDATORY and cannot be changed.
    | The application is designed specifically for French market.
    |
    */

    'locale'          => 'fr',              // DO NOT CHANGE - French locale required
    'currency'        => 'EUR',             // Euro currency
    'currency_symbol' => '€',               // Euro symbol
    'timezone'        => 'Europe/Paris',    // French timezone

    /*
    |--------------------------------------------------------------------------
    | Menu Categories
    |--------------------------------------------------------------------------
    |
    | All menu categories in French. These are the ONLY authorized categories.
    | DO NOT add English categories.
    |
    */

    'categories' => [
        ['name' => 'Nos Tacos', 'sort' => 1, 'description' => 'Nos délicieux tacos avec viandes au choix'],
        ['name' => 'Nos Sandwichs', 'sort' => 2, 'description' => 'Sandwichs gourmands et généreux'],
        ['name' => 'Nos Burgers', 'sort' => 3, 'description' => 'Burgers maison 100% frais'],
        ['name' => 'Nos Assiettes', 'sort' => 4, 'description' => 'Assiettes complètes avec garnitures'],
        ['name' => 'Ojja', 'sort' => 5, 'description' => 'Ojja traditionnelle'],
        ['name' => 'Omelettes', 'sort' => 6, 'description' => 'Omelettes faites maison'],
        ['name' => 'Nos Salades', 'sort' => 7, 'description' => 'Salades fraîches et légères'],
        ['name' => 'Chicken & Tenders', 'sort' => 8, 'description' => 'Ailes de poulet et tenders croustillants'],
        ['name' => 'Frites & Accompagnements', 'sort' => 9, 'description' => 'Frites et accompagnements'],
        ['name' => 'Nos Desserts', 'sort' => 10, 'description' => 'Desserts gourmands'],
        ['name' => 'Nos Boissons', 'sort' => 11, 'description' => 'Boissons fraîches'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Menu Configuration
    |--------------------------------------------------------------------------
    |
    | Global menu settings and constraints.
    |
    */

    'settings' => [
        'tax_rate'              => 10.00,     // TVA restaurant France
        'default_tax_id'        => 1,
        'status_active'         => 1,
        'featured_default'      => true,
        'currency_decimals'     => 2,
        'price_format'          => '%s €',  // sprintf format
    ],

    /*
    |--------------------------------------------------------------------------
    | Meat Options
    |--------------------------------------------------------------------------
    |
    | Available meat choices for customizable items.
    |
    */

    'meats' => [
        'Poulet',
        'Cordon Bleu',
        'Kebab',
        'Viande Hachée',
        'Merguez',
        'Nuggets',
        'Tenders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sauce Options
    |--------------------------------------------------------------------------
    |
    | Available sauces. First sauce is always free (included in base price).
    |
    */

    'sauces' => [
        'Algérienne',
        'Samouraï',
        'Big Burger',
        'Mayo',
        'Ketchup',
        'Harissa',
        'Blanche',
        'Andalouse',
        'Fish',
        'Sans Sauce',
        'Curry',
        'Poivre',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crudité Options
    |--------------------------------------------------------------------------
    |
    | Available vegetable/garnish options.
    |
    */

    'crudites' => [
        'Complet (Salade, Tomate, Oignon)',
        'Sans Oignon',
        'Sans Tomate',
        'Sans Salade',
        'Aucune Crudité',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplement Options
    |--------------------------------------------------------------------------
    |
    | Available extras/supplements with their prices in EUR.
    |
    */

    'supplements' => [
        'Supplément Cheddar'           => 1.00,
        'Supplément Jambon'            => 1.00,
        'Supplément Poulet'            => 2.00,
        'Supplément Kebab'             => 2.00,
        'Supplément Viande Hachée'     => 2.00,
        'Supplément Œuf'               => 1.00,
        'Supplément Raclette'          => 1.00,
        'Supplément Boursin'           => 1.00,
        'Supplément Chèvre'            => 1.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplement Sauce Price
    |--------------------------------------------------------------------------
    |
    | Price for additional sauces beyond the first free one.
    |
    */

    'supplement_sauce_price' => 0.50,

    /*
    |--------------------------------------------------------------------------
    | Menu Items
    |--------------------------------------------------------------------------
    |
    | All menu items with their prices in EUR.
    | Structure: [category_slug => [items]]
    |
    */

    'items' => [

        // =========================================================================
        // TACOS
        // =========================================================================
        'nos-tacos' => [
            [
                'name'        => 'Tacos M (1 Viande)',
                'price'       => 6.50,
                'description' => '1 Viande au choix + Sauce + Garnitures',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Tacos L (2 Viandes)',
                'price'       => 8.50,
                'description' => '2 Viandes au choix + Sauce + Garnitures',
                'viandes'     => 2,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Tacos XL (3 Viandes)',
                'price'       => 10.50,
                'description' => '3 Viandes au choix + Sauce + Garnitures',
                'viandes'     => 3,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Tacos XXL (4 Viandes)',
                'price'       => 12.50,
                'description' => '4 Viandes au choix + Sauce + Garnitures',
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
                'name'        => 'Le Terminator (2 Viandes)',
                'price'       => 9.00,
                'description' => '2 Viandes + Œuf + Jambon + Double Cheddar',
                'viandes'     => 2,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Le Méga (2 Viandes)',
                'price'       => 8.00,
                'description' => '2 Viandes au choix + Double Cheddar',
                'viandes'     => 2,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Le Suprême (1 Viande)',
                'price'       => 7.00,
                'description' => '1 Viande + Boursin + Cheddar + Œuf',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Le Cayenne (1 Viande)',
                'price'       => 7.00,
                'description' => '1 Viande + Cheddar',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Panini (1 Viande)',
                'price'       => 5.00,
                'description' => '1 Viande + Cheddar',
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
                'name'        => 'Cheese Burger',
                'price'       => 5.50,
                'description' => 'Steak + Cheddar + Sauce + Garnitures',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Double Cheese',
                'price'       => 7.00,
                'description' => '2 Steaks + Double Cheddar',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Fish Burger',
                'price'       => 6.00,
                'description' => 'Poisson pané + Cheddar + Sauce',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Chicken Burger',
                'price'       => 6.00,
                'description' => 'Poulet pané + Cheddar + Sauce',
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
            [
                'name'        => 'Big Burger',
                'price'       => 6.50,
                'description' => '2 Steaks + 3 Cheddars',
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
                'description' => 'Poulet + Garnitures + Sauce',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Assiette Kefta',
                'price'       => 12.50,
                'description' => 'Kefta + Garnitures + Sauce',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Assiette Merguez',
                'price'       => 12.50,
                'description' => 'Merguez + Garnitures + Sauce',
                'viandes'     => 1,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Assiette Mixte (3 Viandes)',
                'price'       => 14.50,
                'description' => '3 Viandes au choix + Garnitures + Sauce',
                'viandes'     => 3,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
        ],

        // =========================================================================
        // OJJA
        // =========================================================================
        'ojja' => [
            [
                'name'        => 'Ojja Bœuf',
                'price'       => 13.50,
                'description' => 'Ojja complète avec Bœuf',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Ojja Poulet',
                'price'       => 13.50,
                'description' => 'Ojja complète avec Poulet',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Ojja Viande Hachée',
                'price'       => 13.50,
                'description' => 'Ojja complète avec Viande Hachée',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
            [
                'name'        => 'Ojja Merguez',
                'price'       => 13.50,
                'description' => 'Ojja complète avec Merguez',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> true,
            ],
        ],

        // =========================================================================
        // OMELETTES
        // =========================================================================
        'omelettes' => [
            [
                'name'        => 'Omelette Nature',
                'price'       => 7.50,
                'description' => 'Omelette classique',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Omelette Fromage',
                'price'       => 8.50,
                'description' => 'Omelette avec Fromage',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Omelette Champignons Fromage',
                'price'       => 9.50,
                'description' => 'Omelette complète',
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
                'name'        => 'Salade César',
                'price'       => 7.50,
                'description' => 'Salade avec Poulet et Sauce César',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
                'sauce_special' => ['Sauce César', 'Sans Sauce'],
            ],
            [
                'name'        => 'Salade Chèvre',
                'price'       => 7.50,
                'description' => 'Salade avec Chèvre chaud',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade Royale',
                'price'       => 7.50,
                'description' => 'Salade complète',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade Saumon',
                'price'       => 7.50,
                'description' => 'Salade avec Saumon',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Salade Tunisienne',
                'price'       => 7.50,
                'description' => 'Salade style Tunisien',
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
                'name'        => 'Chicken Wings (6 pièces)',
                'price'       => 6.00,
                'description' => '6 Ailes de poulet panées',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Chicken Wings (12 pièces)',
                'price'       => 10.50,
                'description' => '12 Ailes de poulet panées',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Tenders (6 pièces)',
                'price'       => 6.00,
                'description' => '6 Filets de poulet panés',
                'viandes'     => 0,
                'has_sauce'   => true,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Tenders (12 pièces)',
                'price'       => 10.50,
                'description' => '12 Filets de poulet panés',
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
                'description' => 'Glace artisanale',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Tiramisu Speculoos',
                'price'       => 3.80,
                'description' => 'Tiramisu au Speculoos',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Tarte au Daim',
                'price'       => 3.80,
                'description' => 'Tarte au Daim suédois',
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
                'name'        => 'Oasis Tropical 33cl',
                'price'       => 1.50,
                'description' => 'Oasis Tropical',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Oasis Pomme Cassis 33cl',
                'price'       => 1.50,
                'description' => 'Oasis Pomme Cassis',
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
                'description' => 'Sprite citron-citron vert',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Eau Plate 50cl',
                'price'       => 1.00,
                'description' => 'Eau minérale plate',
                'viandes'     => 0,
                'has_sauce'   => false,
                'has_crudites'=> false,
            ],
            [
                'name'        => 'Eau Gazeuse 50cl',
                'price'       => 1.20,
                'description' => 'Eau minérale gazeuse',
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
                'name'        => 'Capri-Sun',
                'price'       => 1.00,
                'description' => 'Jus Capri-Sun',
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
    |
    | Items that can be added to other items as upsell.
    |
    */

    'addons' => [
        [
            'name'  => 'En Menu (Frites + Boisson)',
            'price' => 3.00,
        ],
        [
            'name'  => 'Frites Seules',
            'price' => 1.50,
        ],
        [
            'name'  => 'Boisson Seule',
            'price' => 1.50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Protection Settings
    |--------------------------------------------------------------------------
    |
    | Security and validation settings to prevent accidental modifications.
    |
    */

    'protection' => [
        'block_english_items'     => true,
        'block_non_eur_currency'  => true,
        'require_french_locale'   => true,
        'verify_on_seed'          => true,
    ],

];
