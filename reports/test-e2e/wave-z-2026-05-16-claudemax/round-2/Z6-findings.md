# Wave Z — Round 2 — Z6 Auth / RBAC / Sanctum — Findings

**Auditor** : Z6 Round 2 (Claude Code RED-team, read-only)
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD** : `56204f052` (`feat(wave-z-5d): auth token revoke on relogin — Z6-01`)
**Date** : 2026-05-16
**Method** : Source verification with file:line citations + targeted PHPUnit run + git diff. No code touched.

---

## Verdict synthétique

| Finding | Severity | R1 Status | R2 Verdict |
|---------|----------|-----------|-----------|
| POS-A3 — `/pos/quote` + `/pos/walk-in-customer` no `permission:pos` | P1 | OPEN | **HEALED** |
| Z6-01 — `LoginController` no token revoke on relogin | P1 | OPEN | **HEALED** |
| Z6-02 — Guest signup `['*']` 30-day token | P1 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Z6-05 — User `$fillable` exposes `branch_id`/`is_guest`/`status` | P1 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Z6-06 — Tokens survive `status` change up to 480 min | P1 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Z6-03 — Forgot-password re-issues `['*']` | P2 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Z6-04 — Password policy `min:6`, no complexity | P2 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Z6-07 — `password_resets` no UNIQUE on email | P2 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Z6-08 — `withoutGlobalScope(BranchScope)` in post-auth paths | P2 | OPEN | DEFERRED V1.0.1 (unchanged) |
| Doc drift — `tokenCan('kiosk:order')` in 6+ vs 4 controllers | info | drift | DEFERRED — informational |
| NEW R2 — No regression test asserts Z6-01 revoke behaviour | low | n/a | **OBSERVATION** |
| Sanctum config (`expiration=480`) | OK | OK | OK |
| Rate limiting on login/kiosk-login/forgot-password | OK | OK | OK |
| Tests `KioskLoginApiTest` + `RateLimitTest` | n/a | n/a | **12/12 GREEN** |

**Aggregate** : 2 P1 from R1 confirmed HEALED, 7 deferred items confirmed unchanged at original file:line, 1 low-severity test-coverage observation surfaced. No new auth-breakage discovered. Sprint 4/5B/5C/5D heals are scope-minimal and pattern-aligned with KioskMachineLoginController precedent.

---

## §1 — Z6-01 verification (LoginController token revoke)

### Heal location
`app/Http/Controllers/Auth/LoginController.php:94`

```php
// [Sprint 5D Z6-01 2026-05-16] Revoke prior `auth_token` rows on relogin
// to prevent token sprawl. CLAUDE.md §9 explicitly required this; the
// original LoginController issued a fresh token every login without
// invalidating the previous one, leaving stale tokens valid for the
// full 480-min TTL after a user logged in from a second device or
// re-authenticated after a password change. Scoped by name so we
// never touch kiosk:order tokens (different name, separate concern).
$user->tokens()->where('name', 'auth_token')->delete();

$this->token = $user->createToken(
    'auth_token',
    ['*'],
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;
```

### Verification

1. Pattern parity confirmed — matches `KioskMachineLoginController.php:96` which has had the same `->where('name', 'kiosk-token')->delete()` pattern in production.
2. Name-scoped delete (`'auth_token'`) — does NOT clobber `kiosk-token` rows that may exist on the same `User` row (kiosk-order ability tokens). Correct.
3. Comment block (lines 87-93) cites CLAUDE.md §9 + explains the multi-device threat model. Documentation-grade.
4. `Auth::guard('web')->logout()` precedes the revoke (line 85) so no race with the web-session resolver.
5. Sanctum `currentAccessToken()` on the freshly-issued token will return the new row only — old bearers (other devices) will now hit the model resolver, find no matching row, and Sanctum will 401. Expected behaviour.

### Tests run

```
php artisan test --filter='KioskLoginApiTest|RateLimitTest'

PASS  Tests\Feature\Fiscal\FiscalRateLimitTest          (4 tests)
PASS  Tests\Feature\KioskLoginApiTest                   (2 tests)
PASS  Tests\Feature\Routes\MenuControllerRateLimitTest  (2 tests)
PASS  Tests\Feature\Security\RateLimitTest              (4 tests)

Tests:  12 passed
Time:   4.45s
```

### Verdict Z6-01 : **HEALED**.

### NEW R2 observation (low severity)

