# Security & Logic Audit — Auth, Routing, Controllers, Middleware, Vuex

**Date**: 2026-03-31
**Auditor**: Claude (Architect & Reviewer)
**Scope**: routes/api.php, kioskRoutes.js, Frontend OrderController, Auth Controllers, Middleware, Vuex stores
**Verdict**: NEEDS_FIX (11 findings: 3 CRITICAL, 4 HIGH, 3 MEDIUM, 1 LOW)

---

## Executive Summary

The codebase shows strong security posture overall — server-side price recalculation, idempotency protection, rate limiting on auth endpoints, and proper `lockForUpdate()` for race conditions are all in place. However, this audit identified **3 critical** and **4 high** severity issues that should be addressed before production deployment.

---

## 1. Routes (`routes/api.php`)

### FINDING-01 — CRITICAL: `authcheck` endpoint lacks rate limiting
**Severity**: CRITICAL
**Location**: `routes/api.php:176-210`
**Description**: The `/auth/authcheck` endpoint is inside the `auth` prefix group but is NOT protected by `auth:sanctum`. It uses `Auth::check()` which relies on session/cookie auth. Since `EnsureFrontendRequestsAreStateful` is commented out in the Kernel (line 43), this endpoint may behave inconsistently. More critically, it has **no rate limiting**, allowing an attacker to probe authentication state at high speed.
**Risk**: Authentication state enumeration, potential session fixation if session middleware is accidentally enabled.
**Fix**:
```php
Route::post('/authcheck', function () { ... })
    ->middleware(['auth:sanctum', 'throttle:30,1']);
```

### FINDING-02 — HIGH: `/frontend/loyalty/check` and `/register` are unauthenticated
**Severity**: HIGH
**Location**: `routes/api.php:888-894`
**Description**: The loyalty `check` and `register` endpoints are public (no `auth:sanctum`). While rate-limited (10/min and 5/min), the `check` endpoint returns user name, loyalty points, discount value, and loyalty code for any phone number or code. This enables **user enumeration** and **PII disclosure** to unauthenticated callers.
**Risk**: An attacker can enumerate all loyalty accounts, harvest names, phone numbers, and point balances.
**Fix**: Either add `auth:sanctum` or strip PII from the response for unauthenticated callers (return only `status: true/false` and `discount_value`, not `name` or `loyalty_code`).

### FINDING-03 — MEDIUM: `frontend/coupon/coupon-checking` is unauthenticated
**Severity**: MEDIUM
**Location**: `routes/api.php:851-856`
**Description**: Coupon checking is rate-limited (10/min) but not behind `auth:sanctum`. An attacker can brute-force coupon codes from any IP. With distributed IPs, the rate limit is ineffective.
**Risk**: Coupon code enumeration and abuse.
**Fix**: Add `auth:sanctum` middleware or implement per-code lockout after N failures.

### FINDING-04 — MEDIUM: Table dining order (`/table/dining-order`) is fully unauthenticated
**Severity**: MEDIUM
**Location**: `routes/api.php:920-925`
**Description**: The table ordering endpoint has no `auth:sanctum` — it relies solely on IP-based throttle (20/min). While this is by design (QR code ordering), there is no mechanism to validate that the request originates from a legitimate QR scan (e.g., a signed token or dining table slug validation).
**Risk**: Anyone with the API key can place orders on any table without scanning the QR code.
**Fix**: Consider adding a short-lived signed token embedded in the QR code URL, validated server-side.

### FINDING-05 — LOW: No role-based authorization on admin routes
**Severity**: LOW
**Location**: `routes/api.php:222-769`
**Description**: The entire `/admin` prefix group requires `auth:sanctum` but does NOT apply `role` or `permission` middleware at the route level. Authorization is presumably handled inside controllers or via Spatie policies, but this is not visible in the route definitions. If any controller method lacks its own permission check, it becomes accessible to any authenticated user (including customers and kiosk machines).
**Risk**: Privilege escalation if a controller method forgets to check permissions.
**Fix**: Add `role_or_permission` middleware to the admin group, or audit every admin controller for explicit permission checks. At minimum, add a guard that rejects tokens with `kiosk:order` ability from accessing `/admin/*`.

### FINDING-06 — HIGH: Refresh token endpoint does not revoke old token
**Severity**: HIGH
**Location**: `routes/api.php:126`, `RefreshTokenController.php:15-29`
**Description**: The `/refresh-token` endpoint accepts a Sanctum token, creates a new token, and returns it — but **never deletes the old token**. This means:
1. Old tokens accumulate indefinitely in `personal_access_tokens`
2. A stolen token can be refreshed to get a new one, and the original remains valid
3. No way to invalidate a compromised token chain
**Risk**: Token proliferation, inability to revoke compromised sessions.
**Fix**:
```php
$token->delete(); // revoke old token before issuing new one
$newToken = $user->createToken('auth_token')->plainTextToken;
```

---

