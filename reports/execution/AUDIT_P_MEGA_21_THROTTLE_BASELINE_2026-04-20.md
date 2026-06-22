# AUDIT P-MEGA-21 — Throttle & login-lockout (baseline K-6.3 / K-6.4)

- **Date** : 2026-04-20
- **HEAD référence** : `8070bc357`
- **Plan** : `plans/PLAN_P_MEGA_W8_2026-04-20.md` § W8.B.1
- **Mode** : READONLY
- **Subagent** : `explore` very thorough

---

## Résumé exécutif

Le baseline testttt montre que **le throttling kiosk-orders et le lockout login-lockout existent déjà** dans `RouteServiceProvider` et sont branchés sur les bonnes routes (`POST /api/frontend/order`, `POST /api/auth/login`, `POST /api/auth/kiosk-login`). La création de commande borne est protégée (cap piloté par `KIOSK_ORDER_RATE_LIMIT`), et le login admin + kiosk machine partage un limiter `email|ip` ou `username|ip` avec paramètres `auth.login_lockout.*` et `.env` `LOGIN_LOCKOUT_*`. Les écarts vs plan W8 / K-6.3 + K-6.4 sont **structurels** : clé `kiosk-orders` **uniquement par IP** (effet collatéral NAT, pas de distinction par machine authentifiée) et **pas de fallback explicite `'anon'`** sur le lockout. Pas de `routes/auth.php` ni login session dans `routes/web.php` (SPA + API). Tests dédiés : `RateLimitTest` + `RateLimiterConfigTest` ; **`KioskThrottleKeysTest` absent**. La variable `KIOSK_THROTTLE_PER_MINUTE` du brief utilisateur n'existe pas ; équivalent réel : `KIOSK_ORDER_RATE_LIMIT` (non documentée dans `.env.example`). `TrustProxies` expose les en-têtes X-Forwarded mais `$proxies` non explicite — risque IP client incorrecte derrière LB. **Gate** : HARD, moteur `foodking-complex-implementer`.

---

## 1. Périmètre cartographié

### 1.1 RouteServiceProvider — limiters existants

| Limiter | Cap | Clé | Config |
|---------|-----|-----|--------|
| `api` | `app.api_throttle_per_minute` (120 def) | `user_id ?: ip` | `API_THROTTLE_PER_MINUTE` |
| `kiosk-orders` | `kiosk.order_rate_limit` (5/min def) | **IP uniquement** | `KIOSK_ORDER_RATE_LIMIT` |
| `kiosk-menu` | 60/min | `user_id ?: ip` | hardcoded |
| `admin-mutation` | 30/min | `user_id ?: ip` | hardcoded |
| `pos-order-create` | 60/min | `user_id ?: ip` | hardcoded |
| `pos-order-update` | 120/min | `user_id ?: ip` | hardcoded |
| `login-lockout` | `auth.login_lockout.max_attempts` × `decay_minutes` | `email\|ip` ou `username\|ip` | `LOGIN_LOCKOUT_*` |

### 1.2 Routes login

- **`POST /api/auth/login`** → `LoginController@login` + `throttle:login-lockout` (email + password)
- **`POST /api/auth/kiosk-login`** → `KioskMachineLoginController@login` + `throttle:login-lockout` (username + password)
- `routes/auth.php` : **absent**
- `routes/web.php` : aucune route login (SPA + API uniquement)

### 1.3 Routes kiosk-orders

- **`POST /api/frontend/order`** : `auth:sanctum` + `throttle:kiosk-orders` (sur store uniquement)
- Autres : `POST /api/table/dining-order` utilise `throttle:20,1` (pas `kiosk-orders`)

### 1.4 Configurabilité

- `.env.example` documente : `LOGIN_LOCKOUT_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_DECAY_MINUTES`, `API_THROTTLE_PER_MINUTE`
- `KIOSK_ORDER_RATE_LIMIT` lue dans `config/kiosk.php`, **NON listée** dans `.env.example` (trou doc)
- `phpunit.xml` override : `LOGIN_LOCKOUT_MAX_ATTEMPTS=1000`, `LOGIN_LOCKOUT_DECAY_MINUTES=1` — préserve tests
- CI Playwright ajuste `LOGIN_LOCKOUT_MAX_ATTEMPTS` côté serveur Laravel ciblé

### 1.5 TrustProxies

```php
class TrustProxies extends Middleware {
    protected $proxies; // non renseigné
    protected $headers = Request::HEADER_X_FORWARDED_FOR | ... | Request::HEADER_X_FORWARDED_AWS_ELB;
}
```

`$proxies` vide → comportement runtime dépend de Laravel ; **à compléter `$proxies = '*'`** ou plages réseau en prod derrière LB.

### 1.6 Tests existants

| Fichier | Couverture |
|---------|------------|
| `tests/Feature/Security/RateLimitTest.php` | `admin-mutation` 429, `login-lockout` 429 (11e), override config, contrôle `.env.example` |
| `tests/Unit/Security/RateLimiterConfigTest.php` | Caps des limiters ; **assertion sur `app.login_lockout_max_attempts`** (pas SSOT auth) |
| `tests/Feature/Routes/MenuControllerRateLimitTest.php` | `kiosk-menu` (pas `kiosk-orders`) |
| **Absent** | `KioskThrottleKeysTest` |

