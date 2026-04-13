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
| Slugs items = Item::slug (Str::slug du nom FR) — alignés sur config/menu.php + MenuSeeder.
| POS : une seule catégorie « Nos Sandwichs » ; la borne duplique visuellement la ligne « Sandwich froid » en bas.
*/
$sandwichColdSlugs = [
    'sandwich-froid',
    'panini',
    'sandwich-classique-pain',
    'sandwich-classique-galette',
];

if ($requireForm) {
    return [
        'spa_auto_login' => false,
        'spa_payload'    => null,
        'default_locale' => $defaultLocale,
        'menu_pricing'   => [
            'full_ratio'   => 1.0,
            'fries_ratio'  => 0.6,
            'drink_ratio'  => 0.4,
        ],
        'sandwich_split' => [
            'parent_category_slug' => 'nos-sandwichs',
            'cold_item_slugs'      => $sandwichColdSlugs,
            'cold_sidebar_label'   => 'Sandwich froid',
        ],
    ];
}

$username = trim((string) env('KIOSK_MACHINE_USERNAME', ''));
$password = (string) env('KIOSK_MACHINE_PASSWORD', '');

$spaPayload = ($username !== '' && trim($password) !== '') ? [
    'username' => $username,
    'password' => $password,
] : null;

return [
    'spa_auto_login' => (bool) $spaPayload,
    'spa_payload'    => $spaPayload,
    'default_locale' => $defaultLocale,
    'menu_pricing'   => [
        'full_ratio'   => 1.0,
        'fries_ratio'  => 0.6,
        'drink_ratio'  => 0.4,
    ],
    'sandwich_split' => [
        'parent_category_slug' => 'nos-sandwichs',
        'cold_item_slugs'      => $sandwichColdSlugs,
        'cold_sidebar_label'   => 'Sandwich froid',
    ],
];
