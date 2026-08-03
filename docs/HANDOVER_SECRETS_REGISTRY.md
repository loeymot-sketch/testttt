# Registre de passation — Secrets & Documents à sécuriser

> **Propriétaire : owner / admin Le Cayenne.** Document de PASSATION.
> **À finaliser EN FIN DE PROJET** (décision owner 2026-07-15 : on teste en réel
> d'abord, les secrets forts définitifs se posent au bout).
>
> ⛔ **Ce fichier ne contient AUCUNE valeur de secret** et ne doit JAMAIS en
> contenir (règle CLAUDE.md §3quater — jamais de secret commité). Il liste les
> *noms*, *emplacements*, *rôles* et l'*action go-live*. Les valeurs vivent
> uniquement dans le `.env` du serveur et/ou un secret manager.

---

> 🔗 **Check-list unique du go-live sécu** (déroulé de bout en bout, tous volets) :
> **`docs/FINAL_SECURITY_PHASE_CHECKLIST.md`**. Ce registre en est le détail « secrets ».

## 0 · Comment utiliser ce registre

1. Pendant les tests en réel : les valeurs actuelles sont des valeurs de
   **test** (souvent faibles / par défaut). C'est assumé — 0 client réel.
2. En fin de projet, dérouler le **§3 Checklist go-live** : chaque ligne « à
   poser » devient une valeur forte, discrète, stockée hors-repo.
3. Barrière finale AVANT ouverture prod :
   ```
   APP_ENV=production php artisan app:preflight-production --strict
   ```
   Ce gate refuse le go-live si un secret est faible/sentinelle **ou** si la
   chaîne NF525 n'est pas vérifiable (durci 2026-07-15, cf. §4).

---

## 1 · Secrets (noms + emplacements + rôle + action go-live)

| # | Variable `.env` | Config Laravel | Rôle / consommateur | Où stocker (prod) | Action go-live |
|---|---|---|---|---|---|
| S1 | `FISCAL_AUDIT_SECRET` | `fiscal.audit_secret` | HMAC chaîne `audit_logs` (NF525) — `AuditLogService` | Secret manager `fiscal-audit-secret-v1` | `openssl rand -hex 32`. **Permanent** — jamais tourné hors runbook `docs/FISCAL_SECRETS.md`. Posé **dès le 1er boot** d'une DB fiscale **propre**. |
| S2 | `FISCAL_Z_REPORT_SECRET` | `fiscal.z_report_secret` | HMAC signature Z-reports (NF525) — `ZReportService` | Secret manager `fiscal-z-secret-v1` | Idem S1 (secret séparé, indépendant). |
| S3 | `APP_KEY` | `app.key` | Chiffrement Laravel (sessions, cookies, colonnes chiffrées) | Secret manager | `php artisan key:generate` **une fois**. Ne jamais changer après chiffrement de données. |
| S4 | `DB_PASSWORD` | `database.connections.mysql.password` | Accès MySQL | Secret manager | Mot de passe fort dédié. Compte applicatif SANS `DROP` (Ansible CVP0-1 `REVOKE DROP` — protège la chaîne NF525 contre TRUNCATE). |
| S5 | `KIOSK_MACHINE_PASSWORD` | `kiosk.spa_payload` | Auth borne (mine token `kiosk:order`) | Secret manager / `.env` | **Remplacer `kiosk123` (PUBLIC dans le repo)** par un mot de passe unique. Aligner env ↔ DB via `foodking:ensure-kiosk-machine` (cf. `docs/runbooks/KIOSK_MACHINE_PROVISIONING.md`). ⚠ voir §2. |
| S6 | `KIOSK_MACHINE_USERNAME` | `kiosk.spa_payload` | Identifiant borne | `.env` | Défaut `kiosk-lecayenne` acceptable (pas un secret). |
| S7 | `KIOSK_AUTO_LOGIN_SECRET` | `kiosk.auto_login_secret` | Lien `?machine_key=` (release creds borne réseau-indépendant) | Secret manager | `openssl rand -hex 24`. **Alternative** : `KIOSK_AUTO_LOGIN_TRUSTED_IPS` (IP/CIDR borne) si REMOTE_ADDR = vrai client (pas un LB). ⚠ voir §2. |
| S8 | `DAILY_BOOK_PIN` | `daily_book.pin` | Code PIN carnet interne (`/carnet`) | `.env` (privé) | 6 chiffres ≠ `2468` (le boot refuse `2468`). `deploy-lecayenne.sh` en génère un `/dev/urandom` si absent. HORS NF525. |
| S9 | `PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` (ou soketi) | `broadcasting.connections.pusher` | Temps-réel KDS/OSS/borne | Secret manager | Poser les vraies clés (le preflight exige `BROADCAST_DRIVER` ≠ null). |
| S10 | Clés SMS (provider OTP) | `services.*` | OTP comptes web / suivi commande | Secret manager | Poser les vraies clés provider (SMS différé en test — cf. mémoire sync web). |
| S11 | `app.api_key` (`API_KEY`) | `app.api_key` | Header `x-api-key` du SPA | `.env` | **Note : rendu côté page (`window.foodkingConfig.apiKey`) — c'est un identifiant client, PAS un secret fort.** Ne pas s'y fier comme barrière d'autorisation. |

---

## 2 · ⚠ Point de sécurité borne (à traiter au go-live — actuellement assumé en test)

**Constat vérifié (2026-07-15, non corrigé — report owner) :**
- Le mot de passe borne par défaut **`kiosk123` est PUBLIC** (`database/seeders/KioskMachineTableSeeder.php:50`) et le seeder n'est bloqué **qu'en `production`** → il peut être présent sur staging.
- L'API `/api/auth/kiosk-login` est joignable **depuis n'importe quelle IP** avec le `x-api-key` **public** (`routes/api.php`, `ApiKeyMiddleware`). Le gate IP/`machine_key` ne protège **que le pré-remplissage de l'écran**, PAS l'API.
- ⇒ tant que `kiosk123` est vivant sur une box exposée Internet, quiconque peut miner un token `kiosk:order` (créer des commandes, etc.).

**Action go-live (obligatoire avant ouverture réelle) :**
1. `php artisan foodking:ensure-kiosk-machine --username=kiosk-lecayenne --password=<FORT-UNIQUE> --branch-id=1 --force`
2. **Révoquer les tokens vivants** (une rotation seule les laisse valides 8 h) : `admin/kiosk-machine/logout/{id}`.
3. Choisir le chemin d'auto-login (S7) — **jamais** une IP de LB dans `TRUSTED_IPS`.
4. (Durcissement recommandé) re-keyer le throttle `kiosk-login` sur `REMOTE_ADDR` (`RouteServiceProvider`) — voir plan Workstream B.

Détails : `docs/runbooks/KIOSK_MACHINE_PROVISIONING.md`.

---

## 3 · Checklist go-live (mécanique, fin de projet)

```
[ ] S1 FISCAL_AUDIT_SECRET   = openssl rand -hex 32   → secret manager + .env prod
[ ] S2 FISCAL_Z_REPORT_SECRET= openssl rand -hex 32   → secret manager + .env prod
[ ] S3 APP_KEY               = php artisan key:generate (si pas déjà fait, DB neuve)
[ ] S4 DB_PASSWORD           = fort ; compte SANS privilège DROP
[ ] S5 KIOSK_MACHINE_PASSWORD= fort unique (≠ kiosk123) + ensure-kiosk-machine + révoquer tokens
[ ] S7 KIOSK_AUTO_LOGIN_SECRET (ou TRUSTED_IPS) posé
[ ] S8 DAILY_BOOK_PIN        = 6 chiffres ≠ 2468
[ ] S9 Pusher/soketi         = vraies clés (BROADCAST_DRIVER ≠ null)
[ ] S10 SMS provider         = vraies clés
[ ] DB prod = genesis PROPRE (pas un dump staging contaminé) — cf. §4
[ ] php artisan config:cache && php artisan queue:restart
[ ] APP_ENV=production php artisan app:preflight-production --strict   → exit 0
[ ] Archiver les anciens secrets (post-rotation) en stockage froid — NE JAMAIS détruire (6 ans NF525)
```

---

## 4 · Garde-fou go-live NF525 (durci 2026-07-15)

`app:preflight-production` a été renforcé pour que la pose des secrets finaux soit
**« 0 risque »** :
- **Sentinelles refusées** : un secret ≥32 chars mais listé dans `fiscal.dev_sentinels` est rejeté (avant, seule la longueur comptait).
- **Chaîne réellement re-vérifiée** : le gate re-marche la chaîne `audit_logs`
  (`AuditLogService::verifyChain`, read-only) — un secret **fort-mais-faux** ou une
  DB contaminée promue en prod **échoue** le preflight au lieu de partir en silence.

Verrou de test : `tests/Feature/Fiscal/PreflightChainGateTest.php`.

---

## 5 · Documents à garder en sécurité (ne pas perdre)

| Doc | Rôle | Sensibilité |
|---|---|---|
| `docs/FISCAL_SECRETS.md` | Runbook rotation NF525 (autorité) | interne |
| **`docs/HANDOVER_SECRETS_REGISTRY.md`** (ce fichier) | Registre de passation | interne |
| Secrets archivés post-rotation (`fiscal-*-v{N}`) | Vérif offline NF525 6 ans | **critique — jamais détruire** |
| `tools/deploy-lecayenne.sh` | Déploiement canonique durci | interne |
| `plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` | Plan de remédiation (contexte) | interne |
| Export chain-heads pré-rotation (`fiscal:audit-chain-head`) | Preuve de continuité NF525 | **critique** |

---

**Dernière mise à jour** : 2026-07-15 (post audit adversaire 3 points).
**Références** : `config/fiscal.php`, `config/kiosk.php`, `config/daily_book.php`,
`app/Console/Commands/PreflightProductionCommand.php`, `docs/FISCAL_SECRETS.md`.
