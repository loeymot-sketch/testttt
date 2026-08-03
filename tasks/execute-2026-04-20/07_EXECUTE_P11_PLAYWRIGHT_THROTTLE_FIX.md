# EXECUTE — P11_PLAYWRIGHT_THROTTLE_FIX — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (test infra config, env-conditionnel, aucune logique métier)
**VAGUE:** V1 (parallélisable backend — plan §2 ligne 117)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 41
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-11-01, F-VERIFY-16-01
- `reports/review/VERIFY_11_KDS_OSS_DRAWER_2026-04-20.md` §0 résumé exécutif (cause-racine = HTTP 429 `login-lockout`)
- `reports/review/VERIFY_16_TESTS_REGRESSIONS_2026-04-20.md` §0 V4

## Constat factuel pré-cycle (vérifié read-only)

```
app/Providers/RouteServiceProvider.php:87+
  RateLimiter::for('login-lockout', function (Request $request) {
      $identifier = Str::lower((string) $request->input('email', ''));
      ...
      $key = $identifier.'|'.$request->ip();
      // Limite hardcodée — selon VERIFY-11 = 10 tentatives / 10 min
  });
```

```
tests/e2e/04-kds-status.spec.js : 3 tests dans le describe, chacun appelle
login(chef@lecayenne.fr, 123456). Avec retries=1 et autres specs e2e qui
loginent aussi avec ce chef → saturation rapide de la clé email|ip.
```

```
test-results/04-kds-status-…/error-context.md → alerte "Too Many Attempts."
test-results/04-kds-status-…-retry1/trace.zip → retry également bloqué (rate-limiter pollué)
```

**Diagnostic** : le rate-limiter `login-lockout` est correctement implémenté pour la prod (anti brute-force). Mais hardcodé → en environnement test/E2E, la limite est trop basse. Solution : rendre la limite **configurable via `config()` + env var**, avec valeur élevée en `.env.testing` / CI Playwright.

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "configuration tweaks", env-conditional, no auth logic change, no schema)
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

