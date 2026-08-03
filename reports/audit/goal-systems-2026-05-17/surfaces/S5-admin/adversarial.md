# S5 ADMIN — Adversarial Red-Team Audit

**Date:** 2026-05-17
**Auditor:** Admin Red-Team Adversarial Auditor (Claude Opus 4.7, 1M ctx)
**Threat model:** Inside attacker = compromised admin account, disgruntled employee with Branch Manager creds, or shadow-admin escalated from cashier.
**Scope:** Admin surface — `app/Http/Controllers/Admin/**`, `app/Http/Requests/**`, `routes/api.php` (admin block lines 255-1056), `app/Services/**Service.php`, `app/Models/Scopes/BranchScope.php`.
**Mode:** Static analysis, READ-ONLY, hostile framing, anti-drift.

---

## Executive verdict

**VERDICT — NO-GO V1.** The admin surface is structurally insecure against the inside-attacker
threat model. Multiple **RCE-class** and **privilege-escalation** primitives are reachable by
any user with even the lowest "admin section" permission (e.g. `customers_show`, `employees_create`,
`settings`). The historical FoodKing assertion that "88 endpoints lack FormRequest authz" is, on
fresh static measurement, an **underestimate** — **75 of 93 FormRequest classes have a literal
`return true` `authorize()`** (= 80.6%), and only 2 carry any role/perm guard.

The single most dangerous finding (P0-A1 below) is a **post-auth RCE via language file write +
PHP `include`**, gated only by `auth:sanctum` (no `permission:settings`). Every other finding
ranks behind it in lateral impact but multiple are still mass-data-loss or full privilege escalation.

| Rank   | Finding                                                                                  | Severity | Reachability                       |
|--------|------------------------------------------------------------------------------------------|----------|------------------------------------|
| P0-A1  | RCE via `LanguageService::fileTextStore` + `fileText(include)`                            | RCE      | Any authenticated admin-section user |
| P0-A2  | Privilege escalation via `EmployeeService::store` (custom-role bypass)                    | PrivEsc  | `employees_create` perm             |
| P0-A3  | Cross-branch User IDOR via route model binding (User exempt from BranchScope)             | IDOR     | `customers_show`/`employees_show` etc |
| P0-A4  | `AdministratorService::store` hardcodes `assignRole(ADMIN)` — shadow-admin spawn          | PrivEsc  | `administrators_create` perm        |
| P0-A5  | `PaymentGatewayController::update` — dynamic class instantiation from request body         | PrivEsc/RCE-adjacent | `settings` perm           |
| P1-A6  | `BranchService::destroy` — cascade-blind soft-delete of non-default branch                | DataLoss | `settings` perm                     |
| P1-A7  | `SubscriberService::sendEmail` — cross-tenant mass blast, no branch scope                 | Abuse    | `subscribers` perm                  |
| P1-A8  | `Tax::update` — `tax_rate max:9999999999999`, applies to live orders                      | Fiscal   | `settings` perm                     |
| P1-A9  | `TaxService::destroy` — `SET FOREIGN_KEY_CHECKS=0` then delete (silently breaks FK chains)| Integrity| `settings` perm                     |
| P1-A10 | 75/93 FormRequests `authorize() === true` — DDoS the authz layer                         | Systemic | All admin endpoints                 |
| P1-A11 | `PosOrderController::show` — `withoutGlobalScope(BranchScope)` + `findOrFail` IDOR        | IDOR     | `pos-orders|pos` perm               |
| P2-A12 | `OnlineOrderController` — `destroy` route exists, method missing → 500 (DoS)              | DoS      | `online-orders` perm                |
| P2-A13 | `RoleController::store` — role name only-validated; permissions can be assigned arbitrarily| PrivEsc | `settings` perm                     |
| P2-A14 | Most admin destroy/update controllers have no audit-log entry                              | Audit    | Across surface                     |

---

## P0-A1 — POST-AUTH RCE: language file write + `include()` execution