---

## 2. État actuel

| Zone | État | Détail |
|------|------|--------|
| `POST /api/frontend/order` throttle | **Présent** | `kiosk-orders` + héritage `api` |
| Login staff | **Présent** | `login-lockout` |
| Login kiosk machine | **Présent** | `login-lockout` |
| Clé `kiosk-orders` par machine | **Absent** | IP seule |
| Fallback `anon` lockout | **Absent** | chaîne vide + `\|ip` |
| Tests K-6.3/K-6.4 dédiés | **Absent** | Pas de `KioskThrottleKeysTest` |

---

## 3. Vulnérabilités

- **V1 (MED)** Partage bucket `kiosk-orders` par IP → bornes derrière même IP publique se bloquent mutuellement
- **V2 (MED)** `kiosk-orders` ignore le principal authentifié (alignement K-6.3 manquant)
- **V3 (LOW)** Cohérence config `login-lockout` double SSOT (`auth.login_lockout.*` vs `app.login_lockout_max_attempts` dans test)
- **V4 (LOW)** Lockout sans `?: 'anon'` (K-6.4) — clé `|ip` si email+username vides
- **V5 (LOW)** Bypass routes parallèles : non confirmé sur le scope audité
- **V6 (MED INFRA)** IP client derrière reverse proxy mal calibrée → throttles erronés
- **DoS kiosk-orders** : NON confirmé — throttle existe ; risque résiduel = dimensionnement IP vs machine

---

## 4. Recommandations implémentation

### `kiosk-orders`
- Conserver `config('kiosk.order_rate_limit')` ; documenter `KIOSK_ORDER_RATE_LIMIT` dans `.env.example`
- Clé K-6.3 : `by(sprintf('kiosk:%s|%s', $request->user()?->id ?? 'guest', $request->ip()))`
- Garder `response()` JSON 429 actuel

### `login-lockout`
- K-6.4 : `Str::lower($request->input('email') ?: $request->input('username') ?: 'anon')` + `|ip`
- Conserver `config('auth.login_lockout.*')` et `retry_after`

### TrustProxies
- Définir `$proxies` selon hébergement (revue prod hors scope code mais à signaler ops)

### Tests
- Aligner `RateLimiterConfigTest` sur `config('auth.login_lockout.max_attempts')` (SSOT unique)

---

## 5. Plan tests sentinelles `KioskThrottleKeysTest` (5 cas)

Fichier : `tests/Feature/Auth/KioskThrottleKeysTest.php`

1. **Légitime** : N requêtes valides sans 429 ; N+1 = 429 (cap `KIOSK_ORDER_RATE_LIMIT`)
2. **Épuisement + récupération** : 429 puis `Carbon::setTestNow` ou `RateLimiter::clear()` → nouvelle requête passe après fenêtre
3. **Isolation multi-machine (K-6.3)** : 2 utilisateurs Sanctum même IP → épuisement A ne 429 pas B (sentinelle régression baseline)
4. **Détection IP (proxy)** : `X-Forwarded-For` + `setTrustedProxies` → bucket suit IP client (skip si infra test ne simule pas proxies)
5. **Login lockout email + username** : `/api/auth/login` 429 après max_attempts ; `/api/auth/kiosk-login` 429 ; cas `anon` (sous-cas 6) optionnel après K-6.4

---

## 6. Estimation + moteur

- Prod : ~10-20 LOC dans `RouteServiceProvider` (+2-5 lignes `TrustProxies`/`.env.example`)
- Tests : ~80-120 LOC `KioskThrottleKeysTest` + ajustement `RateLimiterConfigTest`
- **Moteur** : `foodking-complex-implementer` (auth/rate limiting)
- Risque : E2E Playwright sensibles au lockout — `phpunit.xml` et workflow conservés

---

## 7. Gate humain

- **HARD GATE** : modification limiters `login-lockout` et `kiosk-orders` (surface brute-force + dispo borne + collatéral NAT)
- Decision Required : K-6.3 (`kiosk:{user_id}|{ip}`) + K-6.4 (`anon` explicite) sans régression `kiosk.order_rate_limit` ni `auth.login_lockout.*`

---

## 8. Configurabilité testttt préservée

- Lockout : `LOGIN_LOCKOUT_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_DECAY_MINUTES` → OK
- Kiosk orders : `KIOSK_ORDER_RATE_LIMIT` → OK code, à documenter `.env.example`
- Brief utilisateur (`KIOSK_THROTTLE_PER_MINUTE`) : non présent ; équivalent = `KIOSK_ORDER_RATE_LIMIT`

---

## 9. DoD

- [x] Closures RouteServiceProvider documentées
- [x] Routes login + kiosk-orders énumérées
- [x] 5 cas tests sentinelles décrits
- [x] Configurabilité `.env` validée
- [x] Markdown ~200 lignes
