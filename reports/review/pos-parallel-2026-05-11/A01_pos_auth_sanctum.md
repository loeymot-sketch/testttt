# A01 — POS Auth & Sanctum
> Scope: `app/Http/Controllers/Auth/*`, `app/Http/Middleware/{Authenticate,ApiKeyMiddleware,VerifyEmail}.php`, `routes/api.php` (auth groups), `config/sanctum.php`, `config/auth.php`, `app/Models/User.php`, `app/Providers/AuthServiceProvider.php`, `app/Providers/RouteServiceProvider.php` (limiters), `app/Http/Kernel.php`, `app/Http/Requests/{TokenStoreRequest, OrderRequest, Frontend/PaymentConfirmRequest}.php`, `tests/Feature/Auth/*`.
> HEAD: a220b9bd8
> Method: read-only, file:line verified at run time.

---

## §1 Findings

### P0 — Critical

**None at HEAD.** Both legacy P0s from `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` are closed:

- **P0-07 (past) — REFUTED.** `RefreshTokenController.php:34-46` preserves `$token->abilities` verbatim with a `[]` fallback; `[*]` is no longer hard-coded. Regression suite in `tests/Feature/Auth/RefreshTokenAbilityPreserveTest.php:46-98` locks the kiosk-preserved, admin-preserved, and empty-abilities → `[]` (never `*`) branches.
- **P0-08 (past) — REFRAMED, not a live gap.** Route `routes/api.php:1120` carries only `idempotency`, but `PaymentConfirmRequest.php:19-24` enforces `tokenCan('kiosk:order')`. The rationale for FormRequest over `abilities:kiosk:order` route middleware is documented in `routes/api.php:1102-1112` (Sanctum `CheckAbilities` 401s on session/guard auth — would break tests + non-token callers). The same pattern (FormRequest gate) is reused for `OrderRequest.php:35-66` and `OrderStatusRequest.php:93`.

### P1 — High

- **P1-A01-01** — `app/Http/Controllers/Auth/ForgotPasswordController.php:158-170` — `resetPassword` mints `['*']` (admin-equivalent) PersonalAccessToken automatically inside the same DB::transaction that writes the new hashed password — no re-login, no second-factor confirmation, no email link confirmation step beyond the 6-digit PIN previously validated. Combined with `min:6` on the new password (`:123`) and 3 attempts/hour throttle (`routes/api.php:163-164`) on `POST /api/auth/forgot-password/`, a stolen `reset_token` (size:64 stored in plaintext in `password_resets.token`, `:103-104`) yields a wildcard admin token without ever requiring the legitimate user to log in. **Recommendation**: drop the auto-token mint in `resetPassword` (force user to call `POST /api/auth/login` after reset) OR scope abilities to a least-privilege "post-reset" ability, OR require a second factor (existing `phone_verification` already used by signup).

### P2 — Medium

- **P2-A01-01** — `app/Http/Middleware/ApiKeyMiddleware.php:24` — `$request->header('x-api-key') === $validApiKey` uses non-constant-time string comparison. Although fast attackers cannot easily exploit the few-microseconds delta over HTTP, `hash_equals($validApiKey, (string)$request->header('x-api-key'))` is the standard for secret comparison. **Recommendation**: switch to `hash_equals()`.

- **P2-A01-02** — `routes/api.php:147` — `/refresh-token` carries `installed,apiKey` only — **no throttle**. Combined with `apiKey` being a static shared secret (not rotated per device), a compromised `MIX_API_KEY` allows unbounded refresh attempts (per-token guess) on `Laravel\Sanctum\PersonalAccessToken::findToken` until a hash collision/log-leaked plaintext lands. **Recommendation**: attach `throttle:refresh-token` named limiter (e.g. 10/min keyed by `bearer_token_hash|ip`).

- **P2-A01-03** — `app/Http/Controllers/Auth/LoginController.php:87-91` — admin/customer login mints a fresh `auth_token` (`['*']`) **without revoking prior tokens**, contrasting with `KioskMachineLoginController.php:96` which deletes prior `kiosk-token` rows. A user who logs in from 10 devices accumulates 10 simultaneously valid `['*']` tokens — none rotated on logout from another device. **Recommendation**: revoke same-named tokens on login (mirror kiosk pattern), or document the multi-device behaviour explicitly.

