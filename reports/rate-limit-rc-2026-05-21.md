# Rate-Limit Root-Cause Report — Owner Pain "Too Many Attempts" / "Trop de requêtes — 30s"

Date : 2026-05-21
Branch : `heal/cms-pr1-quickwins-2026-05-18` (HEAD `adad9161f`)
Author : Claude Code (read-only investigation, no fix applied)
Status : Owner-blocking — recurring on every test pass on admin actions
(Livré / Encaisser / Cancel / status changes on online + table orders).

---

## 1. Root Cause (one-paragraph, with file:line + code)

**The bottleneck is the `admin-mutation` rate-limiter at
`app/Providers/RouteServiceProvider.php:115` — hardcoded at `30
req/min` for any non-GET HTTP verb, keyed per `user_id`.** This bucket
is applied as a middleware **on the entire admin API namespace**
(`routes/api.php:270` — `Route::prefix('admin')->...
->middleware([..., 'throttle:admin-mutation'])`). Two POS-write paths
were lifted to 120/min (POS endpoints, KDS bump) but **every other
admin write endpoint** still falls under the 30/min ceiling — INCLUDING
the legitimate owner-tested actions :

- `POST /api/admin/online-order/change-status/{order}` (the "Livré"
  CTA on the Online Orders admin page) — `routes/api.php:938-940`,
  middleware = only `idempotency`, throttle inherited from parent
  group = `admin-mutation` 30/min.
- `POST /api/admin/table-order/change-status/{order}` — same pattern,
  `routes/api.php:951-953`.
- Every admin POST on customer / waiter / setting / category /
  printers / payment-terminals / etc.

```php
// app/Providers/RouteServiceProvider.php — configureRateLimiting()
RateLimiter::for('admin-mutation', function (Request $request) {
    if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
        return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
    }

    if ($request->is('api/admin/pos/*') || $request->is('api/admin/pos')) {
        return Limit::perMinute(120)->by(...);   // POS lifted
    }
    if ($request->is('api/admin/kds-order/change-status/*')) {
        return Limit::perMinute(120)->by(...);   // KDS bump lifted
    }

    return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
    //              ^^^^^^^^^^^^^^ — THE 30/min CAP, line 115
});
```

The owner's `.env` already lifts `POS_RATE_LIMIT_*` and
`KDS_RATE_LIMIT_BUMP` to `1000` — but those env knobs do **not**
control `admin-mutation`. The 30/min number is HARD-CODED.

Sentinel test `tests/Unit/Security/RateLimiterConfigTest.php:32`
asserts the baseline : `'admin-mutation' => [30, true]`. So the
30/min ceiling is the LOCKED contract.

---

## 2. Why first request works, second/third fail (exact mechanism)

Laravel's `Illuminate\Cache\RateLimiting\Limit::perMinute($n)`
implements a **fixed-window** counter, not a token bucket. The user's
counter is keyed by `md5('admin-mutation' . $user_id)`. When a
non-GET request reaches a route inside the `admin` group, the
middleware increments the counter ; the 31st request within the
current 60s window returns 429.

But here the owner experiences "first works, 2nd/3rd fail" — not 30
clicks. Why ? Because the bucket is SHARED ACROSS the entire admin
write surface AND across the same user's prior session work :

1. The owner logs in as admin, navigates through pages — admin POSTs
   (toggle availability, save settings, push notification preview,
   ...) silently increment the counter. He may have 25-28 already
   "spent" in the current minute without realizing.
2. Owner clicks "Encaisser" (counter-collect/confirm = lifted POS
   bucket 1000/min, OK).
3. Owner clicks "Livré" on the online-order list →
   `online-order/change-status` → **admin-mutation bucket** → 31st →
   429.
4. Owner restarts → after ≤60s the window resets → first click works
   → but the next clicks blow through whatever budget remained.

The dual toast :
- "Too Many Attempts." = Laravel's default Symfony 429 body (no
  custom `->response()` on `admin-mutation` limiter).
- "Trop de requêtes — patientez 30s avant de réessayer." =
  axios-error-interceptor in `resources/js/bootstrap.js:176-181`,
  which fires a localized i18n toast on ANY status === 429 (key
  `error.rate_limited` in `resources/js/languages/fr.json:1285`).
  **The "30s" copy is a hardcoded i18n string ; the actual
  `Retry-After` header from Laravel is 60s — the user is misled.**

---

## 3. All Affected Endpoints (sorted by tightest ceiling first)

