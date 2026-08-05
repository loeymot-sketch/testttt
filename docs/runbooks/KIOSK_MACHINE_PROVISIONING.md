# Runbook — Provisionner la borne (auto-login) sur un serveur

> Objectif : faire fonctionner `/kiosk/idle` (auto-login borne) sur un serveur où
> il affiche « Connexion machine non configurée côté serveur » (`status_missing_env`).
> Réf : `plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` (Workstream B).
> Secrets : voir `docs/HANDOVER_SECRETS_REGISTRY.md` (S5–S7).

## Pourquoi l'écran « non configurée » apparaît

L'auto-login exige **3 conditions simultanées** :
1. Une **ligne `KioskMachine`** ACTIVE en DB (`kiosk-lecayenne`).
2. Des **identifiants env** : `KIOSK_MACHINE_USERNAME` + `KIOSK_MACHINE_PASSWORD`
   (`config/kiosk.php`) — sinon `spa_payload = null`.
3. Un **gate de release** franchi (`app/Support/KioskAutoLoginGate.php`) :
   `APP_ENV=local` **OU** `?machine_key=` == `KIOSK_AUTO_LOGIN_SECRET` **OU**
   IP cliente (`REMOTE_ADDR`) ∈ `KIOSK_AUTO_LOGIN_TRUSTED_IPS`.

En `staging`/`production`, sans (2) **et** (3), la page reste sur `missing_env`.

## Étapes

### 1. Créer / réaligner la machine (DB)
```bash
php artisan foodking:ensure-kiosk-machine \
  --username=kiosk-lecayenne --password='<FORT-UNIQUE>' --branch-id=1 --force
```
- ⛔ **Ne jamais** garder `kiosk123` (public dans le repo) sur une box exposée.
- `--force` réaligne user/branche + rehash le mot de passe.

### 2. Poser les identifiants env (doivent ÉGALER l'étape 1)
```
KIOSK_MACHINE_USERNAME=kiosk-lecayenne
KIOSK_MACHINE_PASSWORD=<FORT-UNIQUE>     # == mot de passe de l'étape 1
```

### 3. Choisir UN chemin de release
- **A — `machine_key` (réseau-indépendant, recommandé si IP/box changent)** :
  ```
  KIOSK_AUTO_LOGIN_SECRET=<openssl rand -hex 24>
  ```
  Ouvrir la borne sur `https://<serveur>/kiosk/idle?machine_key=<secret>`.
  ⚠ Le `machine_key` transite dans l'URL → **strip-le des access logs nginx**
  pour `/kiosk*`, et traite-le comme log-exposé (rotation possible).
- **B — IP de confiance (si `REMOTE_ADDR` = vrai client, pas un LB)** :
  ```
  KIOSK_AUTO_LOGIN_TRUSTED_IPS=<IP ou CIDR de la borne, ex. 2a01:.../64>
  ```
  Ouvrir `/kiosk/idle` (sans query). ⛔ **Jamais** une IP de LB/edge ici
  (release des creds à tout Internet).

### 4. Appliquer + vérifier
```bash
php artisan config:clear && php artisan config:cache
# smoke : le payload ne doit plus être null
curl -sk 'https://<serveur>/kiosk/idle?machine_key=<secret>' | grep -o 'kioskAutoLogin":[^,]*'
```
Faire ce smoke **AVANT les heures de service** (un `config:cache` sans `config:clear`
peut laisser la borne DOWN sur une valeur stale).

### 5. Après toute rotation de mot de passe borne
Révoquer les tokens `kiosk-token` vivants (sinon valides jusqu'à 8 h) :
```
POST /api/admin/kiosk-machine/logout/{id}    # (permission:settings)
```

## À vérifier sur le serveur (dépend du déploiement)
- **Y a-t-il un edge/LB devant le VPS ?** Si oui → `REMOTE_ADDR` = IP du proxy →
  le chemin **B est dangereux** (allowlister le proxy = ouvrir à tout Internet).
  Dans ce cas, **utiliser le chemin A** (`machine_key`).
  Diagnostic : comparer `REMOTE_ADDR` observé vs l'IP réelle du client.

## Sécurité (rappel)
- Token borne = ability **`kiosk:order` uniquement**, TTL 480 min, bloqué des routes admin.
- L'API `/api/auth/kiosk-login` est joignable de partout avec le `x-api-key` public →
  **la seule vraie protection est un mot de passe borne fort et unique** (pas `kiosk123`).