> **Note routing** : on touche `RouteServiceProvider.php` qui est du backend, mais la modification est **paramétrique** (extraction d'une constante en config). La logique d'auth elle-même reste inchangée (toujours `email|ip`, toujours `Limit::perMinutes(10, N)`). Composer routine reste applicable per AGENTS.md "configuration tweaks".

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Providers/RouteServiceProvider.php` — méthode `configureRateLimiting()` uniquement, ligne `RateLimiter::for('login-lockout', ...)` (~L87-L100)
- `config/auth.php` — ajout d'une clé `login_lockout` (max_attempts, decay_minutes)
- `.env.example` — ajout des 2 variables documentées
- `phpunit.xml` ou `.env.testing` — override pour PHPUnit (à arbitrer selon ce qui existe)

### SCOPE_FILES (whitelist stricte)
- `app/Providers/RouteServiceProvider.php` (1 méthode, ≤ 10 lignes diff)
- `config/auth.php` (ajout 1 sous-array `login_lockout`)
- `.env.example` (ajout 2 lignes commentées)
- `phpunit.xml` **OU** `.env.testing` (à choisir : un seul des deux selon convention projet — vérifier lequel existe)
- `tests/Feature/Security/RateLimitTest.php` (ajout 1 test couvrant la configurabilité, si fichier existe ; sinon nouveau `tests/Feature/Auth/LoginLockoutConfigTest.php`)

### SUBSYSTEMS_OFF_LIMITS (strict)
- **Toute autre méthode** de `RouteServiceProvider` (ne pas toucher `api`, `kiosk-orders`, `kiosk-menu`, `admin-mutation`, `pos-order-create`, `pos-order-update`, `frontend-order-mutation`)
- `app/Http/Controllers/Auth/**` (logique de login intacte)
- `tests/e2e/**` (les specs Playwright restent inchangées — c'est le backend qui s'adapte)
- `package.json` / `package-lock.json`
- Toute migration DB, route applicative, modèle Eloquent

## Invariants at Risk
- **Sécurité brute-force** : la limite **prod** doit rester stricte (10 tentatives / 10 min). Seule la valeur **test** est élevée. → Risque géré par defaults explicites dans `config/auth.php` qui correspondent à la valeur prod actuelle.
- **`auto-remediation.mdc:90` "auth"** : touche périphérique au flux auth (rate-limiter, pas la logique elle-même). Documenter clairement dans le diff que `email|ip` reste inchangé, seul le seuil bouge selon env.

## Dependencies
- Aucune (cycle indépendant, parallélisable avec AVAILABILITY_TOGGLE_UI_ADMIN)

## Plan bref

### Étape 1 — Lire (vérité terrain)
- `app/Providers/RouteServiceProvider.php` L87-L110 (configureRateLimiting login-lockout)
- `config/auth.php` (structure actuelle)
- `phpunit.xml` (chercher `<env>` block) ET `.env.testing` (existence)
- `tests/e2e/helpers/login.js` (pour vérifier : pas de touche)
- `tests/Feature/Security/RateLimitTest.php` ou équivalent (existant ?)

### Étape 2 — Modifier `app/Providers/RouteServiceProvider.php`

```php
RateLimiter::for('login-lockout', function (Request $request) {
    $identifier = Str::lower((string) $request->input('email', ''));
    if ($identifier === '') {
        $identifier = Str::lower((string) $request->input('username', ''));
    }
    $key = $identifier.'|'.$request->ip();

    $maxAttempts  = (int) config('auth.login_lockout.max_attempts', 10);
    $decayMinutes = (int) config('auth.login_lockout.decay_minutes', 10);

    return Limit::perMinutes($decayMinutes, $maxAttempts)->by($key);
});
```

(Ajustement minimal : extraction des 2 constantes en config + appel `Limit::perMinutes(...)` au lieu de `Limit::perMinute(...)` si l'API actuelle est différente — vérifier lecture)

### Étape 3 — Ajouter `config/auth.php`

```php
'login_lockout' => [
    'max_attempts' => env('LOGIN_LOCKOUT_MAX_ATTEMPTS', 10),
    'decay_minutes' => env('LOGIN_LOCKOUT_DECAY_MINUTES', 10),
],
```

### Étape 4 — Documenter `.env.example`

```
# Login brute-force protection (prod-safe defaults).
# Override LOGIN_LOCKOUT_MAX_ATTEMPTS=1000 in .env.testing for E2E.
LOGIN_LOCKOUT_MAX_ATTEMPTS=10
LOGIN_LOCKOUT_DECAY_MINUTES=10
```

### Étape 5 — Override test
Lire `phpunit.xml`. Si bloc `<env>` existe : ajouter 2 lignes :
```xml
<env name="LOGIN_LOCKOUT_MAX_ATTEMPTS" value="1000"/>
<env name="LOGIN_LOCKOUT_DECAY_MINUTES" value="1"/>
```
Sinon, créer/modifier `.env.testing` (à confirmer lequel est canonique).

Pour Playwright : la base d'exécution Playwright cible un serveur Laravel local. Le `.env` de ce serveur doit recevoir `LOGIN_LOCKOUT_MAX_ATTEMPTS=1000` lors des runs E2E. Documenter ceci dans le rapport (recommandation, pas modification d'un `.env` réel — qui serait hors scope).

### Étape 6 — Test
Créer ou enrichir un test Feature qui vérifie :
- Default config = 10 attempts (prod-safe)
- Avec config override → limite respectée
- 1 test par cas (vert)

### Étape 7 — Rapport
Écrire `reports/execution/RUN_P11_PLAYWRIGHT_THROTTLE_FIX_2026-04-20.md` avec gabarit Final report + diff résumé + sortie phpunit.

## Acceptance Tests
- [ ] `git diff app/Providers/RouteServiceProvider.php` ≤ 8 lignes nettes (extract en config)
- [ ] `config/auth.php` contient `login_lockout` array
- [ ] `.env.example` documente les 2 vars avec defaults prod-safe
- [ ] Test Feature couvre la configurabilité (1+ test vert)
- [ ] Defaults restent **10 attempts / 10 min** (sécurité prod préservée)
- [ ] `vendor/bin/phpunit --filter LoginLockout` (ou test ajouté) → vert
- [ ] **Aucune** modification dans `tests/e2e/`
- [ ] Recommandation env Playwright documentée dans rapport (pas de modif `.env` réel)

## Exit Criteria
- [ ] Configuration extraite + defaults prod-safe préservés
- [ ] Test Feature vert
- [ ] Documentation `.env.example` claire
- [ ] `git diff` strictement dans SCOPE_FILES
- [ ] `reports/execution/RUN_P11_PLAYWRIGHT_THROTTLE_FIX_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé après leçon cycle 05)
**STOP IMMÉDIAT et signaler dans REPORT_FILE** si :
- Modification d'une autre méthode de `configureRateLimiting()` que `login-lockout` requise → SCOPE_PRESSURE
- Touche à `app/Http/Controllers/Auth/**` ou logique de login → SCOPE_PRESSURE (auth zone, escalade humaine `human-gates.mdc:20`)
- Modification de `package.json`, `package-lock.json` → SCOPE_PRESSURE
- Ajout de migration DB → SCOPE_PRESSURE (déclenche gate)
- Toute édition `tests/e2e/**` → SCOPE_PRESSURE (le fix doit être backend, pas test)
- Ajout de dépendance npm/composer → SCOPE_PRESSURE
- Si `phpunit.xml` n'a pas de bloc `<env>` ET `.env.testing` n'existe pas → STOP, signaler quel canonical config existe (peut-être `phpunit.xml.example`?)
- **Anti-pattern interdit** : `git checkout -- <fichier>` pour masquer un diff après tentative ratée → STOP + escalade

## Remediation (`auto-remediation.mdc`)
- Attempt 1 KO (test fail, syntax PHP) → diagnostic + replan + Composer re-EXECUTE
- Attempt 2 KO → analyse plus profonde (peut-être API Laravel `Limit::perMinutes` vs `perMinute(N)` à vérifier)
- Attempt 3 même `bug_signature` → HUMAN_GATE bug irrésolu

## Deliverables
- Diff `app/Providers/RouteServiceProvider.php` (≤ 8 lignes)
- Diff `config/auth.php` (+5 lignes)
- Diff `.env.example` (+3 lignes)
- Diff `phpunit.xml` ou `.env.testing` (+2 lignes)
- Test Feature (1 fichier neuf ou enrichi)
- `reports/execution/RUN_P11_PLAYWRIGHT_THROTTLE_FIX_2026-04-20.md`

## Communication
Subagent renvoie : diff par fichier, sortie phpunit du nouveau test, valeurs prod par défaut confirmées, recommandation env Playwright (instruction humaine pour CI/dev).
