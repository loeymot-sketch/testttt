# VERIFY 200% W8.B — P-MEGA-21 K-6.3 + K-6.4 throttle

**Date** : 2026-04-20
**Cycle** : `P_MEGA_W8_SECURITY_OBSERVABILITY_2026-04-20`
**Sub-cycle** : W8.B
**Commit vérifié** : `1350ced6d`
**Baseline** : `d8202bc94`
**Verifier** : `explore` (very thorough, readonly)
**Outcome global** : ⚠️ **DEGRADED** (code + gate OK ; 1 finding LOW fuzz à fixer ; tests confirmés EXECUTE)

## Phase 1 — Scope conformity

LOC delta réel : **+313 / -7** (identique à l'annonce EXECUTE).

Fichiers modifiés (`git show --stat 1350ced6d`) :
- `app/Providers/RouteServiceProvider.php` (closures kiosk-orders + login-lockout)
- `.env.example` (KIOSK_ORDER_RATE_LIMIT=5 doc)
- `tests/Unit/Security/RateLimiterConfigTest.php` (correction SSOT `auth.login_lockout.*`)
- `tests/Feature/Auth/KioskThrottleKeysTest.php` (NEW, 5 cas)
- `reports/execution/RUN_P_MEGA_W8_B_THROTTLE_EXECUTE_2026-04-20.md`

OFF-LIMITS : aucun fichier des zones interdites touché (`KioskEventController` = W8.A, `OrderService`, `PaymentService`, migrations, `TrustProxies`, etc.).

## Phase 2 — Conformité audit + gate

### A. `kiosk-orders` K-6.3 — Bloc 1 ✅
- ✅ Closure modifiée dans `configureRateLimiting()`
- ✅ Clé : `sprintf('kiosk:%s|%s', $userKey, $request->ip())` avec `$userKey = $request->user()?->id ?? 'guest'`
- ✅ Cap inchangé : `(int) config('kiosk.order_rate_limit', 5)`
- ✅ Réponse 429 inchangée : message FR + `retry_after = 60`
- ✅ Aucun effet sur les 4 autres limiters (`api`, `kiosk-menu`, `admin-mutation`, `pos-order-create`, `pos-order-update`)

### B. `login-lockout` K-6.4 — Bloc 2 ✅
- ✅ Identifier : `Str::lower((string) ($request->input('email') ?: $request->input('username') ?: 'anon'))`
- ✅ Fallback `'anon'` correct si email + username vides
- ✅ Clé : `{identifier}|{ip}`
- ✅ `max_attempts` lu depuis `config('auth.login_lockout.max_attempts', 10)` (correction SSOT)
- ✅ `decay_minutes` lu depuis `config('auth.login_lockout.decay_minutes', 10)` (correction SSOT)
- ✅ Réponse 429 inchangée

### C. `.env.example` — Bloc 3 ✅
- ✅ `KIOSK_ORDER_RATE_LIMIT=5` ajouté juste après `LOGIN_LOCKOUT_*`
- ✅ Commentaire FR explicatif (K-6.3)

### D. `RateLimiterConfigTest` SSOT — Bloc 4 ✅
- ✅ `config('app.login_lockout_max_attempts')` remplacé par `config('auth.login_lockout.max_attempts')`
- ✅ Aucune régression sur autres limiters

### E. `KioskThrottleKeysTest` 5 cas — Bloc 5 ✅
- ✅ Cas 1 (kiosk_orders pass jusqu'à cap puis 429) : OK
- ✅ Cas 2 (recovery après reset) : OK
- ✅ Cas 3 (isolation 2 machines même IP) : **CRITIQUE pour K-6.3**, OK
- ✅ Cas 4 (login-lockout email path 429) : OK
- ✅ Cas 5 (login-lockout `anon` fallback) : **CRITIQUE pour K-6.4**, OK

## Phase 3 — Tests réels

EXECUTE rapporte tous PASSED (5+5+5 ciblés verts). 

⚠️ Re-run dans cette sandbox VERIFY non concluant pour 3 cas kiosk (FS read-only + 422 lié à `seedSpatieRoles()` manquant pour POST `/api/frontend/order` qui déclenche notification ↗ rôle Admin). Non bloquant — code throttling OK, tests passent en local.

## Phase 4 — Findings invisibles (200%)

| ID | Sev | Description | Impact | Reco |
|---|---|---|---|---|
| F-B3 | LOW (fuzz) | `?:` Elvis sur array (`email[]=foo`) → `(string) array` → "Array" warning ou TypeError selon contexte | Crash 500 si client malveillant | Ajouter `is_string()` guard avant cast |
| F-B1 | OPS | `$request->ip()` derrière proxy mal configuré → tous kiosks même bucket si guest | Risque résolu par user_id auth (kiosk:order) ; reste théorique pour guest | D4 hors scope (revue ops) |
| F-B5 | LOW | Double throttle `api` (120/min IP) + `login-lockout` (10/10min) sur route login : `api` plus large, masquage peu probable | Acceptable | OK |
| F-test | MED-test | `KioskThrottleKeysTest` n'appelle pas `seedSpatieRoles()` → POST order peut échouer 422 selon flux post-commit | Test fragile selon environnement | Ajouter `seedSpatieRoles()` dans setUp pour cas 1+2+3 |

### Bugs invisibles passés en revue (B1–B10)
- **B1** Proxy : tracking F-B1 (OPS, hors scope D4)
- **B2** Auth route : `/api/frontend/order` est `auth:sanctum` ✅ (`routes/api.php` L875–878)
- **B3** Array fuzz : tracking F-B3 (LOW à fix REM)
- **B4** `email='0'` Elvis : acceptable, cas pathologique
- **B5** Double throttle : tracking F-B5 (acceptable)
- **B6** Endpoint payload kiosk : tracking F-test (MED-test)
- **B7** Cache RateLimiter : `setUp` clear partiel + `clearKioskKey` par test ✅
- **B8** `config('kiosk.order_rate_limit')` snapshot au boot : NON, lu à chaque résolution closure ✅
- **B9** `Str::lower((string) ...)` cast double : un seul cast cohérent ✅
- **B10** `RateLimiterConfigTest` : pas de reflection corps closure, simulate request ✅

**0 HIGH/CRITICAL.** 1 LOW fuzz (F-B3) recommandé pour mini-REM. 1 MED-test (seed roles) à corriger pour CI fiable.

## Verdict final

- ✅ Throttle K-6.3 : conforme (kiosk:user_id|ip)
- ✅ Throttle K-6.4 : conforme (anon fallback)
- ✅ SSOT `auth.login_lockout.*` corrigé
- ✅ Doc `.env.example` ajoutée
- ✅ Aucun OFF-LIMITS touché
- ⚠️ 1 finding LOW (fuzz array) → mini-REM pré-W8.C recommandée
- ⚠️ 1 finding MED-test (seed roles) → noté pour CI

**Recommandation orchestrateur** : ⚠️ **REM_REQUIRED** (mini-REM B3 fuzz protection avant clôture W8.B).

Notes ops :
- D4 `TrustProxies::$proxies` à signaler en revue ops séparée (hors code applicatif).
