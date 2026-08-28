<?php

/*
|--------------------------------------------------------------------------
| Catalogue canonique des sauces — SSOT présentation + réparation données
|--------------------------------------------------------------------------
|
| [GOAL WIZARD-CAISSE 2026-08-28 · owner] Avant ce fichier, chaque article
| portait SA propre copie des sauces dans `item_variations`. Constat mesuré
| en base le 2026-08-28 sur les 59 articles vendables : CINQ profils
| différents pour un menu unique —
|
|   · 12 articles : les 13 sauces, « Sans sauce » incluse   (référence)
|   ·  2 articles : 12 sauces, SANS « Sans sauce », ordre A  (Galette Normale, Chicken Burger)
|   ·  1 article  : 12 sauces, SANS « Sans sauce », ordre B  (Tacos M)
|   · 10 articles : 12 sauces, SANS « Sans sauce », ordre C  (Tacos L, XL, tous les burgers)
|   ·  2 articles : DEUX sauces seulement                    (Bol Frites, Bol Riz)
|
| D'où le symptôme terrain rapporté par le propriétaire : « pas le même
| ordre d'un sandwich à l'autre », « il manque le choix de mettre pas de
| sauce », « c'est galère, sur une commande tu trouves pas la sauce ».
|
| `order` fixe l'ordre d'affichage sur TOUTES les surfaces (caisse ET borne,
| via ItemResource/NormalItemResource). `aliases` sert à la normalisation :
| la base contient « Sauce fromagère maison » ET « Fromagère maison » pour
| une seule et même sauce.
|
| ⚠️ SSOT (CLAUDE.md §3bis) : n'ajouter ici QUE des sauces réellement
| servies au Cayenne. Ce fichier pilote la commande `foodking:sauces:sync`
| qui ÉCRIT en base — une entrée inventée deviendrait un vrai produit
| commandable et un ticket cuisine pour une sauce qui n'existe pas.
|
*/

