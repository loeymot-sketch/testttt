# Bancs RÉELS fidélité — 2026-11-06

Aucune réponse simulée : un backend PRIVÉ sur une COPIE de base, jamais la base de l'audit, jamais la production.

## Préparer
1. Copie de base : `mysqldump --single-transaction --set-gtid-purged=OFF foodking_e2e | mysql foodking_points`.
2. Backend privé (depuis `public/`) :
   `DB_DATABASE=foodking_points APP_URL=http://127.0.0.1:8781 HORAIRES_REFUSER_HORS_SERVICE=false php -S 127.0.0.1:8781 ../vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php`
3. Site : copie du dépôt du site SANS `.git`/`app`, méta `api-base-url` → `http://127.0.0.1:8781` et `api-key` → `MIX_API_KEY` local
   (le site pointe par défaut sur la PRODUCTION : ne jamais lancer le banc sur l'original), servie sur :8942.
4. `e2e-comptes.json` (à côté du script, variable `S`) : `client_id`, `client_token`, `pos_token`, `code` (client au rôle
   « Customer », jetons `auth_token` ['*'] — durée de vie 8 h).

## Ce qu'ils prouvent
- `parcours-points-reel.mjs` : payer sur place → 0 point à la validation, crédit à l'encaissement, page à jour seule,
  jamais « € dépensés » ; abus client refusés ; commande PRÉPAYÉE → points en attente jusqu'à la remise (rachat refusé
  « remise », accepté après) ; « dont N pts disponibles dès la remise » au portefeuille.
- `borne-identification-reelle.mjs` : écran fidélité de la borne — onglets téléphone / QR / e-mail, identification
  réelle par e-mail, 0 erreur JS (a trouvé l'écran blanc « Invalid linked format » d'un @ brut).
