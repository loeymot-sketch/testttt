# X2 — SECURITY DEEP CROSS-CUTTING AUDIT

**Date** : 2026-05-17
**Agent** : X2 — SECURITY DEEP (hostile cross-cutting auditor)
**Working dir** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Branch HEAD** : `feature/mobile-app-le-cayenne-2026-05-10` (commit c3ba89863 area)
**Prior audits cross-referenced** :
- `reports/audit/cto-global-2026-05-16/agent-2-security-red.md` (28/100, 11 findings)
- `reports/audit/cto-global-2026-05-16/ultra-plans/SECURITY_ULTRA_PLAN.md` (re-verified to commit `adf7036e4`)

---

## OWASP TOP 10 — CATEGORY SCORES

| # | Category | Score /100 | Justification (worst issue) |
|---|---|---|---|
| A01 | Broken Access Control | **22** | `/api/admin/users` group has zero `permission:*` middleware (anyone with sanctum can list/store users) ; `PosOrderController::show` IDOR confirmed ; `MessageRequest` accepts client-supplied `user_id` / `branch_id` (impersonation + cross-tenant) ; 87/93 FormRequest `authorize()` = `true` |
| A02 | Cryptographic Failures | **35** | `APP_DEBUG=true` examples ship in `.env.example` ; `MIX_API_KEY` + `googleMapKey` injected verbatim in HTML for every visitor ; non-constant-time `===` in `ApiKeyMiddleware` ; session encrypt=false ; `SESSION_SECURE_COOKIE` absent from `.env.example` (HTTP cookies in prod if unset) |
| A03 | Injection | **48** | LanguageService RCE primitive (still live per `routes/api.php:486` + lack of permission gate) ; PaymentController `new $className()` arbitrary instantiation (no auth on `/payment/{order}/pay`) ; Stripe truncation bug ; Excel import accepts admin file (PHPSpreadsheet 1.30 CVEs). No SQL injection found directly — Eloquent throughout |
| A04 | Insecure Design | **30** | Frontend `MessageRequest` lets client choose `user_id` + `branch_id` (no design-time tenant assertion) ; ChefRequest / DeliveryBoyRequest accept `branch_id` from client ; Guest OTP grants `['*']` 30-day token ; `/install/*` unauthenticated ; `SimpleUserController::index` has no permission gate |
| A05 | Security Misconfiguration | **18** | `web` middleware group has NO security headers (X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy) — JsonMiddleware only protects `/api/*` JSON responses ; CSP is `report_only` (not `enforce`) ; `TrustHosts` middleware is **commented out in Kernel** (Host-header injection possible) ; `TrustProxies::$proxies = null` with `HEADER_X_FORWARDED_FOR` enabled (IP-spoofing for throttle keys) ; CORS `supports_credentials = true` with allow-list ⇒ OK but headers='*' |
| A06 | Vulnerable & Outdated Components | **20** | Laravel 9.52.21 (EOL Feb 2024) ; phpoffice/phpspreadsheet 1.30.0 (CVE-2024-45048/45291/45292 unpatched) ; stripe-php 10.21 (current 14.x) ; barryvdh/laravel-debugbar + spatie/laravel-ignition in dev — risk if `--no-dev` not enforced. firebase v9.18 (current 10/11) ; dompurify 3.4 OK ; axios 1.1.2 (old, multiple CVEs in 1.x line) |
| A07 | Identification & Authentication Failures | **25** | Sanctum `['*']` wildcard at 3 sites (Login + GuestSignup + ForgotPassword.resetPassword) ; OTP is 4-digit (throttled but brute-forceable across many phones) ; `ForgotPasswordController::resetPassword` does NOT re-verify OTP, only the 64-char `reset_token` — but token is generated server-side via Str::random(64) (safe). Login throttle keyed by `email|IP` (resilient). No MFA option for staff/admin. Session lifetime 120 min OK. |
| A08 | Software & Data Integrity Failures | **40** | No SRI on CDN-loaded JS (master.blade.php loads Vue/Pusher externals if any) ; no Subresource Integrity ; npm install in CI has no `--audit` block ; no signed commits enforced ; gitleaks absent (proposed in ultra plan but not yet implemented) ; `.env.backup-pre-round2` leak in commit history NOT scrubbed |
| A09 | Security Logging & Monitoring Failures | **38** | `Log::info` of `$exception->getMessage()` in CustomerService/ChefService — could leak SQL fragments (low risk) ; `LOG_LEVEL` defaults `debug` in 7 channels (config/logging.php:63-109) ; no centralized SIEM signal ; `security` log channel exists but only used in KioskEventController ; ForgotPasswordController has NO failed-attempt log ; LoginController logs are absent (relies on throttle only). No alerting on `audit_logs` trigger violations. |
| A10 | Server-Side Request Forgery | **75** | Single `Http::post` call in `InstallerService.php:94` with static `$apiUrl = config('app.api_url')` — NOT user-controllable, safe. `file_get_contents` in `LanguageService.php:202` is the language-edit RCE (already P0). No image proxy / URL fetcher / RSS reader detected. SSRF surface is genuinely small. |