| Throttle bucket | Ceiling | Affected endpoints (owner-facing) |
|---|---|---|
| `throttle:3,60` | 3 req / 60 min | password reset (legit, tight) |
| `throttle:3,5` | 3 req / 5 min | login OTP send / verify (legit) |
| `throttle:5,1` | 5/min | various `auth:sanctum` payment endpoints, frontend subscriber, loyalty register, table-order anonymous create, settings *POST* under `/api/payment` (auth) |
| `throttle:10,1` | 10/min | Z-report open/close (legit fiscal), outbox retry-failed, frontend coupon-checking, frontend loyalty check |
| `throttle:20,1` | 20/min | frontend kiosk-token bump |
| `throttle:30,1` | 30/min | various kiosk hard-coded routes, kiosk:order events |
| **`admin-mutation` (non-GET)** | **30/min** | **EVERY admin write endpoint not explicitly lifted** — online-order/change-status, table-order/change-status, customer write, waiter write, setting CRUD, role/permission CRUD, push-notification, administrator CRUD, dashboard write, observability sync (mutating), payment-terminals CRUD, message CRUD, ... |
| `kiosk-orders` | env `KIOSK_ORDER_RATE_LIMIT` (env=60, fallback=5) | kiosk wizard POST/quote |
| `oss-public` | 60/min/IP | OSS popular-items public endpoint |
| `admin-mutation` (GET) | 300/min | admin GETs |
| `kiosk-login` | 30/min env | borne login |
| `pos-quote` | env `POS_RATE_LIMIT_QUOTE` (env=1000, fallback=120) | POS quote preview |
| `pos-order-create` | env (1000/120) | POS order POST |
| `pos-order-update` | env (1000/120) | POS change-status, counter-collect confirm/cancel, collect-kiosk-cash, refund-counter-entry, redeem-loyalty |
| `kds-bump` | env `KDS_RATE_LIMIT_BUMP` (1000/120) | KDS change-status |
| `api` (default) | env `API_THROTTLE_PER_MINUTE` (120) | non-admin auth routes |
| `login-lockout` | 10 per 10 min | login |

**The owner-blocking endpoints are the ones under `admin-mutation`
non-GET (30/min), specifically the `*-order/change-status` family
that is NOT POS** (POS already lifted).

---

## 4. The Custom "30s" Toast Emitter (located + explained)

- **File** : `resources/js/bootstrap.js`
- **Lines** : `176-181` (axios response interceptor 429 branch)
- **Compiled output** : `public/js/pos-app.js:70047`, `public/js/pos-shell.js:3613`
- **I18n key** : `error.rate_limited` (FR `resources/js/languages/fr.json:1285`,
  EN `en.json:1373`, AR `ar.json:1222`)
- **String** : `"Trop de requêtes — patientez 30s avant de réessayer."`

```js
} else if (status === 429) {
    bucket = 'rl';
    message = _i18nMessage(
        'error.rate_limited',
        'Trop de requêtes — patientez 30s avant de réessayer.'
    );
}
```

**Critical insight** : the "30s" is NOT a real `Retry-After` value
extracted from the response — it is a hardcoded copy. Laravel's
`Limit::perMinute(30)` returns `Retry-After: <seconds-until-window-reset>`
which is up to 60s. The toast tells the owner to wait 30s but the
bucket may not have refilled until 60s — explaining why owner's
"restart after 30s" sometimes still fails.

---

## 5. Polling Interval Audit (H5 — partial cause check)

`resources/js/components/admin/pos/PosComponent.vue:2512-2528` :

```js
_kioskPollingInterval() {
    return window._wsService?.isConnected() ? 60000 : 5000;
},
_startKioskPolling() {
    this._kioskPollTimer = setInterval(() => {
        if (this._destroyed) return;
        this.loadKioskCashOrders();      // GET admin/pos/counter-collect/pending
        this.loadActiveOrdersStats();    // GET admin/order-status-screen/lists
        this.loadReadyOrders();          // GET orderStatusScreenOrder/lists
    }, this._kioskPollingInterval());
}
```

- **WS connected** : 60s tick × 3 GETs = 3 GETs/min — negligible.
- **WS disconnected (fallback)** : 5s tick × 3 GETs = 36 GETs/min —
  fits within `admin-mutation` GET ceiling (300/min) and within the
  `pos-order-update` ceiling (1000/min). NOT the bottleneck.

Polling is fine. H5 is NOT the cause.

---

## 6. Recommended Fixes (3 ranked — surgical-minimality 1 → 5)

### FIX A (surgical-minimality 1 — RECOMMENDED) — env-knob the `admin-mutation` ceiling

Same pattern already established for POS, KDS, kiosk-orders. Add
`ADMIN_MUTATION_RATE_LIMIT` env knob with sane default (e.g. 60/min
prod, 1000/min local dev).

