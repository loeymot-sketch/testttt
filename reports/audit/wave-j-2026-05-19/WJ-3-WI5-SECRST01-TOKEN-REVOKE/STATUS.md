# WJ-3 / WI-5 SEC-RST-01 — Password reset must revoke Sanctum tokens

**Wave**: J Heal Wave I
**Priority**: P1 (security — session-fixation post-credential-rotation)
**Status**: GREEN (heal landed + sentinel in place)
**Date**: 2026-05-19
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Wall-clock**: ~35 min

---

## 1. Vulnerability summary

`ForgotPasswordController::resetPassword` (file
`app/Http/Controllers/Auth/ForgotPasswordController.php`, lines 118-180)
updates the user's password hash but did NOT delete any rows from the
`personal_access_tokens` table.

Pre-fix concrete attack scenario:

1. Attacker exfiltrates a Sanctum token (XSS, device theft, log leak, etc.).
2. Victim realises and triggers the official forgot-password flow:
   `POST /api/auth/forgot-password/` → OTP → verify-code → reset-password.
3. The new password lands in DB, the victim believes the session is safe.
4. The attacker's exfiltrated token row still exists in
   `personal_access_tokens` with `expires_at = NULL` (or +480 min).
5. Sanctum keeps validating it because the row is untouched.
6. Attacker retains full admin/customer privileges for up to 480 minutes
   (`config('sanctum.expiration', 480)`) — long after the victim believed
   the breach was contained.

Per CLAUDE.md §9 "Sanctum kiosk:order" and "Old tokens revoked à chaque
relogin (prevent token sprawl)", the relogin path already revokes prior
tokens (LoginController.php:109). The **reset** path was an oversight from
the same epoch (Sprint 5D Z6-01, 2026-05-16) — the heal extends the same
posture to the reset entry point with **broader scope** (all token names,
not only `auth_token`), because a reset signals possible **full credential
compromise** rather than just a session refresh.

---

## 2. Fix

Inside the existing `DB::transaction` block of `resetPassword()`, between
the password hash update and the new token creation:

```php
$user->update([
    'password' => Hash::make($request->post('password'))
]);

// [WJ-3 WI-5 SEC-RST-01 V1.0.1 P1 — 2026-05-19]
// Revoke ALL existing Sanctum tokens BEFORE minting the new
// session token. [...] Sentinel: tests/Feature/Sentinels/PasswordResetRevokesTokensSentinelTest.php
$user->tokens()->delete();

$this->token = $user->createToken(
    'auth_token',
    ['*'],
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;
```

### Placement rationale

The delete must run **before** `createToken(...)`, NOT after:

| Placement | Behaviour | Verdict |
|---|---|---|
| Before `createToken` | All historical tokens revoked, fresh session token survives, response contract preserved (user signed in immediately) | **CHOSEN** |
| After `createToken` | The brand-new session token returned in the JSON body is also deleted → response carries a dead token → user must re-login → bad UX, silently breaks API contract | Rejected |

The behavioral sentinel `test_user_receives_fresh_usable_token_in_reset_response`
catches a regression to the "after" placement variant.

### Scope

- 1 file touched: `app/Http/Controllers/Auth/ForgotPasswordController.php`
- 1 line of executable code added (`$user->tokens()->delete();`)
- 12 lines of inline rationale comment
- 0 frozen-zone files touched (`ForgotPasswordController` is not in
  CLAUDE.md §7 frozen list).
- 0 NF525 surface touched (chain unaffected).
- 0 multi-tenant scope altered.

### Why we did NOT touch idempotency

The original spec called out an optional add of idempotency middleware on
`/reset-password`. We deferred this as a separate concern because:

- `/reset-password` is already throttled `throttle:5,1` (5 per minute).
- The endpoint is a state-mutating one-shot (the `password_resets` row is
  consumed inside the same transaction at line 164, so a replay with the
  same `reset_token` will 422 the second time with "token_is_invalid").
- Adding idempotency would change the response semantics for legitimate
  retries (network-flake → user resubmits) from 422→200 cached, which is
  arguably a UX regression for a security-critical endpoint where 422
  better signals "token already consumed".

If the V1.0.1 hardening wave decides idempotency is required, file a
separate WJ-3-bis ticket; the current heal is intentionally minimal.

---

## 3. Sentinel test

**Path**: `tests/Feature/Sentinels/PasswordResetRevokesTokensSentinelTest.php`
**Mode**: behavioral (HTTP + `RefreshDatabase`)
**Cases**: 4

