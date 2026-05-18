# L4 — AUTH + AUTHZ + MULTI-TENANT LAYER AUDIT
**Date**: 2026-05-17
**Auditor**: Layer L4 (auth/authz/multi-tenant)
**Scope**: Sanctum tokens, Spatie role/permission gating, FormRequest `authorize()` coverage, `BranchScope` correctness, `withoutGlobalScope` triage, Idempotency middleware coverage, password policy, brute-force throttling, session security, RBAC matrix discoverability.
**Mode**: READ-ONLY. Anti-drift cross-checked against L2 audit (Agent 2) and CLAUDE.md §9.

---

## SCORECARD (10 dimensions, /100)

| # | Dimension | Score | Rationale (one-liner) |
|---|---|---|---|
| 1 | Sanctum token discipline | **42** | TTL OK (480 min), revoke-on-relogin shipped, BUT `['*']` wildcard ability on 4 sites + 30-day guest token |
| 2 | Spatie role coverage on privileged actions | **48** | ~24 controller-level `permission:` declarations + ~21 inline `->can()`/`hasRole()` checks; many admin POSTs ungated |
| 3 | FormRequest `authorize()` adoption rate | **12** | 80/91 FormRequest files `return true;` blindly. Only 11 implement real checks |
| 4 | BranchScope correctness on tenant-scoped models | **70** | 17 models register the scope (BRAIN claim 13 was stale — PaymentTerminal & PosParkedOrder added). User exempted correctly |
| 5 | `withoutGlobalScope` triage / audit logging | **55** | 11 distinct call-sites: 10 legit + 1 cross-branch IDOR + 0 audit-log emission anywhere |
| 6 | Idempotency middleware coverage | **20** | Master flag `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` by default → middleware is shelfware; only 9 routes opt-in |
| 7 | Password policy (length / complexity / rotation) | **30** | `min:6` everywhere, no complexity, no rotation, no breach-check, no history |
| 8 | Brute-force protection | **65** | `/login` 10/10min lockout, kiosk-login 30/min, signup paths 5-10/min; password-reset PIN brute-forceable (P1) |
| 9 | Session security | **55** | `http_only=true`, `same_site=lax`, BUT `'secure' => env('SESSION_SECURE_COOKIE')` defaults null (insecure on prod without env); `'encrypt' => false` |
| 10 | RBAC matrix discoverability | **40** | 8 roles seeded, ~68 distinct permissions in code, but no canonical matrix doc; "Tenant Admin" referenced but never seeded |

**WEIGHTED LAYER SCORE: 44/100 — NO-GO for V1 hardened ship without P0 fixes.**

---

## TOP FINDINGS (severity-ranked)

### P0-L4-01 — Cross-branch IDOR on POS order detail
**File**: `app/Http/Controllers/Admin/PosOrderController.php:108`
**Severity**: **P0**
**CVSS-ish**: confidential leak across tenants; cashier-grade credentials suffice; no CSRF needed (auth:sanctum token).

