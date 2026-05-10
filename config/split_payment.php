<?php

/**
 * F-SPLIT-PAYMENT-001 — Configuration du multi-tender POS.
 *
 * `enabled`      : feature flag global. Default flipped ON in iter15
 *                  (2026-05-10) — owner mandate "système doit fonctionner
 *                  comme il est". 20 tests upstream (Unit + Feature + Sentinel)
 *                  prouvent l'implémentation stable. Quand off, le flux
 *                  `PosOrderRequest::prepareForValidation()` strip silencieusement
 *                  le champ `payment_breakdown` du payload et
 *                  `OrderService::posOrderStore` ignore le wiring (chemin legacy
 *                  single-tender intact).
 *                  Pour désactiver explicitement (rollback) : SPLIT_PAYMENT_ENABLED=false.
 * `max_tranches` : nombre max de tranches autorisées par commande (par défaut 12 ;
 *                  couvre largement le cas multi-personnes 2-5).
 */
return [
    'enabled'      => filter_var(env('SPLIT_PAYMENT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'max_tranches' => (int) env('SPLIT_PAYMENT_MAX_TRANCHES', 12),
];