| # | Case | Pre-fix | Post-fix |
|---|---|---|---|
| 1 | `test_reset_password_revokes_existing_sanctum_tokens` — 2 pre-existing tokens (admin + kiosk), reset succeeds, ALL old IDs deleted, at most 1 fresh row | FAIL (assertEmpty over surviving old IDs) | PASS |
| 2 | `test_old_token_returns_401_on_authenticated_endpoint_after_reset` — bearer hit against `/api/auth/logout` with the pre-reset token returns 401, not 200 | FAIL (PersonalAccessToken::findToken still resolves the row) | PASS |
| 3 | `test_user_receives_fresh_usable_token_in_reset_response` — guards against the "delete after createToken" placement bug | PASS (already passes pre-fix) — placement guard | PASS |
| 4 | `test_reset_succeeds_when_user_has_no_existing_tokens` — empty-set idempotency, reset still mints exactly 1 token | PASS — idempotency guard | PASS |

### Pre-fix run

```
Tests:  2 failed, 2 passed   <- exactly the bug cases (1+2) RED
Time:   0.82s
```

### Post-fix run

```
PASS  Tests\Feature\Sentinels\PasswordResetRevokesTokensSentinelTest
  ✓ reset password revokes existing sanctum tokens
  ✓ old token returns 401 on authenticated endpoint after reset
  ✓ user receives fresh usable token in reset response
  ✓ reset succeeds when user has no existing tokens

Tests:  4 passed
Time:   0.74s
```

---

## 4. Regression

Command:

```
php artisan test tests/Feature/Auth tests/Feature/Sentinels/PasswordResetMinLengthSentinelTest.php tests/Feature/Sentinels/PasswordResetRevokesTokensSentinelTest.php
```

Result:

```
Tests:  20 passed
Time:   4.50s
```

Covers:

- `Tests\Feature\Auth\BcryptRoundsUpgradeTest` — 1 case
- `Tests\Feature\Auth\GuestSignupAbilityScopeTest` — kiosk scope
- `Tests\Feature\Auth\KioskThrottleKeysTest` — 4 kiosk throttle scenarios
- `Tests\Feature\Auth\LoginLockoutEmailFallbackTest` — 2 cases
- `Tests\Feature\Auth\RefreshTokenAbilityPreserveTest` — 4 cases (privilege-escalation regression)
- `Tests\Feature\Auth\UserMassAssignmentTest` — 2 cases
- `Tests\Feature\Auth\UserStatusRevalidationTest` — 4 cases
- `Tests\Feature\Sentinels\PasswordResetMinLengthSentinelTest` — 1 case (F-2 AUTH R1 min:12 sentinel)
- `Tests\Feature\Sentinels\PasswordResetRevokesTokensSentinelTest` — 4 cases (NEW, this heal)

### Wider --filter scan

`php artisan test --filter "Auth|Login|ForgotPassword|Sanctum"` ran 130
tests, 127 pass + 3 fail. The 3 failures are in
`Tests\Feature\Composer\ComposerAuthzMinimalTest` (403 vs 404 in branch
composer authz). **Verified pre-existing**: a `git stash` rerun showed the
same 3 failures on the pristine WIP-stashed tree, so they are unrelated to
SEC-RST-01 and are NOT a regression introduced by this heal.

---

## 5. Files changed

```
M app/Http/Controllers/Auth/ForgotPasswordController.php   (+13 lines)
A tests/Feature/Sentinels/PasswordResetRevokesTokensSentinelTest.php  (NEW, ~260 lines)
A reports/audit/wave-j-2026-05-19/WJ-3-WI5-SECRST01-TOKEN-REVOKE/STATUS.md  (NEW, this doc)
```

---

## 6. Commit

```
fix(auth-WJ-3-P1): revoke Sanctum tokens on password reset (SEC-RST-01)
```

---

## 7. Acceptance gate

- [x] Frozen-zone diff = 0 (CLAUDE.md §7 list untouched)
- [x] NF525 chain unaffected (no fiscal surface modified)
- [x] RED → GREEN convergence on sentinel (2 failing → 4 passing)
- [x] Behavioral test (not source-pin) — proves session is actually killed
- [x] No regression in wider auth suite (Composer 404/403 confirmed pre-existing)
- [x] Placement guard (test 3) prevents future "after createToken" regression
- [x] STATUS doc at canonical path under `reports/audit/wave-j-2026-05-19/`

Verdict: GO.
