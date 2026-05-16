# Wave Z — Round 1 — Z6 Auth / RBAC / Sanctum / Authz — Findings

**Auditor** : Z6 (Claude Code RED-team, read-only)
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD** : `c3ba89863`
**Scope** : Verify Sanctum config + token-lifecycle + abilities + RBAC + password policy + pre-auth scopes + RED-team new issues. Cross-validate sister findings POS-A3 and K-002.
**Date** : 2026-05-16
**Method** : Source reads with file:line citations + grep. No code touched.

---

## Verdict synthétique

| Finding | Severity | Status |
|---------|----------|--------|
| POS-A3 — `/pos/quote` + `/pos/walk-in-customer` not behind `permission:pos` | **P1** | **CONFIRMED — UNHEALED** |
| K-002 — `OrderRequest::authorize()` fail-open if token null | **P2** (was P1) | UNHEALED — by design + documented (concur with Z2) |
| NEW Z6-01 — `LoginController` does NOT revoke old tokens on relogin | **P1** | **OPEN** — direct contradiction of CLAUDE.md §9 |
| NEW Z6-02 — Guest signup token over-privileged `['*']` 30 days | **P1** (conditional on `site_guest_login=ENABLE`) | **OPEN** |
| NEW Z6-03 — Password-reset reissues `['*']` ability for any account | **P2** | OPEN |
| NEW Z6-04 — Password policy min:6, no complexity, NIST sub-minimum | **P2** | OPEN |
| NEW Z6-05 — User `$fillable` exposes `branch_id`, `status`, `is_guest` | **P1** | OPEN — mass-assignment privilege escalation surface |
| NEW Z6-06 — No per-request `users.status` revalidation; tokens survive disable up to 480 min | **P1** | OPEN |
| NEW Z6-07 — `password_resets` table has no UNIQUE on email + token reuse window | **P2** | OPEN |
| NEW Z6-08 — `withoutGlobalScope(BranchScope)` used in 20+ post-auth fiscal/reconcile paths | **P2** | Review — not all are pre-auth login flows per CLAUDE.md §9 |
| Doc drift — CLAUDE.md §9 claims `tokenCan('kiosk:order')` in 6+ controllers; actual = 4 controllers | informational | drift |
| Sanctum config | OK | 480 min via env, matches CLAUDE.md §9 |
| Rate limiting on login | OK | dedicated limiters for human + kiosk + forgot-password |

**Aggregate** : 2 P1 cross-validated (POS-A3 + K-002 downgrade), 4 NEW P1 (token sprawl, guest over-priv, mass-assignment, status revalidation), 4 NEW P2. Sanctum core config sound; the breakage is at the controller / model / token-lifecycle edges.

---

## §1 — POS-A3 verification (`/pos/quote` + `/pos/walk-in-customer` permission gap)

### Source

`app/Http/Controllers/Admin/PosController.php:43` :

```php
$this->middleware(['permission:pos'])->only('store');
```

The constructor (lines 31-44) restricts `permission:pos` to **only the `store` method**. The controller exposes additionally :

- `walkInCustomer(Request $request)` — `app/Http/Controllers/Admin/PosController.php:134`
- `quote(...)` — referenced at `routes/api.php:725` and `routes/api.php:1125`.

Route definitions in `routes/api.php:721-728` :

```php
Route::prefix('pos')->name('pos.')->group(function () {
    Route::get('/walk-in-customer', [PosController::class, 'walkInCustomer'])
        ->middleware('throttle:pos-quote')
        ->name('walk-in-customer');                                          // line 722-724
    Route::post('/quote', [PosController::class, 'quote'])
        ->middleware('throttle:pos-quote')
        ->name('quote');                                                     // line 725-727
    Route::post('/', [PosController::class, 'store'])
        ->middleware(['throttle:pos-order-create', 'idempotency']);          // line 728
```

Parent group at `routes/api.php:269` applies `['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation']`. Therefore `quote` and `walk-in-customer` are authenticated but **not gated by `permission:pos`** : any Sanctum-authenticated user (including non-POS roles like Chef, Waiter, Delivery Boy) can call them.

### Exploit surface