**Change** (RouteServiceProvider.php:77-117) :
```php
$defaultLimit = max(1, (int) config('app.admin_mutation_rate_limit', 60));

RateLimiter::for('admin-mutation', function (Request $request) use ($defaultLimit) {
    if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
        return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
    }

    if ($request->is('api/admin/pos/*') || $request->is('api/admin/pos')) {
        return Limit::perMinute(120)->by(...);
    }
    if ($request->is('api/admin/kds-order/change-status/*')) {
        return Limit::perMinute(120)->by(...);
    }

    return Limit::perMinute($defaultLimit)->by($request->user()?->id ?: $request->ip());
});
```

Add to `config/app.php` :
```php
'admin_mutation_rate_limit' => max(1, (int) env('ADMIN_MUTATION_RATE_LIMIT', 60)),
```

Add to `.env.example` : `ADMIN_MUTATION_RATE_LIMIT=60`
Add to `.env` (local) : `ADMIN_MUTATION_RATE_LIMIT=1000`

**Sentinel update required** : `tests/Unit/Security/RateLimiterConfigTest.php:32`
currently asserts `'admin-mutation' => [30, true]`. Update baseline to
60/min (the new prod default) + add a test that env-knob is honored.

**Pros** : pattern parity with POS/KDS knobs, unblocks owner immediately,
local dev gets 1000/min like the other buckets, no business semantics change.
**Cons** : requires updating the sentinel baseline (1 line + 1 test).

### FIX B (surgical-minimality 2) — lift `*-order/change-status` explicitly (no env knob change)

In `RouteServiceProvider.php:115`, before the final `return Limit::perMinute(30)...`,
add :
```php
if ($request->is('api/admin/online-order/change-status/*')
    || $request->is('api/admin/table-order/change-status/*')) {
    return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
}
```

**Pros** : Mirrors the POS / KDS lift pattern; smallest possible diff;
doesn't change the `admin-mutation` 30/min ceiling for everything else
(category CRUD, settings, etc. stay tight against accidental burst).
**Cons** : Doesn't fix owner pain for OTHER admin actions (waiter / customer
write / setting changes during testing); narrower than A.

### FIX C (surgical-minimality 4) — separate write-action limiter for cashier roles

Introduce a `cashier-action` limiter (`Limit::perMinute(300)->by(user_id)`)
explicitly applied as middleware on `*-order/change-status` + related cashier
write endpoints. Tighten `admin-mutation` back to 30/min for actual admin
CRUD (settings, role, permission). Requires touching ~6-8 route declarations.

**Pros** : Cleanest separation between "cashier-frequent writes" (high
ceiling) and "admin CRUD writes" (tight) ; smallest production-attack
surface increase.
**Cons** : ~6-8 route lines to touch + a new limiter to test ; not strictly
surgical.

### Recommended : **FIX A first** (unblock owner), **FIX B optional** (cleaner
explicit lift for the change-status family). C is V1.0.2 polish.

---

## 7. Production Safety Analysis

For each candidate fix :

| Concern | Fix A (env-knob 60 default, 1000 local) | Fix B (lift change-status) | Fix C (separate limiter) |
|---|---|---|---|
| NF525 audit chain | Unaffected — chain insert happens inside the controller transaction, not throttled. ✅ | Same. ✅ | Same. ✅ |
| Login brute-force | `login-lockout` is a separate limiter (10 per 10 min) — UNAFFECTED. ✅ | UNAFFECTED. ✅ | UNAFFECTED. ✅ |
| Password reset | `throttle:3,60` SEPARATE — UNAFFECTED. ✅ | UNAFFECTED. ✅ | UNAFFECTED. ✅ |
| Z-report open/close | Hardcoded `throttle:10,1` SEPARATE (line 1145-1147) — UNAFFECTED. ✅ | UNAFFECTED. ✅ | UNAFFECTED. ✅ |
| Fiscal sequence allocation | Inside controller, not throttle-protected ; safe ✅ | Same ✅ | Same ✅ |
| Idempotency middleware | Independent — runs BEFORE throttle by stack order — replay safe ✅ | Same ✅ | Same ✅ |
| Default production ceiling | 60/min/user (2× of current 30) — still meaningful brute-force protection for admin CRUD | 30/min stays for non-change-status ✅ | 30/min stays for non-cashier ✅ |
| Local dev unblocked | ✅ env=1000 | ❌ still 120/min on change-status (probably enough for manual testing but tight on bursts) | ✅ env=300 default cashier |

**No NF525 / security regression for any candidate.** The only
production semantic change is that the abusive-admin-CRUD-spam ceiling
doubles from 30 → 60 in Fix A — still tight, no realistic operator
exceeds 60 admin writes per minute.

