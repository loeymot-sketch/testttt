<?php

/*
 * [STORES T-3.3.2 · 2026-10-01] Application Le Cayenne des stores (Apple / Google).
 *
 * L'application embarque le site dans son paquet. Une fois installée, elle ne change plus
 * tant que le client ne la met pas à jour ; le serveur, lui, évolue. Le jour où un changement
 * du serveur rend un vieux paquet faux, on relève `APP_MOBILE_MIN_VERSION` : le serveur refuse
 * alors les commandes des versions plus anciennes (App\Http\Middleware\RefuseOutdatedApp) et
 * l'application affiche « Mise à jour nécessaire » avec le lien du store.
 *
 * Le site web et la borne n'envoient aucune version : ils ne sont jamais concernés.
 * Un réglage illisible ne bloque personne (App\Support\AppVersion::minimum).
 */
return [
    // Version la plus ancienne encore acceptée, au format 1.2.3 (versionName Android,
    // MARKETING_VERSION iOS). 1.0.0 = la première version publiée : personne n'est bloqué.
    'min_version' => (string) env('APP_MOBILE_MIN_VERSION', '1.0.0'),

    'message' => (string) env(
        'APP_MOBILE_UPDATE_MESSAGE',
        "Une nouvelle version de l'application Le Cayenne est disponible. Mets-la à jour pour continuer à commander."
    ),

    // Lien de la fiche App Store : son identifiant numérique n'existe qu'une fois l'application
    // créée dans App Store Connect. Vide = le message cite l'App Store sans lien.
    'ios_url' => (string) env('APP_MOBILE_IOS_URL', ''),

    // L'identifiant Android est connu d'avance (fr.lecayenne.app, app/capacitor.config.json).
    'android_url' => (string) env('APP_MOBILE_ANDROID_URL', 'https://play.google.com/store/apps/details?id=fr.lecayenne.app'),
];
