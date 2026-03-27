<?php

/**
 * Borne (kiosk) — UI client directe, sans écran « authentification machine ».
 *
 * Par défaut : le SPA appelle auth/kiosk-login tout seul (identifiants injectés dans la page).
 * L’API exige toujours un token machine ; le client ne voit pas de formulaire.
 *
 * Pour afficher l’écran login (tests, audit) : KIOSK_REQUIRE_MACHINE_LOGIN=true
 *
 * Identifiants : KIOSK_MACHINE_* sinon valeurs seed (KioskMachineTableSeeder) :
 * kiosk-lecayenne / kiosk123 — à faire tourner en admin sur une vraie borne en prod.
 */
$requireForm = filter_var(env('KIOSK_REQUIRE_MACHINE_LOGIN', false), FILTER_VALIDATE_BOOLEAN);

if ($requireForm) {
    return [
        'spa_auto_login' => false,
        'spa_payload'    => null,
    ];
}

$username = trim((string) env('KIOSK_MACHINE_USERNAME', ''));
$password = (string) env('KIOSK_MACHINE_PASSWORD', '');

if ($username === '') {
    $username = trim((string) env('KIOSK_DEFAULT_MACHINE_USER', 'kiosk-lecayenne'));
}
if ($password === '') {
    $password = (string) env('KIOSK_DEFAULT_MACHINE_PASS', 'kiosk123');
}

$spaPayload = $username !== '' ? [
    'username' => $username,
    'password' => $password,
] : null;

return [
    'spa_auto_login' => (bool) $spaPayload,
    'spa_payload'    => $spaPayload,
];