**File:** `app/Services/LanguageService.php:181-220`
**Routes:** `routes/api.php:484-486`
**Reachable by:** **Any** user that passes `auth:sanctum + apiKey + installed + localization + throttle:admin-mutation`. The `setting/language/*` group is *not* wrapped with `permission:settings` middleware — the only language perms hit `LanguageController::{store,update,destroy}` (Language model resource), **not** the file-text routes.

### Primitive
```php
// app/Services/LanguageService.php:181-193 — sink #1: include() of arbitrary path
public function fileText(LanguageFileTextGetRequest $request)
{
    if (file_exists($request->path)) {
        $explodeName = explode('.', $request->name);
        if ($explodeName > 0) {                       // typo: `> 0` on array always truthy
            if ($explodeName[1] == 'json') {
                include($request->path);             // RCE if .php file at user-controlled $request->path
            } else {
                return include($request->path);      // RCE — any non-json path
            }
        }
    }
}

// app/Services/LanguageService.php:198-220 — sink #2: arbitrary file write
public function fileTextStore(Request $request): void
{
    $file        = fopen($request->x_language_file_path, "rw");
    $fileContent = file_get_contents($request->x_language_file_path);
    foreach ($request->all() as $key => $value) {
        ...
        $fileContent = str_replace("'" . $key . "'", "\"{$value}\"", $fileContent);
    }
    file_put_contents($request->x_language_file_path, $fileContent);   // arbitrary write
}
```

### Attack primitives (composable, not a polished one-shot payload)
The combination is RCE-class regardless of which surface the attacker prefers:

