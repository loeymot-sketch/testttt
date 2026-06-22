# GATE_BRIEF P-MEGA-21 — Throttle K-6.3 + K-6.4

**Date** : 2026-04-20  
**Sub-cycle** : W8.B  
**Audit source** : `reports/execution/AUDIT_P_MEGA_21_THROTTLE_BASELINE_2026-04-20.md`  
**Type** : HARD GATE (auth/rate-limiting critical zone)  
**Moteur EXECUTE recommandé** : `foodking-complex-implementer` (GPT-5.4)  
**Effort estimé** : ~10-20 LOC prod + ~80-120 LOC tests + 1 spec NEW (`KioskThrottleKeysTest`)  
**Auto-remediation** : DÉSACTIVÉE par défaut

---

## Problème

Le throttling existe déjà mais comporte 2 écarts vs plan K-6.3/K-6.4 :

1. **K-6.3 — `kiosk-orders` clé IP-only** : `Limit::perMinute(...)->by($request->ip())` ne distingue pas le principal authentifié. Effet collatéral : bornes derrière même IP publique (NAT, hôtel, foire) se bloquent mutuellement.

2. **K-6.4 — `login-lockout` sans fallback explicite `'anon'`** : si `email` ET `username` vides, clé devient `|<ip>` → toutes requêtes anonymes sur une IP partagent un bucket sans signal forensic explicite.

Bonus :
- `KIOSK_ORDER_RATE_LIMIT` non documenté dans `.env.example` (trou doc ops)
- `RateLimiterConfigTest` lit `app.login_lockout_max_attempts` au lieu de `auth.login_lockout.max_attempts` (double SSOT silencieux)
- `TrustProxies::$proxies` non explicite (risque IP client incorrecte derrière LB)

---

## Solution proposée

### `kiosk-orders` (K-6.3)

```php
RateLimiter::for('kiosk-orders', function (Request $request) {
    $userKey = $request->user()?->id ?? 'guest';
    return Limit::perMinute((int) config('kiosk.order_rate_limit', 5))
        ->by(sprintf('kiosk:%s|%s', $userKey, $request->ip()))
        ->response(/* JSON 429 inchangé */);
});
```

### `login-lockout` (K-6.4)

```php
$identifier = Str::lower((string) ($request->input('email') ?: $request->input('username') ?: 'anon'));
$key = $identifier.'|'.$request->ip();
```

### Bonus
- Documenter `KIOSK_ORDER_RATE_LIMIT` dans `.env.example`
- Aligner `RateLimiterConfigTest` sur `config('auth.login_lockout.max_attempts')`

---

## Décisions business requises

### D1 — Format clé `kiosk-orders`

- **A.** `kiosk:{user_id|guest}|{ip}` — équilibre granularité + IP fallback ✅
- B. `kiosk:{user_id}` strict (rejette guest) — risque casser flux non authentifiés résiduels
- C. `kiosk:{user_id}` + bucket `kiosk-orders-anon` séparé pour guest

**Recommandation** : A. Préserve compat + sépare effectivement les bornes authentifiées même derrière même IP.

### D2 — Cap `KIOSK_ORDER_RATE_LIMIT`

- Valeur actuelle : 5/min (default)
- Question : est-ce suffisant en pic réel (3 bornes actives même branche, période midi) ?
- **Recommandation** : conserver 5/min default, exposer dans `.env.example` + documenter ops

### D3 — Migration progressive ou breaking

- **A.** Ship K-6.3 + K-6.4 en un seul commit (10 LOC, isolation testée) ✅
- B. Ship K-6.4 d'abord (lockout `anon`), K-6.3 dans cycle suivant

**Recommandation** : A. Volume LOC trivial, tests sentinelles couvrent les deux.

### D4 — `TrustProxies::$proxies`

- Hors scope strict de ce GATE (config infra, pas code applicatif)
- **Recommandation** : signaler dans report final pour revue ops séparée

---

## Sentinelles requises (`KioskThrottleKeysTest`)

| # | Cas | Couverture |
|---|-----|------------|
| 1 | Légitime (happy path) | N requêtes sans 429 ; N+1 = 429 |
| 2 | Épuisement + récupération | 429 puis `Carbon::setTestNow` ou `RateLimiter::clear()` → passe |
| 3 | **Isolation multi-machine (K-6.3)** | 2 utilisateurs Sanctum même IP → A épuisé ne 429 pas B |
| 4 | Détection IP (proxy) | `X-Forwarded-For` + `setTrustedProxies` → bucket suit IP client |
| 5 | Login lockout email + username + anon | `/api/auth/login` 429 ; `/api/auth/kiosk-login` 429 ; sous-cas `anon` après K-6.4 |

---

## Risques résiduels

- E2E Playwright sensibles au lockout → `phpunit.xml` override `LOGIN_LOCKOUT_MAX_ATTEMPTS=1000` préservé
- Si infra prod n'utilise pas `setTrustedProxies` correctement, K-6.3 perd son intérêt (toutes les requêtes vues comme une seule IP)
- Pas de monitoring des 429 → différé observabilité (W8 P-MEGA-22 ou cycle dédié)

---

## Décision attendue

- [ ] D1 : A (`kiosk:{user_id|guest}|{ip}`) ou B/C
- [ ] D2 : Confirmer `KIOSK_ORDER_RATE_LIMIT=5` ou ajuster
- [ ] D3 : A (un seul commit) ou B (séquencé)
- [ ] D4 : Acknowledger TrustProxies pour revue ops séparée
- [ ] Validation effort + moteur + auto-remediation OFF

**Statut** : PRÊT POUR DÉCISION HUMAINE (le plus simple des 3 GATE_BRIEFs W8 — risque très faible si validé)