## 2. Kiosk Routes (`kioskRoutes.js`)

### FINDING-07 — MEDIUM: `kiosk.admin` route has no additional PIN/password guard
**Severity**: MEDIUM
**Location**: `kioskRoutes.js:191-197`
**Description**: The `/kiosk/admin` route uses `requireKioskAuth` (same as all kiosk routes) but has no additional authentication. The `KioskAdminComponent` is accessible to anyone who can touch the kiosk screen while it's logged in. This page likely provides maintenance/config functions.
**Risk**: Any customer at the kiosk can navigate to `/kiosk/admin` and access admin functions.
**Fix**: Add a `beforeEnter` guard that requires a staff PIN or password before allowing access. The component itself may already have a PIN prompt — verify this is enforced and cannot be bypassed via direct URL navigation.

### Route Guards Assessment (POSITIVE)
- `requireKioskAuth` correctly checks for `kioskToken` in store state
- `requireCart` prevents accessing payment/loyalty/upsell with empty cart
- `requireOrderRef` validates orderId param against `undefined`/`null`/empty string
- `requireConfirmationContext` prevents accessing confirmation without an order
- Auto-login via `getKioskAutoCredentials()` correctly checks for maintenance mode
- Lazy-loading with webpack chunks is properly configured

---

## 3. Frontend OrderController & FrontendOrderService

### FINDING-08 — CRITICAL: `show()` returns empty array instead of 403 for unauthorized access
**Severity**: CRITICAL
**Location**: `FrontendOrderService.php:544-555`
**Description**: The `show()` method checks `$frontendOrder->user_id == Auth::user()->id` using **loose comparison** (`==`). If the user doesn't own the order, it returns an empty array `[]` instead of throwing a 403 Forbidden. The controller wraps this in `OrderDetailsResource`, which may serialize the empty array as a valid response. Additionally, the loose `==` comparison can cause type juggling issues (e.g., string "0" == int 0).
**Risk**: IDOR — any authenticated user can probe order IDs. While they get an empty response (not the order data), the difference between an empty response and a 404 leaks information about which order IDs exist. The loose comparison is also a subtle bug.
**Fix**:
```php
public function show(FrontendOrder $frontendOrder): FrontendOrder
{
    if ((int) $frontendOrder->user_id !== (int) Auth::id()) {
        throw new Exception(trans('all.message.unauthorized'), 403);
    }
    return $frontendOrder;
}
```

### FINDING-09 — CRITICAL: `changeStatus()` uses loose comparison for ownership check
**Severity**: CRITICAL
**Location**: `FrontendOrderService.php:560-615`
**Description**: The `changeStatus()` method uses `$frontendOrder->user_id == Auth::user()->id` (loose `==`). More critically, if the user does NOT own the order, the method **silently returns the order without making changes** (line 610: `return $frontendOrder`). Combined with `OrderStatusRequest::authorize()` which allows staff roles to change ANY order status, there is a logic gap:
1. Staff can change status of any order (intended)
2. Kiosk can only cancel their own orders (intended)
3. But the ownership check at line 566 means a kiosk user who doesn't own the order gets the order returned without error — this is an information leak
**Risk**: Information disclosure via the response object; loose comparison type juggling.
**Fix**: Use strict comparison `(int) $frontendOrder->user_id !== (int) Auth::id()` and return 403 for unauthorized access.

### Positive Findings (OrderController/Service)
- Server-side price recalculation from DB (client prices ignored) ✓
- Cross-item injection guards for variations/extras ✓
- Idempotency key with both application-level and DB-level duplicate detection ✓
- `lockForUpdate()` for loyalty point deduction ✓
- Atomic queue number allocation with Cache lock ✓
- `paymentConfirm()` has proper ownership check with strict comparison ✓
- Address IDOR protection (validates address belongs to authenticated user) ✓
- Client-supplied `total`, `subtotal`, `discount` are explicitly unset before creation ✓

---

## 4. Auth Controllers

### FINDING-10 — HIGH: `resetPassword()` does not require prior code verification
**Severity**: HIGH
**Location**: `ForgotPasswordController.php:105-134`
**Description**: The `resetPassword()` method accepts `email` + `password` and resets the password **without verifying that the caller previously completed `verifyCode()`**. The `verifyCode()` method deletes the OTP record on success (line 93), but `resetPassword()` never checks if verification occurred. An attacker who knows a user's email can directly call `resetPassword()` to change their password.
**Risk**: Account takeover — any user's password can be reset by anyone who knows their email.
**Fix**: Add a verification token flow:
1. `verifyCode()` should return a short-lived signed reset token instead of just a success message
2. `resetPassword()` should require and validate this reset token
3. Alternative: keep the OTP record with a `verified` flag and check it in `resetPassword()`

