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
    'scopes'        => env('UBER_SCOPES', 'eats.store eats.order'),

    // Webhook : Uber signe le body en HMAC-SHA256. La clé de signature est en général le
    // client_secret ; si Uber fournit une clé de signature dédiée, la mettre ici.
    'webhook_signing_secret' => env('UBER_WEBHOOK_SECRET', env('UBER_CLIENT_SECRET', '')),

    // Endpoints (Eats Marketplace)
    'endpoints' => [
        'order'   => '/v1/eats/orders/{order_id}',
        'accept'  => '/v1/eats/orders/{order_id}/accept_pos_order',
        'deny'    => '/v1/eats/orders/{order_id}/deny_pos_order',
        'store'   => '/v1/eats/stores/{store_id}',
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

    // Branche cible (V1 = single restaurant).
    'branch_id' => (int) env('UBER_BRANCH_ID', 1),

    // ── [UBER-PHOTO 2026-08-10 · owner] Commandes prises EN PHOTO sur la tablette ────────────
    // Tant qu'Uber n'accorde pas l'accès production (statut ci-dessus), le restaurant recopie les
    // commandes à la main. L'owner photographie donc le ticket sur la tablette et le système le
    // lit. Ce canal est INDÉPENDANT du webhook : il fonctionne aujourd'hui, sans Uber.
    //
    // `vision_enabled` = true + OPENAI_API_KEY renseignée → lecture réelle par un modèle de
    // vision. Sinon → doublure locale : le parcours complet fonctionne, mais la lecture rend le
    // ticket d'exemple ; le personnel corrige alors les lignes à l'écran avant d'envoyer.
    'vision_enabled' => (bool) env('UBER_VISION_ENABLED', false),
    // Nombre maximal de photos pour UN ticket (l'owner en prend 1 à 3 selon la longueur).
    'photo_max_files' => (int) env('UBER_PHOTO_MAX_FILES', 6),
    // Taille maximale par photo, en kilo-octets (12 Mo — une photo de tablette pèse 2 à 5 Mo).
    'photo_max_kb' => (int) env('UBER_PHOTO_MAX_KB', 12288),

    // [GO-LIVE UBER 2026-07-04] Item d'ancrage pour les lignes NON MAPPÉES (dégradation
    // gracieuse — une commande payée ne se perd JAMAIS). 0/absent = le mapper crée/réutilise
    // un placeholder technique inactif hors canaux ('uber-article-non-mappe'). L'owner peut
    // pointer un item dédié existant ici s'il préfère.
    'fallback_item_id' => (int) env('UBER_FALLBACK_ITEM_ID', 0),
];
