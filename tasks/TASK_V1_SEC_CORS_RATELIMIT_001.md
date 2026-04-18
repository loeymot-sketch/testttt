# TASK_V1_SEC_CORS_RATELIMIT_001 — CORS whitelist + rate limit sweep

## Meta
- **Priority** : P1 (sécurité base)
- **Vague** : 3 — Sécurité base
- **PRIMARY_MODEL** : Composer
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : (indépendant)
- **BLOCKS** : —
- **Estimation** : 2 j-h

## Contexte

**CORS ouvert** : `config/cors.php` a `allowed_origins = ['*']`. N'importe quel site peut déclencher des requêtes authentifiées par cookie (CSRF voisin).

**Rate limit partiel** : seul le login et `kiosk-orders` ont un throttle. Les endpoints admin CRUD mutables (`POST /admin/products`, `DELETE /admin/branches/{id}`, etc.) sont **non limités**. Brute force / scraping / accidental burst possible.

V1 : rien d'exotique. Juste whitelist CORS et throttle cohérent partout.

## Acceptance Criteria
- [ ] `config/cors.php` : `allowed_origins = [env('APP_URL'), env('KIOSK_DOMAIN', null), env('ADMIN_DOMAIN', null)]` filtré non-null, **jamais** `*`.
- [ ] Tous les endpoints POST/PUT/PATCH/DELETE dans `routes/api.php` + `routes/web.php` ont un middleware `throttle` appliqué.
- [ ] Matrice rate limit par type de route :
  - login : `5/min` par IP (existant).
  - OTP : `3/5min` par IP (existant).
  - kiosk-orders : `10/min` par machine_id (existant).
  - Admin CRUD : `30/min` par user.
  - POS order creation : `60/min` par user.
  - POS order update : `120/min` par user.
- [ ] Lockout login : après **10 échecs en 10 min** depuis une même IP → blocage 15 min avec event loggué.
- [ ] `docs/RATE_LIMITS_MATRIX.md` livré — tableau route × limite × justification.
- [ ] Test intégration : preflight OPTIONS depuis origine non whitelistée → 403.
- [ ] Test intégration : 31 requêtes admin en 1 min → 31e reçoit 429.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `config/cors.php` | whitelist | Write | No | No |
| `app/Http/Kernel.php` | middleware aliases throttle | Write | No | No |
| `app/Providers/RouteServiceProvider.php` | RateLimiter::for(...) pour chaque catégorie | Write | No | No |
| `routes/api.php` | ajout middleware throttle sur mutable routes | Write | No | No |
| `routes/web.php` | idem | Write | No | No |
| `app/Http/Middleware/LoginLockoutMiddleware.php` | nouveau si besoin | Write | No | No |
| `docs/RATE_LIMITS_MATRIX.md` | doc | Write | No | No |
| `tests/Feature/Security/CorsTest.php` | tests | Write | No | No |
| `tests/Feature/Security/RateLimitTest.php` | tests | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Auth logic (pas de 2FA).
- RGPD.
- Business logic.
- Frozen zones.

## Invariants at Risk
- [x] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [ ] branch_id data isolation
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — CORS whitelist
```php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([
        env('APP_URL'),
        env('KIOSK_DOMAIN'),
        env('ADMIN_DOMAIN'),
    ]),
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];
```
Assertion CI : test unitaire qui lit le config et échoue si `'*'` présent.

### E2 — RateLimiter definitions
```php
// RouteServiceProvider::boot()
RateLimiter::for('admin-mutation', function($req) {
    return Limit::perMinute(30)->by($req->user()?->id ?: $req->ip());
});
RateLimiter::for('pos-order-create', function($req) {
    return Limit::perMinute(60)->by($req->user()?->id);
});
RateLimiter::for('pos-order-update', function($req) {
    return Limit::perMinute(120)->by($req->user()?->id);
});
RateLimiter::for('login', function($req) {
    return Limit::perMinute(5)->by($req->input('email').$req->ip());
});
// ...
```

### E3 — Apply to routes
Walk `routes/api.php` et `routes/web.php`. Grouper par préfixe, appliquer :
```php
Route::middleware(['auth', 'throttle:admin-mutation'])->prefix('admin')->group(function() { ... });
Route::middleware(['auth', 'throttle:pos-order-create'])->post('/pos/orders', ...);
```

### E4 — Lockout login
Option 1 (simple) : utiliser `Illuminate\Foundation\Auth\ThrottlesLogins` natif Laravel avec `maxAttempts=10, decayMinutes=10, lockoutMinutes=15`.
Option 2 (explicite) : middleware custom `LoginLockoutMiddleware` qui utilise Cache pour compter.

Préférer Option 1.

### E5 — Tests
1. `CorsTest` : request OPTIONS avec `Origin: https://evil.com` → 403. Avec `Origin` whitelist → 204.
2. `RateLimitTest` : boucle 31 requêtes admin → 31e → 429. Vérifier header `Retry-After`.
3. `LoginLockoutTest` : 11 tentatives login en 10 min → 429 ou 403 lockout.

### E6 — Documentation
`docs/RATE_LIMITS_MATRIX.md` :
| Route | Limite | Clé | Justification |
|---|---|---|---|
| `POST /login` | 5/min | email+ip | anti-brute-force |
| `POST /api/admin/*` | 30/min | user_id | admin mutation frequency |
| `POST /api/pos/orders` | 60/min | user_id | ~1 commande/sec, marge |
| ... | ... | ... | ... |

## SYMMETRY_NOTE
N/A.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : demande d'introduire un WAF externe (Cloudflare, etc.) — hors V1, archi infra.
- Stop-gate si : demande de 2FA — V1.5.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
