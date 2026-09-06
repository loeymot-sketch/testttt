<?php

/**
 * Borne (kiosk) — UI client directe, sans écran « authentification machine ».
 *
 * Par défaut : le SPA appelle auth/kiosk-login tout seul (identifiants injectés dans la page).
 * L’API exige toujours un token machine ; le client ne voit pas de formulaire.
 *
 * Pour afficher l’écran login (tests, audit) : KIOSK_REQUIRE_MACHINE_LOGIN=true
 *
 * Identifiants : KIOSK_MACHINE_* obligatoires pour l'auto-login.
 * Aucun fallback hardcodé n'est autorisé ici pour éviter d'exposer des credentials connus.
 */
$requireForm = filter_var(env('KIOSK_REQUIRE_MACHINE_LOGIN', false), FILTER_VALIDATE_BOOLEAN);

$defaultLocale = strtolower(trim((string) env('KIOSK_DEFAULT_LOCALE', 'fr')));
if (! in_array($defaultLocale, ['fr', 'en', 'ar'], true)) {
    $defaultLocale = 'fr';
}

/*
 | [ADR-007 / Sprint 3D 2026-05-16] Kiosk runtime FR-immutable.
 |
 | En V1 fast-food Le Cayenne, la borne tourne en français pour toute la
 | session (UI, clavier virtuel, Web Speech, screen readers). Le drawer
 | a11y ne propose plus de sélecteur FR/EN/AR — exposé ici uniquement comme
 | feature flag pour un pilote multi-langue post-V1. Tant que `false`, le
 | frontend doit refuser tout `kioskSettings/setLocale` initié par l'UI.
 | Voir docs/adr/ADR-007-kiosk-fr-lock.md.
 */
$localeSwitchAllowed = filter_var(env('KIOSK_LOCALE_SWITCH_ALLOWED', false), FILTER_VALIDATE_BOOLEAN);

/*
| [SUPERVISOR WAVE C Z1 2026-05-28] Payment route all to counter (Plan B).
|
| Owner mandate Le Cayenne V1 LOCAL : tous les paiements de la borne passent
| par la caisse. La borne crée une commande PENDING_COUNTER (espèces logique
| CASH_ON_DELIVERY=1) et la caisse choisit espèces (ouvre tiroir) OU carte
| (imprime ticket + encaisse manuellement terminal). Cash flow kiosk→caisse
| reste permanent même quand les terminals seront câblés au TPE.
|
| Quand true :
|   - KioskPaymentComponent SKIP l'UI de sélection de méthode (card / cash /
|     tr) et auto-submit avec payment_method=1 (CASH_ON_DELIVERY).
|   - Backend FrontendOrderService:186-237 traite déjà ce cas → l'order part
|     PENDING_COUNTER, fiscal_sequence_no alloué à l'encaissement POS (pas
|     au create), pas d'appel TPE borne.
|   - Le client voit un message clair « Veuillez payer à la caisse » +
|     numéro de commande à donner au caissier.
|
| Override via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=false pour revenir au
| flow legacy (sélection card/cash/tr à la borne).
*/
$paymentRouteAllToCounter = filter_var(env('KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER', true), FILTER_VALIDATE_BOOLEAN);