- `/api/admin/pos/quote` — exposes price computation for any item/qty/option combination. Information leak (menu pricing visible to e.g. delivery boys), or worse if `quote` performs side-effects (stock check, idempotency cache writes).
- `/api/admin/pos/walk-in-customer` — at line 137 calls `walkInCustomerResolver->resolve()`. If this method creates / restores a sentinel customer row, any authenticated non-POS user can trigger that mutation.

### Verdict POS-A3 : **CONFIRMED — UNHEALED**. P1.

Heal path = add `'quote'` and `'walkInCustomer'` to the `only(...)` list at line 43.

---

## §2 — K-002 verification (cross-check with Z2)

`app/Http/Requests/OrderRequest.php:35-66` :

```php
public function authorize(): bool {
    $user = $this->user();
    if (! $user) { return false; }                              // line 38-40
    // ...
    $token = $user->currentAccessToken();
    if (! $token) { return true; }                              // line 60-63  ← fail-open
    return (bool) $user->tokenCan('kiosk:order');               // line 65
}
```

The fail-open at line 62 is justified in comments lines 50-59 as a test-affordance for `actingAs($user, 'sanctum')` which yields a `TransientToken` whose `currentAccessToken()` returns null.

**Z6 concurs with Z2** : architectural risk (any session-cookie-authenticated request bypasses the ability check), maintainers documented the trade-off. Downgrade P1 → P2. Test-cleanness fix = `$user->tokens()->where('abilities', 'LIKE', '%kiosk:order%')->exists()` instead of `currentAccessToken()` short-circuit.

---

## §3 — Sanctum config + token lifecycle

### Config — OK

`config/sanctum.php:50` :

```php
'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480),
```

Comment at line 48 explicitly cites the CLAUDE.md §9 invariant. ✓

### Token creation inventory (`createToken` call sites)

| File:line | Token name | Abilities | TTL |
|-----------|------------|-----------|-----|
| `app/Http/Controllers/Auth/LoginController.php:87` | `auth_token` | `['*']` | 480 min |
| `app/Http/Controllers/Auth/KioskMachineLoginController.php:98` | `kiosk-token` | `['kiosk:order']` | 480 min |
| `app/Http/Controllers/Auth/RefreshTokenController.php:49` | `auth_token` | preserved from prior token | 480 min |
| `app/Http/Controllers/Auth/GuestSignupController.php:140` | `auth_token` | **`['*']`** | **30 days** |
| `app/Http/Controllers/Auth/ForgotPasswordController.php:165` | `auth_token` | **`['*']`** | 480 min |

### Z6-01 — `LoginController` does NOT revoke old tokens on relogin (P1)

`app/Http/Controllers/Auth/LoginController.php:84-90` :

```php
Auth::guard('web')->logout();
$this->token = $user->createToken('auth_token', ['*'], now()->addMinutes(...))->plainTextToken;
```

No `$user->tokens()->delete()` call before `createToken`. Each successful login mints a new row in `personal_access_tokens` without revoking prior rows. Over months of staff usage, `personal_access_tokens` grows unbounded for each user — token sprawl.

Compare `app/Http/Controllers/Auth/KioskMachineLoginController.php:96` which correctly revokes :

```php
$user->tokens()->where('name', 'kiosk-token')->delete();
$this->token = $user->createToken('kiosk-token', ['kiosk:order'], ...)->plainTextToken;
```

CLAUDE.md §9 stipulates : "**Old tokens revoked à chaque relogin (prevent token sprawl)**". Human login violates this.

**Verdict Z6-01** : OPEN P1. Add `$user->tokens()->where('name', 'auth_token')->delete();` before line 87.

### Z6-02 — Guest signup token over-privileged (P1, conditional)

`app/Http/Controllers/Auth/GuestSignupController.php:140` :

```php
$this->token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
```

A *guest* (phone-only OTP, no full identity verification, role = `CUSTOMER`) receives a token with ability `['*']` valid 30 days. `['*']` satisfies `tokenCan('kiosk:order')` (Sanctum wildcard semantics) — a captured guest token can drive `/api/frontend/order` and any other Sanctum-gated endpoint where authorization relies on `tokenCan(...)`.

The feature is gated at line 91 by `site_guest_login` (Activity::DISABLE → throws). If the setting is ENABLE in prod, the over-privilege is live.

**Verdict Z6-02** : OPEN. P1 if `site_guest_login=ENABLE`; P2 otherwise. Heal = `['kiosk:order']` instead of `['*']`.