return [

    /*
     | Attributs porteurs de sauce. 5 = « Sauce (1ère Gratuite) » (sandwichs,
     | tacos, burgers, frites), 8 = « Sauce bol » (bols).
     */
    'attribute_ids' => [5, 8],

    /*
     | Articles qui DOIVENT porter la liste de sauces alors qu'ils n'ont encore
     | aucun attribut sauce en base. Par défaut la commande `foodking:sauces:sync`
     | ne touche QUE les articles qui en ont déjà un — un Coca ne doit pas hériter
     | d'une carte de sauces. Cette liste est l'exception explicite et auditable.
     |
     | VIDE, et c'est délibéré. Vérifié le 2026-08-28 : les articles 1 « Menu
     | (Frites + Boisson) », 2 « Frites Seules » et 3 « Boisson Seule » n'ont pas
     | de sauces, mais ils appartiennent à la catégorie 27 « Technique (interne —
     | upsell) » (status 10). Ce sont des articles TECHNIQUES de formule, jamais
     | ouverts en caisse — `api/admin/item/details/2` répond d'ailleurs 404 par
     | construction. Leur attacher une carte de sauces crée des lignes que
     | personne n'affiche. La sauce des frites d'une formule est déjà servie par
     | l'étape « 🍟 Sauce pour frites » du wizard, alimentée par la liste du
     | produit principal.
     |
     | Les vraies frites vendables (33/34 Petite & Grande, 107-110 cheddar)
     | portent bien les 13 sauces.
     */
    'force_attach' => [],

    /*
     | Liste canonique. L'ordre du tableau EST l'ordre d'affichage.
     | Les plus demandées d'abord, « Sans sauce » toujours en dernier.
     |
     | bg/fg : pastille couleur de la tuile caisse (fg = texte, contrasté).
     */
    'catalog' => [
        [
            'key'     => 'ketchup',
            'name'    => 'Ketchup',
            'emoji'   => '🍅',
            'bg'      => '#D62828',
            'fg'      => '#FFFFFF',
            'aliases' => ['ketchup', 'sauce ketchup'],
        ],
        [
            'key'     => 'mayonnaise',
            'name'    => 'Mayonnaise',
            'emoji'   => '🥚',
            'bg'      => '#F7E7A6',
            'fg'      => '#4A3F12',
            'aliases' => ['mayonnaise', 'mayo', 'sauce mayonnaise'],
        ],
        [
            'key'     => 'blanche',
            'name'    => 'Blanche',
            'emoji'   => '🥛',
            'bg'      => '#FFFFFF',
            'fg'      => '#2A2A3A',
            'aliases' => ['blanche', 'sauce blanche'],
        ],
        [
            'key'     => 'algerienne',
            'name'    => 'Algérienne',
            'emoji'   => '🌶️',
            'bg'      => '#E8590C',
            'fg'      => '#FFFFFF',
            'aliases' => ['algerienne', 'algérienne', 'sauce algerienne', 'sauce algérienne'],
        ],
        [
            'key'     => 'samourai',
            'name'    => 'Samouraï',
            'emoji'   => '⚔️',
            'bg'      => '#F4501E',
            'fg'      => '#FFFFFF',
            'aliases' => ['samourai', 'samouraï', 'sauce samourai', 'sauce samouraï'],
        ],
        [
            'key'     => 'andalouse',
            'name'    => 'Andalouse',
            'emoji'   => '🫑',
            'bg'      => '#F08C4B',
            'fg'      => '#3A2208',
            'aliases' => ['andalouse', 'sauce andalouse'],
        ],
        [
            // [owner 2026-08-28] Ajout décidé par le propriétaire : la sauce est
            // bien servie au Cayenne mais n'existait dans AUCUNE des 27 listes en
            // base — elle était donc incommandable en caisse comme sur la borne.
            'key'     => 'americaine',
            'name'    => 'Américaine',
            'emoji'   => '🌟',
            'bg'      => '#E9A88C',
            'fg'      => '#4A2415',
            'aliases' => ['americaine', 'américaine', 'sauce americaine', 'sauce américaine'],
        ],
        [
            'key'     => 'barbecue',
            'name'    => 'Barbecue',
            'emoji'   => '🔥',
            'bg'      => '#A0522D',
            'fg'      => '#FFFFFF',
            'aliases' => ['barbecue', 'bbq', 'sauce barbecue', 'sauce bbq'],
        ],
        [
            'key'     => 'curry',
            'name'    => 'Curry',
            'emoji'   => '🍛',
            'bg'      => '#E8A317',
            'fg'      => '#3A2A02',
            'aliases' => ['curry', 'sauce curry'],
        ],
        [
            'key'     => 'harissa',
            'name'    => 'Harissa',
            'emoji'   => '🌶️',
            'bg'      => '#B02020',
            'fg'      => '#FFFFFF',
            'aliases' => ['harissa', 'sauce harissa'],
        ],
        [
            'key'     => 'hannibal',
            'name'    => 'Hannibal',
            'emoji'   => '🦁',
            'bg'      => '#E05B7A',
            'fg'      => '#FFFFFF',
            'aliases' => ['hannibal', 'sauce hannibal'],
        ],
        [
            'key'     => 'fromagere',
            'name'    => 'Fromagère maison',
            'emoji'   => '🧀',
            'bg'      => '#F2C14E',
            'fg'      => '#3A2E05',
            'aliases' => ['fromagere maison', 'fromagère maison', 'sauce fromagere maison', 'sauce fromagère maison', 'fromagere', 'fromagère'],
        ],
        [
            'key'     => 'spicy',
            'name'    => 'Spicy maison',
            'emoji'   => '🥵',
            'bg'      => '#D9480F',
            'fg'      => '#FFFFFF',
            'aliases' => ['spicy maison', 'sauce spicy', 'spicy', 'sauce spicy maison'],
        ],
        [
            'key'     => 'sans_sauce',
            'name'    => 'Sans sauce',
            'emoji'   => '🚫',
            'bg'      => '#EDEEF3',
            'fg'      => '#5A5D70',
            'aliases' => ['sans sauce', 'aucune sauce', 'pas de sauce'],
        ],
    ],
];