---

## 8. E2E Test Plan (proof scenarios for the fix)

Test fixtures must run AFTER `clearFoodKingRateLimits()` (already
exists in `tests/e2e/helpers/rate-limit.js`).

### Scenario T1 — Rapid Livré bursts
Login as `pos@lecayenne.fr`. Place 12 kiosk-paid orders. Bump all 12
to PREPARED on KDS. On POS, click "Livré" rapidly on each within 5s.
**Expected** : all 12 succeed (HTTP 200). With current code : the 11th
fails with 429 (admin-mutation bucket).

### Scenario T2 — Rapid Encaisser bursts
Login as `pos@lecayenne.fr`. Seed 15 kiosk-cash pending orders. Open
the "Encaisser" modal on each in sequence, confirm cash. **Expected** :
all 15 succeed (200), no 429 from `pos-order-update` (1000/min). With
current code : already passes (POS already lifted to 1000/min).

### Scenario T3 — Rapid Cancel bursts
Same seed, but cancel instead of confirm. **Expected** : all 15 succeed.

### Scenario T4 — Mixed flow (the owner's actual pain)
Login admin. Perform sequence : modify category, save setting,
toggle 86 item, change customer profile, click "Livré" on online
order, click "Cancel" on online order — 10 cycles within 60s.
**Expected** : all 60 actions succeed. With current code : actions
~31-60 return 429.

### Scenario T5 — Failed-login still rate-limited (security regression check)
Try logging in as `admin@lecayenne.fr` with wrong password 11 times.
**Expected** : 11th attempt returns 429 from `login-lockout` (unchanged).

### Scenario T6 — Idempotency replay (not throttle) on double-click
Click "Livré" twice within 1s on the same order. **Expected** :
first = 200 (state transition), second = 200 (idempotency replay of
cached 2xx). **Critical : second must be 200 NOT 429**, proving the
throttle bucket doesn't fight idempotency.

### Scenario T7 — Toast accuracy (UX regression check)
On any 429, verify the toast message AND the `Retry-After` response
header. If toast says "30s" but `Retry-After: 60`, fix the i18n string
to read the real value : `Trop de requêtes — patientez {seconds}s
avant de réessayer.` and interpolate.

---

## 9. Optional Bonus Fix — Toast accuracy

**Issue** : `resources/js/bootstrap.js:176-181` shows hardcoded "30s"
copy regardless of the actual `Retry-After` header.

**Recommended** : extract `error.response?.headers?.['retry-after']`,
parse to int, fallback 30, interpolate into the i18n message :

```js
} else if (status === 429) {
    bucket = 'rl';
    const retryAfter = parseInt(error?.response?.headers?.['retry-after'] ?? '30', 10);
    message = _i18nMessage(
        'error.rate_limited',
        `Trop de requêtes — patientez ${retryAfter}s avant de réessayer.`,
        { seconds: retryAfter }
    );
}
```

i18n strings updated to `Trop de requêtes — patientez {seconds}s avant
de réessayer.` with placeholder substitution. Removes the 30s vs 60s
mismatch the owner observes.

---

## 10. Files to touch (for the recommended fix path A + B + bonus)

- `app/Providers/RouteServiceProvider.php` (lines 77-117) — env-knob +
  optional explicit lift for online-order/table-order change-status.
- `config/app.php` — add `admin_mutation_rate_limit` knob.
- `.env.example` — add `ADMIN_MUTATION_RATE_LIMIT=60`.
- `.env` — add `ADMIN_MUTATION_RATE_LIMIT=1000` (local dev only).
- `tests/Unit/Security/RateLimiterConfigTest.php:32` — update baseline
  `'admin-mutation' => [60, true]` and add env-honor test.
- `resources/js/bootstrap.js:176-181` — read real `Retry-After`.
- `resources/js/languages/{fr,en,ar}.json` `error.rate_limited` — add
  `{seconds}` placeholder.

**Zero frozen-zone touch** (CLAUDE.md §7 list checked — RouteServiceProvider
is NOT frozen, only the fiscal/NF525 services are).

---

## 11. Open Questions for Owner (after fix lands)

1. Production default for `admin-mutation` ceiling — keep 60/min or
   raise to 120/min (to match POS/KDS) ? My recommendation : **60/min
   prod / 1000/min local-dev** — preserves brute-force protection for
   the broad admin CRUD surface while clearly removing the test-flow
   bottleneck.
2. Should the toast wording also be changed (e.g. "Patientez quelques
   instants…") to avoid the hardcoded "30s" ambiguity entirely ? My
   recommendation : interpolate real `Retry-After` (Section 9).

---

## END