No regression test in `tests/Feature/` asserts the specific behaviour : "logging in twice as the same user invalidates the first token". Concrete recommendation for V1.0.1 :

```php
public function test_relogin_revokes_prior_auth_token(): void {
    // login #1 → capture token A
    // login #2 → token B
    // assert: GET /api/admin/me with bearer=A → 401
    // assert: personal_access_tokens row for A is deleted
}
```

Not a blocker. The current `KioskLoginApiTest` covers kiosk-token revoke (which has worked since iter11) — the heal mirrors that pattern faithfully.

---

## §2 — POS-A3 verification (PosController gate)

### Heal locations

**Constructor middleware** (`app/Http/Controllers/Admin/PosController.php:51`) :

```php
$this->middleware(['permission:pos'])->except('quote');
```

This pivot from `->only('store')` (R1 baseline) to `->except('quote')` extends `permission:pos` to **every** PosController method (`store`, `walkInCustomer`, …) **except** `quote`. The class only exposes 3 public actions in the routing surface (`store`, `quote`, `walkInCustomer`), so this is equivalent to `only(['store', 'walkInCustomer'])` plus future-proofing.

**Inline `quote` guard** (`PosController.php:165-167`) :

```php
public function quote(Request $request): \Illuminate\Http\JsonResponse
{
    // [Sprint 5B Z1-NEW-002 / Sister POS-A3] Gate `/api/admin/pos/quote`
    // on permission:pos (alongside the constructor middleware which
    // only covers `store`). The kiosk surface uses
    // `/api/frontend/order/quote` (auth:sanctum + kiosk:order ability)
    // which lives in a different route group — bypass perm check there
    // so kiosk pricing checks keep working.
    if (! $request->is('api/frontend/*')) {
        abort_unless($request->user()?->can('pos'), 403);
    }
    …
```

**Walk-in guard** (`PosController.php:144`) :

```php
public function walkInCustomer(Request $request): JsonResponse
{
    abort_unless($request->user()?->can('pos'), 403);
    …
```

The `walkInCustomer` inline guard is defense-in-depth — the constructor middleware already covers it, but the inline `abort_unless` provides a second layer if a future refactor accidentally exempts it.

### Route surface

`routes/api.php:721-728` (admin POS group, parent middleware `auth:sanctum`) :

- `GET /api/admin/pos/walk-in-customer` → gated by constructor `permission:pos` + inline `abort_unless`. **2-layer.**
- `POST /api/admin/pos/quote` → constructor excludes; inline `abort_unless` enforces when path is NOT `api/frontend/*`.
- `POST /api/admin/pos` → constructor `permission:pos`.

`routes/api.php:1125` (kiosk frontend group, parent `auth:sanctum` only) :

- `POST /api/frontend/order/quote` → routes to same `PosController::quote` action. The inline check skips `permission:pos` because `$request->is('api/frontend/*')` returns true. Kiosk callers (token with `kiosk:order` ability) keep working.

### Verdict POS-A3 : **HEALED**. Surface-aware dual-mount handled correctly. No way for a non-POS Sanctum user to reach `/api/admin/pos/quote` or `/api/admin/pos/walk-in-customer`.

---

## §3 — Deferred items — confirmed unchanged

Spot-checked each deferred finding via grep + `git diff c3ba89863..56204f052` :

| Finding | File:line | Status |
|---------|-----------|--------|
| Z6-02 | `app/Http/Controllers/Auth/GuestSignupController.php:140` — `createToken('auth_token', ['*'], now()->addDays(30))` | unchanged |
| Z6-03 | `app/Http/Controllers/Auth/ForgotPasswordController.php:165` — `createToken('auth_token', ['*'], …)` | unchanged |
| Z6-05 | `app/Models/User.php:42-53` — `$fillable` still contains `branch_id`, `is_guest`, `status` | unchanged (Sprint 5A Z9 sentinel logging added at line 106+ but does NOT touch `$fillable`) |
| Z6-06 | `app/Http/Controllers/Auth/LoginController.php:57` — `status` only checked at login attempt; no per-request middleware | unchanged |
| Z6-04 | `min:6` rule across 13 FormRequests | unchanged |
| Z6-07 | `database/migrations/2014_10_12_100000_create_password_resets_table.php` | unchanged |
| Z6-08 | 20+ `withoutGlobalScope(BranchScope)` call sites | unchanged |