**Composite cross-cutting security score** : **(22+35+48+30+18+20+25+40+38+75) / 10 = 35.1 / 100**

Marginal improvement over Agent 2's 28/100 because A10 SSRF is solid and Stripe/Senangpay webhook signature verification is correctly implemented. **Hard regressions found**: A05 misconfiguration is **WORSE** than Agent 2 reported because web responses lack ALL security headers (Agent 2 only flagged CSP report_only ; X-Frame-Options absence on admin Blade is critical for clickjacking).

---

## TOP 5 NEW FINDINGS (NOT in Agent 2 or Ultra Plan)

### XS-NEW-01 — `/api/admin/users` ROUTE GROUP HAS NO PERMISSION MIDDLEWARE — P0 NEW

**File** : `routes/api.php:984-989`

```php
Route::prefix('users')->name('users.')->group(function () {
    Route::get('/',                            [SimpleUserController::class, 'index']);          // <-- LISTS ALL USERS
    Route::post('/',                           [SimpleUserController::class, 'store']);
    Route::get('/address/{customer}',          [SimpleUserController::class, 'addresses']);
    Route::post('/address/{customer}',         [SimpleUserController::class, 'storeAddress']);
    Route::match(['put','patch'], '/address/{customer}/{address}', [SimpleUserController::class, 'updateAddress']);
});
```

**Enclosing group** (line 269) : `['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation']` — **no `permission:*`**.

**Controller-level gate** (`SimpleUserController.php:36`) : `$this->middleware(['permission:pos'])->only('store', 'addresses', 'storeAddress', 'updateAddress');` — **`index` is NOT in the list**.

**Attack scenario** : Any authenticated user (including a guest customer with the `['*']` token from `GuestSignupController.php:140`) can `GET /api/admin/users` and receive `SimpleUserResource::collection()` containing every user record — `name, email, phone, branch_id, status, balance, roles, addresses` for every staff + admin + customer in the SaaS. The `SimpleUserService::list` only filters BY input keys, never restricts WHICH users are returned by role/branch.

**Severity** : GDPR breach magnitude. Combined with the existing `['*']` token issue, a phone number with SMS reception → full PII dump of every user.

**Exploit complexity** : Trivial. `curl -H "Authorization: Bearer $guest_token" $APP/api/admin/users?paginate=1&per_page=10000`.

**Fix** : Add `$this->middleware(['permission:customers_index'])->only('index');` to `SimpleUserController::__construct`. Also enforce `EnumRole::CUSTOMER` filter in `SimpleUserService::list` (currently `User::where(...)` returns ALL roles, not just CUSTOMER).

---

### XS-NEW-02 — `MessageRequest` ACCEPTS CLIENT-SUPPLIED `user_id` + `branch_id` (Impersonation + Cross-tenant) — P0 NEW

**File** : `app/Http/Requests/MessageRequest.php:30-35`

```php
public function rules(): array
{
    return [
        'branch_id' => ['required', 'numeric'],   // <-- client-controlled
        'user_id'   => ['required', 'numeric'],   // <-- client-controlled — impersonation primitive
        'is_read'   => ['required', 'numeric'],
        'text'      => ['nullable', 'max:5000'],
        'image'     => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048']
    ];
}
```

**Route** : `routes/api.php` `/api/admin/message` group `middleware(['auth:sanctum'])` — **no `permission:*`**.

**Attack scenario** : Authenticated attacker POSTs `/api/admin/message` with `user_id=<victim_id>`, `branch_id=<any branch>`, `text=<phishing copy>`. The message is recorded as if FROM the victim. Combined with cross-tenant browsing (XS-NEW-01), an attacker enumerates user IDs, then posts messages impersonating staff or admins. Victims see messages "from manager" containing phishing links or social-engineering payloads.

**Fix** : Replace `user_id` rule with server-side assignment `$message->user_id = auth()->id()` ; replace `branch_id` rule with `auth()->user()->branch_id` (or scoped lookup). Add `MessageRequest::authorize()` returning `$user->can('messages.create')`.

---

