<?php

/**
 * ============================================================================
 * MENU IMAGES — Le Cayenne canonical mapping (V2 2026-05-21)
 * ============================================================================
 *
 * Le Cayenne owner-curated asset pack, 85 detoured PNGs, kebab-case strict.
 * Source: image POS Kiosk Wizard/_LISEZMOI.md (transfer manifest 2026-05-21).
 *
 * Images live in public/images/menu/<name>.png.
 * Categories, items, sauces, viandes, crudités, suppléments use the same flat
 * directory so Spatie media fallback + slug-keyed lookup resolve from a single
 * place.
 *
 * Resolution order in app (see App\Models\Item::getThumbAttribute,
 * App\Models\ItemCategory::getThumbAttribute, App\Models\ItemVariation::getThumbAttribute,
 * App\Models\ItemExtra::getThumbAttribute):
 *   1. Spatie media collection ('item', 'item-category') if present
 *   2. config('menu_images.<bucket>.<key>') as filename
 *   3. config('menu_images.default') fallback ('item-default.svg')
 *
 * Legacy slugs are kept under each bucket so V0 seeders / fixtures still
 * resolve to a sensible image without breaking older orders.
 * ============================================================================
 */

return [

    'base_path' => 'images/menu',

    /*
    |--------------------------------------------------------------------------
    | Categories (POS / Kiosk tab strip)
    |--------------------------------------------------------------------------
    | Live DB slugs 2026-05-21 — 11 categories.
    */
    'categories' => [
        'sandwich-cayenne'   => 'cat-sandwich-cayenne.png',
        'galette'            => 'cat-galette.png',
        'sandwich-classique' => 'cat-sandwich-classique.png',
        'burgers'            => 'cat-burgers.png',
        'tacos'              => 'cat-tacos.png',
        'bols-gourmands'     => 'cat-bols-gourmands.png',
        'frites'             => 'cat-frites.png',
        'supplements'        => 'cat-supplements.png',
        'desserts'           => 'cat-desserts.png',
        'boissons'           => 'cat-boissons.png',
        'menu-enfant'        => 'cat-menu-enfant.png',

        // Le Cayenne caisse v5 2026-06-24 — catégories renommées (slug changé) :
        // cat 1 « Sandwich Cayenne » → « Sandwichs » (slug sandwichs) ; cat 6
        // « Bols Gourmands » → « Bols » (slug bols). Sans ces entrées → item-default.
        'sandwichs'          => 'cat-sandwich-cayenne.png',
        'bols'               => 'cat-bols-gourmands.png',

        // Legacy V0 slugs (back-compat for fixtures / archived orders).
        'nos-tacos'              => 'cat-tacos.png',
        'nos-sandwichs'          => 'cat-sandwich-classique.png',
        'nos-burgers'            => 'cat-burgers.png',
        'nos-assiettes'          => 'cat-sandwich-cayenne.png',
        'ojja'                   => 'cat-sandwich-classique.png',
        'omelettes'              => 'cat-sandwich-classique.png',
        'nos-salades'            => 'cat-bols-gourmands.png',
        'chicken-tenders'        => 'cat-menu-enfant.png',
        'frites-accompagnements' => 'cat-frites.png',
        'nos-desserts'           => 'cat-desserts.png',
        'nos-boissons'           => 'cat-boissons.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Items (main products) — keyed by Item.slug (DB live state 2026-05-21)
    |--------------------------------------------------------------------------
    */
    'items' => [
        // ── Sandwich Cayenne (cat 1)
        'sandwich-cayenne-classique' => 'sandwich-cayenne.png',
        'big-cayenne'                => 'sandwich-cayenne-maxi.png',
        'menu-frites-boisson'        => 'menu-frites-boisson.png',
        'frites-seules'              => 'frites.png',
        'boisson-seule'              => 'coca.png',

        // ── Galette (cat 2)
        'galette-cayenne' => 'galette.png',
        'galette-normale' => 'galette.png',
        // [AUDIT 2026-08-12] Créée le jour même, elle n'avait aucune entrée ici : l'accesseur
        // `Item::getThumbAttribute()` ne lit que `items` + `addons`, jamais `categories`. Une
        // entrée manquante ne casse rien et ne lève aucune erreur — elle sert simplement la
        // vignette par défaut. Le client la voyait donc en « produit sans photo » sur la borne,
        // au milieu de voisins illustrés. Trouvé en LISANT une capture, pas par un test.
        'galette-classique' => 'galette.png',

        // ── Sandwich Classique (cat 3)
        'sandwich-classique-faluche' => 'sandwich-classique.png',
        'big-classique'              => 'sandwich-classique-maxi.png',
        // [AUDIT 2026-08-12] Même trou que la galette ci-dessus. Le fichier existait déjà
        // (public/images/menu/sandwich-classique.png) : il ne manquait que la correspondance.
        'sandwich-classique' => 'sandwich-classique.png',

        // ── Burgers (cat 4)
        'chicken-burger' => 'burger-cheese.png',
        'big-chicken'    => 'burger-big.png',

        // ── Tacos (cat 5)
        'tacos-1-viande'        => 'tacos.png',
        'big-tacos-2-viandes'   => 'tacos-cayenne.png',

        // ── Bols Gourmands (cat 6)
        'bol-marine'             => 'bol-frites.png',
        'bol-tandoori'           => 'bol-frites.png',
        'bol-curry'              => 'bol-frites.png',
        'bol-crousti'            => 'bol-frites.png',
        'bol-gratine'            => 'bol-frites-gratine.png',
        'bowl-frites-marine'     => 'bol-frites.png',
        'bowl-frites-curry'      => 'bol-frites.png',
        'bowl-frites-tandoori'   => 'bol-frites.png',
        'bowl-frites-crispy'     => 'bol-frites.png',
        'bowl-riz-marine'        => 'bol-riz.png',
        'bowl-riz-curry'         => 'bol-riz.png',
        'bowl-riz-tandoori'      => 'bol-riz.png',
        'bowl-riz-crispy'        => 'bol-riz.png',

        // ── Frites (cat 7)
        'petite-frites' => 'frites.png',
        'grande-frites' => 'frites.png',

        // ── Suppléments (cat 8)
        'oeuf'                    => 'oeuf.png',
        'supp-oeuf'               => 'oeuf.png',
        'jambon-de-dinde'         => 'jambon-dinde.png',
        'supp-jambon'             => 'jambon-dinde.png',
        'fromage-supplementaire'  => 'fromage.png',
        'supp-cheddar'            => 'cheddar.png',
        'supp-emmental'           => 'fromage.png',
        'fromage-a-raclette'      => 'raclette.png',
        'supp-raclette'           => 'raclette.png',
        'boursin'                 => 'boursin.png',
        'supp-boursin'            => 'boursin.png',
        'supp-bacon'              => 'bacon.png',
        'supp-champignons'        => 'champignons.png',
        'supp-oignons-frits'      => 'oignons-frits.png',
        'supp-legumes-sautes'     => 'legumes-sautes.png',
        'sauce-supplementaire'    => 'sauce-supplementaire.png',
        'galette-pommes-de-terre' => 'galette.png',
        'salade-verte'            => 'salade.png',
        'supp-boule-gratinee'     => 'bol-frites-gratine.png',

        // ── Desserts (cat 9)
        'glace'      => 'ben-jerrys.png',
        'tarte-daim' => 'tarte.png',
        'tiramisu'   => 'tiramisu.png',

        // ── Boissons (cat 10)
        'coca'      => 'coca.png',
        'coca-zero' => 'coca-zero.png',
        'fanta'     => 'fanta-orange.png',
        'sprite'    => 'sprite.png',
        'oasis'     => 'oasis.png',
        'orangina'  => 'tropico.png',
        'eau-plate' => 'eau.png',
        'capri-sun' => 'capri-sun.png',
        // [BOISSONS-UPDATE 2026-07-05] Owner : nouvelles boissons (images fournies).
        'coca-cherry' => 'coca-cherry.png',
        'tropico'     => 'tropico.png',
        'ice-tea'     => 'lipton-peche.png',
        'fanta-citron' => 'fanta-citron.png',
        // [2026-07-05] Repli DISTINCT en attendant les vrais visuels owner
        // (fuze-tea.png / hawai.png / perrier.png). Swap 1 ligne à réception.
        'fuze-tea'    => 'lipton-framboise.png', // thé framboise ≠ ice-tea (pêche)
        // [OWNER8 2026-07-06] renommage « Fanta Hawai 33cl » → « Hawaï 33cl » (slug hawai) ;
        // clé legacy conservée pour un VPS pas encore migré par le seeder.
        'hawai'       => 'fanta-fraise.png',     // Hawaï rose ≠ orange/citron
        'fanta-hawai' => 'fanta-fraise.png',     // legacy slug (pré-migration)
        'perrier'     => 'eau.png',              // eau gazeuse (repli eau)

        // ── Menu enfant (cat 11)
        'menu-nuggets' => 'nuggets.png',

        // ── Legacy V0 slugs (fixtures / archive)
        'tacos-m-1-viande'          => 'tacos.png',
        'tacos-l-2-viandes'         => 'tacos.png',
        'tacos-xl-3-viandes'        => 'tacos.png',
        'tacos-xxl-4-viandes'       => 'tacos.png',
        'le-terminator-2-viandes'   => 'sandwich-classique.png',
        'le-mega-2-viandes'         => 'sandwich-classique.png',
        'le-supreme-1-viande'       => 'sandwich-classique.png',
        'le-cayenne-1-viande'       => 'sandwich-cayenne.png',
        'panini-1-viande'           => 'sandwich-classique.png',
        'cheese-burger'             => 'burger-cheese.png',
        'double-cheese'             => 'burger-big.png',
        'fish-burger'               => 'burger-cheese.png',
        'grill-burger'              => 'burger-big.png',
        'big-burger'                => 'burger-big.png',
        'frites-moyenne'            => 'frites.png',
        'frites-grande'             => 'frites.png',
        'tiramisu-speculoos'        => 'tiramisu.png',
        'coca-cola-33cl'            => 'coca.png',
        'coca-cola-zero-33cl'       => 'coca-zero.png',
        'oasis-tropical-33cl'       => 'oasis.png',
        'oasis-pomme-cassis-33cl'   => 'oasis.png',
        'fanta-orange-33cl'         => 'fanta-orange.png',
        'sprite-33cl'               => 'sprite.png',
        'eau-plate-50cl'            => 'eau.png',
        'eau-gazeuse-50cl'          => 'eau.png',
        'orangina-33cl'             => 'tropico.png',

        // ── Le Cayenne carte officielle 2026-06-24 (visuels owner « seul » —
        //    dernière occurrence = priorité PHP last-wins sur les slugs live).
        'cayenne'        => 'sandwich-cayenne.png',
        'supreme'        => 'sandwich-supreme.png',
        'mega'           => 'sandwich-mega.png',
        'terminator'     => 'sandwich-terminator.png',
        'tacos-m'        => 'tacos-cayenne.png',
        'tacos-l'        => 'tacos-cayenne.png',
        'chicken-burger' => 'chicken_burger.png',
        'cheese-burger'  => 'cheese-burger.png',
        'double-cheese'  => 'double-cheese.png',
        'fish-burger'    => 'fish-burger.png',
        'big-burger'     => 'big-burger.png',
        'grill-burger'   => 'grill-burger.png',

        // ── Le Cayenne caisse v5 2026-06-24 (slugs renommés / nouveaux SKU) :
        //    bols repurposés, menu enfant 2 produits, variantes frites cheese.
        //    Sans ces entrées → item-default.svg sur la caisse ET la borne.
        'bol-frites'                          => 'bol-frites.png',
        'bol-riz'                             => 'bol-riz.png',
        'menu-enfant-nuggets'                 => 'nuggets.png',
        // [IMG-HEAL 2026-06-27] Use the NEW kebab-case asset pack (owner: "use all the
        // borne+caisse images, some old remain"). Frites-style SKUs off the generic
        // frites.png → their dedicated cheddar / cheddar+oignons photos.
        // [OWNER 2026-07-17] Kids burger must show a CHICKEN burger, not the beef
        // cheeseburger visual — chicken_burger.png (poulet pané détouré) is the only
        // chicken-burger asset in the pack.
        'menu-enfant-burger'                  => 'chicken_burger.png',
        'petite-frites-cheddar-fondu'         => 'frites-cheddar.png',
        'petite-frites-cheddar-oignons-frits' => 'frites-cheddar-oignons.png',
        'grande-frites-cheddar-fondu'         => 'frites-cheddar.png',
        'grande-frites-cheddar-oignons-frits' => 'frites-cheddar-oignons.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Addons (Menu / Frites / Boisson combos)
    |--------------------------------------------------------------------------
    */
    'addons' => [
        'en-menu-frites-boisson' => 'menu-frites-boisson.png',
        'frites-seules'          => 'frites.png',
        'boisson-seule'          => 'coca.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sauces — Le Cayenne canonical 13 (12 sauces + Sans Sauce)
    |--------------------------------------------------------------------------
    | Owner mandate 2026-05-21: 12 sauces + Sans Sauce, no other variant.
    | All variants (display-name aliases) point to the same canonical PNG.
    */
    'sauces' => [
        // Canonical 13
        'Mayonnaise'              => 'sauce-mayonnaise.png',
        'Ketchup'                 => 'sauce-ketchup.png',
        'Blanche'                 => 'sauce-blanche.png',
        'Hannibal'                => 'sauce-hannibal.png',
        'Samouraï'                => 'sauce-samurai.png',
        'Algérienne'              => 'sauce-algerienne.png',
        'Andalouse'               => 'sauce-andalouse.png',
        'Curry'                   => 'sauce-curry.png',
        'Barbecue'                => 'sauce-barbecue.png',
        'Harissa'                 => 'sauce-harissa.png',
        'Sauce Fromagère Maison'  => 'sauce-fromagere-maison.png',
        'Sauce Spicy Maison'      => 'sauce-spicy-maison.png',
        'Sans Sauce'              => 'sauce-aucune.png',

        // Aliases (accent-stripped + legacy display variants)
        'Samurai'                 => 'sauce-samurai.png',
        'Algerienne'              => 'sauce-algerienne.png',
        'Fromagere Maison'        => 'sauce-fromagere-maison.png',
        'Sauce Fromagere Maison'  => 'sauce-fromagere-maison.png',
        'Fromagère Maison'        => 'sauce-fromagere-maison.png',
        'Spicy Maison'            => 'sauce-spicy-maison.png',
        'Spicy'                   => 'sauce-spicy-maison.png',
        'Sauce spicy'             => 'sauce-spicy-maison.png', // [IMG-HEAL 2026-06-27] exact bol-sauce (attr8) name → was default
        'Sauce Spicy'             => 'sauce-spicy-maison.png',
        'Sauce fromagère maison'  => 'sauce-fromagere-maison.png',
        'Sauce fromagere maison'  => 'sauce-fromagere-maison.png',
        'BBQ'                     => 'sauce-barbecue.png',
        'Mayo'                    => 'sauce-mayonnaise.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppléments — Le Cayenne canonical 11
    |--------------------------------------------------------------------------
    | All display names (legacy + new) keyed to the 11 canonical PNG.
    */
    'supplements' => [
        // Canonical names (config/menu.php + DB seed)
        'Fromage'                 => 'fromage.png',
        'Boursin'                 => 'boursin.png',
        'Fromage à raclette'      => 'raclette.png',
        'Fromage a raclette'      => 'raclette.png',
        'Raclette'                => 'raclette.png',
        'Cheddar'                 => 'cheddar.png',
        'Jambon de dinde'         => 'jambon-dinde.png',
        'Jambon'                  => 'jambon-dinde.png',
        'Bacon'                   => 'bacon.png',
        'Oignons frits'           => 'oignons-frits.png',
        'Oignon frais'            => 'oignons-frits.png',
        'Champignons'             => 'champignons.png',
        'Légumes sautés'          => 'legumes-sautes.png',
        'Legumes sautes'          => 'legumes-sautes.png',
        'Œuf'                     => 'oeuf.png',
        'Oeuf'                    => 'oeuf.png',
        'Sauce supplémentaire'    => 'sauce-supplementaire.png',
        'Sauce supplementaire'    => 'sauce-supplementaire.png',
        'Emmental'                => 'fromage.png',
        'Boule gratinée'          => 'bol-frites-gratine.png',
        'Galette pommes de terre' => 'galette.png',

        // [IMG-HEAL 2026-06-27] Live extras that resolved to item-default.svg (placeholder)
        // — now keyed to the matching NEW asset so borne+caisse show a real image.
        'Viande supplémentaire'   => 'viande-marine.png',
        'Option Gratiné'          => 'bol-frites-gratine.png',
        'Cheddar Fondu'           => 'cheddar.png',
        'Cheddar fondu'           => 'cheddar.png',
        'Cheddar + Oignons frits' => 'oignons-frits.png',
        'Grande Portion'          => 'frites.png',

        // Legacy display variants
        'Supplément Cheddar'      => 'cheddar.png',
        'Supplément Jambon'       => 'jambon-dinde.png',
        'Supplément Œuf'          => 'oeuf.png',
        'Supplément Raclette'     => 'raclette.png',
        'Supplément Boursin'      => 'boursin.png',
        'Supplément Chèvre'       => 'boursin.png',
        'Supplément Poulet'       => 'viande-marine.png',
        'Supplément Kebab'        => 'viande-marine.png',
        'Supplément Viande Hachée'=> 'viande-marine.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crudités (ItemExtra "crudite" group, atomic per item)
    |--------------------------------------------------------------------------
    */
    'crudite_extras' => [
        'Salade'    => 'salade.png',
        'Tomate'    => 'tomate.png',
        'Oignon'    => 'oignon.png',
        'Cornichon' => 'cornichon.png',
        // [2026-08-09] L'oignon cuit n'a pas sa propre photo : on réutilise
        // celle de l'oignon. L'ingrédient reste identifiable, ce qui vaut
        // mieux qu'une case grise. ⚠️ Deux options partagent donc le même
        // visuel — seul le libellé les distingue. À remplacer dès qu'une
        // photo d'oignons cuits existe.
        'Oignons cuits' => 'oignon.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crudités (legacy ItemVariation aggregate options + atomic)
    |--------------------------------------------------------------------------
    */
    'crudites' => [
        'Salade'    => 'salade.png',
        'Tomate'    => 'tomate.png',
        'Oignon'    => 'oignon.png',
        'Cornichon' => 'cornichon.png',

        // Legacy aggregate options (V0 wizard "Complet / Sans X / Aucune")
        'Complet (Salade, Tomate, Oignon)' => 'salade.png',
        'Sans Oignon'    => 'salade.png',
        'Sans Tomate'    => 'salade.png',
        'Sans Salade'    => 'tomate.png',
        'Aucune Crudité' => 'sauce-aucune.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Viandes (composer step, 4 canonical)
    |--------------------------------------------------------------------------
    */
    'viandes' => [
        // Canonical config/menu.php names
        'Poulet classic'   => 'viande-marine.png',
        'Poulet mariné'    => 'viande-marine.png',
        'Poulet curry'     => 'viande-curry.png',
        'Poulet tandoori'  => 'viande-tandoori.png',
        'Poulet crispy'    => 'viande-crispy.png',

        // Legacy V0 viande names
        'Poulet'             => 'viande-marine.png',
        'Cordon Bleu'        => 'viande-marine.png',
        'Kebab'              => 'viande-marine.png',
        'Kefta'              => 'viande-marine.png',
        'Mexicain'           => 'viande-marine.png',
        'Viande Hachée'      => 'viande-marine.png',
        'Merguez'            => 'viande-marine.png',
        'Nuggets'            => 'nuggets.png',
        'Tenders'            => 'tenders.png',
        'Escalope de poulet' => 'viande-marine.png',
        'Fricandelle'        => 'viande-marine.png',
        'Tandoori'           => 'viande-tandoori.png',
        'Curry'              => 'viande-curry.png',
        'Crispy'             => 'viande-crispy.png',
        'Mariné'             => 'viande-marine.png',

        // ── Le Cayenne viandes officielles 2026-06-24 (visuels owner —
        //    dernière occurrence = priorité PHP last-wins). Cordon Bleu
        //    sans visuel dédié → garde le générique viande-marine.png.
        'Mexicanos'     => 'viande-mexicanos.png',
        'Fricadelle'    => 'viande-fricadelle.png',
        'Tenders'       => 'viande-tenders.png',
        'Nuggets'       => 'viande-nuggets.png',
        'Poulet mariné' => 'viande-poulet.png',
        'Viande Hachée' => 'viande-hachee.png',
        'Cordon Bleu'   => 'viande-cordon-bleu.png', // ⚠️ visuel watermarké PNGTREE — à remplacer par version propre
    ],

    /*
    |--------------------------------------------------------------------------
    | Bases — étapes « Type de Pain » et « Base bol »
    |--------------------------------------------------------------------------
    | Ajouté le 2026-08-09. ItemVariation::getThumbAttribute ne traitait que les
    | attributs « Sauce », « Crudité », « Garniture » et « Viande » ; tout le
    | reste tombait dans le `else` et renvoyait l'image par défaut. Les DEUX
    | PREMIÈRES étapes du wizard sandwich et bol — donc la toute première chose
    | que voit le client — étaient des cases grises.
    |
    | « Pain » prend la photo du sandwich classique et « Galette » celle de la
    | galette : c'est exactement la distinction utile à cette étape, pain long
    | contre galette roulée.
    */
    'bases' => [
        'Pain'        => 'sandwich-classique.png',
        'Galette'     => 'galette.png',
        'Frites'      => 'frites.png',
        'Riz basmati' => 'bol-riz.png',
        'Riz'         => 'bol-riz.png',
    ],

    'default' => 'item-default.svg',

];