`git diff c3ba89863..56204f052 -- app/Http/Controllers/Auth/GuestSignupController.php app/Http/Controllers/Auth/ForgotPasswordController.php` → 0 lines diff. Confirmed.

All deferral decisions match the brief : cascading risk (Z6-02 ability scope change touches OrderRequest auth path), schema migration (Z6-05/07), middleware insertion + perf measurement (Z6-06), broad-impact policy uplift (Z6-04), architectural refactor (Z6-08).

---

## §4 — RED-team — Did Wave Z heals break auth?

Adversarial review across the 4 sprint commits (5B/5C/5D + Sprint 4) :

### Hypothesis 1 — Token revoke clobbers legitimate concurrent sessions

**Tested by reading** `LoginController::login` flow :
- Web session is logged out at line 85 *before* the token revoke at line 94.
- Any in-flight request with the old bearer token will hit Sanctum's resolver after the revoke and 401 — by design.
- The 5D comment block explicitly accepts this trade-off (single-active-session per `auth_token` name).

**Refuted.** Behaviour matches CLAUDE.md §9 invariant ("Old tokens revoked à chaque relogin").

### Hypothesis 2 — `->except('quote')` accidentally exempts a future method

**Tested by reading** PosController public method list. The class has 3 public actions today (`store`, `walkInCustomer`, `quote`). If a contributor adds `Pos::refund(...)` tomorrow, the constructor would auto-cover it — `except('quote')` is *more* future-safe than the prior `only('store')`.

**Refuted.** Pivot direction improves the invariant.

### Hypothesis 3 — Inline kiosk-path discriminator can be spoofed

**Tested by reading** `$request->is('api/frontend/*')`. This is Laravel's request-path matcher — it consults the actual URI, not user-controlled headers/body. A kiosk caller cannot fake a `/api/admin/...` URI without literally calling the admin route, at which point `auth:sanctum` requires a token with `pos` permission. Conversely, a non-kiosk Sanctum holder cannot reach `/api/frontend/order/quote` if their token lacks `kiosk:order` ability (FormRequest layer `OrderRequest::authorize` covers `/api/frontend/order/store`; `/api/frontend/order/quote` itself has no FormRequest, but the surface only exposes pricing reads, not mutations).

**Caveat surfaced.** `/api/frontend/order/quote` has no `kiosk:order` ability check. Any Sanctum-authenticated user (including admin/staff tokens with `['*']`) can call it. Information disclosure surface : same pricing info as `/api/admin/pos/quote` — but the data is *the same menu prices admin users can already see via `/api/admin/items`*. **Low severity, not new.** Already filed under K-002 (architectural test-affordance trade-off).

### Hypothesis 4 — Spatie permission cache breaks under load

**Out of scope** for Z6 R2 (covered by Z2 RBAC + Z10 cash). No new breakage from heals.

### Verdict RED-team : **No new auth-breakage introduced by Wave Z heals.** One pre-existing low-severity surface (kiosk `/quote` accepts any Sanctum token) surfaced for documentation only — not a regression.

---

## §5 — Summary for CONVERGENCE_FINAL aggregator

**Confirmed healed (2/2 R1 P1)** :
- POS-A3 — Sprint 4 (`c9509b3ad`) + Sprint 5B (`d424f8402`) + Sprint 5C (`d424f8402`)
- Z6-01 — Sprint 5D (`56204f052`)

**Deferred V1.0.1 (7/7 unchanged at original file:line)** :
- Z6-02, Z6-03, Z6-04, Z6-05, Z6-06, Z6-07, Z6-08

**NEW R2 observations** :
1. No regression test asserts Z6-01 token-revoke behaviour (low severity, recommend adding before V1.0.1).
2. `/api/frontend/order/quote` accepts any Sanctum bearer without `kiosk:order` ability check (pre-existing, documented under K-002 trade-off — not new, not a regression).

**Tests** : 12/12 GREEN (`KioskLoginApiTest`, `RateLimitTest`, `FiscalRateLimitTest`, `MenuControllerRateLimitTest`).

**Verdict Z6 Round 2** : **CONVERGED.** Heals are scope-minimal, pattern-aligned with prior fixes, documentation-grade, and break nothing. Deferred items remain at original file:line as expected. Ready for `CONVERGENCE_FINAL.md` aggregation.

---

**End of Z6 R2 findings. ~210 lines. No code modified. Read-only audit.**
