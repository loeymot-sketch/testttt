# Password policy — V1 GO-LIVE baseline + V1.0.2 consumer extension

**Date** 2026-05-18 · **Branch** `v1-0-1-hardening-2026-05-17`
**Spec source** CLAUDE.md §1 V1.0.1 North Star — *"Password policy min:12 + complexity"*
**Ultra-review ref** `reports/ultra-review-2026-05-18/PR_D_findings.json` finding `F-D-T5`

---

## What landed this commit (Wave 7, V1 GO-LIVE staff path)

**7 FormRequests** raised from `min:6` → `Password::min(12)->letters()->numbers()`:

| File | Path semantics | Rule (new) |
|---|---|---|
| `app/Http/Requests/AdministratorRequest.php` | Admin create/edit | `Password::min(12)->letters()->numbers()` |
| `app/Http/Requests/EmployeeRequest.php` | Employee create/edit | same |
| `app/Http/Requests/ChefRequest.php` | Chef create/edit | same |
| `app/Http/Requests/WaiterRequest.php` | Waiter create/edit | same |
| `app/Http/Requests/DeliveryBoyRequest.php` | Delivery boy create/edit | same |
| `app/Http/Requests/ChangePasswordRequest.php` | Admin/staff self-change (with `old_password` check) | same |
| `app/Http/Requests/UserChangePasswordRequest.php` | User self-change (no old check — token-gated) | same |

`password_confirmation` aligned to `min:12` across the same 7.

**Backward compat** — `old_password` stays `min:6`. Existing weaker passwords still let users log in; only the NEW password they set must meet the policy. No forced rotation in this commit.

---

## Deferred to V1.0.2 (consumer paths)

| File | Path | Current min | Plan |
|---|---|---|---|
| `app/Http/Requests/CustomerRequest.php` | Admin creates Customer | `min:6` | Raise to `Password::min(8)->letters()->numbers()` once consumer UX team confirms |
| `app/Http/Requests/SignupRequest.php` | Customer self-signup | `min:6` | Same — needs UX sign-off (Customer abandonment vs security) |

Why deferred — staff hygiene is non-negotiable for NF525 / shop operations. Consumer-facing passwords have UX abandonment trade-offs that need owner decision (raise to 8/10/12). Document only in this backlog; do NOT change in V1 to avoid breaking signup mid-flight.

---

## Composer Password rule complexity options (reference)

The Laravel `Password` rule chain is composable. Today's commit uses the conservative trio:

```php
Password::min(12)->letters()->numbers()
```

V1.0.2 candidates if owner wants harder:
- `->mixedCase()` — at least 1 upper + 1 lower
- `->symbols()` — at least 1 special char
- `->uncompromised()` — checks haveibeenpwned API (network-dependent, opt-in per env)

Not added in this commit because the network call (`uncompromised`) needs an explicit env toggle + offline-mode fallback for kiosk LAN deploys.

---

## Sentinel test (added this commit)

`tests/Feature/Sentinels/PasswordPolicyV1Sentinel.php` asserts:
1. Each of the 7 staff FormRequests rejects `'password' => 'abc'` (too short, no numbers)
2. Each accepts `'password' => 'StaffPwd-2026-strong-7'` (≥12, letters+numbers)
3. Customer + Signup paths still accept `min:6` (V1 baseline)

The sentinel guards against accidental regression to `min:6` during V1.0.2 refactors.

---

## Acceptance gate

- ✅ 7 staff requests at min:12 + letters + numbers
- ✅ Sentinel test green (7 ACCEPT cases + 7 REJECT cases)
- ✅ Existing PHPUnit/Vitest unaffected (no regression)
- ✅ Backward compat: `old_password` still min:6 (existing users can login)

## V1.0.2 owner-gate

| Gate | Description | WHO | WHAT | WHERE |
|---|---|---|---|---|
| G-PWD-1 | Customer + Signup min raise (8, 10, or 12?) | Physical owner | Choose target min for consumer paths | This doc + commit message |
| G-PWD-2 | `uncompromised()` HIBP gate enable | Physical owner | Per-env toggle config | `config/auth.php` (new key) |
