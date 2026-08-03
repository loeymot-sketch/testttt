# EXECUTE — P11_DEPLOY_PROCEDURE_DOC — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (docs/.env example, aucune logique applicative)
**VAGUE:** V3 salve 2 (P1 hardening — plan §1.2 ligne 59 + §2 V3 ligne 146)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.2 ligne 59 + §2 V3 ligne 146
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-17-02
- `reports/review/VERIFY_17_I18N_DEPLOY_2026-04-20.md` §107-114 + §176 V3 + §214 (cycle proposé)

## Constat factuel pré-cycle (vérifié read-only)

**Variables manquantes dans `.env.example`** (preuve VERIFY-17 §107-110) :

| Variable | Référencée par | Sévérité | État actuel `.env.example` |
|---|---|---|---|
| `FCM_SERVER_KEY`, `FCM_SENDER_ID`, `FCM_TOPIC_PREFIX` | `config/services.php` (push notifications mobile) | Moyen | **absentes** |
| `LOG_CHANNEL` | `config/logging.php` (default channel) | Faible | **absente** (mention indirecte ligne 164 sans bloc dédié) |
| `LOG_LEVEL` | `config/logging.php` (per-channel) | Faible | **absente** |
| `MIX_GOOGLE_MAP_KEY` | `config/app.php` (`google_map_key`) | Faible | **absente** |
| `SENTRY_*` | non installé (composer) | Info | **non requis** (pas dans scope) |

**État courant `.env.example`** (lu read-only par parent) :
- Bloc "Mail" présent (ligne 122-131)
- Bloc "Observability" minimal (ligne 159-164) avec `HEALTH_IPS_ALLOWED` mais sans `LOG_CHANNEL`/`LOG_LEVEL` explicites
- Pas de bloc dédié "Push notifications mobile" / "FCM"
- Pas de bloc dédié "Maps / Google API"
- Checklist déploiement (ligne 189-206) ne mentionne pas zero-downtime migrations

**Volet zero-downtime migrations** (preuve VERIFY-17 §178 + §214) :
- `docs/DEPLOIEMENT.md`, `DEPLOYMENT_GUIDE_V1.md`, `PRODUCTION_SETUP.md` documentent déjà `migrate --force`, supervisord, cron
- Manque : référence explicite à la procédure zero-downtime dans la checklist `.env.example`

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "documentation, no schema, no auth, no pricing")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `.env.example` (édition — ajout 4 variables + référence procédure migrations)