/*
| [W2 audit heal 2026-06-26] Kiosk promo/loyalty discount UI — DEDICATED gate.
|
| The borne cart exposed a promo-code + loyalty-redeem block that promised a
| "-X €" the backend never applies: the kiosk only sends `kiosk_promo_code`
| metadata, never a `coupon_id`, so the order is created at full price while
| the cart UI shows a discount → the customer is charged more than the cart
| promised. The shared `pos.manual_discount_enabled` flag CANNOT be flipped
| off to hide it (it also drives the legitimate POS manual discount + the web
| CheckoutComponent). This dedicated flag (default FALSE = hidden) gates the
| kiosk promo + loyalty entries ONLY, without weakening POS or checkout.
| Flip to true via KIOSK_PROMO_ENABLED once the kiosk coupon path is genuinely
| wired end-to-end (coupon_id sent + backend applies it).
*/
$promoEnabled = filter_var(env('KIOSK_PROMO_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

/*
| [FIDÉLITÉ BORNE 2026-08-19] RACHAT DE POINTS À LA BORNE — interrupteur DÉDIÉ.
|
| POURQUOI IL FALLAIT LE SÉPARER. Le drapeau `promo_enabled` juste au-dessus a été créé le
| 2026-06-26 pour une raison précise et JUSTE : le câblage des CODES PROMO borne est cassé
| (la borne n'envoie qu'un `kiosk_promo_code`, jamais de `coupon_id`), donc le panier
| affichait une remise que le serveur n'appliquait pas. Il gate depuis le bloc promo ET le
| rachat de points — deux choses de nature différente, prises en otage par le même défaut.
|
| C'est la TROISIÈME fois que ce motif apparaît dans ce projet : `pos.manual_discount_enabled`
| coupait déjà la fidélité (découplé le 2026-07-18 par `pos.loyalty_enabled`), puis les codes
| promo (découplés le 2026-08-07 par `pos.coupon_codes_enabled`). Même remède ici.
|
| CE QUI A CHANGÉ POUR QUE CE FLAG PUISSE VALOIR TRUE. Le rachat borne n'était pas seulement
| masqué, il était NON CÂBLÉ : `buildKioskQuotePayload` n'envoyait que `loyalty_code`, jamais
| le montant demandé, si bien que le serveur sortait immédiatement. Et une fois câblé, il
| tombait sur un second défaut, jamais vu parce que tous les tests remplaçaient le sceau du
| devis par un double : le débit s'exécutait AVANT `sealForCommit`, qui recalcule le devis en
| relisant le solde VIVANT → « Order quote intent mismatch » dès que le rachat faisait tomber
| le solde sous le plancher. Les deux sont corrigés et prouvés bout-en-bout, sceau compris
| (`tests/Feature/Loyalty/KioskRedeemThroughSealedQuoteTest.php`).
|
| Fiscalement : la fidélité passe par `pos.loyalty_enabled` (défaut true) dans
| `FrontendOrderService::assertDiscretionaryDiscountAllowed`, pas par le kill-switch des
| remises manuelles — le défaut F1 est corrigé et prouvé (ZReportDiscountNettingTest).
|
| Kill-switch : KIOSK_LOYALTY_REDEEM_ENABLED=false masque le rachat borne SANS toucher aux
| codes promo ni à la caisse. La consultation du solde et le CUMUL restent toujours actifs :
| ils n'appliquent aucune réduction, donc aucun risque d'afficher un prix non tenu.
*/
$loyaltyRedeemEnabled = filter_var(
    env('KIOSK_LOYALTY_REDEEM_ENABLED', true),
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
) ?? true;

/*
| [TRAP-2 2026-06-04] Stale counter-collect TTL (minutes).
|
| A walk-away kiosk order auto-accepts to status=ACCEPT + PENDING_COUNTER and
| otherwise sits in the cashier collect queue + KDS forever. CleanupStalePending
| KioskOrders auto-cancels (ACCEPT→CANCELED, legal, non-fiscal) any kiosk order
| with NO fiscal sequence allocated that is older than this TTL. Default 180 min
| (3 h) — a safe envelope so a customer who genuinely takes their time queuing
| at the counter is never auto-cancelled mid-service. Sealed (PAID + fiscal_
| sequence_no) orders are excluded → NF525 chain untouched. Override via
| KIOSK_STALE_COLLECT_TTL_MINUTES.
*/
$staleCollectTtlMinutes = max(1, (int) env('KIOSK_STALE_COLLECT_TTL_MINUTES', 180));

// [C4-CAISSE-TELEPHONE FIX-2 2026-07-07] TTL distinct pour les COMMANDES TÉLÉPHONE
// abandonnées (source_surface='phone'). Volontairement plus généreux (défaut 360 min /
// 6 h) qu'une borne : une commande téléphone est prise « à l'avance » (« je passe ce
// soir »), on ne l'auto-annule qu'après un délai large ET après son créneau prévu.
// Override via KIOSK_STALE_PHONE_COLLECT_TTL_MINUTES.
$stalePhoneCollectTtlMinutes = max(1, (int) env('KIOSK_STALE_PHONE_COLLECT_TTL_MINUTES', 360));

// [owner 2026-07-07] Numéro de file (queue_number "A00NN") de départ du jour (défaut 32).
// [P2-s heal 2026-07-18] Hoisté pour que les DEUX branches de return partagent la MÊME
// valeur — la branche $requireForm=true l'omettait (compteur qui repartait à A0001 au lieu
// de A0032). Récurrence exacte de la classe RED-08 (cf. payment_route_all_to_counter L296).
// N'affecte PAS le fiscal_sequence_no NF525 (séquence distincte, gap-free).
$queueStartNumber = (int) env('KIOSK_QUEUE_START_NUMBER', 32);

// [P2-t heal 2026-07-18] Flag « wizard POS sur borne » (kioskUsePosWizard côté SPA).
// Lu via config() dans master.blade.php (plus env() cru) pour survivre à
// `php artisan config:cache` (sinon env() → null → flag neutralisé silencieusement au
// deploy). filter_var comme features.staff_only_mode : "false" dans .env => bool false.
$usePosWizard = filter_var(env('KIOSK_USE_POS_WIZARD', false), FILTER_VALIDATE_BOOLEAN);

/*
| [MENU-RESET 2026-05-13] Sandwich-split DISABLED — new structure has 3 separate
| sandwich categories (sandwich-cayenne, galette, sandwich-classique) so no need
| for cold-vs-signature sidebar split anymore. Kept as empty array for backwards
| compat; kiosk store reads `cold_item_slugs` as [] → no sidebar duplication.
*/
$sandwichColdSlugs = [];

/*
| [Sprint H1 K-003 2026-05-17] FRITES_INCLUDED_CATS
|
| Category IDs whose items include a free side of fries (sandwich/menu
| bundles). Previously hardcoded in KioskWizardComponent.vue:1029.
| Externalize so a menu reset / DB renumber doesn't silently break the
| wizard. Override via KIOSK_FRITES_INCLUDED_CATS env (CSV of int IDs).
| Defaults to 309,310,311,314 (Assiettes, Ojja, Omelettes, Menus Enfants).
*/
$fritesIncludedCategoryIds = array_values(array_filter(array_map(
    static fn ($v) => (int) trim((string) $v),
    explode(',', (string) env('KIOSK_FRITES_INCLUDED_CATS', '309,310,311,314'))
), static fn ($v) => $v > 0));

/*
| [Sprint H1 K-004 2026-05-17] Wizard template aliases (Owner G3 Option B)
|
| Map of name/category-substring → canonical wizard template. Consulted
| BEFORE the legacy substring inference in KioskWizardComponent's
| detectTemplateFromName so admin item renames don't silently break
| template routing (e.g., "Sandwich Cayenne" → "Sandwich Royal Spicy").
|
| Canonical templates that exist in the wizard switch:
|   simple | tacos | sandwich | burger | assiette | omelette | salade | snacking
|
| Keys are case-insensitive substrings matched against
| `${name} ${category_name}`. Keep them lowercase here.
*/
$wizardTemplateAliases = [
    // Sandwich family
    'cayenne'           => 'sandwich',
    'galette'           => 'sandwich',
    'classique'         => 'sandwich',
    'royal'             => 'sandwich',
    // Tacos family
    'tacos'             => 'tacos',
    'big tacos'         => 'tacos',
    // Burger family
    'burger'            => 'burger',
    'cheeseburger'      => 'burger',
    // Assiette family
    'assiette'          => 'assiette',
    // Omelette / Ojja / Menu Enfant (legacy mapping preserved)
    'omelette'          => 'omelette',
    'ojja'              => 'omelette',
    // Salades
    'salade'            => 'salade',
    // Snacking family
    'nugget'            => 'snacking',
    'tenders'           => 'snacking',
    'goujon'            => 'snacking',
    // Frites are addons, not standalone wizard templates — excluded.
];

if ($requireForm) {
    return [
        'spa_auto_login' => false,
        'spa_payload'    => null,
        // [2026-05-18 PR-B P0 heal] Mirror the gate config so the blade can
        // read these regardless of which branch this file returns through.
        'auto_login_trusted_ips' => [],
        'auto_login_local_bypass' => env('APP_ENV') === 'local',
        'default_locale' => $defaultLocale,
        // [ADR-007 / Sprint 3D] V1 FR-immutable. `false` désactive le picker UI
        // côté SPA. Voir docs/adr/ADR-007-kiosk-fr-lock.md.
        'locale_switch_allowed' => $localeSwitchAllowed,
        'menu_pricing'   => [
            'full_ratio'   => 1.0,
            // [G-PRIX owner 2026-07-22] frites seules / boisson seule = 1,90 € (2,50 × 0.76).
            'fries_ratio'  => 0.76,
            'drink_ratio'  => 0.76,
        ],
        'sandwich_split' => [
            // [MENU-RESET 2026-05-13] Disabled. New structure: 3 sandwich cats.
            'parent_category_slug' => null,
            'cold_item_slugs'      => $sandwichColdSlugs,
            'cold_sidebar_label'   => 'Sandwich froid',
        ],
        'max_item_qty' => (int) env('KIOSK_MAX_ITEM_QTY', 20),
        // [RATE-FIX 2026-07-10] défaut 5→30/min : 5 était trop bas (un client qui enchaîne 2
        // commandes tombait sur un 429). Le quote a désormais son propre bucket (quote_rate_limit).
        'order_rate_limit' => (int) env('KIOSK_ORDER_RATE_LIMIT', 30),
        'quote_rate_limit' => (int) env('KIOSK_QUOTE_RATE_LIMIT', 120),
        // [iter15-mega-fix D-001 2026-05-10] Hardware credential, not a brute-force surface.
        'login_rate_limit' => (int) env('KIOSK_LOGIN_RATE_LIMIT', 30),
        'confirmation_auto_return_seconds' => (int) env('KIOSK_CONFIRMATION_AUTO_RETURN_SECONDS', 30),
        // [SUPERVISOR WAVE C Z1 2026-05-28] Plan B: route ALL kiosk payments to counter (no TPE at kiosk).
        'payment_route_all_to_counter' => $paymentRouteAllToCounter,
        // [W2 audit heal 2026-06-26] Dedicated kiosk promo/loyalty UI gate (default FALSE) — see top of file.
        'promo_enabled' => $promoEnabled,
        // [FIDÉLITÉ BORNE 2026-08-19] Interrupteur DÉDIÉ au rachat de points — présent dans
        // LES DEUX branches de retour, comme l'exige le piège documenté plus haut.
        'loyalty_redeem_enabled' => $loyaltyRedeemEnabled,
        // [TRAP-2 2026-06-04] Stale counter-collect cleanup TTL (minutes) — see top of file.
        'stale_collect_ttl_minutes' => $staleCollectTtlMinutes,
        // [C4-CAISSE-TELEPHONE FIX-2 2026-07-07] TTL distinct pour la purge des commandes téléphone.
        'stale_phone_collect_ttl_minutes' => $stalePhoneCollectTtlMinutes,
        // [Sprint H1 K-003 2026-05-17] Externalized FRITES_INCLUDED_CATS — see top of file.
        'frites_included_category_ids' => $fritesIncludedCategoryIds,
        // [Sprint H1 K-004 2026-05-17] Wizard template aliases — see top of file.
        'wizard_template_aliases' => $wizardTemplateAliases,
        // [P2-s heal 2026-07-18] queue_start_number DOIT figurer dans les DEUX branches
        // (classe RED-08) : lu par OrderService::allocateQueueNumber + FrontendOrderService
        // (fallback 1). Absent ici, KIOSK_REQUIRE_MACHINE_LOGIN=true faisait repartir le
        // compteur du jour à A0001 au lieu de A0032.
        'queue_start_number' => $queueStartNumber,
        // [P2-s heal 2026-07-18] auto_login_secret mirroré pour parité de forme entre
        // branches (inerte ici : spa_payload est null en mode formulaire → KioskAutoLoginGate
        // retourne null quelle que soit la valeur du secret).
        'auto_login_secret' => (string) env('KIOSK_AUTO_LOGIN_SECRET', ''),
        // [P2-t heal 2026-07-18] use_pos_wizard lu via config() (plus env() cru dans le blade)
        // pour survivre à `php artisan config:cache`. Présent dans les deux branches.
        'use_pos_wizard' => $usePosWizard,
    ];
}

$username = trim((string) env('KIOSK_MACHINE_USERNAME', ''));
$password = (string) env('KIOSK_MACHINE_PASSWORD', '');

// Local uniquement : alignement sur KioskMachineTableSeeder (kiosk-lecayenne / kiosk123).
// Évite l'écran « connexion auto indisponible » quand le .env n'a pas encore KIOSK_MACHINE_*.
// Jamais appliqué en production / staging / testing.
// Ne pas utiliser app() ici : les fichiers de config sont chargés avant que le container soit prêt.
if (env('APP_ENV') === 'local') {
    if ($username === '') {
        $username = 'kiosk-lecayenne';
    }
    if (trim($password) === '') {
        $password = 'kiosk123';
    }
}

$spaPayload = ($username !== '' && trim($password) !== '') ? [
    'username' => $username,
    'password' => $password,
] : null;

/*
| [2026-05-18 PR-B P0 kiosk-creds-leak heal] Security gate for the SPA
| machine-credential auto-login payload.
|
| Before this gate, `master.blade.php` would inject `spa_payload` (a JSON
| containing the kiosk machine username + password) into the global
| `window.foodkingConfig` whenever the request matched `/kiosk*`, regardless
| of the requester's identity / network. Result: any unauthenticated HTTP
| caller (`curl https://host/kiosk/idle`) could harvest the credentials
| and mint a `kiosk:order` Sanctum token.
|
| Gate semantics (cumulative — ALL conditions must be true):
|   1. `request()->is('kiosk*')`  ← existing path filter, still required
|   2. `spa_payload !== null`     ← credentials actually configured
|   3. EITHER `APP_ENV=local` (dev convenience)
|      OR `request()->ip()` is in `KIOSK_AUTO_LOGIN_TRUSTED_IPS` (CSV)
|
| Production deployment must set `KIOSK_AUTO_LOGIN_TRUSTED_IPS` to the LAN
| IPs of the physical kiosk machines, OR set
| `KIOSK_REQUIRE_MACHINE_LOGIN=true` to disable auto-login entirely (a UI
| login form is shown instead — see top of this file).
|
| The gate is evaluated at blade-render time (not here, because config files
| load before the request container). This file exposes the two pieces the
| blade needs: the canonical IP allowlist and the local-bypass signal.
*/
$autoLoginTrustedIps = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('KIOSK_AUTO_LOGIN_TRUSTED_IPS', '')),
)));