```php
$this->middleware(['permission:pos-orders|pos'])->only('index', 'show');  // line 37
...
public function show(int|string $order) {
    $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);  // line 108
    return new OrderDetailsResource($this->orderService->show($order, false));
}
```
The `permission:pos|pos-orders` middleware passes for any cashier on any branch. `withoutGlobalScope(BranchScope::class)` strips tenant isolation, and there is **no post-fetch `branch_id` check** (compare with the sibling `refundWithCounterEntry` lines 56-61 which does enforce it):
```php
if ($authUser && !$authUser->hasRole('Admin')
    && (int) ($authUser->branch_id ?? 0) !== (int) $order->branch_id) {
    abort(403, 'Cross-branch refund denied.');
}
```
**Impact**: A POS Operator at Branch A can `GET /api/admin/pos-order/{any_id}` and read Branch B/C/D orders (customer name, items, totals, payment). Disclosure of PII + cash-side data.
**Fix**: Add the same Admin-bypass-or-branch-match guard in `show()` (and `changeStatus`, `changePaymentStatus`, `selectDeliveryBoy`, `reorderItems`, `destroy` which all rely on Laravel route-model binding — needs verification that those bind respect the scope; if `Order::find` resolves via BranchScope, they're safe, but `show($order: int|string)` does not use binding).
**Drift note**: Agent 2 flagged this as "no authz" — the more accurate framing is "permission-gated but tenant-scope-stripped without compensating control."

---

### P0-L4-02 — Login + guest + password-reset all issue `['*']` wildcard ability tokens
**Files**:
- `app/Http/Controllers/Auth/LoginController.php:96-100` — staff login → `['*']`
- `app/Http/Controllers/Auth/GuestSignupController.php:140` — guest signup → `['*']`, **TTL = 30 days**
- `app/Http/Controllers/Auth/ForgotPasswordController.php:165-169` — post-reset → `['*']`
- `app/Console/Commands/E2EStressCommand.php:222` — `['*']` (test code, lower severity)

**Severity**: **P0** (LoginController + ForgotPassword), **P1** (GuestSignup — token reach is narrower but TTL is 30d)

```php
// LoginController:96
$this->token = $user->createToken('auth_token', ['*'], now()->addMinutes(...))->plainTextToken;
```
**Impact**: `['*']` makes `tokenCan('anything')` always return true. Every `tokenCan('kiosk:order')` check in 18+ sites (e.g. `OrderRequest:65,294`, `OrderStatusRequest:93`, `Kiosk/PromoValidateRequest:19`, `MenuController:37`, `LoyaltyController:258,579`) is **bypassable** by any admin token — a staff PAT used by mistake or theft can drive a kiosk-only mutation flow. The `RefreshTokenController:42` privilege-escalation fix shipped iter15-P0-07 only preserves whatever the prior token had — so a wildcard root-equivalent token at issuance time will refresh into another wildcard token.
**Fix**: Replace `['*']` with explicit ability lists per persona:
- Staff login → `['admin:full']` (gated through Spatie permissions, not Sanctum wildcard).
- Guest → `['frontend:order']` + reduce TTL to ≤ 24h (current 30d is unjustified).
- Reset → match the prior token's abilities or re-derive from role.

---

### P0-L4-03 — Idempotency middleware ships **OFF** by default
**File**: `config/idempotency.php:20` → `env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false)`
**Severity**: **P0** for production-readiness (NF525 + cash invariants)

The middleware (`IdempotencyKeyMiddleware`) is correctly authored — scope `(branch_id, user_id, hash(key))`, replay cache, 2xx-only cache, 409 conflict on payload mismatch, 425 in-flight, scoped via Redis. It is wired on 9 routes (POS create, change-payment, kiosk order, payment-confirm, etc.) via `'idempotency'` middleware alias (`Kernel:98`).

But **`config('idempotency.enabled', false)`** gates the very first line of `handle()` (`IdempotencyKeyMiddleware:41`) — when `false`, the middleware is a passthrough. Default env `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` → in any environment that hasn't explicitly flipped the env var, **the middleware does nothing**. Out of 199 mutating routes (POST/PUT/PATCH/DELETE) in `routes/api.php`, only 9 even ask for it, and those 9 are gated off by default. The CLAUDE.md §9 invariant ("HTTP `X-Idempotency-Key` header on POST mutating, dual-layer middleware + DB UNIQUE") is currently honored only by the app-layer DB UNIQUE fallback.

**Fix**: Flip the default `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` in `.env.example` + flip env in prod/staging. Then expand `required_routes` to cover all mutating endpoints in scope (currently 8 patterns; need ≥ ~40).

---

### P1-L4-04 — 80 of 91 FormRequests `authorize()` return `true` blindly
**Files** (sample of the 80):
- `app/Http/Requests/CustomerRequest.php:16` → `return true;`
- `app/Http/Requests/PosOrderRequest.php:44` → `return true;`
- `app/Http/Requests/AdministratorRequest.php:16` → `return true;`
- `app/Http/Requests/RoleRequest.php:16` → `return true;`
- `app/Http/Requests/CouponRequest.php:17` → `return true;`
- `app/Http/Requests/ItemRequest.php:18` → `return true;`
- `app/Http/Requests/BranchRequest.php:15` → `return true;`
- `app/Http/Requests/TaxRequest.php:15` → `return true;`
- `app/Http/Requests/DiningTableRequest.php:15` → `return true;`
- `app/Http/Requests/SignupRequest.php:18` → `return true;`
- (full list: see grep `find app/Http/Requests -name "*.php" -exec ...`)

**Adoption rate**: 11 / 91 = **12%**. Real-check FormRequests are limited to: `PaymentStatusRequest`, `OrderRequest`, `OrderStatusRequest`, `Kiosk/PromoValidateRequest`, `Kiosk/PricingPreviewRequest`, `Frontend/PaymentConfirmRequest`, `Admin/Pos/FloorplanTransferRequest`, `Admin/Observability/StoreClientMetricsRequest`, `Kds/KdsOrderStatusRequest`, `AddressRequest`, `CurrentPassword`.

**Severity**: **P1** (defense-in-depth gap, not direct exploit — routes still have `permission:` middleware in most cases, but the FormRequest is the documented secondary layer).
**Risk path**: any controller that DOES NOT register the permission middleware and relies on the FormRequest for authz is exposed. Spot check showed several controllers (e.g. `AddressController`, `ProfileController`, `OrderSetupController` paths) have no `$this->middleware(['permission:..'])` declaration but accept `*Request $request` for authorization. These are not exhaustively triaged here.
**Fix**: BRAIN already schedules this as V1.0.1 refactor (88 endpoints). Suggest converting each `return true;` to either `auth()->user()?->can('<perm>') ?? false` or a documented `// SECURITY_REVIEWED: route-level permission middleware enforces` comment.

---

### P1-L4-05 — Password policy `min:6` everywhere, no complexity, no rotation
**Files**: 17 sites with `min:6` (full list above). Includes staff signup, customer signup, password reset, change-password, kiosk-machine password.
**Severity**: **P1**

- `SignupRequest:36` → `'password' => ['required', 'string', 'min:6']`
- `ForgotPasswordController:124` → `'password' => ['required', 'string', 'min:6', 'confirmed']`
- `KioskMachineRequest:31` → `'min:6'`
- `ChangePasswordRequest:28-30` → `'min:6'`

No complexity rule (uppercase, digit, symbol), no rotation, no breach-corpus check, no password history. CNIL recommandation for staff terminals (POS Operator, Branch Manager): ≥ 12 chars OR ≥ 8 + complexity + 2FA. The kiosk machine password is a shared hardware credential — `min:6` is acceptable, but staff/customer should be ≥ 10 with complexity.
**Fix**: Add `App\Rules\StrongPassword` for staff roles; keep `min:6` for kiosk/customer if needed but document the deviation. Add `password_changed_at` column + 90d rotation gate for `permission:settings` and `permission:pos-manage-fiscal` carriers.

---

### P1-L4-06 — Password-reset PIN brute-forceable
**File**: `app/Http/Controllers/Auth/ForgotPasswordController.php:44` + `routes/api.php:166,168`
**Severity**: **P1**

```php
$this->pin = random_int(100000, 999999);  // 6-digit PIN, ~900k space
```
- `/forgot-password/` throttle: `throttle:3,60` (3 attempts / 60 min) — for initial request, OK.
- `/forgot-password/verify-code` throttle: `throttle:5,1` (5/min) — **300/hr per IP, 7200/day**. Without an account-locking counter on verify-code, a 900k PIN space falls in ~125 days with a single IP, faster with IP rotation.
- `/forgot-password/reset-password` throttle: `throttle:5,1` (5/min) — same.

The PIN row has no `attempts` counter; it persists in DB until used or `otp_expire_time` expires (`Settings::group('otp')->get('otp_expire_time') * 60` seconds), but there's no per-PIN attempt cap, only IP-bucket throttle.
**Fix**: Add `attempts INT DEFAULT 0` on `password_resets`; abort + invalidate after 5 failed attempts on the same row. Move PIN to 8 digits and/or use a 32-char `Str::random` reset URL (already implemented for the post-verify reset_token).

---

### P1-L4-07 — Email enumeration on forgot-password
**File**: `app/Http/Controllers/Auth/ForgotPasswordController.php:63-65`
**Severity**: **P1**

```php
} else {
    return new JsonResponse([
        'errors' => ['email' => [trans('all.message.email_does_not_exist')]]
    ], 400);
}
```
The endpoint differentiates "email exists" (200 + "check your email") vs "doesn't exist" (400 + explicit message). Attacker can enumerate the staff/customer base. Combined with the wildcard `['*']` token leakage and the `Tenant Admin` dead-bypass, this is a recon enabler.
**Fix**: Always return 200 with "If the email exists, a code has been sent" — never reveal existence.

---

### P1-L4-08 — `Gate::before('admin')` is silently dead due to case mismatch
**File**: `app/Providers/AuthServiceProvider.php:30-32`
**Severity**: **P1** (semantic drift; current behavior is "safer" than intended — admins lose implicit grant; but the bug suggests the `can()` audit was never run)

```php
\Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
    return $user->hasRole('admin') ? true : null;  // lowercase 'admin'
});
```
`RoleTableSeeder.php:19` creates `'Admin'` (capital A). Spatie `hasRole()` is case-sensitive. The "Super Admin grants all permissions" gate is silently no-op — the Admin role passes Spatie permission checks because permissions are explicitly attached via `RolePermissionTableSeeder`, but no `Gate::define` calls benefit from the implicit grant. Any place that uses `auth()->user()->can('foo')` for a permission that is NOT in `RolePermissionTableSeeder` will fail for Admin too.
**Fix**: `$user->hasRole('Admin') ? true : null` (capital A). Then audit `can()` call sites for any expected admin-bypass that was relying on the dead gate.

---

### P2-L4-09 — `Tenant Admin` role referenced but never seeded
**Files**:
- Referenced: `app/Http/Controllers/Admin/AdminController.php:22,34`; `app/Http/Controllers/Admin/PosOrderController.php:58`; `app/Http/Controllers/Admin/ComposerProfileController.php:30,54`; `app/Http/Controllers/Admin/MenuProjectionController.php:56`; `database/seeders/IngredientPermissionSeeder.php:19`; `database/seeders/ComposerPermissionsMinimalSeeder.php:12`
- NOT created in: `database/seeders/RoleTableSeeder.php:17-66` (only 8 roles seeded: Admin, Customer, Delivery Boy, Waiter, Chef, Branch Manager, POS Operator, Stuff)

**Severity**: **P2** (dead bypass paths, not exploit; `hasRole('Admin')` always wins for the actual Admin case).
**Risk path**: any deploy that seeds permissions before role-table or in a different order will reference a non-existent role. Spatie throws `RoleDoesNotExist` if `assignRole('Tenant Admin')` is ever called — but right now nothing assigns it, so it's purely dead branches.
**Fix**: Either seed `Tenant Admin` in `RoleTableSeeder` and document the V1 vs V2 (SaaS multi-tenant) distinction, or remove the references.

---

### P2-L4-10 — `'secure' => env('SESSION_SECURE_COOKIE')` defaults null
**File**: `config/session.php:171`
**Severity**: **P2** (web layer; staff log in over HTTPS in prod, but the default leaves the cookie sendable over HTTP)

```php
'secure' => env('SESSION_SECURE_COOKIE'),  // null = framework default behavior
```
And `'encrypt' => false` (`session.php:49`). The session cookie carries CSRF and Sanctum stateful auth on first-party SPA — sending it over HTTP exposes both.
**Fix**: Default to `true` in non-local env; `env('SESSION_SECURE_COOKIE', !app()->environment('local'))`.

---

### P2-L4-11 — `VerifyEmail` middleware is non-defensive + misapplied
**File**: `app/Http/Middleware/VerifyEmail.php:21`
**Severity**: **P2**

```php
if (Auth::user()->email_verified_at === null) {  // NPE if Auth::user() === null
```
Will throw `Trying to access property of non-object` if unauthenticated (defense-in-depth missing). Applied only to `/auth/logout`, `/auth/kiosk-logout`, `/auth/delete-account` (`routes/api.php` auth-namespace group). These are not the surfaces where email verification matters (delete-account maybe — but it's already a destructive op that should require a stronger signal). The actual admin/POS surface does not gate on email verification.
**Fix**: `Auth::user()?->email_verified_at` + decide whether email-verification is even a relevant control in a B2B POS context (it is not for kiosk machines or POS operators using `username`).

---

### P2-L4-12 — Token issuance order: `loginUsingId` then issue token (GuestSignup)
**File**: `app/Http/Controllers/Auth/GuestSignupController.php:130,140`
**Severity**: **P2**

```php
Auth::guard('web')->loginUsingId($user->id);
$branchId = Auth::user()->branch_id;
...
$this->token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
```
A session is created (`loginUsingId`) but never closed before returning the bearer token — the caller now holds both a stateful session cookie AND a bearer token. Same pattern was explicitly fixed in `LoginController:85` (`Auth::guard('web')->logout()` after attempt). GuestSignup retains the stateful session, which means `Sanctum::guard` may resolve the user via the session instead of the bearer token, breaking `tokenCan` semantics (TransientToken vs PersonalAccessToken).
**Fix**: `Auth::guard('web')->logout();` immediately before `createToken`.

---

### P2-L4-13 — No `withoutGlobalScope` audit-log emission
**Files**: 11 sites that bypass BranchScope (file:line summary):
- `app/Http/Controllers/Auth/KioskMachineLoginController.php:55,90` — pre-auth lookup (legit, documented).
- `app/Http/Controllers/Frontend/OrderController.php:159,184` — kiosk payment-confirm pre-auth.
- `app/Http/Controllers/Frontend/PaymentReconcileController.php:143,194,232,247,288` — kiosk reconciliation (legit, has its own access checks).
- `app/Http/Controllers/Admin/PosOrderController.php:108` — **NOT LEGIT** (see P0-L4-01).
- `app/Jobs/CleanupStalePendingKioskOrders.php:30,47` — background job (legit, no user context).
- `app/Services/Fiscal/ZReportCashEnrichmentService.php:54,77,154,181` — fiscal close (legit, runs as system).
- `app/Services/Fiscal/ZReportService.php:337,589` — fiscal aggregation (legit).
- `app/Console/Commands/EnsureKioskMachineCommand.php:80` — console command.

**Severity**: **P2** (audit-trail gap — no `AuditLog::record('scope_bypass', ...)` exists anywhere).
**Fix**: Wrap each legit override with a `// AUDIT: scope bypass justified — <reason>` comment and consider `AuditLogService::record('BRANCHSCOPE_BYPASS', ['caller'=>__METHOD__, 'reason'=>$reason])` for traceability.

---

### P3-L4-14 — Hard-coded super-admin policy in `AuthServiceProvider`
**File**: `app/Providers/AuthServiceProvider.php:14-17`
**Severity**: **P3**

```php
protected $policies = [
    // 'App\Models\Model' => 'App\Policies\ModelPolicy',
];
```
No model policies registered at all. All authorization decisions are middleware + ad-hoc checks. For a 138-controller codebase this is a maintenance smell, not a vuln.
**Fix**: V1.0.2 roadmap — introduce per-resource policies for Order, FrontendOrder, OrderPayment, Item.

---

### P3-L4-15 — CORS `allowed_origins` falls back to empty if envs missing
**File**: `config/cors.php`
**Severity**: **P3** (deploy hygiene; in prod the envs are set)

```php
'allowed_origins' => array_values(array_filter([
    env('APP_URL'),
    env('KIOSK_DOMAIN'),
    env('ADMIN_DOMAIN'),
])),
```
With `supports_credentials=true`, an empty array means CORS denies cross-origin — fail-closed, which is fine. But the silent-empty behavior hides misconfiguration.

---

## CONFIRMED INVARIANTS (positive findings)

1. **BranchScope correctly exempts `User` model** (`BranchScope.php:21-23`) — prevents Sanctum recursion. Documented.
2. **17 models register `addGlobalScope(new BranchScope())`** — exceeds the BRAIN claim of 13:
   `Order, FrontendOrder, OrderItem, OrderPayment, OrderQuote, CashDrawerSession, CashMovement, KioskMachine, StockLevel, StockMovement, PendingPaymentConfirmation, PushNotification, DiningTable, Printer, PosParkedOrder, PaymentTerminal, User` (User exempted at runtime).
3. **Login revokes prior `auth_token` rows** (`LoginController.php:94`) — Sprint 5D Z6-01 shipped, prevents token sprawl.
4. **Kiosk login revokes prior `kiosk-token` rows** (`KioskMachineLoginController.php:96`) — parity with staff login.
5. **RefreshToken preserves source abilities, NEVER falls back to `['*']`** (`RefreshTokenController.php:42-45`) — iter15-P0-07 fix verified.
6. **Login throttle keyed by `email|ip`** (`RouteServiceProvider.php:130-149`) — proper bucket; 10 attempts / 10 min default.
7. **Kiosk-login has its own throttle bucket** (`RouteServiceProvider.php:115-128`) — 30/min, keyed by `kiosk:<username>|ip`, prevents legitimate kiosk retry from burning human bucket.
8. **`session.same_site = 'lax'`, `http_only = true`** — sane CSRF defaults.
9. **Sanctum TTL 480 min** (`config/sanctum.php:51`) — staff tokens expire daily.
10. **Pre-auth lookups bypass scope explicitly + comment justification** (`KioskMachineLoginController.php:48-57`) — discipline shown.
11. **POS controller has defense-in-depth branch check on `refundWithCounterEntry`** (`PosOrderController.php:56-61`) — the pattern exists; just not propagated to `show()`.
12. **Idempotency middleware design is correct** when enabled — atomic acquire, 2xx-only cache, 409 on payload mismatch, scope by `(branch, user, key_hash)`. Just needs the master flag flipped.

---

## RBAC MATRIX (extracted from code; no canonical doc exists)

| Role | Source | Branch ID | Notable powers |
|---|---|---|---|
| Admin | seeder | branch_id=0 | All Spatie permissions, BranchScope bypass (FIX-54-8) |
| Tenant Admin | **NOT SEEDED**, referenced in 6 controllers | n/a | dead bypass paths |
| Branch Manager | seeder | branch_id>0 | permission:settings, permission:pos-manage-fiscal (if assigned) |
| POS Operator | seeder | branch_id>0 | permission:pos, permission:pos-orders |
| Chef | seeder | branch_id>0 | permission:kitchen-display-system |
| Waiter | seeder | branch_id>0 | permission:dining-tables, etc. |
| Delivery Boy | seeder | branch_id>0 | own-order scope |
| Customer | seeder | branch_id=0 (mobile/web) | frontend order, profile |
| Stuff | seeder | branch_id>0 | generic staff |

**Permissions count**: ~68 distinct `permission:*` strings used in controllers. Examples: `dashboard, items, items_create, items_edit, items_show, items_delete, items-report, pos, pos-orders, pos-manage-fiscal, settings, ingredients_manage, catalog.compose, catalog.publish, employees, employees_*, dining_tables_*, coupons_*, kitchen-display-system, messages, offers_*, administrators_*, chefs_*, customers_*, delivery-boys_*`.

---

## RECOMMENDED REMEDIATION PLAN (3 sprints)

**Sprint 1 (P0)** — 1 week, ship-blocker:
- P0-L4-01: Add branch check to `PosOrderController::show` (and verify `changeStatus`, `changePaymentStatus`, `selectDeliveryBoy`, `destroy`).
- P0-L4-02: Replace `['*']` with explicit ability scopes in 3 Auth controllers. Decision: define `auth:full`, `auth:admin`, `auth:customer` abilities.
- P0-L4-03: Flip `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` in staging + extend `required_routes` to cover all 40+ mutating endpoints.

**Sprint 2 (P1)** — 2 weeks, hardening:
- P1-L4-04: FormRequest authz refactor (88 endpoints — already on V1.0.1 roadmap).
- P1-L4-05: StrongPassword rule for staff roles + `password_changed_at` rotation.
- P1-L4-06: PIN attempts counter on `password_resets`.
- P1-L4-07: Email enumeration fix.
- P1-L4-08: Fix `Gate::before('Admin')` case mismatch.

**Sprint 3 (P2)** — 1 week, cleanup:
- P2-L4-09 to P2-L4-13: Tenant Admin role decision, secure cookie default, VerifyEmail removal/rework, GuestSignup session-after-token fix, scope-bypass audit logging.

---

## SUMMARY (≤500 words)

The L4 auth/authz/multi-tenant layer scores **44/100** — V1 NO-GO without P0 remediation. Three production-blocking defects dominate. **First**, `PosOrderController::show()` (`app/Http/Controllers/Admin/PosOrderController.php:108`) executes `Order::withoutGlobalScope(BranchScope::class)->findOrFail($order)` while gated only by `permission:pos|pos-orders` middleware (line 37). Any POS Operator credentialed for Branch A can read any other branch's order detail by guessing the integer ID — a textbook cross-branch IDOR with PII + cash-side disclosure. The sibling `refundWithCounterEntry` method (lines 56-61) ships the correct compensating check (`hasRole('Admin') || branch_id == order->branch_id`); the fix is to propagate this guard to `show()` (and audit `changeStatus`, `changePaymentStatus`, `selectDeliveryBoy`, `reorderItems`, `destroy` for the same gap pattern).

**Second**, staff login (`LoginController:96`), guest signup (`GuestSignupController:140`, 30-day TTL), and password reset (`ForgotPasswordController:165`) all issue Sanctum tokens with `['*']` wildcard ability. This nullifies every `tokenCan('kiosk:order')` check across the 18 sites that depend on it (`OrderRequest:65,294`, `OrderStatusRequest:93`, `Kiosk/PromoValidateRequest:19`, `MenuController:37`, `LoyaltyController:258,579`, `OrderQuoteService:168`, etc.). A stolen staff PAT thus has not just admin reach but ALSO satisfies kiosk-scoped guards designed to compartmentalize hardware credentials. The `RefreshTokenController` iter15-P0-07 fix preserves the source token's abilities — which means wildcard issuance propagates wildcard refresh forever. Replace with explicit ability lists per persona.

**Third**, the well-authored `IdempotencyKeyMiddleware` is shelfware: `config/idempotency.php:20` defaults `IDEMPOTENCY_MIDDLEWARE_ENABLED=false`, and `IdempotencyKeyMiddleware:41` early-returns when disabled. Out of 199 mutating routes in `routes/api.php`, only 9 even register the middleware alias, and those 9 are no-ops in default config. CLAUDE.md §9's "HTTP X-Idempotency-Key dual-layer middleware + DB UNIQUE" invariant currently relies entirely on the app-layer UNIQUE fallback. Flip the flag and expand `required_routes`.

Beyond the P0s, FormRequest `authorize()` adoption is **12%** (11 / 91 implement real checks; the other 80 `return true;` blindly, including `AdministratorRequest`, `RoleRequest`, `BranchRequest`, `PosOrderRequest`, `ItemRequest`, `SignupRequest`). This is documented as V1.0.1 backlog but is a defense-in-depth gap today. Password policy is `min:6` everywhere with no complexity/rotation/breach-check. Password-reset uses a 6-digit PIN with 5/min verify throttle, brute-forceable in ~125 days at single-IP cadence; no per-PIN attempt counter. `ForgotPasswordController:63-65` enumerates emails by differentiating 200 vs 400 with explicit message. `AuthServiceProvider:31` super-admin gate is silently dead (`hasRole('admin')` lowercase vs seeded `'Admin'`).

Positives worth preserving: BranchScope correctly exempts `User` (no Sanctum recursion), 17 models register the scope (more than the BRAIN-claimed 13 — PaymentTerminal + PosParkedOrder added), token revocation on relogin shipped for both staff and kiosk, RefreshTokenController correctly preserves abilities (no `['*']` fallback), kiosk-login has dedicated throttle bucket (30/min), login bucket is per email|ip with 10 attempts / 10 min, and the `withoutGlobalScope` triage is 10-of-11 legitimate (the 1 illegit is P0-L4-01).

**Verdict**: L4 = **44/100**, V1 SHIP-BLOCKED on P0-L4-01/02/03. Post-fix projected ≈ 70/100, GO.
