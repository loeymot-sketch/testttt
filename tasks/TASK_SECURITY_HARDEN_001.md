# TASK_SECURITY_HARDEN_001 — Sécurité & Hardening

## Meta
- **Priority**: P1 (MAJOR)
- **PRIMARY_MODEL**: claude-sonnet-4-5-20250514
- **TEST_STRATEGY**: local-validation
- **DEPENDS_ON**: (none)
- **BLOCKS**: (none)

## Constats couverts
| ID | Severity | Titre |
|----|----------|-------|
| F-07 | MAJOR | Rate limit trop permissif sur commandes kiosk |
| F-11 | MINOR | landing_url kiosk non validé |
| F-16 | MINOR | Permissions admin trop larges (catch-all *) |

## Contexte

Ces constats concernent le durcissement sécurité avant mise en production. Aucun n'est critique individuellement, mais ensemble ils représentent une surface d'attaque non négligeable (kiosk accessible au public, admin panel avec permissions larges).

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Http/Kernel.php` ou `routes/api.php` — rate limiting kiosk
- `app/Models/KioskMachine.php` — validation landing_url
- `app/Http/Requests/KioskMachineRequest.php` — validation rules
- `database/seeders/` — permissions granulaires
- `config/permission.php` ou equivalent — review catch-all

### Hors scope
- Authentification (login, tokens, refresh)
- CORS policy
- SSL/TLS configuration

## Étapes d'exécution

### E1 — Rate limit kiosk (F-07)
1. Créer un rate limiter dédié : `RateLimiter::for('kiosk-orders', ...)`
2. Limite : 5 commandes/minute par kiosk machine (identifié par IP ou token)
3. Réponse 429 avec message clair : "Trop de commandes, veuillez patienter"
4. Log les tentatives excessives pour détection d'abus

### E2 — Validation landing_url (F-11)
1. Dans `KioskMachineRequest` (ou model mutator) :
   - Valider que l'URL commence par `/` (chemin relatif uniquement)
   - OU valider contre une whitelist de domaines autorisés
2. Rejeter toute URL externe (http://, https:// vers domaine tiers)
3. Valeur par défaut si null : `/kiosk`

### E3 — Permissions admin granulaires (F-16)
1. Auditer les rôles existants dans `seeders/`
2. Remplacer les permissions `*` (catch-all) par des permissions explicites
3. Créer un rôle `super-admin` explicite avec toutes les permissions listées
4. Les autres rôles admin reçoivent uniquement les permissions nécessaires
5. Documenter la matrice dans `docs/AUTHZ_MATRIX.md` (mise à jour)

## Validation attendue

- [ ] `php artisan test` — 0 failures
- [ ] Test rate limit : 6ème commande kiosk en 1 min → 429
- [ ] Test landing_url : URL externe → rejetée en validation
- [ ] Test permissions : admin standard ne peut pas accéder aux routes super-admin

## Invariants
- Le rate limit ne doit PAS affecter les commandes POS (seulement kiosk)
- Les permissions existantes des rôles fonctionnels ne sont pas réduites (seulement le catch-all)
- La validation landing_url ne bloque pas les URLs internes légitimes

## Gate
- **Gate requise** : NON (sauf si modification de la table permissions → gate migration)
- Si réduction de permissions casse des fonctionnalités existantes → STOP et escalade