### Z6-03 — Password-reset reissues `['*']` (P2)

`app/Http/Controllers/Auth/ForgotPasswordController.php:165` :

```php
$this->token = $user->createToken('auth_token', ['*'], now()->addMinutes(...))->plainTextToken;
```

After successful password reset, any account regardless of prior abilities is granted a `['*']` token. Equivalent to LoginController but worse — the user is silently logged in without going through the throttled `/login` endpoint. Also lacks prior-token revocation (same gap as Z6-01).

**Verdict Z6-03** : OPEN P2. Either remove the auto-login (force redirect to `/login`) or scope abilities to user's role.

### Z6-04 — Password policy `min:6`, no complexity (P2)

All FormRequests + login endpoints enforce `min:6` only :

- `app/Http/Requests/SignupRequest.php:36`
- `app/Http/Requests/CustomerRequest.php:39`
- `app/Http/Requests/AdministratorRequest.php:39`
- `app/Http/Requests/EmployeeRequest.php:39`
- `app/Http/Requests/ChefRequest.php:39`
- `app/Http/Requests/WaiterRequest.php:39`
- `app/Http/Requests/DeliveryBoyRequest.php:39`
- `app/Http/Requests/KioskMachineRequest.php:31`
- `app/Http/Requests/ChangePasswordRequest.php:28-30`
- `app/Http/Requests/UserChangePasswordRequest.php:28-29`
- `app/Http/Controllers/Auth/LoginController.php:48`
- `app/Http/Controllers/Auth/KioskMachineLoginController.php:32`
- `app/Http/Controllers/Auth/ForgotPasswordController.php:123-124`

NIST SP 800-63B recommends 8 char minimum + breach-list check. None of the 13 sites enforce `letters|mixed|numbers|symbols` rules.

**Verdict Z6-04** : OPEN P2. Backlog candidate for V1.0.1 RBAC refactor.

---

## §4 — RBAC / Spatie Permissions

### `permission:settings` coverage

30 controllers gate writes with `permission:settings`. Examples (file:line) :

- `app/Http/Controllers/Admin/PermissionController.php:27`
- `app/Http/Controllers/Admin/RoleController.php:21-22`
- `app/Http/Controllers/Admin/CompanyController.php:18`
- `app/Http/Controllers/Admin/CurrencyController.php:20`
- `app/Http/Controllers/Admin/TaxController.php:21`
- `app/Http/Controllers/Admin/PaymentGatewayController.php:21`
- `app/Http/Controllers/Admin/PaymentTerminalController.php:28`
- `app/Http/Controllers/Admin/KioskMachineController.php:22`
- `app/Http/Controllers/Admin/KioskSetupController.php:20`
- `app/Http/Controllers/Admin/LoyaltySetupController.php:19`
- `app/Http/Controllers/Admin/LicenseController.php:18`
- `app/Http/Controllers/Admin/PrinterController.php:20`

Pattern looks healthy. **Caveat** — many controllers only gate state-mutating methods (`store|update|destroy`) leaving `index|show` open to any authenticated user → information disclosure surface for the V1.0.1 88-endpoint refactor.

### `permission:pos` coverage

11 sites apply `permission:pos`. Notable :

