<?php

/**
 * [UBER-EATS 2026-07-01] Intégration Uber Eats → Caisse + KDS.
 *
 * ⚠️ SECRETS : client_secret + client_id viennent du .env (JAMAIS commités). Voir
 *    .env.example pour les clés attendues. Après mise en prod : régénérer le secret côté Uber.
 *
 * Statut : App créée, Org liée, NDA signé, Production Access REQUESTED (en attente Uber).
 * L'ingestion s'active dès que : (1) Uber accorde le Production Access, (2) le webhook
 * `https://<domaine>/api/webhooks/uber` est enregistré sur le dashboard Uber.
 */

return [
    // OAuth 2.0 (client_credentials)
    'client_id'     => env('UBER_CLIENT_ID', ''),
    'client_secret' => env('UBER_CLIENT_SECRET', ''),
    'org_id'        => env('UBER_ORG_ID', ''),
    'store_id'      => env('UBER_STORE_ID', ''), // Store UUID Le Cayenne
    'token_url'     => env('UBER_TOKEN_URL', 'https://login.uber.com/oauth/v2/token'),
    'api_base'      => env('UBER_API_BASE', 'https://api.uber.com'),
    'scopes'        => env('UBER_SCOPES', 'eats.store eats.order eats.store.orders.read'),

    // Webhook : Uber signe le body en HMAC-SHA256. La clé de signature est en général le
    // client_secret ; si Uber fournit une clé de signature dédiée, la mettre ici.
    'webhook_signing_secret' => env('UBER_WEBHOOK_SECRET', env('UBER_CLIENT_SECRET', '')),

    // Endpoints (Eats Marketplace)
    'endpoints' => [
        // [UBER-SANDBOX 2026-08-02] v2 obligatoire : la v1 renvoie order_items sans titre
        // (mapper conçu pour cart.items v2 → commande vide, prouvé sur ordre test 5a3eef3c).
        // La v2 exige le scope eats.store.orders.read (ajouté aux scopes par défaut).
        'order'   => '/v2/eats/order/{order_id}',
        'accept'  => '/v1/eats/orders/{order_id}/accept_pos_order',
        'deny'    => '/v1/eats/orders/{order_id}/deny_pos_order',
        'store'   => '/v1/eats/stores/{store_id}',

        // [UBER-BASIC-PROD 2026-08-02] Checklist « Basic Production validation » exigée par
        // l'équipe Uber (email Case# 58936938) — famille /v1/delivery + menus v2. Le
        // « Mark Order Ready » existe bien ici (absent de la doc publique, confirmé par Uber).
        'delivery_stores'  => '/v1/delivery/stores',
        'delivery_store'   => '/v1/delivery/store/{store_id}',
        'store_status_get' => '/v1/delivery/store/{store_id}/status',
        'store_status_set' => '/v1/delivery/store/{store_id}/update-store-status',
        'order_cancel'     => '/v1/delivery/order/{order_id}/cancel',
        'order_deny'       => '/v1/delivery/order/{order_id}/deny',
        'order_ready'      => '/v1/delivery/order/{order_id}/ready',
        'menu_put'         => '/v2/eats/stores/{store_id}/menus',
        'menu_item'        => '/v2/eats/stores/{store_id}/menus/items/{item_id}',
    ],

    // ── Décisions métier (à trancher par l'owner — défauts prudents) ──────────────
    // 1. Fiscal : les ventes Uber entrent-elles dans le Z de caisse (NF525) ? Défaut NON
    //    (Uber facture à part → canal séparé non-fiscal, pas de fiscal_sequence_no).
    'fiscalize' => env('UBER_FISCALIZE', false),
    // 2. Acceptation : auto-accept à réception, ou le cuisinier accepte sur le KDS ?
    //    Défaut auto-accept (la commande apparaît en cours, le chef clique « prête/sortie »).
    'auto_accept' => env('UBER_AUTO_ACCEPT', true),
    // 3. Rupture stock : refuser la commande si un produit est en rupture, ou accepter ?
    //    Défaut accepter (on ne bloque pas une commande Uber payée).
    'deny_on_out_of_stock' => env('UBER_DENY_ON_OOS', false),

    // [UBER-READY 2026-08-02] Temps de préparation annoncé à Uber dans accept_pos_order
    // (`pickup_time` = now + N minutes, secondes Unix). C'est LE levier officiel qui cale le
    // dispatch du coursier — il n'existe PAS d'endpoint public « mark ready » pour les
    // commandes Delivery-by-Uber (vérifié doc 2026-08-02 ; restaurantdelivery/status =
    // self-delivery only). Ajuster via UBER_PREP_TIME_MIN selon la vitesse réelle cuisine.
    'prep_time_minutes' => (int) env('UBER_PREP_TIME_MIN', 15),

    // Branche cible (V1 = single restaurant).
    'branch_id' => (int) env('UBER_BRANCH_ID', 1),

    // [GO-LIVE UBER 2026-07-04] Item d'ancrage pour les lignes NON MAPPÉES (dégradation
    // gracieuse — une commande payée ne se perd JAMAIS). 0/absent = le mapper crée/réutilise
    // un placeholder technique inactif hors canaux ('uber-article-non-mappe'). L'owner peut
    // pointer un item dédié existant ici s'il préfère.
    'fallback_item_id' => (int) env('UBER_FALLBACK_ITEM_ID', 0),
];
