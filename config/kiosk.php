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
            'fries_ratio'  => 0.6,
            'drink_ratio'  => 0.4,
        ],
        'sandwich_split' => [
            // [MENU-RESET 2026-05-13] Disabled. New structure: 3 sandwich cats.
            'parent_category_slug' => null,
            'cold_item_slugs'      => $sandwichColdSlugs,
            'cold_sidebar_label'   => 'Sandwich froid',
        ],
        'max_item_qty' => (int) env('KIOSK_MAX_ITEM_QTY', 20),
        'order_rate_limit' => (int) env('KIOSK_ORDER_RATE_LIMIT', 5),
        // [iter15-mega-fix D-001 2026-05-10] Hardware credential, not a brute-force surface.
        'login_rate_limit' => (int) env('KIOSK_LOGIN_RATE_LIMIT', 30),
        'confirmation_auto_return_seconds' => (int) env('KIOSK_CONFIRMATION_AUTO_RETURN_SECONDS', 30),
        // [SUPERVISOR WAVE C Z1 2026-05-28] Plan B: route ALL kiosk payments to counter (no TPE at kiosk).
        'payment_route_all_to_counter' => $paymentRouteAllToCounter,
        // [TRAP-2 2026-06-04] Stale counter-collect cleanup TTL (minutes) — see top of file.
        'stale_collect_ttl_minutes' => $staleCollectTtlMinutes,
        // [Sprint H1 K-003 2026-05-17] Externalized FRITES_INCLUDED_CATS — see top of file.
        'frites_included_category_ids' => $fritesIncludedCategoryIds,
        // [Sprint H1 K-004 2026-05-17] Wizard template aliases — see top of file.
        'wizard_template_aliases' => $wizardTemplateAliases,
        // [BORNE-PROMO-01 / ultra-audit 2026-06-10 — LOT D quick-win] Dormancy
        // gate for the kiosk promo DISPLAY chain (validate + banner + preview):
        // the order path never bills kiosk_promo_code (OrderQuoteService:416
        // metadata-only), so showing −5,00 € is a price lie. NEVER flip to true
        // without the G7 owner-gated order-path wiring. Present in BOTH return
        // branches (Z1-RED-08 lesson).
        'promos_redeemable' => (bool) env('KIOSK_PROMOS_REDEEMABLE', false),
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
    'spa_auto_login' => (bool) $spaPayload,
    'spa_payload'    => $spaPayload,
    'auto_login_trusted_ips' => $autoLoginTrustedIps,
    'auto_login_local_bypass' => env('APP_ENV') === 'local',
    'default_locale' => $defaultLocale,
    // [ADR-007 / Sprint 3D] V1 FR-immutable. `false` désactive le picker UI côté
    // SPA et le persisted-state localStorage. Voir docs/adr/ADR-007-kiosk-fr-lock.md.
    'locale_switch_allowed' => $localeSwitchAllowed,
    'menu_pricing'   => [
        'full_ratio'   => 1.0,
        'fries_ratio'  => 0.6,
        'drink_ratio'  => 0.4,
    ],
    'sandwich_split' => [
        // [MENU-RESET 2026-05-13] Disabled. New structure: 3 sandwich cats.
        'parent_category_slug' => null,
        'cold_item_slugs'      => $sandwichColdSlugs,
        'cold_sidebar_label'   => 'Sandwich froid',
    ],
    'max_item_qty' => (int) env('KIOSK_MAX_ITEM_QTY', 20),
    'order_rate_limit' => (int) env('KIOSK_ORDER_RATE_LIMIT', 5),
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
    // [TRAP-2 2026-06-04] Stale counter-collect cleanup TTL (minutes) — see top of file.
    'stale_collect_ttl_minutes' => $staleCollectTtlMinutes,
    // [BORNE-PROMO-01 / ultra-audit 2026-06-10 — LOT D quick-win] Dormancy
    // gate for the kiosk promo DISPLAY chain (validate + banner + preview):
    // the order path never bills kiosk_promo_code (OrderQuoteService:416
    // metadata-only), so showing −5,00 € is a price lie. NEVER flip to true
    // without the G7 owner-gated order-path wiring. Present in BOTH return
    // branches (Z1-RED-08 lesson).
    'promos_redeemable' => (bool) env('KIOSK_PROMOS_REDEEMABLE', false),
];