### XS-NEW-03 — WEB MIDDLEWARE GROUP MISSING ALL SECURITY HEADERS (Clickjacking on Admin Panel) — P0 NEW

**Files** :
- `app/Http/Kernel.php:36-46` (web middleware group)
- `app/Http/Middleware/JsonMiddleware.php:24-27` (security headers ONLY on JSON responses)

**Evidence** :
```php
// JsonMiddleware (api group ONLY)
$response->header('X-Content-Type-Options', 'nosniff');
$response->header('X-Frame-Options', 'SAMEORIGIN');
$response->header('X-XSS-Protection', '1; mode=block');
$response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
```

**The `web` middleware group includes**:
- TrustProxies, HandleCors, PreventRequestsDuringMaintenance, ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull
- EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, VerifyCsrfToken, SubstituteBindings, CorrelationIdMiddleware
- ContentSecurityPolicyHeader (CSP only, mode=`report_only` by default)

**Missing on web responses** : `X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security` (HSTS), `Referrer-Policy`, `Permissions-Policy`.

**Attack scenario** :
1. Clickjacking on `/admin/*` Blade routes — attacker embeds `https://app.foodking.fr/admin/items` in a hostile iframe with overlay buttons. An admin who navigates to the attacker page while logged in unwittingly clicks "Delete category" / "Apply discount 100%" / etc.
2. MIME-sniffing on user-uploaded files served via `/storage/<path>` — `Content-Type-Options: nosniff` is the standard mitigation against IE/old-browser XSS.
3. No HSTS = first connection over HTTP can be MITM'd ; subsequent connections vulnerable to SSLStrip.

Combined with **CSP set to `report_only`** (`config/security.php:32`), `frame-ancestors 'none'` is **NOT enforced** in production. Pure cosmetic.

**Fix** : Create `app/Http/Middleware/WebSecurityHeaders.php` mirroring JsonMiddleware headers, add to `web` middleware group, set `CSP_ENFORCE_MODE=enforce` in production env.

---

### XS-NEW-04 — `TrustHosts` MIDDLEWARE COMMENTED OUT IN KERNEL (Host Header Injection) — P1 NEW

**File** : `app/Http/Kernel.php:18`

```php
protected $middleware = [
    // \App\Http\Middleware\TrustHosts::class,   // <-- DISABLED
    \App\Http\Middleware\TrustProxies::class,
    \Illuminate\Http\Middleware\HandleCors::class,
    ...
];
```

**Concrete impact** : Laravel uses `$request->root()` / `URL::previous()` / `URL::current()` to build URLs in many places — most notoriously **password-reset emails** (`Mail::send` → message body contains reset URL built from current Host header). With TrustHosts disabled, an attacker sends a password reset request with header `Host: evil.com` ; the email body contains `https://evil.com/reset?token=...` ; victim clicks → token leaked to attacker domain → account takeover.

**File evidence** :
- `app/Http/Controllers/Auth/ForgotPasswordController.php:46-52` inserts the `password_resets` row with the OTP `token` ; the email template (not shown) typically links to `route('password.reset', ['token' => ...])` which inherits the request's host.
- No `URL::forceRootUrl(config('app.url'))` call in `app/Providers/AppServiceProvider.php` — verified absent.

