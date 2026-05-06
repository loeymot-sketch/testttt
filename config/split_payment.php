<?php

/**
 * F-SPLIT-PAYMENT-001 — Configuration du multi-tender POS.
 *
 * `enabled`      : feature flag global. Default OFF pour roll-out safe.
 *                  Quand off, `PosOrderRequest::prepareForValidation()` strip
 *                  silencieusement le champ `payment_breakdown` du payload
 *                  et `OrderService::posOrderStore` ignore le wiring.
 * `max_tranches` : nombre max de tranches autorisées par commande.
 */
return [
    'enabled'      => (bool) env('SPLIT_PAYMENT_ENABLED', false),
    'max_tranches' => (int) env('SPLIT_PAYMENT_MAX_TRANCHES', 12),
];