### SCOPE_FILES (whitelist stricte)
- `.env.example` (édition unique)
- `reports/execution/RUN_P11_DEPLOY_PROCEDURE_DOC_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict)
- ❌ `config/*.php` (déjà OK — variables sont déjà référencées par `services.php`, `logging.php`, `app.php`)
- ❌ `.env` (fichier réel, jamais touché en cycle automatisé — uniquement `.env.example`)
- ❌ `docs/DEPLOIEMENT.md`, `docs/DEPLOYMENT_GUIDE_V1.md`, `docs/PRODUCTION_SETUP.md`, etc. (cycle séparé si besoin)
- ❌ `composer.json` (Sentry non installé → variable hors scope)
- ❌ Tout code applicatif, tests, migrations
- ❌ `phpunit.xml` (déjà touché par cycle V1 #07)
- ❌ Toute autre doc (`docs/BUSINESS_RULES.md`, etc.)
- ❌ Plans/rapports antérieurs

## Invariants at Risk
- **Aucun** — c'est un fichier d'exemple lu uniquement par le développeur lors du setup. `.env.example` n'est pas chargé par Laravel à l'exécution.
- Risque potentiel : altérer accidentellement une valeur existante (ex. `MIX_API_KEY=change-me-...`). Mitigation : ajout pur, jamais modification de lignes existantes.

## Dependencies
- Aucune

## Plan bref

### Étape 1 — Lire (vérité terrain)
- `.env.example` (intégral 207 lignes — déjà lu par parent, contenu confirmé)
- `config/services.php` (read-only — confirmer noms exacts variables FCM)
- `config/logging.php` (read-only — confirmer `LOG_CHANNEL` / `LOG_LEVEL` lecture)
- `config/app.php` (read-only — confirmer `google_map_key` lit `MIX_GOOGLE_MAP_KEY`)

### Étape 2 — Modifier `.env.example`

**Ajouts ciblés (jamais de modification de lignes existantes)** :

#### Bloc 1 : Logging (insérer après le bloc "Observability" ligne 158-164, ou créer un bloc dédié juste après)

```bash
# -----------------------------------------------------------------------------
# Logging — channel & level
# -----------------------------------------------------------------------------
# Default channel used by Log facade. See config/logging.php for available channels:
#   single, daily, stack, slack, papertrail, syslog, errorlog, monolog, custom,
#   stderr, null, emergency, observability (kiosk_locale + audit), production_json.
# Production: prefer `production_json` for structured JSON logs (docs/OBSERVABILITY.md).
LOG_CHANNEL=stack
# Min severity propagated by Log facade. Standard PSR-3 levels: debug, info, notice,
# warning, error, critical, alert, emergency. Production: info or warning.
LOG_LEVEL=debug
```

#### Bloc 2 : Push notifications mobile FCM (insérer après bloc "Mail" ligne 122-131 ou avant "AWS S3" ligne 133)

```bash
# -----------------------------------------------------------------------------
# FCM — Firebase Cloud Messaging (push notifications mobile clients)
# -----------------------------------------------------------------------------
# Optionnel — utilisé par config/services.php pour push notifs commande
# (status update, livraison). Si vide, push silencieusement désactivé.
# Documentation FCM : https://firebase.google.com/docs/cloud-messaging
FCM_SERVER_KEY=
FCM_SENDER_ID=
FCM_TOPIC_PREFIX=foodking
```

#### Bloc 3 : Maps Google (insérer dans une section "Frontend Mix" ou ajouter section dédiée)

```bash
# -----------------------------------------------------------------------------
# Frontend (Laravel Mix prefixed env vars exposed to JS bundle)
# -----------------------------------------------------------------------------
# Google Maps API key — admin map widgets uniquement (branch picker, livraison).
# Lue côté front via config/app.php → google_map_key.
MIX_GOOGLE_MAP_KEY=
```

#### Bloc 4 : Référence zero-downtime dans la checklist (modifier ligne 192-206)

Ajouter UNE nouvelle ligne dans le bloc PRODUCTION DEPLOYMENT CHECKLIST, juste après `# [ ] Run migrations: php artisan migrate` :

```bash
# [ ] Zero-downtime migrations: see docs/DEPLOIEMENT.md (forward-compatible, no destructive DDL during traffic)
```

> **NOTE** : ce dernier ajout est dans le bloc commenté checklist (lignes 189-206). Aucun risque exécution.

### Étape 3 — Validation
- `git diff --stat .env.example` (1 fichier modifié, ~25 lignes ajoutées max)
- `git status --short` → vérifier aucun fichier hors whitelist
- Lecture finale `.env.example` : vérifier que les blocs ajoutés sont propres, pas de duplication, pas de cassure du fichier existant
- Vérifier qu'aucune ligne existante n'a été modifiée : `git diff .env.example` ne doit montrer que des additions (`+`), pas de suppressions (`-`)

### Étape 4 — Rapport
`reports/execution/RUN_P11_DEPLOY_PROCEDURE_DOC_2026-04-20.md` avec gabarit Final report.

## Acceptance Tests
- [ ] `.env.example` contient `LOG_CHANNEL=stack` et `LOG_LEVEL=debug` avec commentaire dédié
- [ ] `.env.example` contient `FCM_SERVER_KEY=`, `FCM_SENDER_ID=`, `FCM_TOPIC_PREFIX=foodking` avec bloc commenté
- [ ] `.env.example` contient `MIX_GOOGLE_MAP_KEY=` avec bloc commenté
- [ ] Checklist déploiement contient une ligne sur zero-downtime migrations renvoyant vers `docs/DEPLOIEMENT.md`
- [ ] **Aucune ligne existante de `.env.example` modifiée** (preuve : `git diff` ne contient que des `+` ajoutés)
- [ ] **Aucun** autre fichier modifié

## Exit Criteria
- [ ] 4 ajouts ciblés dans `.env.example`
- [ ] Diff montre uniquement des additions
- [ ] `reports/execution/RUN_P11_DEPLOY_PROCEDURE_DOC_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé)
**STOP IMMÉDIAT** si :
- Tentation de modifier `config/*.php` → ❌ déjà OK, c'est `.env.example` qui manque
- Tentation de modifier `docs/DEPLOIEMENT.md` ou autre doc → ❌ scope strict `.env.example` only
- Tentation d'ajouter `SENTRY_DSN=` ou `SENTRY_TRACES_SAMPLE_RATE=` → ❌ Sentry non installé (composer), variable inutile
- Tentation d'ajouter d'autres variables non listées (ex. `REDIS_*`, `PUSHER_*`, `SANCTUM_*`, etc.) → ❌ déjà présentes dans `.env.example`
- Tentation d'installer `sentry/sentry-laravel` ou autre dépendance → ❌ totalement hors scope V3
- Tentation de créer un test (ex. `EnvExampleTest.php`) → ❌ pas dans scope (cycle test séparé éventuel plus tard)
- Tentation de modifier `composer.json`, `package.json`, lockfiles → ❌
- Tentation de toucher `.gitignore`, `.env` (fichier réel) → ❌
- **Anti-pattern** : `git checkout` ou bypass lockfile → STOP + escalade

## Remediation
- Attempt 1 KO (mauvais format ou duplication) → fix
- Attempt 2 KO → simplifier
- Attempt 3 → STOP + escalade

## Deliverables
- Diff `.env.example` (~25 lignes ajoutées max, 4 blocs)
- `reports/execution/RUN_P11_DEPLOY_PROCEDURE_DOC_2026-04-20.md`

## Communication
Subagent renvoie : verdict, output `git status --short`, `git diff --stat`, confirmation diff `.env.example` ne contient que des `+`, liste des 4 blocs ajoutés.
