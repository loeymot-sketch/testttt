# Runbook — Bascule PRODUCTION Uber Eats (Le Cayenne)

> Préparé le 2026-08-03, pendant l'attente de la validation « Basic Production » d'Uber
> (ticket #58936938, dossier de preuves envoyé le 2026-08-02).
> À dérouler UNIQUEMENT quand Uber a confirmé : scopes production whitelistés sur l'app
> `kds-caisse` (Client ID `1vx5TIwSpo6PK8V50w0fIloPKvn9OPNB`).

## Pré-requis confirmés par Uber
- [ ] Email Uber : production access GRANTED / scopes whitelistés pour `1vx5TIwSpo6PK8V50w0fIloPKvn9OPNB`
- [ ] Le store réel `0b5b375c-79f6-5769-bf0f-c77a20e54942` est provisionné/branché sur l'app de prod
      (sinon : refaire le flux pos_provisioning — authorize marchand puis POST /pos_data — sur
      l'app de PROD, comme fait en sandbox, PUIS PATCH pos_data COMPLET : is_order_manager
      + integration_enabled + pos_integration_enabled + require_manual_acceptance +
      webhooks_config — ⚠️ PATCH partiel = champs absents RÉINITIALISÉS).

## Étape 1 — .env de production (VPS `/var/www/lecayenne`)
Restaurer les credentials PROD (sauvegarde faite avant le mode sandbox) puis compléter :
```
UBER_CLIENT_ID=1vx5TIwSpo6PK8V50w0fIloPKvn9OPNB
UBER_CLIENT_SECRET=<secret app prod — icône œil dashboard ; À RÉGÉNÉRER après go-live>
UBER_STORE_ID=0b5b375c-79f6-5769-bf0f-c77a20e54942
UBER_WEBHOOK_SECRET=<Signing Key du webhook de l'app PROD (2888… créée le 2026-08-01, ou la régénérer)>
UBER_TOKEN_URL=https://login.uber.com/oauth/v2/token
UBER_API_BASE=https://api.uber.com
UBER_AUTO_ACCEPT=true
UBER_PREP_TIME_MIN=15
UBER_MENU_MANAGED=false   # ← OPTION A owner : le menu Uber officiel (menu-maker) reste maître
```
Puis : `php artisan config:clear && php artisan config:cache && php artisan queue:restart`

## Étape 2 — Code
- Merger la PR #29 (`fix/uber-order-fetch-v2`) dans la branche de déploiement, pull sur le VPS.
- Frontend (badges 🛵 caisse/historique/KDS legacy) : `npm run prod` puis déployer les assets.

## Étape 3 — Vérifications (dans l'ordre)
1. Token prod : `php artisan tinker --execute="var_dump((bool) app(\App\Services\Uber\UberClient::class)->accessToken());"` → true.
2. `GET /v1/eats/stores` (token prod) liste le store réel.
3. `pos_data` du store réel : `order_manager_client_id` = app prod, `pos_integration_enabled` = true.
4. Webhook : POST signé main → 200 ack ; signature bidon → 401.
5. `php artisan uber:menu-push` → DOIT répondre « REFUSÉ : UBER_MENU_MANAGED=false » (verrou Option A).
6. Commande réelle de faible montant passée par l'owner sur Uber Eats → caisse + KDS + auto-accept
   ACCEPTED + « prête » KDS → ready 200. (⚠️ vraie commande, vrai paiement, vrai coursier.)

## Étape 4 — Post go-live (sécurité)
- [ ] Régénérer le Client Secret de l'app prod (a transité par chats/terminal) + le reposer dans .env.
- [ ] Vérifier `UBER_MENU_MANAGED=false` (le menu-maker Uber garde la main — prix Uber dédiés).
- [ ] Surveiller `webhook_events` provider=uber_eats status=failed (backlog monitoring).

## Pièges connus (payés en sandbox — ne pas re-payer en prod)
- Tout objet vide envoyé à Uber = `{}` (stdClass), jamais `[]` (400 Bad Request silencieux).
- PATCH `/pos_data` RÉINITIALISE les champs absents → toujours envoyer l'état complet.
- deny/cancel impossibles après ~11,5 min (fenêtre d'acceptation).
- `update-store-status` : enum ONLINE/OFFLINE uniquement, `is_offline_until` = string RFC3339.
- Uber valide en lisant SES logs : exécuter les flux, pas seulement les coder.
- En prod (Option A, menu non géré par la caisse) : mapping des commandes par NOM (mapper
  insensible accents/casse) ; la synchro 86 → Uber ne cible pas les items du menu-maker
  (gérer les ruptures dans Uber Eats Manager).
