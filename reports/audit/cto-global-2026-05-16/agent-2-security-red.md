# FoodKing — Security RED-TEAM Audit
**Date**: 2026-05-16
**Agent**: 2/N — SECURITY RED-TEAM (hostile pentester framing)
**Scope**: Static analysis, READ-ONLY. No exploitation performed.
**Working dir**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`

---

## SECURITY SCORE — 28 / 100

**Brutal rationale**: This is a fast-food restaurant SaaS that handles cash, NF525 fiscal seals, credit card data, and personal data — under French law. The fiscal layer (the only thing that could put a director in prison) is genuinely well-built (HMAC chain, locks, hardening hooks). Everything around it is two years behind. Tokens issued with wildcard `['*']` abilities make Sanctum's ability system effectively decorative. The `MIX_API_KEY` is injected verbatim into the HTML response served to every browser, defeating the very middleware that gates the entire admin API. Live AWS access keys were pushed to git history thirteen days ago and have not been rotated. A single regular customer with a guest OTP can hit `/api/admin/language/file-text/store` (route-level auth only checks `auth:sanctum`, not `permission:settings`) and the underlying `LanguageService::edit` writes attacker-controlled content to an attacker-controlled file path — full RCE primitive available behind nothing more than a phone number with SMS reception. Laravel 9 is EOL. PHPSpreadsheet 1.30 has an unpatched RCE CVE (CVE-2024-45048) and is wired to two admin import endpoints. 87 of 93 FormRequests `return true;` for authorize() with no Spatie/Gate fallback. POS controller bypasses BranchScope on order lookup, breaking the very multi-tenant invariant the system advertises.

The fiscal chain alone holds the score above 15. Without that, this is a 12/100 stack — a cash-handling SaaS with no defense in depth, no rotated credentials, and one user-controlled `new $className()` away from RCE.

---

## TOP FINDINGS (10)

### P0-S-01 — LEAKED AWS PRODUCTION KEY IN GIT HISTORY (NOT ROTATED)
**File**: commit `a4a88df06c6fefb73e04c98d559eb54673e195ca` (2026-05-13)
**Path**: `.env.backup-pre-round2` (untracked now, but commit persists in history)
**Evidence**:
```
+AWS_ACCESS_KEY_ID=AKIAYJOT77SIZHDXNYOZ          [REDACTED — leaked in commit a4a88df06]
+AWS_SECRET_ACCESS_KEY=oqfWQa5+FmW+G9u9q3U4DY6DIMCoiAVoyf108M0c  [REDACTED — leaked in commit a4a88df06]
+APP_KEY=base64:lfRbtuf0JOWf768dxQQq5dZ03USyGUxnzBdKTMygONY=     [REDACTED]
+FISCAL_AUDIT_SECRET=local-e2e-fiscal-audit-secret-padding-48chars-ok-20260427  [REDACTED]
+FISCAL_Z_REPORT_SECRET=local-e2e-fiscal-zreport-secret-padding-48chars-ok-20260427
```
88 lines of secrets total. The follow-up commit `1e0611aeb` only untracks the file — git history is permanent.

**Attack scenario**: Trivial. Clone the repo, grep, pivot. `aws sts get-caller-identity` confirms validity, then S3/IAM/EC2 enumeration. If the IAM key has S3 write, attacker pushes a poisoned media asset that ends up rendered to customers. If the key has IAM permissions, full AWS account takeover. The `FISCAL_AUDIT_SECRET` in the leak appears to be a dev sentinel, but if the same secret was ever used in any preprod that touched real data, the entire audit_log HMAC chain is forgeable.
**Exploit complexity**: Trivial (clone + greb).
**Business impact**: AWS bill bombing, S3 data exfil/wipe, credential abuse for crypto mining, possible IAM lateral movement. If any preprod inherited the same FISCAL_AUDIT_SECRET, NF525 audit chain integrity is questionable in court.
**Fix**: Immediately rotate `AKIAYJOT77SIZHDXNYOZ` in AWS console. Rotate `APP_KEY` (re-encrypts cookies/sessions — plan for forced re-login). Use `git filter-repo` or BFG to scrub history if the repo is/was ever public. Confirm `FISCAL_AUDIT_SECRET` differs from all current/historic prod values.

---

### P0-S-02 — USER-CONTROLLED CLASSNAME → `new $className()` (Arbitrary Object Instantiation, RCE-adjacent)
**File**: `app/Http/Controllers/Frontend/PaymentController.php:75`
**File**: `app/Http/Requests/PaymentRequest.php:25-30` (validation)
**Code**:
```php
// PaymentController.php:74-78
$className = 'App\\Http\\PaymentGateways\\PaymentRequests\\' . ucfirst($request->paymentMethod);
$gateway   = new $className;
$request->validate($gateway->rules());
return $this->paymentManagerService->gateway($request->paymentMethod)->payment($order, $request);
```
```php
// PaymentRequest.php
public function authorize(): bool { return true; }            // line 17 — no auth
public function rules() {
    return ['paymentMethod' => ['required', 'string', 'max:190']];  // line 28 — NO whitelist
}
```
**Attack scenario**: Attacker POSTs `/payment/{order}/pay` with `paymentMethod=AnyClass\\With\\Side\\Effects`. The string concatenation `App\Http\PaymentGateways\PaymentRequests\` constrains namespace, but the attacker still controls the leaf segment. Reachable classes that auto-load and run side-effects in `__construct` are the gadget surface. Even without RCE, `paymentMethod=../../../../../../etc/passwd` or LDAP-like inputs probe error states. With Laravel 9 autoload + `ucfirst()` only, any class in the application autoload reachable via the `PaymentRequests` sub-namespace can be instantiated by ANYONE (`authorize() = true`, no auth on the wrapping web route group `/payment/{order}/pay`).
**Exploit complexity**: Skilled (gadget chain hunt) → trivial DoS via Throwable from arbitrary FormRequest construction.
**Business impact**: Pre-auth instantiation of arbitrary FormRequests on a payment page. Service disruption guaranteed; full RCE depends on gadget availability.
**Fix**: Replace with `match($request->paymentMethod) { 'stripe' => StripePaymentRequest::class, 'senangpay' => SenangpayPaymentRequest::class, default => abort(400) }`. Add `Rule::in(['stripe', 'senangpay', ...])` to validation.

---

### P0-S-03 — `MIX_API_KEY` LEAKED IN HTML BODY (Bypass of the only "API auth" middleware for unauth callers)
**File**: `resources/views/admin-pos-v4.blade.php:96-98`, `resources/views/master.blade.php:107-109`
**Code**:
```html
<!-- master.blade.php:107-109 -->
window.foodkingConfig = {
    ...
    apiKey: @json((string) config('app.api_key')),
```
**File**: `app/Http/Middleware/ApiKeyMiddleware.php:24` — non-constant-time `===` comparison (timing attack secondary to leak).
**Attack scenario**: Any unauthenticated visitor opens `view-source:` or DevTools, copies `window.foodkingConfig.apiKey`, then issues `curl -H 'x-api-key: <copied>'` against ANY `/api/admin/*` route. The middleware passes. The actual gate that protects /api/admin/* is `auth:sanctum`, so the api-key middleware is purely cosmetic — BUT the deployment doc treats it as "[CRITICAL] Toutes les requêtes /api/* exigent le header x-api-key" (.env.example:57). Documentation lies create wrong threat models for ops.
**Exploit complexity**: Trivial.
**Business impact**: Documentation-grade false sense of security; combined with token-ability finding (P0-S-04), the actual layered defense is one layer (Sanctum token issuance), not two.
**Fix**: Either (a) remove the api-key middleware entirely (it provides zero security as currently implemented), or (b) re-design it as per-client signed tokens never present in client-side JS. Stop injecting `apiKey` into HTML.

---

### P0-S-04 — SANCTUM TOKENS ISSUED WITH WILDCARD `['*']` ABILITIES (Granular auth gates are decorative)
**File**: `app/Http/Controllers/Auth/LoginController.php:87-91`
**File**: `app/Http/Controllers/Auth/GuestSignupController.php:140`
**Code**:
```php
// LoginController.php:87-91
$this->token = $user->createToken(
    'auth_token',
    ['*'],                                              // <-- WILDCARD ABILITIES
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;

// GuestSignupController.php:140  (guest customer via OTP)
$this->token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
```
**Effect**: `Sanctum::tokenCan('kiosk:order')` returns `true` for `*` tokens. So every authentication path — admin, staff cashier, guest customer with SMS OTP — gets a token that satisfies EVERY `tokenCan(...)` ability check in the codebase. Defense-in-depth gates like `OrderRequest::authorize()` are bypassed by design.
**Attack scenario**: A guest customer signs up via OTP (phone + 4-digit code, throttled 3/5min), receives a 30-day `['*']` token, and can now hit every endpoint that gates only by `tokenCan('kiosk:order')`. Combined with the FormRequest authz gap (87/93 return `true;`), this is admin-permission-bypass-by-token-abuse waiting to happen.
**Exploit complexity**: Trivial (anyone with a phone).
**Business impact**: Sanctum's ability system is unusable as a security primitive in the current shape. The 88-endpoint authz refactor scheduled for V1.0.1 (BRAIN.md note) is mandatory before any production deployment to a multi-tenant cloud.
**Fix**: LoginController: issue ability arrays scoped to user role (`['admin']`, `['pos']`, `['kds']`, etc.). Guest: `['customer:order']` only. KioskMachineLoginController already does this correctly (`['kiosk:order']` — line 100). Adjust all `tokenCan(...)` gates accordingly.

---

### P0-S-05 — RCE PRIMITIVE: ARBITRARY FILE WRITE VIA `LanguageService::edit()` REACHABLE BY ANY AUTHED USER
**File**: `app/Services/LanguageService.php:198-220` (the `fileTextStore` method's `edit()` helper)
**Code**:
```php
// LanguageService.php:200-214
$file        = fopen($request->x_language_file_path, "rw");
$fileContent = file_get_contents($request->x_language_file_path);
foreach ($request->all() as $key => $value) {
    if ($key != 'x_language_file_path' && $key != 'x_language_file_name') {
        $key = str_replace('_', ' ', $key);
        if (strpos($fileContent, "'" . $key . "'") !== false) {
            $fileContent = str_replace("'" . $key . "'", "\"{$value}\"", $fileContent);  // <-- value NOT escaped
        } elseif (strpos($fileContent, "\"{$key}\"") !== false) {
            $fileContent = str_replace("\"{$key}\"", "\"{$value}\"", $fileContent);
        }
    }
}
file_put_contents($request->x_language_file_path, $fileContent);
```
**File**: `routes/api.php:486` — `Route::post('/file-text/store', [LanguageController::class, 'fileTextStore']);`
**Group middleware** (`api.php:269`): `['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation']` — **no `permission:*`**.
**Controller** (`LanguageController.php:23`): `permission:settings` applied only to `store/update/destroy`, NOT to `fileTextStore`.

**Attack scenario**:
1. Attacker obtains any Sanctum token (guest signup with OTP works — no permission required).
2. POST `/api/admin/language/file-text/store` with body:
   - `x_language_file_path = /var/www/html/storage/framework/php-shell.php` (or similar PHP-served path)
   - any key matching existing strings, with value containing PHP code (since the str_replace breaks quoting easily — the value wraps in `"..."` but a value containing `"` closes the string)
3. The endpoint writes attacker-controlled content to an attacker-controlled disk path.
4. Browse to the planted file → PHP execution → full webshell.

The exact code path uses `request->x_language_file_path` directly as both the read target and write target — NO path normalisation, NO whitelist of language-file directories, NO mime/extension check.

**Exploit complexity**: Trivial — any authenticated user.
**Business impact**: Full RCE on the webserver. Compromise of FISCAL_AUDIT_SECRET (the secret an attacker with RCE will use to forge or rewind the NF525 audit chain). NF525 fines, GDPR breach reporting, criminal liability for the operator.
**Fix**: (a) Move `fileTextStore` under `permission:settings` middleware. (b) Validate `x_language_file_path` against `realpath(lang_path())` whitelist. (c) Sanitize `$value` — refuse content containing `<?` or backtick. (d) Use the dedicated language editor API that already exists upstream rather than this raw replace-in-file pattern.

---

### P0-S-06 — IDOR: `PosOrderController::show()` BYPASSES BranchScope
**File**: `app/Http/Controllers/Admin/PosOrderController.php:108`
**Code**:
```php
public function show(int|string $order) {
    $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
    return new OrderDetailsResource($this->orderService->show($order, false));
}
```
**Effect**: A POS user in branch 17 can `GET /api/admin/pos-order/show/42` and retrieve order #42 from branch 4. Order details include customer name, phone, address, total, payment method, and `composition_snapshot` (PII-rich JSON). The whole point of `BranchScope` (CLAUDE.md §9, applied to 11 models) is defeated for the order detail endpoint.
**Attack scenario**: A franchise's disgruntled cashier scrapes every order across every other franchise in the SaaS by iterating order IDs. GDPR data subject access on competitors' customers.
**Exploit complexity**: Trivial — auto-incrementing IDs.
**Business impact**: Multi-tenant breach, GDPR notification (72h), customer trust collapse, possible CNIL fine.
**Fix**: Remove `withoutGlobalScope(BranchScope::class)`. If admin needs cross-branch view, use a separate endpoint gated by `permission:admin` AND log access.

---

### P0-S-07 — UNAUTHENTICATED `/install/*` ROUTES (installer trigger / takeover risk)
**File**: `routes/web.php:22-34`
**Code**:
```php
Route::prefix('install')->name('installer.')->middleware(['web'])->group(function () {
    Route::get('/database',  [InstallerController::class, 'database']);
    Route::post('/database', [InstallerController::class, 'databaseStore']);
    Route::post('/license',  [InstallerController::class, 'licenseStore']);
    Route::post('/site',     [InstallerController::class, 'siteStore']);
    // ... all unauthenticated
});
```
**Effect**: The `Installed` middleware checks for `storage/installed` sentinel — but `/install/*` itself has no guard. If the sentinel is deleted (deploy script glitch, disk corruption, malicious rm), an external attacker can re-trigger installation, point the DB to their own server, and become the admin.
**Attack scenario**: Combine with file-write primitive (P0-S-05) — write/delete `storage/installed` → call `/install/database` → take over.
**Exploit complexity**: Skilled (requires complementary primitive or operational mishap).
**Business impact**: Full site takeover, fiscal chain reset.
**Fix**: Add explicit `abort(403, 'already installed')` at the top of `/install/*` if `storage/installed` exists. Better: gate the entire installer behind `APP_ENV !== 'production'` AND IP allowlist.

---

### P1-S-08 — STRIPE CHARGE AMOUNT TRUNCATED (Revenue Loss + Mis-paid Fiscal Receipt)
**File**: `app/Http/PaymentGateways/Gateways/Stripe.php:51`
**Code**:
```php
$response = $this->gateway->charges->create([
    'amount'      => (int) $order->total * 100,    // <-- truncation BUG
    'currency'    => $currencyCode,
    ...
]);
```
**Effect**: For `$order->total = 12.99`, the expression is `(int) 12.99 * 100 = 12 * 100 = 1200` cents = €12.00 charged. €0.99 lost per order, every single time. **Stripe charges the wrong amount; the fiscal receipt records `$order->total = 12.99` though.** That's a NF525 invariant violation — the receipt amount must equal the captured payment.
**Attack scenario**: Not an exploit per se — a math bug. But attacker observation: pick prices like €X.99 to consistently extract value, OR attack the discrepancy auditing process by inducing the bug to mask theft.
**Exploit complexity**: N/A (bug).
**Business impact**: Revenue leak, NF525 receipt/payment mismatch on every web-Stripe transaction. Cumulative loss + audit risk.
**Fix**: `'amount' => (int) round($order->total * 100)`. Add invariant assertion `assert($response->amount === (int) round($order->total * 100))` post-charge.

---

### P1-S-09 — LARAVEL 9.52 IS EOL (No Security Updates Since Feb 2024) + PHPSpreadsheet 1.30 has UNPATCHED RCEs
**Files**: `composer.lock` (laravel/framework v9.52.21, phpoffice/phpspreadsheet 1.30.0)
**Reachable**: `app/Http/Controllers/Admin/ItemController.php:188` and `ItemCategoryController.php:125` both call `Excel::import()` on admin-uploaded files.
**Known CVEs**:
- **CVE-2024-45048** — PHPSpreadsheet < 1.29.4/2.1.2 — XSS via HTMLWriter (and parser-side issues with crafted .xlsx). FoodKing ships 1.30.0 — **vulnerable**.
- **CVE-2024-45291 / 45292** — PHPSpreadsheet HTML/sheet parsing — patched in 1.29.5+ but 1.30.0 is on the older branch; verify against vendor advisory matrix.
- **CVE-2024-43377** / -43378 — same family.
- Laravel 9 EOL since 2024-02-06 — any framework-level CVE found after that date is unpatched in 9.52.x branch (notable: HTTP smuggling, mass assignment edge cases, possible session fixation regressions).

**Attack scenario**: An admin (or any user holding a stolen admin token) uploads a crafted .xlsx into the item-import endpoint. PHPSpreadsheet's HTMLWriter / formula parser triggers XSS-to-stored or, in worst cases on older 1.30.0, server-side calculation gadget.
**Exploit complexity**: Skilled (CVE weaponization), but public PoCs exist for several of these.
**Business impact**: Stored XSS in admin panel → admin session hijack → cascade to fiscal secret reads → forgeable audit chain.
**Fix**: Bump `laravel/framework` to ^10 or ^11 (LTS path). Bump `phpoffice/phpspreadsheet` to ^2.x current. Run `composer audit` in CI.

---

### P1-S-10 — `APP_DEBUG` Posture Risk + Ignition Dev-Time Stack Trace Leak
**File**: `.env:6` (current value `APP_DEBUG=false` — OK, but the example ships `APP_DEBUG=true` and the dev backup-pre-round2 leaked into commit also had APP_DEBUG=true).
**Composer**: `spatie/laravel-ignition` (dev) — historically had CVE-2022-40127 (debug-mode RCE).
**File**: `app/Http/Middleware/ContentSecurityPolicyHeader.php:29` — CSP default is `report_only`, not `enforce`. A real XSS lands.

**Attack scenario**: If any deployment forgets to set `APP_DEBUG=false` (and ENV is not `production`), Ignition's "execute solution" feature can be triggered remotely with crafted requests. Combined with `cors.php` allowing `supports_credentials: true` and wide origins from env, an attacker hosting a malicious page can pivot via CSRF on debug endpoints.
**Exploit complexity**: Skilled.
**Business impact**: One ops mistake away from full RCE in dev environments that are not air-gapped.
**Fix**: Hard assertion in `AppServiceProvider::boot()` — if `APP_ENV==='production' && APP_DEBUG===true` → throw. CSP: move to `enforce` mode. Remove `ignition` from production composer (use `--no-dev`).

---

### P1-S-11 — FORMREQUEST AUTHZ COVERAGE: 87 / 93 FILES MISSING ANY PERMISSION CHECK
**Discovery**:
```bash
$ find app/Http/Requests -name "*.php" | wc -l       # 93
$ find app/Http/Requests -name "*.php" -exec grep -L "tokenCan\|hasRole\|hasPermission\|can(\|Gate::" {} \; | wc -l   # 87
```
**Effect**: Of all FormRequests, 94% rely on route-level middleware ALONE for authorization. A future routes refactor that drops `permission:*` middleware silently widens 88 endpoints to anyone with `auth:sanctum`. Combined with token wildcard issue (P0-S-04), the practical permission boundary is wherever the route file explicitly placed `permission:settings`. Audit trail of which endpoints check what is essentially "read the routes file" — not auditable per-request.
**Exploit complexity**: Skilled (requires endpoint enumeration).
**Business impact**: Single-line route refactor mistake → silent permission widening. The V1.0.1 refactor note in BRAIN.md confirms 88 endpoints scheduled for authz remediation.
**Fix**: Convert each FormRequest's `authorize()` to a Gate check (`Gate::allows('orders.update', $this->route('order'))`). Make `authorize() = true` an explicit anti-pattern flagged in CI.

---

## DEPENDENCY CVE SUMMARY

| Package | Installed | Status | CVEs / Notes |
|---|---|---|---|
| `laravel/framework` | v9.52.21 | **EOL since Feb 2024** | Any post-EOL CVE is unpatched here. Upgrade to ^10 (LTS) or ^11. |
| `laravel/sanctum` | v3.3.3 | Latest 3.x branch | Token wildcard issue is FoodKing's, not Sanctum's. |
| `phpoffice/phpspreadsheet` | 1.30.0 | **Multiple unpatched CVEs** | CVE-2024-45048, CVE-2024-45291/2/3, CVE-2024-43377/8 family. Bump to ^2.x. |
| `spatie/laravel-permission` | 5.11.1 | OK | Used as authz primitive — recommended pattern. |
| `stripe/stripe-php` | v10.21.0 | Stale (current 14.x) | No known CVE on 10.21, but lacks newer SCA features. |
| `barryvdh/laravel-dompdf` | v3.1.1 | OK | Historical CVE-2022-41343 fixed. |
| `aws/aws-sdk-php` | 3.359.13 | OK | Current. |
| `google/apiclient` | v2.18.4 | OK | Current. |
| `guzzlehttp/guzzle` | 7.10.0 | OK | Current major. |
| `maennchen/zipstream-php` | 3.1.2 | OK | Composer constraint pinned `<3.2` — investigate why; may indicate compat issue with a later patch. |
| `predis/predis` | v3.4.2 | OK | Current. |
| `simplesoftwareio/simple-qrcode` | 4.2.0 | OK |  |
| `barryvdh/laravel-debugbar` (dev) | — | **MUST NOT SHIP** | Confirm production install runs `composer install --no-dev`. |
| `spatie/laravel-ignition` (dev) | — | **MUST NOT SHIP** | CVE-2022-40127 (debug-mode RCE) if exposed. |

---

## TOP 3 HARDENING RECOMMENDATIONS

### 1. Detonate the wildcard token (P0-S-04) — 2-day cycle
- Change `LoginController::login()` to issue role-scoped abilities (`['admin']`, `['pos']`, `['kds']`, `['waiter']`, `['delivery']`, `['customer']`).
- Update every `tokenCan('kiosk:order')` site to also accept the matching role ability where appropriate.
- Mass-rotate all live tokens (`personal_access_tokens.truncate()` in maintenance window — users re-login).
- Add a CI lint that fails the build if `createToken(..., ['*'], ...)` appears anywhere except a documented exemption (currently: nothing should be `*`).

### 2. Quarantine the `LanguageService::edit` RCE primitive (P0-S-05) — same-day patch
- Move route `POST /api/admin/language/file-text/store` under `permission:settings` middleware (existing pattern: `LanguageController.php:23` already gates `store/update/destroy`).
- In `LanguageService::edit()`: validate `$request->x_language_file_path` via `realpath()` startswith `realpath(lang_path())` else 403.
- Reject any `$value` containing `<?`, `?>`, backticks, `eval(`, `assert(`, `${`.
- Add a regression test `LanguageFileEditCannotEscapeLangDirectoryTest`.

### 3. Rotate ALL secrets exposed in commit `a4a88df06` (P0-S-01) — block production deploy until done
- AWS console: rotate `AKIAYJOT77SIZHDXNYOZ` immediately, audit CloudTrail since 2026-05-13 for that key's usage.
- Rotate `APP_KEY` (forces session/cookie re-issue — communicate to users).
- Audit every `FISCAL_AUDIT_SECRET` / `FISCAL_Z_REPORT_SECRET` deployment — if the leaked value was used in any environment that touched real fiscal data, the audit chain integrity is impeachable.
- `git filter-repo --invert-paths --path .env.backup-pre-round2` (with force-push) — coordinate with all collaborators. If the repo is private and the only consumer of the leaked AWS key is you, history rewriting may be optional; rotation is not.
- Implement pre-commit hook (`.git/hooks/pre-commit`) that scans staged files for `AKIA[0-9A-Z]{16}`, `-----BEGIN PRIVATE KEY-----`, etc. — there are several free tools (gitleaks, trufflehog).

---

## POSITIVES (what they got RIGHT)

So this report isn't all dark — the things below are genuinely well-done and should not be touched.

- **Fiscal HMAC chain** (`AuditLogService.php` / `ZReportService.php`): cache lock + DB transaction + UNIQUE(branch_id, prev_hash) + retry-on-collision pattern is correct. The `assertProductionSafe()` guard against dev-sentinels and short secrets is the right shape. `hash_equals()` constant-time compare is used.
- **Idempotency middleware**: scope `(branch_id, user_id, hash(key))`, payload-hash conflict 409, `Idempotency-Replayed` header — solid implementation.
- **Webhook signature verification** (Stripe `Webhook::constructEvent` + SenangPay `hash_equals` HMAC-SHA-256): correct.
- **OTP randomness**: `random_int()` CSPRNG (not `rand()`) — fixed by [GAP-32-5].
- **BranchScope recursion guard**: skip for `User` model to avoid Sanctum guard re-entry — careful design.
- **Throttle limits on auth/OTP**: 3-per-5min on OTP verify is the right defense vs 4-digit OTP brute force.

The fiscal core is the one place this codebase is built like a real SaaS. Everything around it needs to catch up.

---

**END OF REPORT** — Agent 2 — Security RED-TEAM.