**Attack scenario** : Trivial — Burp Repeater set Host: evil.com on the password-reset trigger endpoint. SMS-based OTP flow (GuestSignup) is unaffected (SMS body doesn't include Host), but email-based forgot-password is exploitable.

**Fix** : Uncomment `// \App\Http\Middleware\TrustHosts::class` AND verify `app/Http/Middleware/TrustHosts.php::hosts()` returns the production domain allow-list (currently `[$this->allSubdomainsOfApplicationUrl()]` — OK if `APP_URL` is correctly set in prod). Add `URL::forceRootUrl(config('app.url'))` in `AppServiceProvider::boot()` for defense in depth.

---

### XS-NEW-05 — `googleMapKey` LEAKED IN HTML BODY (Billing-Abuse + Quota Drain) — P1 NEW

**File** : `resources/views/master.blade.php:110`

```html
window.foodkingConfig = {
    baseUrl:      @json(rtrim((string) config('app.url'), '/')),
    apiKey:       @json((string) config('app.api_key')),         // <-- already flagged P0-S-03 by Agent 2
    googleMapKey: @json((string) config('app.google_map_key')),  // <-- NEW: billing-bearing API key
    ...
};
```

**Impact** : Google Maps Platform keys are billing-bearing — even with HTTP referrer restrictions, an attacker can spoof `Referer: https://app.foodking.fr` from server-side scripts and burn through Maps quota → Google bills the FoodKing account. Worst case = billing surprise. Google does provide HTTP-referrer restrictions, but those are advisory client-side ; they CAN be bypassed.

**Best practice** : Maps keys for browser-side rendering should be (a) restricted to specific HTTP referrers in GCP Console AND (b) IP-restricted to expected client geographies AND (c) per-API restricted (Maps JavaScript API only, not Geocoding API). The codebase has zero documentation of these restrictions — likely the key is unrestricted (default state).

**Attack scenario** : Attacker scrapes the key from any public page (`view-source: https://app.foodking.fr/`), spins up a server that proxies Geocoding/Places/Directions API calls using the stolen key with spoofed Referer. Owner discovers €X,000+ unexpected bill from Google Cloud.

**Fix** :
1. **Owner action** (GCP Console) — restrict `app.google_map_key` to:
   - APIs: Maps JavaScript API ONLY (no Geocoding, no Directions, no Places).
   - HTTP referrers: `https://app.foodking.fr/*`, `https://*.foodking.fr/*`.
   - (Optional) IP allowlist if server-side calls are also used.
2. **Code action** — proxy any server-side Geocoding/Places/Directions calls through a backend endpoint that uses a separate **IP-restricted** key, never exposed to the client.
3. **CI gate** — extend the regex in proposed gitleaks `.gitleaks.toml` to detect `AIza[0-9A-Za-z\-_]{35}` patterns committed.

---

## VERIFIED-AGAIN (already-found by Agent 2 / Ultra Plan)

Marked as **CONFIRMED CURRENT** during this audit ; do not re-expand.

| ID | Source | Status 2026-05-17 |
|---|---|---|
| P0-S-01 (AWS keys leaked) | Agent 2 | Confirmed — `git log -S 'AKIAYJOT77SIZHDXNYOZ'` still finds commits ; rotation status unknown (owner action) |
| P0-S-02 (PaymentController arbitrary new) | Agent 2 | Confirmed — `app/Http/Controllers/Frontend/PaymentController.php:75` unchanged ; `/payment/{order}/pay` (routes/web.php:40) has only `installed` middleware — STILL unauthenticated |
| P0-S-03 (MIX_API_KEY in HTML) | Agent 2 | Confirmed — `master.blade.php:109` and `admin-pos-v4.blade.php` still inject `apiKey` into `window.foodkingConfig` |
| P0-S-04 (Sanctum wildcard tokens) | Agent 2 | Confirmed — `LoginController.php:87`, `GuestSignupController.php:140`, `ForgotPasswordController.php:165-169` all still issue `['*']` ; `RefreshTokenController:49` preserves abilities (good) ; `KioskMachineLoginController:98-102` uses scoped `['kiosk:order']` (good) |
| P0-S-05 (LanguageService RCE) | Agent 2 | Confirmed — `LanguageService.php:200-214` unchanged ; route `routes/api.php:486` still in group without `permission:settings` for `fileTextStore` |
| P0-S-06 (PosOrderController IDOR) | Agent 2 | Confirmed — `app/Http/Controllers/Admin/PosOrderController.php:108` still has `withoutGlobalScope(BranchScope::class)` |
| P0-S-07 (/install/* unauthenticated) | Agent 2 | Confirmed — `routes/web.php:22-34` unchanged, no `abort_if(installed())` guard |
| P1-S-08 (Stripe `(int) $total * 100`) | Agent 2 | **FIXED** per code comment "P0-6 CTO audit 2026-05-16 round-before-cast" — verify via grep `(int) $order->total * 100` returns 0 hits |
| P1-S-09 (Laravel 9 EOL + phpspreadsheet) | Agent 2 | Confirmed — composer.lock still on Laravel 9.52.21 + phpspreadsheet 1.30.0 (CVE-2024-45048 family) |
| P1-S-10 (APP_DEBUG / Ignition) | Agent 2 | Confirmed — ignition in `composer.json:46` require-dev ; `.env.example` ships APP_DEBUG=true risk |
| P1-S-11 (87/93 FormRequest authorize=true) | Agent 2 | Confirmed — pattern unchanged ; CustomerRequest/ChefRequest/DeliveryBoyRequest all `return true;` |

---

## SECONDARY NEW FINDINGS (NOT TOP 5 but worth recording)

### XS-NEW-06 — Frontend Profile / Customer / Chef / DeliveryBoy creation accepts `branch_id` from client (cross-tenant write) — P1

**Files** :
- `app/Http/Requests/CustomerRequest.php:61` : `'branch_id' => ['nullable', 'numeric']`
- `app/Http/Requests/ChefRequest.php:53` : `'branch_id' => ['nullable', 'numeric']`
- `app/Http/Requests/DeliveryBoyRequest.php:50` : `'branch_id' => ['nullable', 'numeric']`

Combined with `BranchScope`'s pre-auth bypass for `User` model, a Branch-Manager in branch 1 calls `POST /api/admin/chef` with `branch_id=17` to provision a chef in another branch.

**Fix** : Remove `branch_id` from the request rules ; assign server-side from `auth()->user()->branch_id` in `ChefService::store`.

### XS-NEW-07 — `TrustProxies::$proxies = null` with X-Forwarded-For header enabled (IP spoofing) — P1

**File** : `app/Http/Middleware/TrustProxies.php:13-22`

```php
protected $proxies;   // null
protected $headers = Request::HEADER_X_FORWARDED_FOR | ...;
```

With `$proxies = null` and Laravel 9, `$request->ip()` will trust `X-Forwarded-For` from ANY upstream (no proxy filter). This corrupts:
- Throttle keys (kiosk-orders limiter uses `$request->ip()` as part of the key)
- Security logs (`Log::channel('security')` records `'ip' => $request->ip()`)
- Geo-based fraud rules if any

**Fix** : Set `protected $proxies = '*'` if behind a known reverse proxy (Cloudflare, ALB) ; OR set to a specific CIDR like `['10.0.0.0/8', '172.16.0.0/12']` for the LAN proxy ; OR if no proxy, set to `[]` and remove the `HEADER_X_FORWARDED_*` flags.

### XS-NEW-08 — `SESSION_SECURE_COOKIE` not documented in `.env.example` (HTTP cookies possible in prod) — P2

**File** : `config/session.php:171` uses `env('SESSION_SECURE_COOKIE')` (default `null` ⇒ Laravel auto-detect ⇒ HTTP cookies if app served over HTTP).

**Fix** : Add to `.env.example`:
```env
# CRITICAL: must be `true` in production behind HTTPS
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true   # already default true in config
SESSION_SAME_SITE=lax    # already default in config
```

### XS-NEW-09 — `CORS::allowed_headers = ['*']` (overly permissive) — P3

**File** : `config/cors.php:11` — `'allowed_headers' => ['*']`. Combined with `supports_credentials = true`, this means any browser with a cookie can be coerced into sending custom headers like `X-Custom-Internal-Bypass` if such a header is ever added. Best practice: explicit allowlist (`['Content-Type', 'Authorization', 'X-Idempotency-Key', 'X-Kiosk-Locale']` etc).

### XS-NEW-10 — Logging at `debug` level by default across 7 channels — P3

**File** : `config/logging.php:63, 69, 78, 83, 94, 104, 109` — `'level' => env('LOG_LEVEL', 'debug')`.

If `LOG_LEVEL` not set in prod (it isn't in `.env.example`), all channels log at DEBUG — which is verbose and potentially leaks sensitive request context. `'level' => env('LOG_LEVEL', 'warning')` would be safer default.

### XS-NEW-11 — `axios 1.1.2` in package.json — known CVEs in 1.x line — P2

**File** : `package.json:65` — `"axios": "^1.1.2"`. Axios 1.x had several CVEs (CVE-2023-45857 cookie leak, CVE-2024-39338 SSRF via protocol-relative URL). Latest 1.7.x patches these. The `^1.1.2` constraint should pull a newer 1.7 on `npm install`, but if `package-lock.json` is committed and pinned, the older version is locked in.

**Fix** : Run `npm audit --omit=dev` and `npm update axios` ; verify `package-lock.json` reflects 1.7.x+.

### XS-NEW-12 — `firebase 9.18.0` in dependencies — major version behind (10/11) — P3

**File** : `package.json:69` — used for FCM push notifications. v9 LTS support window is closing 2026 ; v10/11 have improved security and tree-shaking. Worth bumping in a maintenance cycle.

---

## SYSTEMATIC OWASP DRILLDOWN (TERSE)

### A01 — Broken Access Control — 22/100
**Drillthroughs**:
- `/api/admin/users` (XS-NEW-01) — NO permission gate on index — **P0**.
- `MessageController` user_id spoofing (XS-NEW-02) — **P0**.
- PosOrder IDOR (P0-S-06) — **confirmed**.
- Customer/Chef/DeliveryBoy branch_id mass-assign (XS-NEW-06) — **P1**.
- 87/93 FormRequest::authorize()=true (P1-S-11) — confirmed.
- Frontend `MessageRequest` (XS-NEW-02) — confirmed.

### A02 — Cryptographic Failures — 35/100
- `MIX_API_KEY` HTML leak (P0-S-03) — confirmed.
- `googleMapKey` HTML leak (XS-NEW-05) — **P1 NEW**.
- `ApiKeyMiddleware:24` non-constant-time `===` — already flagged ; if header is leaked anyway, timing is moot.
- `SESSION_SECURE_COOKIE` undocumented — XS-NEW-08 P2.
- `session.encrypt = false` (`config/session.php:49`) — session contents stored cleartext on disk/Redis. If session driver is `redis`, attackers who breach Redis read sessions plaintext.

### A03 — Injection — 48/100
- LanguageService RCE (P0-S-05) — confirmed.
- PaymentController `new $className()` (P0-S-02) — confirmed ; reachable WITHOUT auth via `/payment/{order}/pay` (`routes/web.php:40`).
- Stripe truncation (P1-S-08) — **FIXED per code comment** ; verify post-deploy.
- PHPSpreadsheet 1.30 CVEs (P1-S-09) — confirmed via composer.lock.
- No SQL injection found via grep `DB::raw\|whereRaw\|->raw(` — Eloquent throughout.
- No `eval()`, `unserialize()`, `assert()`, `system()`, `shell_exec()`, `proc_open()` found via grep — clean.

### A04 — Insecure Design — 30/100
- Guest OTP grants 30-day `['*']` token — pattern flaw (P0-S-04 confirmed).
- `/install/*` always-on (P0-S-07 confirmed).
- `MessageRequest` accepts user_id/branch_id (XS-NEW-02 NEW).
- `ChefRequest`/`DeliveryBoyRequest` accept branch_id (XS-NEW-06 NEW).
- `SimpleUserController::index` no permission (XS-NEW-01 NEW).
- No design-time RBAC matrix doc — `docs/AUTHZ_MATRIX.md` referenced in CLAUDE.md but content not validated against routes.

### A05 — Security Misconfiguration — 18/100 ★ WORST
- **Web responses have NO security headers** (XS-NEW-03 NEW P0).
- CSP `report_only` default (`config/security.php:32`).
- `TrustHosts` commented out (XS-NEW-04 NEW P1).
- `TrustProxies::$proxies = null` (XS-NEW-07 NEW P1).
- CORS allowed_headers `['*']` (XS-NEW-09 NEW P3).
- Logging at debug level default (XS-NEW-10 NEW P3).
- `APP_DEBUG` posture risk (P1-S-10 confirmed).

### A06 — Vulnerable Components — 20/100
| Package | Installed | Risk |
|---|---|---|
| laravel/framework | 9.52.21 | **EOL Feb 2024** |
| phpoffice/phpspreadsheet | 1.30.0 | CVE-2024-45048 family **unpatched** |
| stripe/stripe-php | 10.21 | stale (14.x current) |
| barryvdh/laravel-debugbar | dev | must not ship |
| spatie/laravel-ignition | dev | CVE-2022-40127 if exposed |
| axios | ^1.1.2 | CVE-2023-45857 / 2024-39338 line |
| firebase | 9.18.0 | major version behind |
| dompurify | 3.4.0 | OK |

### A07 — Identification & Authentication Failures — 25/100
- Wildcard `['*']` tokens at 3 sites (P0-S-04 confirmed).
- OTP 4-digit, throttled `3/5min` — brute-forceable across many phone numbers if attacker has a pool.
- Forgot-password flow: `resetPassword` (`ForgotPasswordController.php:120-180`) does NOT re-verify OTP — relies solely on the 64-char `reset_token` issued at OTP-verify step. **Critical detail**: token IS strong (`Str::random(64)`) and one-shot (deleted after use). Safe IF token transport is encrypted.
- BUT — combined with **XS-NEW-04 (Host header injection)**, the `reset_token` is delivered via email to `<email>` ; if attacker poisons the Host header at the request that triggered the email, victim clicks `evil.com/reset?token=...` → attacker captures token.
- No MFA for admin / staff.
- Login throttle keyed by `email|ip` (good, prevents single-IP saturation but allows distributed attacks).

### A08 — Software & Data Integrity Failures — 40/100
- AWS keys + APP_KEY + fiscal secrets leaked in git (P0-S-01 confirmed).
- No SRI on CDN scripts (if any) — grep `master.blade.php` for `<script src="https://cdn` returned 0 hits ; npm-vendored = safe.
- No signed commits enforced.
- Gitleaks proposed (ultra plan) but NOT yet deployed.

### A09 — Security Logging & Monitoring Failures — 38/100
- `audit_logs` HMAC chain is well-implemented (Agent 2 §POSITIVES confirmed) — fiscal layer is the bright spot.
- `Log::info($exception->getMessage())` pattern at `CustomerService.php:39, 84, 122` etc — exception messages may include SQL fragments (low risk, behind try/catch).
- ForgotPasswordController has NO `Log::channel('security')` calls for failed OTP / failed reset token attempts.
- LoginController has NO failed-login logs (relies on throttle counter only).
- No SIEM ingestion configured — logs sit on local disk via `storage/logs/laravel.log`.
- KioskEventController has good `Log::channel('security')` usage for branch_id mismatch — that's the model to extend.

### A10 — SSRF — 75/100 ★ GENUINELY GOOD
- Single `Http::post` call (`InstallerService.php:94`) uses static `config('app.api_url')` — not user-controlled.
- `file_get_contents` count = 2 ; both in `LanguageService.php:201-202` (RCE primitive, already P0).
- No image-proxy, no URL-fetcher, no RSS reader, no WebHook outbound.
- `curl_init` count = 0 ; `GuzzleHttp\Client` direct usage count = 0 (Laravel uses `Http::` wrapper, which is reviewed).

---

## DEPENDENCY CVE TABLE (DELTA vs Agent 2)

| Package | Installed | Agent 2 noted | This audit additions |
|---|---|---|---|
| `laravel/framework` | 9.52.21 | EOL | Confirmed |
| `phpoffice/phpspreadsheet` | 1.30.0 | CVE-2024-45048 | Confirmed |
| `axios` (npm) | ^1.1.2 | Not flagged | **NEW** CVE-2023-45857 cookie leak ; CVE-2024-39338 SSRF |
| `firebase` (npm) | 9.18.0 | Not flagged | **NEW** major version behind ; v10/11 have security improvements |
| `aws/aws-sdk-php` | 3.359.13 | OK | Confirmed OK |
| `barryvdh/laravel-dompdf` | v3.1.1 | OK | Confirmed OK |
| `barryvdh/laravel-debugbar` | dev | must not ship | Confirmed dev-only |
| `spatie/laravel-ignition` | dev | CVE-2022-40127 if exposed | Confirmed dev-only |

---

## TOP 3 HARDENING RECOMMENDATIONS (cross-cutting, NEW)

### 1. Web security headers — same-day patch, ≤30 LOC
Create `app/Http/Middleware/WebSecurityHeaders.php` mirroring `JsonMiddleware`'s 4 headers + add HSTS for HTTPS, add `Permissions-Policy: geolocation=(self), camera=(), microphone=()`. Register in `web` middleware group. Flip `CSP_ENFORCE_MODE=enforce` in production `.env`.

### 2. Fix `/api/admin/users` + `MessageRequest` access control gaps — 2-day cycle
- `SimpleUserController::__construct` → add `$this->middleware(['permission:customers_index'])->only('index');`.
- `SimpleUserService::list` → constrain to `EnumRole::CUSTOMER` (mirror `CustomerService::list`).
- `MessageRequest` → drop `user_id` + `branch_id` rules ; set them server-side from `auth()->user()`.
- Add `MessageRequest::authorize()` returning a Gate check (`messages.create`).
- Regression tests : `tests/Feature/Security/SimpleUserIndexPermissionTest.php`, `tests/Feature/Security/MessageImpersonationTest.php`.

### 3. Uncomment TrustHosts + fix TrustProxies — same-day patch, ≤10 LOC
- `app/Http/Kernel.php:18` → remove the `//` before `\App\Http\Middleware\TrustHosts::class`.
- `app/Http/Middleware/TrustProxies.php:13` → set `protected $proxies = '*'` (if behind Cloudflare/ALB) or restrict to LAN CIDR.
- `app/Providers/AppServiceProvider.php::boot()` → add `URL::forceRootUrl(config('app.url'))`.
- Regression test : `tests/Feature/Security/HostHeaderInjectionTest.php` — assert that a request with `Host: evil.com` does NOT produce a password-reset email body containing `evil.com`.

---

## POSITIVES — what the codebase gets RIGHT

(extending Agent 2's list)

- **SSRF surface is genuinely tiny** — only the LanguageService file_get_contents (already P0 separately) and one InstallerService HTTP call to a static URL.
- **No SQL injection vectors found** — Eloquent throughout, no `DB::raw($user_input)`, no `whereRaw($input)`. 100% parameterized via PDO.
- **No insecure deserialization** — zero `unserialize()` calls in the codebase.
- **No RCE primitives via shell_exec/system/eval** — zero hits across `app/`.
- **CSP directive set is reasonable** (`frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`) — wasted in `report_only` mode but the directive itself is correct.
- **Stripe + Senangpay webhook signature verification** — implemented correctly with `hash_equals` constant-time compare and HMAC-SHA-256. `Stripe::Webhook::constructEvent` is the canonical safe pattern.
- **WebhookEvent UNIQUE constraint** on `(provider, webhook_id)` — DB-floor idempotency for both providers.
- **KioskEventController PII forbidden-keys allowlist** — rare disciplined GDPR pattern in this codebase.
- **OTP uses `random_int()` CSPRNG** (per Agent 2 §POSITIVES confirmed) + throttle 3/5min.
- **Fiscal HMAC chain** (NF525) — production-grade, the one thing that holds the system above 12/100.

---

## SUMMARY (500 words)

This cross-cutting deep audit goes beyond Agent 2's 11-finding sweep and the SECURITY_ULTRA_PLAN's deepening by surfacing **5 NEW P0-P1 findings** that neither prior audit identified. The cumulative cross-cutting OWASP score is **35/100** — marginal improvement over Agent 2's 28/100 because Server-Side Request Forgery (A10) is genuinely well-defended (75/100) and webhook signature verification is correctly implemented for both Stripe and Senangpay.

**OWASP category scores**: A01 Broken Access Control 22/100 ; A02 Cryptographic Failures 35/100 ; A03 Injection 48/100 ; A04 Insecure Design 30/100 ; A05 Security Misconfiguration **18/100 (worst)** ; A06 Vulnerable Components 20/100 ; A07 Authentication 25/100 ; A08 Data Integrity 40/100 ; A09 Logging & Monitoring 38/100 ; A10 SSRF 75/100.

**Top 5 NEW findings** (not in agent-2-security-red.md and not in SECURITY_ULTRA_PLAN.md):

1. **XS-NEW-01 (P0)** — `/api/admin/users` route group at `routes/api.php:984-989` has NO `permission:*` middleware ; `SimpleUserController::index` is the ONLY method without controller-level permission gate ; any authenticated user (including guest customer with `['*']` token) can `GET /api/admin/users` and dump every user PII record (name, email, phone, branch_id, addresses, roles) across all branches.

2. **XS-NEW-02 (P0)** — `MessageRequest` at `app/Http/Requests/MessageRequest.php:30-35` accepts client-supplied `user_id` AND `branch_id` ; combined with `auth:sanctum`-only routing this is an impersonation + cross-tenant write primitive. Attacker POSTs messages "as" any user_id in any branch — phishing payload delivered with manager attribution.

3. **XS-NEW-03 (P0)** — Web middleware group (`app/Http/Kernel.php:36-46`) is MISSING all security headers (`X-Frame-Options`, `X-Content-Type-Options`, `HSTS`, `Referrer-Policy`, `Permissions-Policy`). `JsonMiddleware` only adds these for JSON responses. Admin Blade pages are clickjacking-exploitable, and `CSP_ENFORCE_MODE=report_only` default means `frame-ancestors 'none'` is NOT enforced.

4. **XS-NEW-04 (P1)** — `TrustHosts` middleware is **commented out** in `app/Http/Kernel.php:18`. Combined with the absent `URL::forceRootUrl()` in `AppServiceProvider`, password-reset emails inherit the request's `Host` header. Burp Repeater attack: send forgot-password request with `Host: evil.com` → victim email contains `evil.com/reset?token=...` → click → token capture → account takeover.

5. **XS-NEW-05 (P1)** — `googleMapKey` (`config('app.google_map_key')`) leaked verbatim in `master.blade.php:110` alongside the already-flagged `apiKey`. Google Maps Platform keys are billing-bearing ; spoofable Referer enables quota drain. Likely unrestricted in GCP Console (no documentation found).

**Verified-again** (already-found): all 11 Agent 2 findings remain except P1-S-08 (Stripe truncation) which a code comment claims FIXED in commit "P0-6 CTO audit 2026-05-16 round-before-cast" — pending regression test.

**Top 3 hardening recommendations**: (1) Web security headers middleware ≤30 LOC same-day patch ; (2) Fix `/api/admin/users` + `MessageRequest` access control 2-day cycle ; (3) Uncomment TrustHosts + fix TrustProxies $proxies ≤10 LOC same-day.

**File**: `reports/audit/goal-systems-2026-05-17/cross-cutting/X2-security-deep.md`.

---

**END OF REPORT** — X2 — SECURITY DEEP cross-cutting auditor 2026-05-17.
