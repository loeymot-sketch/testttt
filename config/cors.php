<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        env('APP_URL'),
        env('KIOSK_DOMAIN'),
        env('ADMIN_DOMAIN'),
        // Wave Y A-002 — kiosk SPA loads from 127.0.0.1:8000 while APP_URL=localhost:8000.
        // Allow both same-host variants explicitly so Echo / broadcasting auth handshake
        // passes CORS without depending on the operator aligning APP_URL with the served origin.
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        // [WEB-WIREUP 2026-06-26] Standalone customer web (React CDN, no build) served on :8011,
        // calls the API cross-origin with Bearer token + X-API-Key (no cookies). FRONTEND_WEB_DOMAIN
        // overrides for prod; the localhost variants cover local dev / e2e.
        // [DOMAINE 2026-07-29] FRONTEND_WEB_DOMAIN accepte désormais une LISTE séparée par
        // virgules (vercel + lecayenne.fr + www.lecayenne.fr) — le site vit sur le domaine
        // propre ET l'URL vercel historique reste valide.
        ...array_map('trim', array_filter(explode(',', (string) env('FRONTEND_WEB_DOMAIN', '')))),
        'http://localhost:8011',
        'http://127.0.0.1:8011',
    ]))),
    'allowed_origins_patterns' => [
        // [WEB-WIREUP 2026-06-26] Loopback dev origins (any port) — the standalone web / e2e
        // server runs on assorted local ports. Safe: only matches localhost/127.0.0.1 (same box),
        // never a remote origin; production uses the explicit APP_URL / FRONTEND_WEB_DOMAIN above.
        '#^http://(localhost|127\.0\.0\.1):\d{2,5}$#',

        /*
         * [APPS 2026-08-19] Origines des APPLICATIONS iOS et Android (Capacitor).
         *
         * Une application empaquetée sert ses fichiers depuis un serveur LOCAL interne à la
         * vue web. Son origine n'est donc pas « lecayenne.fr » mais :
         *   · https://localhost      — configuration retenue ici (iosScheme + androidScheme = https)
         *   · capacitor://localhost  — schéma par défaut de Capacitor sur iOS
         *   · ionic://localhost      — schéma hérité, gardé par sécurité si la config change
         *
         * Sans ces motifs, TOUS les appels de l'application sont refusés par la politique
         * d'origine du navigateur. Le défaut est particulièrement traître : la carte
         * s'affiche (elle vient de fichiers embarqués) et l'application paraît fonctionner,
         * mais la connexion, la commande et la fidélité échouent — le « ça a l'air bien mais
         * rien ne marche » que ce dépôt a déjà connu avec `api-base-url`.
         *
         * Le motif exige `localhost` SANS port : ajouter `:\d+` ne l'attraperait pas. Il ne
         * peut désigner aucune machine distante — c'est toujours l'appareil lui-même.
         */
        '#^https://localhost$#',
        '#^capacitor://localhost$#',
        '#^ionic://localhost$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => true,
];