### FINDING-11 — HIGH: LoginController tokens have no expiration
**Severity**: HIGH
**Location**: `LoginController.php:76`
**Description**: `$user->createToken('auth_token')->plainTextToken` creates a token with **no expiration**. Guest tokens correctly expire after 30 days (`GuestSignupController.php:140`), and kiosk tokens have no explicit expiry but are revoked on re-login. However, regular user tokens (admin, staff, customer) persist indefinitely.
**Risk**: A stolen admin/staff token remains valid forever. If a device is compromised, the attacker has permanent access.
**Fix**:
```php
$this->token = $user->createToken('auth_token', ['*'], now()->addDays(7))->plainTextToken;
```

### Positive Findings (Auth)
- Kiosk login correctly validates machine status AND linked user status ✓
- Kiosk tokens are scoped with `kiosk:order` ability ✓
- Old kiosk tokens are revoked on re-login (line 81) ✓
- Guest signup prevents privilege escalation (refuses non-guest phone matches) ✓
- Rate limiting on all auth endpoints ✓
- Web session is explicitly logged out after `attempt()` to prevent TransientToken issues ✓

---

## 5. Middleware

### ApiKeyMiddleware Assessment (POSITIVE)
- Uses `config()` instead of `env()` (safe after config:cache) ✓
- Timing-safe comparison is NOT used (`===` is used instead of `hash_equals()`) — this is a **minor** concern since API keys are typically long enough that timing attacks are impractical, but `hash_equals()` would be best practice
- Returns 400 on invalid key (could be 401 for semantic correctness)

### Kernel Assessment (POSITIVE)
- Global `throttle:api` rate limiter applied to all API routes ✓
- `SubstituteBindings` middleware present for route model binding ✓
- `JsonMiddleware` forces JSON responses ✓
- `EnsureFrontendRequestsAreStateful` is correctly commented out (SPA uses token auth, not session) ✓

### Missing Middleware Concern
- No CORS origin validation visible (relies on Laravel's `HandleCors` which needs proper config)
- No `Content-Security-Policy` headers middleware

---

## 6. Vuex Stores (`kioskMenu.js`, `kioskCart.js`)

### State Management Assessment (POSITIVE)
- 5-minute TTL cache with proper invalidation on branch change ✓
- Offline snapshot fallback with freshness check ✓
- Idempotency key generated once per cart session, reused on retry ✓
- `RESET` mutation properly clears all sensitive state ✓
- Offline queue with auto-sync preserves idempotency key ✓
- Price calculations use `parseFloat` with `|| 0` fallback ✓

### Minor Concerns
1. **kioskCart.js line 213-214**: UUID generation uses a clever but non-standard pattern. Consider using `crypto.randomUUID()` where available (supported in all modern browsers).
2. **kioskMenu.js line 169**: `branchParam` is constructed via string concatenation (`&branch_id=${resolvedBranchId}`). While `resolvedBranchId` comes from trusted state, this pattern is fragile. Consider using axios params object.
3. **kioskCart.js**: The `kioskToken` is stored in Vuex state (persisted via vuex-persistedstate). If localStorage is accessible, the token can be extracted. This is acceptable for a kiosk but worth noting.

---

## Summary Table

| ID | Severity | Component | Finding |
|----|----------|-----------|---------|
| F-01 | CRITICAL | routes/api.php | `authcheck` lacks rate limiting and may have inconsistent auth |
| F-02 | HIGH | routes/api.php | Loyalty `check` leaks PII to unauthenticated callers |
| F-03 | MEDIUM | routes/api.php | Coupon checking unauthenticated, brute-forceable |
| F-04 | MEDIUM | routes/api.php | Table ordering has no QR validation |
| F-05 | LOW | routes/api.php | Admin routes lack route-level role middleware |
| F-06 | HIGH | RefreshTokenController | Old tokens not revoked on refresh |
| F-07 | MEDIUM | kioskRoutes.js | Admin route lacks PIN/password guard |
| F-08 | CRITICAL | FrontendOrderService | `show()` leaks order existence via empty array vs 404 |
| F-09 | CRITICAL | FrontendOrderService | `changeStatus()` loose comparison + silent return |
| F-10 | HIGH | ForgotPasswordController | Password reset without prior code verification |
| F-11 | HIGH | LoginController | Auth tokens have no expiration |

---

## Recommended Fix Priority

### Immediate (before next deployment)
1. **F-10**: Fix password reset flow (account takeover risk)
2. **F-08 + F-09**: Fix `show()` and `changeStatus()` to use strict comparison and proper 403 responses
3. **F-06**: Revoke old token in `RefreshTokenController`

### Short-term (within 1 week)
4. **F-01**: Add rate limiting to `authcheck`
5. **F-11**: Add token expiration to `LoginController`
6. **F-02**: Strip PII from unauthenticated loyalty check response

### Medium-term (within 2 weeks)
7. **F-03**: Add auth to coupon checking or per-code lockout
8. **F-07**: Add PIN guard to kiosk admin route
9. **F-04**: Add signed token to table QR ordering
10. **F-05**: Audit admin controllers for permission checks

---

*End of audit report.*