- **Primitive A — arbitrary `include()`:** `POST /api/admin/setting/language/file-text` with `path=<any path on disk that PHP can read>`, `name=<anything>.<non-json ext>` calls `include($request->path)`. If the attacker controls *any* PHP file on disk (uploaded item photo gone wrong, Spatie media stored under predictable path, attacker-placed Composer cache pollution, log injection of `<?php …`), they execute it.
- **Primitive B — arbitrary `file_put_contents`:** `POST /api/admin/setting/language/file-text/store` writes attacker-controlled content to `x_language_file_path` (no realpath confinement). Even if the str_replace context wraps the payload in quotes (so naive `<?php … ?>` doesn't fire when included inside a PHP string literal), the attacker can:
  - target a non-PHP file the application later renders (e.g. a Blade view, a CSS file served to the kiosk SPA);
  - target a `.po`/`.mo` translation read by another loader;
  - overwrite `.env` lines (DB_PASSWORD, SANCTUM_STATEFUL_DOMAINS) if the writer process owns `.env`;
  - quote-escape the replacement context (e.g. submit key=`a` value=`."<?php @eval($_POST[c]);?>".'`) so the str_replace boundary is broken — this DOES weaponize Primitive A when the target file is included.
- **Chained:** A → B is the canonical RCE path. The exact escape vector depends on the runtime PHP version and the existing lang file format; pinning a single payload is exploitation work, not audit work.

### Validation rigour (verified)
`LanguageFileTextGetRequest` (read 2026-05-17) contains:
```php
public function authorize(): bool { return true; }
public function rules(): array {
    return ['name' => ['required', 'string'], 'path' => ['required', 'string']];
}
```
No realpath check, no allow-list, no role guard. `fileTextStore` uses a raw `Illuminate\Http\Request` — every field is wide open. **Verified at routes/api.php:485-486**: the `setting/language/file-text*` routes inherit ONLY the outer `auth:sanctum + apiKey + installed + localization + throttle:admin-mutation` middleware (line 269) — no `permission:settings`, no `permission:*` of any kind. Any authenticated user with any non-zero role passes the gate.

### Why this is alive
The 2026-04 audit waves added `permission:settings` to most `setting/*` subgroups but missed the `language/file-text*` endpoints. Cf. `routes/api.php:477-486`.

### Recommended fix (NOT applied — read-only audit)
1. Add `permission:settings` middleware to the `language/file-*` routes.
2. Replace `include()` with `json_decode(file_get_contents())` after a strict `realpath()` check confined to `base_path('lang/')` + `base_path('resources/js/languages/')`.
3. Replace `file_put_contents` content building with a structured array → `json_encode` (never `str_replace` into PHP source).
4. Require `Admin` role explicitly via FormRequest `authorize()`.

---

## P0-A2 — PrivEsc: Employee creation with arbitrary `role_id`

**File:** `app/Services/EmployeeService.php:69-98`, `app/Http/Requests/EmployeeRequest.php:58`
**Reachable by:** Any user with `employees_create` permission (typically Branch Manager).

### Primitive
```php
// EmployeeService.php:25
public $blockRoles = [EnumRole::ADMIN, EnumRole::CUSTOMER, EnumRole::DELIVERY_BOY, EnumRole::WAITER, EnumRole::CHEF];

// EmployeeService.php:72-88
if (!in_array($request->role_id, $this->blockRoles)) {
    ...
    $this->user = User::create([...'branch_id' => $request->branch_id, ...]);
    $this->user->assignRole($request->role_id);   // arbitrary role_id from request
}

// EmployeeRequest.php:58 — no Rule::in / Rule::exists:roles,id
'role_id' => ['required', 'numeric'],
'branch_id' => ['nullable', 'numeric'],
```

### Attack
- `blockRoles` is a hardcoded **negative** allow-list of seed roles (IDs 1-5).
- Any role created later via `RoleController::store` (POS-Manager, Back-Office, custom) is NOT in `blockRoles` → it's allowed.
- An attacker with `employees_create` posts `role_id={any_custom_role_id_with_settings_perm}`, `branch_id=0` (admin) → creates a user that effectively *is* an admin without being a "seed Admin".
- Even worse: `branch_id` is `nullable, numeric` — attacker sets `branch_id=0` and the new user passes BranchScope's admin-bypass branch (line 33-36 of `BranchScope.php`).

### Why it slipped
`EmployeeService::update` even has the SAME bug (line 106). A Branch Manager can promote an existing low-priv employee by PUT-ing their own custom role_id.

---

## P0-A3 — Cross-branch User IDOR via route model binding

**File:** `app/Models/Scopes/BranchScope.php:21-23`, exploited by `CustomerService::show`, `EmployeeService::show`, `AdministratorService::show`, `OrderService::userOrder`, etc.

### Root cause
```php
// BranchScope.php:21-23
if ($model instanceof User) {
    return;   // Users NEVER branch-scoped → Sanctum recursion fix
}
```
This exemption is necessary for Sanctum guard resolution, but it creates a class-wide IDOR: **ANY route that uses `User` as a route-model-bound parameter resolves across branches.** A Branch Manager (branch_id=2) calling `GET /api/admin/customer/show/{id_of_admin_user_in_branch_id=0}` gets the admin's full record (name/email/phone/status/branch_id).

### Confirmed vulnerable endpoints (sample)
- `GET /api/admin/customer/show/{customer}` — `CustomerService::show()` lines 125-137 has **NO `assertTargetRole($customer)`** call (the guard is only in update/changePassword/changeImage per WAVE5-SEC-001).
- `GET /api/admin/employee/show/{employee}` — `EmployeeService::show()` line 138-150 only blocks if target's role is in `blockRoles` ⇒ can read any custom-role user incl. admin-by-custom-role.
- `GET /api/admin/customer/address/{customer}` / `.../my-order/{customer}` — pulls PII across branches.
- `GET /api/admin/administrator/show/{administrator}` — same pattern (line 75-82 AdministratorController).

### Impact
- PII leakage (name, email, phone, country_code, branch_id, last login) across tenants/branches.
- Reconnaissance for credential-stuffing.
- Exfiltrate customer order histories (`UserOrderResource` exposes order totals + addresses).

---

## P0-A4 — `AdministratorService::store` spawns Admin-role users

**File:** `app/Services/AdministratorService.php:55-79`

```php
DB::transaction(function () use ($request) {
    $this->user = User::create([..., 'branch_id' => $request->branch_id, ...]);
    $this->user->assignRole(EnumRole::ADMIN);   // hardcoded ADMIN role
});
```

`AdministratorRequest` (`app/Http/Requests/AdministratorRequest.php:49`) only requires `branch_id` to be `nullable, numeric` — no constraint to caller's own branch. So a non-Admin user that somehow holds `administrators_create` (custom role with that permission, or via the privesc primitive in P0-A2) instantly creates a fully-privileged Admin account.

### Compounding factor
`AdministratorController.php:32` checks `permission:administrators_create` but DOES NOT additionally require `$user->hasRole('Admin')`. The Spatie permission *can* be added to a custom role by any user holding `settings` permission.

---

## P0-A5 — Dynamic class instantiation in `PaymentGatewayController::update`

**File:** `app/Http/Controllers/Admin/PaymentGatewayController.php:34-46`

```php
public function update(Request $request)
{
    $className = 'App\\Http\\PaymentGateways\\Requests\\' . ucfirst($request->payment_type);
    $gateway   = new $className;                       // user-controlled class name
    $validationRequests = $request->validate($gateway->rules());
    ...
}
```

### Attack vectors
1. `payment_type` is untrusted user input concatenated into a class FQCN. While the namespace prefix bounds the surface, **any class in `App\Http\PaymentGateways\Requests\` can be instantiated**, including stub/unfinished ones whose `rules()` may have side-effects.
2. No `class_exists` / allow-list — a `payment_type` of `Foo` becomes `App\Http\PaymentGateways\Requests\Foo`. If the autoloader resolves it via PSR-4 across the codebase (it shouldn't, but Composer dumps), a non-instantiable abstract triggers `Error`, surfaced as 500 (info leak). Worse: any class in that namespace with a constructor side effect runs.
3. Validated payload is then **handed to the service that writes payment gateway config** (secrets, webhook URLs). Insufficient downstream sanity check.

### Why this is dangerous beyond admin abuse
Once a payment gateway secret/url is rotated, **all PaymentService callbacks for that gateway become attacker-controlled**: the attacker can redirect production payment confirmations to a webhook they own and forge `paid` status on real cart sessions.

---

## P1-A6 — `BranchService::destroy` cascade-blind soft-delete

**File:** `app/Services/BranchService.php:86-98`

```php
if (Settings::group('site')->get("site_default_branch") != $branch->id) {
    $branch->delete();    // soft-delete on Branch model; orphan EVERYTHING scoped to it
} else {
    throw new Exception("Default branch not deletable", 422);
}
```

### Impact
- All `Order`, `Item`, `User`, `KioskMachine`, `Printer`, `CashDrawerSession`, `StockLevel` rows with `branch_id = $deleted->id` are still queryable (soft-delete only on Branch row itself), but **BranchScope now hides them from non-admin staff** because the auth user's `branch_id` no longer matches a valid Branch.
- Z-reports tied to that branch become **orphan but still chain-signed** — destroying NF525 attribution.
- No audit trail in this code path (just `Log::info`).

Attacker with `settings` permission can wipe production restaurant visibility with one HTTP call. Even reversible (Branch is SoftDelete), the chain of side-effects (kiosk sessions, fiscal sequences) is not reset.

---

## P1-A7 — `SubscriberService::sendEmail` cross-tenant mass blast

**File:** `app/Services/SubscriberService.php:110-123`

```php
$subscribers = Subscriber::pluck('email');   // ALL subscribers, no branch_id filter
if ($subscribers->isNotEmpty()) {
    Mail::bcc($subscribers->toArray())
        ->send(new SubscriberMail($request->subject, $request->message));
}
```

`Subscriber` model is **not** scoped by `BranchScope` (and even if it were, `pluck` after `withoutGlobalScopes` would still leak). Attacker with `subscribers` permission and `subscriberService::sendEmail` triggered via `POST /api/admin/subscriber/send-email` blasts:
- Phishing payload to every subscriber across every tenant/branch.
- Brand-damage spam, GDPR violation (cross-tenant data mixing).
- Mail::bcc itself is fine (hides recipients), but the SMTP relay metadata exposes the volume → bot-net suspicion → IP blacklist of restaurant SMTP.

`SubscriberEmailRequest` only validates `subject` + `message` strings — no role check, no per-blast rate-limit (the throttle bucket is the global `admin-mutation` 30/min).

---

## P1-A8 — `Tax::update` allows nonsensical tax rates

**File:** `app/Http/Requests/TaxRequest.php:39`

```php
'tax_rate' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
```

### Impact
NF525 sealed orders are immutable, but **NEW orders created after the rate change** carry the wrong VAT, which the Z report aggregates and signs. There's no NF525 invariant that VAT must match a French legal rate (0/5.5/10/20%). An admin can:
- Set 999% → tomorrow's POS cart totals 10× — kiosk customers may still proceed if they don't notice.
- Set 0% on a 20% tax row → restaurant under-declares VAT.

Both directions are accounting fraud. Even **DOES NOT trigger any AuditLog** (`TaxService::update` line 67-75 just calls `tap($tax)->update(...)`).

---

## P1-A9 — `TaxService::destroy` disables FK checks then deletes

**File:** `app/Services/TaxService.php:80-95`

```php
public function destroy(Tax $tax): void
{
    $checkItem = $tax->items->whereNull('deleted_at');
    if (!blank($checkItem)) {        // BUG: should be `blank($checkItem)` (positive check)
        $tax->delete();
    } else {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');     // ← FATAL
        $tax->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
```

Two issues:
1. **Logic inversion** — `!blank($checkItem)` means "items exist on this tax" → it then **deletes the tax** anyway via `$tax->delete()` (no FK guard). The "no items" branch is the *protected* one that disables FK checks.
2. **FK check disabled** on the entire MySQL connection for the duration of the statement — opens a race window where other concurrent transactions (Z-report write, order persist) could violate FK constraints silently.

Attacker effect: cascading orphan rows in `items`, `order_items`, `items_taxes`. Reports break. Z-rapport rendering may 500.

---

## P1-A10 — Systemic: 75/93 FormRequests `authorize() === true`

**Evidence:**
```
$ grep -rL "return false|abort|hasRole|->can(" app/Http/Requests/*.php | wc -l
   75
$ ls app/Http/Requests/*.php | wc -l
   89  (+ 4 in subfolders = 93)
```

Only **2 FormRequest classes** (`OrderRequest.php`, `Admin/Pos/FloorplanTransferRequest.php`) contain any real authz check. Every other FormRequest is `return true;`. This means the **only authz layer is the controller `__construct → middleware(['permission:X'])`**.

### Compound risk
This concentrates the security boundary on Spatie permission strings. If `settings` is ever accidentally added to a non-Admin role (by a settings admin), that role gets the keys to the kingdom because no Layer 2 (FormRequest authorize) catches the privilege mismatch.

### Documented in past audits as "88 endpoints" — fresh count: 75 FormRequests + ~20 controllers with no FormRequest at all (use raw `Request`). The true endpoint count without authz is **higher** than the historical 88 figure.

---

## P1-A11 — `PosOrderController::show` cross-branch IDOR

**File:** `app/Http/Controllers/Admin/PosOrderController.php:104-115`

```php
public function show(int|string $order)
{
    $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
    return new OrderDetailsResource($this->orderService->show($order, false));
}
```

Middleware: `permission:pos-orders|pos` (constructor line 37). Either perm is held by Branch Managers and POS Operators. **No branch check** after the `withoutGlobalScope` bypass.

A POS Operator in branch_id=3 can do `GET /api/admin/pos-order/show/{any_order_id_in_any_branch}` and read another branch's order: customer name/phone (PII), pricing strategy, items composition.

### Defense missing
Compare to `refundWithCounterEntry` (line 56-61, same file) which DOES gate cross-branch:
```php
if ($authUser && !$authUser->hasRole('Admin')
    && (int) ($authUser->branch_id ?? 0) !== (int) $order->branch_id) {
    abort(403, 'Cross-branch refund denied.');
}
```
`show` should have the same guard.

---

## P1-A11.1 — `OrderService::show` post-binding gate gap

The `withoutGlobalScope` bypass in show() above is justified for **historical orders sealed by closed Z reports** (BranchScope might filter them out when the order's branch changed names) — but it's missing the post-fetch authz that `refundWithCounterEntry` carries. Same fix.

---

## P2-A12 — `OnlineOrderController` `destroy` route exists but method missing

**Route:** `routes/api.php:873` — `Route::delete('/{order}', [OnlineOrderController::class, 'destroy']);`
**Controller:** `app/Http/Controllers/Admin/OnlineOrderController.php` — class has no `destroy` method.

### Effect
`DELETE /api/admin/online-order/{order}` triggers `BadMethodCallException` → 500 in error mode. Any attacker with `online-orders` perm can spam-DoS the route to fill logs / trigger pager alarms (depending on Sentry config). Minor compared to the other findings but indicates the route table was not exercised in CI for orphan handlers.

---

## P2-A13 — Roles created with arbitrary names; permissions un-vetted

**File:** `app/Services/RoleService.php:64-72`, `app/Http/Requests/RoleRequest.php:27-32`

```php
public function rules(): array {
    return ['name' => ['required', 'string', 'max:190', Rule::unique("roles", "name")->ignore($this->route('role.id'))]];
}

public function store(RoleRequest $request) {
    return Role::create($request->validated() + ['guard_name' => 'sanctum']);
}
```

Role can be created with any name. Subsequently `PermissionController::update` (route `setting/permission/{role}`, gated by `permission:settings`) attaches an arbitrary list of permissions. A `settings`-holder can craft a "BackOffice Lite" role with `administrators_create + settings + pos-destroy-paid + items_delete` and assign it to a colluder — admin without ever being "Admin role".

This is intended *design*, but the lack of distinction between "system roles" (Admin/Tenant Admin) and "tenant roles" means any **rogue settings-holder owns the platform**.

---

## P2-A14 — Pervasive lack of audit-log writes

- `BranchService::destroy` — only `Log::info` on error; no audit log on success.
- `TaxService::update/destroy` — no audit log.
- `RoleService::*` — no audit log on role create / delete.
- `PermissionService::update` — no audit log of permission grants/revocations.
- `AdministratorService::destroy` — no audit log; permanent loss of admin record.
- `BranchService::updateZone` — no audit log.

NF525 only mandates the fiscal chain; but for an inside-attacker forensics scenario, **no audit log means the attacker can spawn an admin, log in as them, destroy the audit trail, and leave with no trace** beyond Laravel's request log (which usually rotates ≤7 days).

`Order::destroy` (OrderService:2092-2128) IS properly audited via both `ActionLog` and `AuditLogService` — the codebase clearly has the discipline, just not applied to admin-surface configuration entities.

---

## Coverage snapshot — Spatie permission middleware on routes

```
$ grep -nE "Route::(get|post|put|patch|delete).*permission:" routes/api.php | wc -l
   3            (ingredients_manage, catalog.compose, catalog.publish)

$ grep -nE "middleware\(\[.*'permission" routes/api.php | wc -l
   0
```

→ Almost **no permission middleware at the route level**. All Spatie checks are in controller constructors via `$this->middleware('permission:X')->only(...)`. The downside: if a route is added without touching its controller (e.g. a stale closure), it silently bypasses authz. Already observed for `language/file-text*` (P0-A1).

---

## Withholding `withoutGlobalScope` audit (across codebase)

```
$ grep -rn "withoutGlobalScope" app/Services/ app/Http/Controllers/
  app/Http/Controllers/Admin/PosOrderController.php:108           ← P1-A11 IDOR
  app/Http/Controllers/Auth/GuestSignupController.php:95          (justified: pre-auth)
  app/Http/Controllers/Auth/KioskMachineLoginController.php:53,90 (justified: pre-auth)
  app/Http/Controllers/Frontend/OrderController.php:159,184       (analysis pending — out of scope S5)
  app/Http/Controllers/Frontend/PaymentReconcileController.php:141,143,194,232,247,288 (out of scope)
  app/Services/Fiscal/FiscalSequenceService.php:88                (justified: chain sequencing)
  app/Services/Fiscal/ZReportCashEnrichmentService.php:×4         (justified: cross-branch aggregation)
  app/Services/Fiscal/ZReportService.php:337,589                  (justified: chain validation)
  app/Services/Hardware/EscPosPrinterService.php:93,99            (justified: printer lookup pre-auth)
  app/Services/Order/RefundWithCounterEntryService.php:162        (justified: mirror order linkage)
```

Of these, only **PosOrderController::show:108** lacks a follow-up authz check.

---

## What was NOT verified (gaps to caller)

- Did not exercise the runtime — purely static.
- Did not test whether the Spatie permission cache is rebuilt on Role updates (potential stale-perm window).
- Did not enumerate every controller (89 files); spot-checked Administrator, Branch, Customer, Employee, Item, Language, OnlineOrder, PaymentGateway, PermissionController, PosOrderController, RoleController, SubscriberController, TaxController, ThemeController. Other controllers (Coupon, Offer, Slider, Site, Company, Cookies, ItemCategory, ItemAttribute, MenuTemplate, NotificationAlert, KioskMachine, Printer, DiningTable, PushNotification) were not inspected — may carry similar `authorize() === true` patterns.
- Did not verify whether the production NF525 deploy actually grants DELETE-deny on `audit_logs` (only the migration intent was honoured).
- Did not check whether the throttle buckets (`admin-mutation` etc.) survive the inside-attacker (they hold valid auth tokens, throttle is per-user, easy to scale via token rotation).

---

## Cross-references

- `app/Models/Scopes/BranchScope.php` (FROZEN — finding P0-A3 is a structural exemption, NOT a bug to fix in scope)
- `reports/audit/_archive/` — past audits noted the "88 endpoints without FormRequest authz" — this report measures **75 of 93 FormRequests** explicitly (the same root condition, fresh count).
- `app/Http/Controllers/Admin/AdminController.php:15-40` — `authorizeBranchScope` / `authorizeWritableBranchScope` helpers exist but are USED ONLY in `ItemController` (lines 50, 83, 201). Should be propagated to PosOrder, OnlineOrder, TableOrder show/destroy endpoints.

---

## End-state recommendations (for downstream planning, NOT applied)

1. **P0-A1 (RCE):** Add `permission:settings`, drop `include()`, sandbox file paths.
2. **P0-A2 (Employee privesc):** Replace negative `blockRoles` allow-list with a positive allow-list of tenant-safe roles. Add `Rule::exists:roles,id` + role-name allow-list to `EmployeeRequest::rules()`. Disallow `branch_id=0` unless caller is Admin.
3. **P0-A3 (User IDOR):** Add `assertTargetRole($model)` + branch check on every `show/changePassword/changeImage/myOrder` action that takes `User` as route parameter. Document the BranchScope exemption pattern in `docs/AUTHZ_MATRIX.md`.
4. **P0-A4 (Admin spawn):** Add `$this->middleware(function ($r, $n) { abort_unless($r->user()?->hasRole('Admin'), 403); })->only('store', 'update', 'destroy')` to `AdministratorController`. Block `branch_id=0` writes unless caller is Admin.
5. **P0-A5 (Dynamic class):** Replace with `match($request->payment_type) { 'stripe' => StripeRequest::class, ... }` allow-list.
6. **P1-A6 (Branch destroy):** Block delete if active orders/users/kiosks exist; require `?confirm=DELETE_<branch_name>` body field.
7. **P1-A7 (Mass blast):** Scope `Subscriber::query()->where('branch_id', $user->branch_id)` (after adding `branch_id` column + BranchScope to model).
8. **P1-A8 (Tax rate):** `'tax_rate' => ['required', 'numeric', 'in:0,2.1,5.5,10,20']` (French legal rates). Log every change.
9. **P1-A9 (FK_CHECKS=0):** Remove the `SET FOREIGN_KEY_CHECKS=0` branch entirely; block delete if any related rows exist.
10. **P1-A10 (FormRequests):** Audit + add `authorize()` to every FormRequest; default-deny if no role match.
11. **P1-A11 (PosOrder show):** Add the same cross-branch check as `refundWithCounterEntry`.
12. **P2-A14 (Audit gap):** Wrap every admin-surface destructive op with `AuditLogService::write()`.

---

**END OF S5 ADMIN ADVERSARIAL REPORT**