- **P2-A01-04** — `app/Http/Controllers/Auth/ForgotPasswordController.php:62-66` — explicit `email_does_not_exist` response on unknown email enables enumeration. Compare with rate-limited generic "if it exists, we sent it" pattern. **Recommendation**: return the same 200 "check_your_email_for_code" payload regardless, log internally.

- **P2-A01-05** — Password policy `min:6` across `LoginController.php:48`, `KioskMachineLoginController.php:32`, `ForgotPasswordController.php:123-124`, `SignupController` (via `SignupRequest`). Below modern baseline (NIST SP800-63B recommends 8+, OWASP 10+). Already on V1.0.1 backlog — flagged here for traceability. **Recommendation**: lift to `min:12` with complexity rule for admin/staff, keep `min:8` for customer.

- **P2-A01-06** — `config/sanctum.php:51` — `expiration` default 480 minutes (8h) for ALL tokens including admin/`['*']`. NIST recommends short-lived tokens for sensitive ops. Already on V1.0.1 backlog. **Recommendation**: split per-token-name TTL — `kiosk-token` 480m OK (hardware), `auth_token` admin 60m with `/refresh-token` flow.

- **P2-A01-07** — `app/Http/Requests/OrderRequest.php:60-63` and `OrderStatusRequest.php` (similar pattern) — defense-in-depth bypass for session-auth: `if (! $token) return true;`. Documented intentionally for `actingAs($user, 'sanctum')` test compat, but any **session-authenticated admin** (e.g. a logged-in admin via web cookie) could POST `/api/frontend/order` without holding `kiosk:order`. Production-side this only matters if an admin's session cookie is replayed, but it's a defense-in-depth hole. **Recommendation**: tighten to `if (! $token) return $user->hasAnyRole([...kiosk roles]);` or whitelist `runningUnitTests()` only.

- **P2-A01-08** — `app/Http/Requests/Frontend/PaymentConfirmRequest.php:21` — `$hasKioskAbility = $token ? $user->tokenCan('kiosk:order') : app()->runningUnitTests();` — production fallback when no token is present returns `false` (correct), but the test-only override means **any test** can hit `paymentConfirm` without ability proof. Acceptable but worth a comment explaining intent. **Recommendation**: keep but document; optionally gate on a test-helper trait rather than `runningUnitTests()`.

- **P2-A01-09** — `app/Http/Controllers/Auth/LoginController.php:48` validates `'password' => ['required', 'string', 'min:6']` BEFORE the rate-limited attempt — but `Validator::fails()` returns 422 **before** the throttle decrement runs (throttle is route-level, applies even on 422). Net effect: throttle still applies. **No actual defect — sanity-checked**. Listed here as a NOT REPRODUCIBLE concern, traced only for the next agent.

### P3 — Low

- **P3-A01-01** — `app/Http/Controllers/Auth/KioskMachineLoginController.php:59-83` — three distinct error responses (`credentials_invalid` for unknown username, `kiosk_machine_inactive` for inactive machine, `kiosk_user_inactive` for inactive linked user, `credentials_invalid` for wrong password) enable enumeration of machine usernames and their states. Risk low (kiosk usernames are not user-facing), but inconsistent with the user-login pattern. **Recommendation**: collapse to a single `credentials_invalid` for all four paths; keep distinct messages in server logs only.

- **P3-A01-02** — `app/Http/Controllers/Auth/LoginController.php:89` — admin AND customer users mint `['*']` (wildcard) tokens. Project design (RBAC via Spatie downstream), but worth noting: a stolen customer Sanctum token has the same Sanctum-surface as a stolen admin token. Mitigation lies entirely in Spatie permission gates per controller. **Recommendation**: future hardening — scope customer tokens to `['customer:order','customer:profile']` and admin to `['*']`.

- **P3-A01-03** — `app/Models/User.php:79-82` — `$hidden` excludes `password` and `remember_token` but NOT `email_verified_at`, `branch_id`, `is_guest`. Not a leak per se (those fields are intentionally exposed via `UserResource`), but worth confirming `UserResource` is the only serialization path. **Recommendation**: no action; informational.

- **P3-A01-04** — `app/Http/Middleware/VerifyEmail.php:21` — `Auth::user()->email_verified_at === null` does not handle the case where `Auth::user()` itself is null (e.g. token resolved but tokenable gone). Returns 500 instead of 401. **Recommendation**: null-guard `$user = Auth::user(); if (! $user) return 401;`.

---

