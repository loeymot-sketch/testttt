<?php

/*
|--------------------------------------------------------------------------
| [ONB-11 2026-08-28] Réglages de la vue « Conso & Stock »
|--------------------------------------------------------------------------
|
| Ce fichier n'existait pas.
|
| `UnifiedStockViewService::resoldProductRows()` identifiait les produits
| REVENDUS — ceux qu'on achète pour les revendre tels quels, par opposition aux
| matières premières — par un mot écrit en dur :
|
|     $query->where('slug', 'like', 'boisson%')->orWhere('name', 'like', 'Boisson%');
|
| Rien n'oblige un restaurateur à nommer sa catégorie « Boissons ». S'il écrit
| « Softs », « Canettes » ou « Bières » — et rien à l'écran ne le lui déconseille —
| la section reste VIDE, sous un message qui dit « Aucun élément ne correspond au
| filtre » alors qu'il n'a posé aucun filtre et qu'il n'a aucun moyen d'en retirer
| un. Un écran de valorisation de stock vide, et la faute rejetée sur lui.
|
| La règle de nommage devient donc un RÉGLAGE. Elle reste imparfaite — la bonne
| réponse serait un attribut porté par la catégorie elle-même, ce qui demande une
| migration, donc le gate propriétaire G-DATA, en attente. En attendant, un
| exploitant peut au moins déclarer ses propres noms sans toucher au code.
|
*/

return [

    /*
    | Préfixes de nom OU de slug qui désignent une catégorie de produits revendus.
    | La comparaison est insensible à la casse et porte sur le DÉBUT du nom.
    |
    | Plusieurs valeurs séparées par des virgules :
    |     STOCK_CATEGORIES_REVENDUES="boisson,soft,canette,biere"
    */
    'categories_revendues' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STOCK_CATEGORIES_REVENDUES', 'boisson'))
    ))),

];
