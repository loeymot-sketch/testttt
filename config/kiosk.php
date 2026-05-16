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
| [MENU-RESET 2026-05-13] Sandwich-split DISABLED — new structure has 3 separate
| sandwich categories (sandwich-cayenne, galette, sandwich-classique) so no need
| for cold-vs-signature sidebar split anymore. Kept as empty array for backwards
| compat; kiosk store reads `cold_item_slugs` as [] → no sidebar duplication.
*/
$sandwichColdSlugs = [];

if ($requireForm) {
    return [
        'spa_auto_login' => false,
        'spa_payload'    => null,
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

return [
    'spa_auto_login' => (bool) $spaPayload,
    'spa_payload'    => $spaPayload,
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
];
