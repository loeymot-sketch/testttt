<?php

/*
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Carnet — mini-app web mobile à code PIN.
 * Registre INTERNE déclaratif (dépenses, acomptes travailleurs, notes, photos de
 * factures). AUCUN lien avec la chaîne fiscale NF525 (audit_logs / z_reports /
 * CashMovement) — c'est un carnet de gestion, pas un document fiscal.
 */
return [

    // Code PIN d'accès (owner : changer en prod via .env DAILY_BOOK_PIN).
    'pin' => env('DAILY_BOOK_PIN', '2468'),

    // Durée de session déverrouillée (minutes) avant re-demande du PIN.
    'session_minutes' => (int) env('DAILY_BOOK_SESSION_MINUTES', 240),

    // Taille max photo facture (kilo-octets) — photos smartphone.
    'photo_max_kb' => (int) env('DAILY_BOOK_PHOTO_MAX_KB', 8192),
];
