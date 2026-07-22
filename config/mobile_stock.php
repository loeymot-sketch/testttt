<?php

/*
 * [GOAL MEGA W-MOBILE 2026-07-22] Stock mobile — mini-app web à code PIN pour
 * l'owner. Un simple lien + un code 4 chiffres pour, depuis son téléphone :
 *   - voir les produits en RUPTURE (section « À acheter »),
 *   - basculer un produit DISPO ⇄ RUPTURE.
 *
 * Registre de GESTION uniquement — AUCUN lien avec la chaîne fiscale NF525
 * (audit_logs / z_reports / CashMovement / pricing). Le toggle délègue au SSOT
 * App\Services\Menu\AvailabilityService::toggle (raison 'stock_rupture').
 *
 * Miroir du pattern validé du Carnet (config/daily_book.php), à une exception
 * DURE près : ici le PIN par défaut est VIDE → accès REFUSÉ (fail-closed).
 * Le PIN 4 chiffres exposé sur Internet est faible par nature ; poser
 * MOBILE_STOCK_PIN dans .env est OBLIGATOIRE, et le roter au go-live.
 */
return [

    // Code PIN d'accès. VIDE par défaut = accès entièrement refusé (fail-closed).
    // Poser via .env MOBILE_STOCK_PIN (staging local : 2580 — À ROTER en prod).
    'pin' => env('MOBILE_STOCK_PIN', ''),

    // Durée de session déverrouillée (minutes) avant re-demande du PIN (~12h).
    'session_minutes' => (int) env('MOBILE_STOCK_SESSION_MINUTES', 720),
];
