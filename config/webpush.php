<?php

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Notifications Web Push (standard VAPID) vers les téléphones des clients.
 *
 * ARRÊT D'URGENCE : WEBPUSH_ENABLED=false (ou clés absentes) → aucun envoi, aucune erreur, et la route publique
 * `GET /api/frontend/push/key` répond `enabled:false` : le site ne propose plus rien. Aucun redéploiement du site requis.
 *
 * LES CLÉS NE CHANGENT PLUS JAMAIS une fois posées : changer la clé publique désabonne tous les appareils (chacun
 * devra redonner son accord). Générer UNE paire avec `php artisan webpush:keys`, la poser dans le `.env` de production.
 */
return [
    'enabled' => filter_var(env('WEBPUSH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'public_key' => env('WEBPUSH_PUBLIC_KEY', ''),
    'private_key' => env('WEBPUSH_PRIVATE_KEY', ''),
    // Contact exigé par le protocole (adresse du restaurant), jamais une donnée personnelle d'un client.
    'subject' => env('WEBPUSH_SUBJECT', 'mailto:contact@lecayenne.fr'),
    'ttl' => (int) env('WEBPUSH_TTL', 900),
    'urgency' => env('WEBPUSH_URGENCY', 'high'),
    // [audit adverse P1 SSRF] Seuls les services de notification des navigateurs reçoivent une requête (voir PushEndpoint).
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBPUSH_ALLOWED_HOSTS', implode(',', [
        'fcm.googleapis.com',                                           // Chrome / Edge / Android (PAS « *.googleapis.com » : storage.googleapis.com est multi-locataire)
        'updates.push.services.mozilla.com', '*.push.services.mozilla.com', // Firefox
        'web.push.apple.com', '*.push.apple.com',                       // Safari / iPhone
        '*.notify.windows.com',                                         // anciens Edge
    ])))))),
    // BANC LOCAL SEULEMENT (faux service de notification en http://127.0.0.1) : lève https / port / IP littérale. JAMAIS en production.
    'insecure_endpoints' => filter_var(env('WEBPUSH_ALLOW_INSECURE_ENDPOINTS', false), FILTER_VALIDATE_BOOLEAN),
    // Appareils par client : le 6e évince le moins récemment réussi.
    'max_devices_per_user' => 5,
    // Un abonnement sans succès depuis ce nombre de jours est supprimé par la tâche de ménage.
    'prune_after_days' => 90,
    // Échecs consécutifs avant suppression d'un abonnement.
    'max_failures' => 5,
    // Anti-doublon : même commande + même statut = une seule notification pendant cette durée (minutes).
    'dedupe_minutes' => 360,
];