- `app/Http/Controllers/Admin/PosController.php:43` — **only(`store`) — see Z6 §1 POS-A3 gap**
- `app/Http/Controllers/Admin/PrinterController.php:19` — `only('index', 'show', 'testPrint')`
- `app/Http/Controllers/Admin/Pos/FloorplanController.php:16` — class-wide
- `app/Http/Controllers/Admin/Pos/CashDrawerController.php:16` — class-wide
- `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:31` — class-wide
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php:15` — class-wide
- `app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php:16` — class-wide

The class-wide pattern is correct; `PosController.php:43` is the outlier and the root cause of POS-A3.

---

## §5 — User model mass-assignment — NEW Z6-05 (P1)

`app/Models/User.php:42-53` :

```php
protected $fillable = [
    'name', 'email', 'password', 'username', 'phone',
    'branch_id',      // ← multi-tenant boundary
    'country_code',
    'is_guest',       // ← guest vs staff distinction
    'status',         // ← active/disabled flag
    'email_verified_at'
];
```

`$fillable` (not `$guarded`) approach with sensitive fields :

- `branch_id` — multi-tenant boundary; mass-assignment would let any FormRequest with a `User::create($request->all())` or `User::fill($request->validated())` move a user across branches.
- `is_guest` — distinguishes guest customer vs staff account in `GuestSignupController.php:97` (security check at line 97 refuses guest login when `is_guest != Ask::YES`). If an attacker can set `is_guest=NO` on a guest-controlled signup, the guest-account guardrail evaporates.
- `status` — `Status::ACTIVE`/`INACTIVE`/`DEACTIVATED`. Mass-assignable means a user-controlled write could reactivate their own disabled account.

Compare to `app/Http/Controllers/Auth/GuestSignupController.php:111-122` which explicitly *uses* `branch_id => 0`, `is_guest => Ask::YES`, `status => not set` (defaults). But any future controller that does `User::create($request->validated())` without explicit field stripping inherits the risk.

**Verdict Z6-05** : OPEN P1. Heal = move `branch_id`, `is_guest`, `status` to `$guarded` or to a `casts` whitelist, and force explicit assignment.

---

## §6 — Token survival post user-disable — NEW Z6-06 (P1)

`app/Http/Controllers/Auth/LoginController.php:54-60` :

```php
$request->merge(['status' => Status::ACTIVE]);
if (!Auth::guard('web')->attempt($request->only('email', 'password', 'status'))) {
    return new JsonResponse(['errors' => ['validation' => ...]], 400);
}
```

`Status::ACTIVE` is checked **only at login attempt**. Once a Sanctum token is minted (480 min TTL), subsequent requests do NOT re-validate `users.status`. If an admin disables a user account, that user's existing token remains valid for up to 480 minutes.

No middleware in `app/Http/Kernel.php` re-checks `users.status` for Sanctum-authenticated requests. The `verify.api` middleware (`app/Http/Middleware/VerifyEmail.php`) verifies email, not active status (per `app/Http/Kernel.php:76`).

**Exploit** : disgruntled employee's account disabled at T0. They retain full access (`['*']`) until T0+480min unless an admin manually calls `$user->tokens()->delete()`.

**Verdict Z6-06** : OPEN P1. Heal options : (a) add middleware `AbortIfUserDisabled` to the `auth:sanctum` chain; (b) cascade-delete tokens in a User updating observer when `status` transitions to disabled.

---

## §7 — Password reset table — NEW Z6-07 (P2)

`database/migrations/2014_10_12_100000_create_password_resets_table.php:16-20` :

```php
Schema::create('password_resets', function (Blueprint $table) {
    $table->string('email')->index();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});