return [

    /*
    |----------------------------------------------------------------------
    | [ONB-05 2026-08-28] Trois cles appelees et jamais definies
    |----------------------------------------------------------------------
    |
    | Les trois etaient lues par du code de production avec un repli SILENCIEUX.
    | La plus visible : `rush_windows` retombait sur un tableau VIDE, donc
    | `KioskMenuService::isRush()` rendait TOUJOURS faux et le bandeau « coup de
    | feu » de `KioskWaitingComponent.vue:27` ne pouvait jamais s'afficher — une
    | fonction livree et injoignable.
    |
    | Les valeurs ci-dessous reproduisent EXACTEMENT les replis d'alors : rien ne
    | change de comportement, la molette devient simplement atteignable.
    |
    */

    // Creneaux d'affluence, 'HH:MM-HH:MM' separes par des virgules.
    // Exemple : KIOSK_RUSH_WINDOWS="11:45-14:00,18:30-21:30"
    // Vide par defaut = aucun creneau, identique a aujourd'hui.
    'rush_windows' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KIOSK_RUSH_WINDOWS', ''))
    ))),

    // Commande borne payee et jamais retiree : delai avant nettoyage (6 h).
    'stale_web_collect_ttl_minutes' => (int) env('KIOSK_STALE_COLLECT_TTL_MINUTES', 360),

    // Mise en cache de la carte servie a la borne, en secondes.
    'menu_cache_ttl' => (int) env('KIOSK_MENU_CACHE_TTL', 60),
    'spa_auto_login' => (bool) $spaPayload,
    'spa_payload'    => $spaPayload,
    'auto_login_trusted_ips' => $autoLoginTrustedIps,
    // [BORNE-CLOUD 2026-06-27] Lien secret réseau-indépendant pour borne distante
    // (l'IP/box change — fibre à venir). ?machine_key=<secret> == ce secret ⇒
    // auto-login. Vide = chemin secret désactivé. Voir App\Support\KioskAutoLoginGate.
    'auto_login_secret' => (string) env('KIOSK_AUTO_LOGIN_SECRET', ''),
    'auto_login_local_bypass' => env('APP_ENV') === 'local',
    'default_locale' => $defaultLocale,
    // [ADR-007 / Sprint 3D] V1 FR-immutable. `false` désactive le picker UI côté
    // SPA et le persisted-state localStorage. Voir docs/adr/ADR-007-kiosk-fr-lock.md.
    'locale_switch_allowed' => $localeSwitchAllowed,
    'menu_pricing'   => [
        'full_ratio'   => 1.0,
        // [G-PRIX owner 2026-07-22] frites seules / boisson seule = 1,90 € (2,50 × 0.76).
        'fries_ratio'  => 0.76,
        'drink_ratio'  => 0.76,
    ],
    'sandwich_split' => [
        // [MENU-RESET 2026-05-13] Disabled. New structure: 3 sandwich cats.
        'parent_category_slug' => null,
        'cold_item_slugs'      => $sandwichColdSlugs,
        'cold_sidebar_label'   => 'Sandwich froid',
    ],
    'max_item_qty' => (int) env('KIOSK_MAX_ITEM_QTY', 20),
    // [RATE-FIX 2026-07-10] défaut 5→30/min (5 était trop bas : 2 commandes d'affilée = 429).
    // Le quote (aperçu prix) a désormais son propre bucket (quote_rate_limit) et ne consomme plus
    // le budget des commandes. Ceci est le tableau réellement retourné (SSOT config kiosk).
    'order_rate_limit' => (int) env('KIOSK_ORDER_RATE_LIMIT', 30),
    'quote_rate_limit' => (int) env('KIOSK_QUOTE_RATE_LIMIT', 120),
    // [owner 2026-07-07] Numéro de file (queue_number "A00NN") de départ du jour.
    // L'owner veut que le compteur quotidien commence à 32 (au lieu de 1). Partagé
    // par toutes les surfaces (borne + caisse) via allocateQueueNumber (OrderService
    // + FrontendOrderService) : le 1er ordre du jour = A0032, puis 33, 34…
    // N'affecte PAS le fiscal_sequence_no NF525 (séquence distincte, gap-free).
    // [P2-s heal 2026-07-18] Valeur hoistée partagée avec la branche $requireForm=true.
    'queue_start_number' => $queueStartNumber,
    // [iter15-mega-fix D-001 2026-05-10] Hardware credential, not a brute-force surface.
    'login_rate_limit' => (int) env('KIOSK_LOGIN_RATE_LIMIT', 30),
    'confirmation_auto_return_seconds' => (int) env('KIOSK_CONFIRMATION_AUTO_RETURN_SECONDS', 30),
    // [Sprint H1 K-003 2026-05-17] Externalized FRITES_INCLUDED_CATS — see top of file.
    'frites_included_category_ids' => $fritesIncludedCategoryIds,
    // [Sprint H1 K-004 2026-05-17] Wizard template aliases — see top of file.
    'wizard_template_aliases' => $wizardTemplateAliases,
    // [Z1-RED-08 heal 2026-05-28] Plan B kiosk payment routing — flag MUST
    // appear in BOTH return branches (requireForm + production default).
    // Previously only present in $requireForm=true branch (line 147),
    // breaking env override on production code path (RED-08 P0 caught
    // by adversarial review during Wave C dispatch).
    'payment_route_all_to_counter' => $paymentRouteAllToCounter,
    // [W2 audit heal 2026-06-26] Dedicated kiosk promo/loyalty UI gate (default FALSE) — see top of file.
    'promo_enabled' => $promoEnabled,
    // [FIDÉLITÉ BORNE 2026-08-19] Interrupteur DÉDIÉ au rachat de points (cf. branche ci-dessus).
    'loyalty_redeem_enabled' => $loyaltyRedeemEnabled,
    // [TRAP-2 2026-06-04] Stale counter-collect cleanup TTL (minutes) — see top of file.
    'stale_collect_ttl_minutes' => $staleCollectTtlMinutes,
    // [C4-CAISSE-TELEPHONE FIX-2 2026-07-07] TTL distinct pour la purge des commandes téléphone.
    'stale_phone_collect_ttl_minutes' => $stalePhoneCollectTtlMinutes,
    // [P2-t heal 2026-07-18] Flag « wizard POS sur borne » via config() (survit à config:cache).
    'use_pos_wizard' => $usePosWizard,
];
