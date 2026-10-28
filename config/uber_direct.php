<?php

/**
 * [UBER-DIRECT 2026-09-06] Livraison à la demande par coursier Uber, pour NOS commandes.
 *
 * ⚠️ À NE PAS CONFONDRE AVEC `config/uber.php`. Ce sont DEUX produits Uber distincts :
 *   · `uber.php`        = Uber Eats **Marketplace** — Uber nous ENVOIE des commandes.
 *                         Scopes `eats.store eats.order`, endpoints `/v1/eats/...`.
 *   · `uber_direct.php` = Uber **Direct** — nous PAYONS Uber pour livrer nos commandes.
 *                         Scope `eats.deliveries`, endpoints `/v1/customers/{id}/...`.
 * Les deux ont leurs propres identifiants et leur propre cache de jeton : un client OAuth
 * séparé est donc obligatoire (cf. `App\Services\UberDirect\UberDirectClient`).
 *
 * ⚠️ SECRETS : jamais commités, jamais journalisés, jamais exposés au navigateur. Toutes les
 *    communications avec Uber se font côté serveur.
 *
 * ÉTAT : éteint par défaut (`UBER_DIRECT_ENABLED=false`). Tant que ce drapeau est faux,
 * aucun appel réseau n'est émis et le parcours « À emporter » est strictement inchangé.
 */

return [
    // Interrupteur maître. Faux = l'intégration n'existe pas pour le reste de l'application.
    'enabled' => (bool) env('UBER_DIRECT_ENABLED', false),

    // OAuth 2.0 client_credentials — identifiants DISTINCTS de ceux de la marketplace.
    // Relevés dans le tableau de bord https://direct.uber.com, onglet Developer.
    'customer_id'   => env('UBER_DIRECT_CUSTOMER_ID', ''),
    'client_id'     => env('UBER_DIRECT_CLIENT_ID', ''),
    'client_secret' => env('UBER_DIRECT_CLIENT_SECRET', ''),
    'token_url'     => env('UBER_DIRECT_TOKEN_URL', 'https://auth.uber.com/oauth/v2/token'),
    'api_base'      => env('UBER_DIRECT_API_BASE', 'https://api.uber.com'),
    'scopes'        => env('UBER_DIRECT_SCOPES', 'eats.deliveries'),

    // Webhook : Uber signe le corps en HMAC-SHA256, en-tête `X-Uber-Signature`. La clé est
    // fournie à la création du webhook dans le tableau de bord (secret partagé dédié).
    // Suffixe `_SECRET` = convention du dépôt (UBER_WEBHOOK_SECRET, STRIPE_WEBHOOK_SECRET).
    'webhook_secret' => env('UBER_DIRECT_WEBHOOK_SECRET', ''),

    /*
     * Points d'entrée — PARAMÉTRABLES À DESSEIN.
     *
     * La documentation publique d'Uber est ambiguë (une page décrit les devis en GET, une
     * autre en POST) et ne publie pas le schéma exact des corps. Ces valeurs seront
     * confrontées à la documentation rattachée au compte avant tout appel réel : elles sont
     * donc surchargeables par l'environnement plutôt que gravées dans le code.
     * Détail : docs/uber/UBER_DIRECT_API_FAITS_VERIFIES_2026-09-06.md §4.
     */
    'endpoints' => [
        'quote'  => '/v1/customers/{customer_id}/delivery_quotes',
        'create' => '/v1/customers/{customer_id}/deliveries',
        'get'    => '/v1/customers/{customer_id}/deliveries/{delivery_id}',
        'cancel' => '/v1/customers/{customer_id}/deliveries/{delivery_id}/cancel',
    ],
    'quote_method' => env('UBER_DIRECT_QUOTE_METHOD', 'POST'),

    /*
     * Marge de sécurité sur l'expiration d'un devis, en secondes.
     *
     * Un devis Uber est temporaire. Juste avant de déclencher le paiement, on refuse
     * d'utiliser un devis dont il reste moins que cette marge : le temps que le client
     * saisisse sa carte, il aurait expiré, et il serait débité d'un montant qu'il n'a pas
     * accepté. En deçà, on redemande un devis ; si le montant change, on le lui fait
     * confirmer.
     */
    'quote_safety_margin_seconds' => (int) env('UBER_DIRECT_QUOTE_MARGIN', 120),

    // Délai maximal d'un appel Uber. Court à dessein : le client attend devant son écran,
    // et une panne Uber ne doit pas faire tomber le checkout.
    'timeout_seconds' => (int) env('UBER_DIRECT_TIMEOUT', 8),

    /*
     * Règle tarifaire V1 — décision propriétaire du 2026-09-06 :
     *   « delivery_fee_customer = montant Uber Direct. Aucune remise, aucune majoration,
     *     aucun minimum donnant la livraison gratuite, aucune prise en charge. »
     *
     * ⚠️ AUCUN TARIF N'EST ÉCRIT EN DUR, ici ni ailleurs. Ces réglages existent pour que les
     * évolutions déjà envisagées (livraison offerte au-delà de X, participation du
     * restaurant, plafond, promotions) se posent SANS toucher à l'intégration Uber.
     * Ils sont tous neutres en V1.
     */
    'pricing' => [
        // Offerte à partir de ce total en centimes. null = jamais (V1).
        'free_above_cents' => env('UBER_DIRECT_FREE_ABOVE_CENTS') !== null
            ? (int) env('UBER_DIRECT_FREE_ABOVE_CENTS')
            : null,
        // Part prise en charge par le restaurant, en centimes. 0 = aucune (V1).
        'restaurant_subsidy_cents' => (int) env('UBER_DIRECT_SUBSIDY_CENTS', 0),
        // Plafond facturé au client, en centimes. null = pas de plafond (V1).
        'cap_cents' => env('UBER_DIRECT_CAP_CENTS') !== null
            ? (int) env('UBER_DIRECT_CAP_CENTS')
            : null,
    ],
];