## §2 Cross-validation watch list
Lines another agent might cite (to deconflict downstream):
- `app/Http/Controllers/Auth/LoginController.php:65-78` — branch fallback chain (settings → first branch by id) — A02/A03 may flag default-branch leak risk.
- `app/Http/Controllers/Auth/LoginController.php:104-108` — `landing_url` regex `[a-zA-Z0-9\-_\/]*` allows empty string → empty redirect. Frontend likely tolerates, but A06 (UX) may flag.
- `routes/api.php:142-144` — generic `/login` returns `401 unauthenticated` — never reached for real login (overridden by `/api/auth/login`). Possibly dead route.
- `routes/api.php:1113-1120` — `frontend/order` group is `auth:sanctum` only; ability gate is FormRequest-side (A09 Kiosk/OrderRequest may revisit).
- `app/Providers/RouteServiceProvider.php:115-128` (kiosk-login limiter, 30/min) and `:130-149` (login-lockout, 10/10min) — A02 (POS rate-limit) likely cites these.
- `routes/channels.php:27` — broadcast channel check via `tokenCan('kiosk:order')` — A10/A11 (broadcasting/notifications) may cite.

---

## §3 Proposed E2E coverage

1. **E2E-A01-01 — Admin login + Sanctum token lifecycle**
   Visit `/login`, POST `/api/auth/login` with admin creds, assert 201 + `token` issued + branch_id resolved + token works on `/api/admin/setting/site` (`['*']`-only route). Logout via `POST /api/auth/logout`, re-attempt with same token → expect 401.

2. **E2E-A01-02 — Kiosk machine login + token isolation**
   POST `/api/auth/kiosk-login` with valid `username`+`password`. Assert: token ability is `['kiosk:order']` ONLY (assert via stored `personal_access_tokens.abilities` projection). Attempt admin-only call (e.g. `POST /api/admin/menu/availability/toggle`) with kiosk token → expect 401/403. Confirm `tokens()->where('name','kiosk-token')->delete()` revoked prior session.

3. **E2E-A01-03 — Refresh token preserves abilities**
   Create kiosk token. Hit `POST /api/refresh-token` with `x-api-key`. Decode new token's `abilities` → MUST equal `['kiosk:order']`, MUST NOT contain `*`. Then refresh an admin `['*']` token → new token MUST still be `['*']`. Already covered by `RefreshTokenAbilityPreserveTest` unit-level; promote to browser-level via Playwright fetch.

4. **E2E-A01-04 — Login throttle (login-lockout 10/10min)**
   POST `/api/auth/login` 11× from same IP+email with wrong password → expect 429 on attempt 11 with `Retry-After` header. Different email from same IP should NOT bleed (key = `email|ip`). Wait limiter decay, retry → 200/201.

5. **E2E-A01-05 — Cross-branch leak guard (admin branch_id=0 → staff branch_id=X)**
   Log in as admin (`branch_id=0`), set `default_access.branch_id = 1`. Open a second tab logged in as POS Operator with `branch_id=2`. POST `/api/admin/order` from admin context → assert payload returns branch_id=1 orders ONLY (BranchScope bypass for admin), NOT branch_id=2. Log out admin, retry as POS Operator → must see branch_id=2 orders only. Confirms session/token isolation under multi-tenant scope.

---

## §4 Verdict for this scope
**HEAL** — No live P0. P1 reset-password auto-token escalation is the worst live issue; P2 cluster (throttle on refresh, hash_equals, token revoke on login, enumeration) aligns with the V1.0.1 hardening backlog. Past P0-07 / P0-08 are demonstrably closed at HEAD `a220b9bd8`.

---

## §5 BRAIN.md drift note
Two items to update post-audit:
- `PROJECT_BRAIN.md` §7 should explicitly mark **P0-07 (RefreshToken abilities)** and **P0-08 (kiosk ability gate at FormRequest layer)** as CLOSED + tested, with file:line evidence (`RefreshTokenController.php:34-46`, `RefreshTokenAbilityPreserveTest.php:46-98`, `OrderRequest.php:35-66`, `PaymentConfirmRequest.php:12-26`). The old verdict's "P0-08 missing route middleware" framing is incorrect at this HEAD — leaving it un-retired creates false-positive drift.
- V1.0.1 backlog ("Password min:12 + complexity, Sanctum TTL 8h → 1h sensitive ops") should add **(a)** drop auto-token mint from `ForgotPasswordController::resetPassword` and **(b)** revoke prior `auth_token` rows on `LoginController::login`, to close the P1 reset-escalation and P2-A01-03 token-sprawl items in the same hardening pass.