```

No UNIQUE on `email` or `(email, token)`. No `id` primary key. `ForgotPasswordController::forgotPassword` (`app/Http/Controllers/Auth/ForgotPasswordController.php:37-46`) deletes any existing row before insert, but a race condition between two concurrent requests can leave two valid PINs for the same email simultaneously (TOCTOU). PIN range = 100,000-999,999 (line 43) — 6-digit numeric, 900,000 combinations. Rate limit is `throttle:3,60` (3/hour at `routes/api.php:164`) — strong, so brute-force is hard, but the lack of UNIQUE on email + token type=`varchar` (no length cap visible) allows accidental row duplication.

**Verdict Z6-07** : OPEN P2. Add UNIQUE on email + replace 6-digit PIN with 32-char random token for the verification step.

---

## §8 — `withoutGlobalScope(BranchScope)` — NEW Z6-08 (P2)

20+ call sites grep'd. Justified pre-auth ones (CLAUDE.md §9 compliant) :

- `app/Http/Controllers/Auth/KioskMachineLoginController.php:55, 90` — explicit comment block lines 47-52 justifying pre-auth lookup.
- `app/Console/Commands/EnsureKioskMachineCommand.php:78-80` — console command, no user context, justified.

Post-auth bypass cases — wider scope than CLAUDE.md §9 implies (which says "pre-auth lookups") :

- `app/Http/Controllers/Frontend/PaymentReconcileController.php:143, 194, 232, 247, 288` — payment reconciliation paths (cross-branch reconcile may be intentional but the doc doesn't carve this out).
- `app/Http/Controllers/Frontend/OrderController.php:159, 184` — duplicate-transaction detection across branches.
- `app/Http/Controllers/Admin/PosOrderController.php:108` — `Order::withoutGlobalScope(...)->findOrFail($order)`. If a POS user from branch A passes an order ID from branch B, they fetch it. Authz check should follow but is not visible at this line — needs trace.
- `app/Services/Fiscal/ZReportService.php:337, 589` — daily Z-report close; documented in CLAUDE.md §8 as cross-branch aggregator.
- `app/Services/Fiscal/ZReportCashEnrichmentService.php:54, 77, 154, 181` — same.
- `app/Jobs/CleanupStalePendingKioskOrders.php:30, 47` — background job, no user context, justified.

**Verdict Z6-08** : OPEN P2. The CLAUDE.md §9 wording "pre-auth lookups: `withoutGlobalScope(BranchScope::class)` explicit" undersells how widespread the bypass is in production paths. Recommend either (a) update CLAUDE.md §9 to enumerate the legitimate post-auth cases, or (b) add explicit `branch_id` filtering after the bypass for `PosOrderController::108` style calls.

---

## §9 — Documentation drift

CLAUDE.md §9 claims `tokenCan('kiosk:order')` checks in **6+ controllers**. Actual grep across `app/Http/Controllers/` :

1. `app/Http/Controllers/Frontend/MenuController.php:37`
2. `app/Http/Controllers/Frontend/UpsellController.php:32`
3. `app/Http/Controllers/Frontend/PaymentReconcileController.php:87` (ternary check)
4. `app/Http/Controllers/Frontend/LoyaltyController.php:258, 579` (one controller, two sites)

Plus the FormRequest layer :

5. `app/Http/Requests/OrderRequest.php:65`

= **4 distinct controllers + 1 FormRequest**. CLAUDE.md §9 "6+" claim is mildly inflated. **Drift, informational only.**

---

## §10 — Rate limiting — OK

`app/Providers/RouteServiceProvider.php` (lines 60-149) configures :

- `login-lockout` — 10/10min keyed by email|IP (line 130-149)
- `kiosk-login` — 30/min keyed by username|IP (line 115-128) with explanatory comment for the kiosk-machine retry pattern
- `forgot-password` — 3/60min (`routes/api.php:164`)
- `signup/otp` — 5/min (line 173, 184)
- `signup/verify` — 3/5min (line 176, 189)
- `pos-quote` — 120/min (`RouteServiceProvider:93`)
- `pos-order-create` — 60/min (`RouteServiceProvider:97`)

Sound configuration. **Caveat** — `signup/register` is 10/min (`routes/api.php:179`) which is generous for account creation; consider 3/hour for V1.0.1 tightening.

---

## §11 — CSRF — OK with caveats

`app/Http/Middleware/VerifyCsrfToken.php` whitelist :

- `/payment/sslcommerz/*`, `/payment/paytm/*`, `/payment/cashfree/*`, `/payment/phonepe/*`, `/payment/iyzico/*`, `/payment/pesapal/*` — gateway callbacks, signature-verified at controller layer.
- `/payment/stripe-webhook/*`, `/payment/senangpay-webhook/*` — comment lines 17-22 documents signature verification (Stripe-Signature header / SenangPay HMAC).

Whitelist is small, justified. No wildcard. Sanctum API routes use stateless tokens (no CSRF needed).

---

## §12 — Summary for Round 1 aggregator

**Confirmed sister findings** : 2 (POS-A3 P1, K-002 P2-downgraded).
**NEW Z6 findings** : 8 (4 P1 + 4 P2).
**Healable in Sprint 4 hardening (low blast radius)** :

1. **Z6-01** — Add `$user->tokens()->where('name','auth_token')->delete()` to `LoginController:87`.
2. **Z6-02** — Change `['*']` → `['kiosk:order']` in `GuestSignupController:140`.
3. **POS-A3** — Extend `only(...)` to `['store', 'quote', 'walkInCustomer']` in `PosController:43`.
4. **Z6-05** — Move `branch_id`, `is_guest`, `status` from `$fillable` to `$guarded` in `app/Models/User.php`.

**Requires architectural decision** : Z6-06 (per-request user-status revalidation) — likely middleware-based; affects every Sanctum-authenticated request, performance impact must be measured.

**Backlog V1.0.1** : Z6-03, Z6-04, Z6-07, Z6-08, doc drift §9.

---

**End of Z6 findings. ~310 lines. No code modified. Read-only audit.**
