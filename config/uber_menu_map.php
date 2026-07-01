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
