<?php

/**
 * [UBER-EATS 2026-07-01] Correspondance titre article Uber → item_id catalogue Le Cayenne.
 *
 * Uber envoie le TITRE de l'article (tel qu'affiché sur la carte Uber). On le mappe vers notre
 * item_id. Deux niveaux :
 *  - `by_title` : correspondance exacte (titre Uber normalisé → item_id). À remplir avec la vraie
 *    carte Uber de l'owner. À défaut, le mapper tente un match par nom sur le catalogue (fallback).
 *  - `by_uber_id` : si l'article Uber porte un id externe stable, on peut mapper par id.
 *
 * Normalisation : minuscule + trim + accents retirés (voir UberOrderMapper::norm).
 */

/*
|--------------------------------------------------------------------------
| ⛔ NE PAS REMPLIR CETTE TABLE AVEC LES LIBELLÉS « NON MAPPÉS » DE LA BASE
|--------------------------------------------------------------------------
| [AUDIT 2026-08-13] Un audit a signalé « 7 lignes Uber sur 19 (37 %) tombent dans l'article
| fourre-tout » et a proposé de les mapper. VÉRIFIÉ EN PRODUCTION, LIGNE PAR LIGNE : il ne faut
| PAS le faire, et voici les chiffres qui le disent.
|
| Les 7 lignes se réduisent à DEUX libellés, et aucun des deux n'est une vente réelle :
|
|  · « Best Burger » — 5 lignes, toutes du 2026-08-02 entre 02h57 et 05h18, à **1,00 € pièce**
|    en quantités **23, 19, 13, 4 et 1**, sur des commandes dont le numéro de série est un jeton
|    hexadécimal (`88367`, `2D78C`, `792F7`, `B98AA`, `B8CEC`) et non notre format `ORD-xxxx-XX`.
|    C'est le **bac à sable d'Uber**, pas la carte du restaurant — « Best Burger » n'existe
|    d'ailleurs nulle part au catalogue (on a Big, Grill, Cheese, Chicken et Fish Burger).
|    Le mapper GRAVERAIT DES DONNÉES DE TEST dans la configuration de production.
|
|  · « Menu sandwich Cayenne » — 2 lignes, commande #475 du 2026-08-12. Cette commande porte
|    **trois lignes, toutes à 0,00 €**, dont un « Cheese Burger » PARFAITEMENT mappé, pour un
|    total de commande de 25,80 €. Le défaut réel n'est donc pas la correspondance produit : c'est
|    que **les prix de ligne n'arrivent pas** sur ce chemin d'ingestion. Le total de la commande,
|    lui, est juste — l'argent est bon, c'est le détail par ligne qui est vide.
|    « Menu sandwich Cayenne » à 0,00 € encadrant un produit facturé a la forme d'une LIGNE
|    D'EN-TÊTE DE MENU, pas d'un produit vendable : lui donner un `item_id` ajouterait un plat
|    fantôme au ticket cuisine et décrémenterait le stock DEUX FOIS pour un seul sandwich.
|
| RÈGLE : ne remplir cette table qu'à partir de la CARTE UBER RÉELLE (l'export du back-office
| Uber Eats), jamais à partir des libellés observés dans `order_items`. Un libellé observé peut
| être un test, un en-tête de menu, ou une option — trois choses qui ne sont pas des produits.
*/

return [
    'by_title' => [
        // 'tacos m'        => 26,
        // 'tacos l'        => 97,
        // 'cayenne'        => 22,
        // 'mega'           => 104,
        // 'terminator'     => 105,
        // ... (à compléter avec la carte Uber réelle)
    ],
    'by_uber_id' => [
        // '<uber_item_id>' => <item_id>,
    ],
];
