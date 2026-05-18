# Wave W4 — T-2.2.1 Architect Audit (Round 3)
## Role/Permission CRUD authz (Spatie 5 sync)

**Mission:** `goal-ultra-central-mgmt-sync-2026-05-18` | Round 3 / W4 / T-2.2.1
**Role:** ARCHITECT (read-only — Read + Bash grep)
**Anchors:** `app/Http/Controllers/Admin/{Role,Permission,Administrator}Controller.php`, `app/Http/Requests/{Role,Permission,Administrator,Branch}Request.php`, `app/Services/{Role,Permission,Administrator,Employee,DeliveryBoy}Service.php`, `app/Providers/AuthServiceProvider.php`, `database/seeders/{RoleTable,RolePermissionTable}Seeder.php`, `database/seeders/SpatieRoleLookup.php`, `vendor/spatie/laravel-permission/src/Traits/HasRoles.php`, `routes/api.php:453-464,901-923`.
**Cross-ref Rounds 1+2:** R2-T-1.3.1 Sec S-1 (BranchManager + setBranch dead path); R2-T-1.3.1 DBA (`->role($int)` class-of-bug); R2-T-2.4.2 Sec (no audit trail on settings); R1 MGMT-P0-B (Ingredient DoS via `permission:ingredients_manage`). Commit `10a00c127` (2026-05-18 10:29) closed the LIST half of the class-of-bug; the WRITE/CHECK half (4× `hasRole(int)` in AdministratorService) is **untouched**.
**Spatie version (verified):** `5.11.1` (`composer.lock:6700`). `permission.teams=false`, `permission.cache.expiration_time=24h`, `permission.cache.key='spatie.permission.cache'`, `permission.cache.store='default'` (cache.php default = redis in prod, array in test). Guard = `sanctum` (`auth.php:17`).
**Date:** 2026-05-18

---

## Verdict — **NO-GO V1 ABSOLUTE-AS-IS**

PermissionController exposes `GET /admin/permission/{role}` with **zero authz middleware** and PermissionRequest authorize() **returns true unconditionally** — any auth:sanctum holder (POS Operator, Chef, Waiter, even a Customer with a stale token if branch_id check is the only gate) can enumerate the permission matrix of any role globally, including Admin. This is a direct miss in the Wave 5H FormRequest hardening that fixed Role/Administrator/Branch but left Permission. Compounding it, the class-of-bug heal commit `10a00c127` patched `User::role(EnumRole::ADMIN)` (LIST endpoint at L46) but left 4 callsites of `$administrator->hasRole(EnumRole::ADMIN)` (L119, L145, L162, L181) — Spatie 5 `HasRoles::hasRole(int)` calls `contains($key, $roles)` against `roles.id` (`vendor/spatie/laravel-permission/src/Traits/HasRoles.php:202`). After a fresh seed lands roles at ids 73-80, **every admin destroy/show/changePassword/changeImage path hard-fails with `permission_denied`** — a real functional break, not theoretical. V1 Le Cayenne 1-tenant unexposed for cross-branch leak (1 branch); V2-SaaS day-1 hard-fail on both vectors. Self-revoke also unguarded (admin can `syncRoles([])` themselves to a stripped state via EmployeeService).

---

## §1 — Coverage Map

### §1.1 — Route → controller → request → service trace (5 entry points)

| Route | Controller | Middleware | FormRequest authz | Service |
|---|---|---|---|---|
| `GET /admin/role` | `RoleController@index` | `permission:settings\|employees` (controller) | `PaginateRequest` (none) | `RoleService::list` |
| `POST /admin/role` | `RoleController@store` | `permission:settings` | `RoleRequest@authorize → can('settings')` ✅ | `RoleService::store` |
| `PUT/PATCH /admin/role/{role}` | `RoleController@update` | `permission:settings` | `RoleRequest@authorize → can('settings')` ✅ | `RoleService::update` |
| `DELETE /admin/role/{role}` | `RoleController@destroy` | `permission:settings` | none (no request param) | `RoleService::destroy` (5-id blocklist) |
| **`GET /admin/permission/{role}`** | **`PermissionController@index`** | **NONE — only outer `auth:sanctum`** | **n/a (GET)** | **inline DB join (lines 33-42)** |
| `PUT/PATCH /admin/permission/{role}` | `PermissionController@update` | `permission:settings` | **`PermissionRequest@authorize → return true`** ⚠️ | `PermissionService::update` |
| `GET /admin/administrator` | `AdministratorController@index` | `permission:administrators` | `PaginateRequest` | `AdministratorService::list` (uses `->role('Admin','sanctum')` post-heal) |
| `POST /admin/administrator` | `AdministratorController@store` | `permission:administrators_create` | `AdministratorRequest@authorize → can(create OR edit)` ✅ | `AdministratorService::store` (`assignRole(EnumRole::ADMIN=1)`) |
| `DELETE /admin/administrator/{administrator}` | `AdministratorController@destroy` | `permission:administrators_delete` | none | `AdministratorService::destroy` (**`hasRole(int)` broken**) |

### §1.2 — Spatie 5 callsite census (writers/checkers)

**Stable (NAME):** `IdempotencyKeyMiddleware:191 'Admin'`, `Provider AuthServiceProvider:31 'admin'` (⚠ lowercase typo — see F3), `AdminController:22,34 'Admin'/'Tenant Admin'`, `Items/Pos/Composer/MenuProjection/Dashboard/TransactionService/OrderStatusScreen/OrderService:1731,1911,2368 'Admin'`, `SpatieRoleLookup` (centralised name-resolver, used by `RolePermissionTableSeeder`, `PermissionTableSeederVersionTwo`).

**Broken (INT):** `AdministratorService:119,145,162,181 hasRole(EnumRole::ADMIN=1)`, `DeliveryBoyService:150,182 hasRole(EnumRole::DELIVERY_BOY=3)`, `EmployeeService:160 hasRole(optional($employee->roles[0])->id)` (incoherent — `roles[0]->id` is a real DB id, so it always returns true tautologically, defeating the guard), `OrderService:2117 ->role(\App\Enums\Role::DELIVERY_BOY)` (still int — sibling heal missed this read-only callsite), `AdministratorService:74 assignRole(EnumRole::ADMIN=1)` (Spatie's `assignRole(int)` also goes through `getStoredRole(int)` and `findById(int)` — same class-of-bug for the WRITE side).

**`Gate::before` dead branch:** `AuthServiceProvider:31` uses `hasRole('admin')` lowercase, but every seeded role is capitalized (`RoleTableSeeder:19 'Admin'`). Returns `null` always — Super-Admin bypass never fires. Operational impact muted: Admin role gets `Permission::all()` via `RolePermissionTableSeeder:18-19`, so `$user->can(...)` passes through real permission rows. Dead-code, not break.

### §1.3 — Self-revoke / self-modify surface

`AdministratorService::destroy` line 118 guards `Auth::user()->id != $administrator->id && $administrator->id != 1` — protects against deleting self + protects super-admin id=1 *if* it survived the auto-increment skip. The id=1 guard is a P0 latent if fresh seed lands users beyond 1 (which it does — admin seed ID drift is the same class as the role drift). **EmployeeService::update at L123 calls `syncRoles($request->role_id)` with NO Auth::id check** — an admin can therefore call `PATCH /admin/employee/{self}` with `role_id=<Customer>` and self-strip Admin role unless `permission:employees_edit` middleware on the controller covers it (controller L34 enforces it for non-Admin, but Admin holds the permission). Combined with `Gate::before('admin')` being dead, there's no global override. **No `authorize` callback prevents privilege downgrade of last remaining Admin.**

### §1.4 — Permission propagation

Spatie 5.11 invalidates `spatie.permission.cache` on every `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, `assignRole`, `removeRole`, `syncRoles` via `PermissionRegistrar::forgetCachedPermissions()`. **Verified at `IngredientPermissionSeeder:34` (called explicitly post-`givePermissionTo`).** `PermissionService::update` (L42) uses `syncPermissions` — flush happens automatically. **BUT**: cache is a singleton with 24-hour TTL; in production with **Redis backed by `permission.cache.store='default'`** and a **multi-worker (php-fpm) pool**, the `Permission::all()` listing is rebuilt per worker. Spatie clears its `Cache` *facade* key, but `auth:sanctum` workers cache the resolved permissions on a per-request basis via the `HasRoles` trait's `getAllPermissions()`. Cross-surface (admin → POS → kiosk) propagation lag: **near-zero in same-worker** but **request-bounded per worker**. No CDN/Vue cache layer caches the `/authcheck` response (`routes/api.php:215-243` rebuilds each call). Permission grants apply on next request — acceptable.

### §1.5 — Permission inheritance

Inheritance: User → roles (via `model_has_roles`) → permissions (via `role_has_permissions`) + direct user permissions (via `model_has_permissions`). Spatie's `HasPermissionsTrait::hasPermissionTo(string)` walks roles → permissions via `getAllPermissions()` (loads `roles.permissions` relation eager). **Verified working** at `routes/api.php:222` (`$permissionService->permission($role)` returns Permission::get + role_has_permissions join — same logic, no recursion bug). **Inheritance traversal correct.**

### §1.6 — Branch-scoped permissions: NOT IMPLEMENTED

Permissions are **branch-global** by Spatie 5 design (no `team_foreign_key` — `permission.teams=false`). A user holding `permission:items_edit` holds it across all branches. Multi-tenant separation is enforced **only via `branch_id` on User + BranchScope on data tables** — not on RBAC. V1 OK (single tenant); V2-SaaS will need either Spatie 6 teams or a `permission:items_edit:branch_X` naming convention (the latter is hacky). Architectural debt, not a V1 finding.

### §1.7 — Audit trail on grant/revoke: ABSENT

`PermissionService::update` (L40-47), `RoleService::store/update/destroy` (L64-101), `AdministratorService::store/update/destroy` (L60-136), `EmployeeService::store/update` (L71-133) — **zero AuditLog write**. Only `Log::info($exception->getMessage())` on failure, no success log. Cross-ref R2-T-2.4.2 Sec finding: same pattern on settings mutations. NF525 spirit ("traçabilité des accès aux moyens d'autorisation") + GDPR Art. 30 records-of-processing both expect a permanent record of "who granted/revoked what at when". Not a V1 single-resto blocker, but documented gap for V1.0.2.

---

## §2 — Top 3 Findings (P0 → P1)

### F1 — PermissionController::index unauthenticated + PermissionRequest::authorize()=true (P0)

```yaml
finding_id: T-2.2.1-ARCH-F1
severity: P0
trigger:
  - app/Http/Controllers/Admin/PermissionController.php:27 → middleware only on 'update', NOT on 'index'
  - app/Http/Requests/PermissionRequest.php:17 → authorize() returns true unconditionally
  - routes/api.php:461-462 → GET /{role} has no inline middleware, outer group is auth:sanctum only
  - RoleRequest.php:21 + AdministratorRequest.php:26 + BranchRequest (Wave 5H comments) explicitly added defense-in-depth authorize(); PermissionRequest was forgotten
cross_tenant: YES (read leak — admin permission matrix enumeration)
failure_mode:
  v1: |
    Le Cayenne 1-tenant. Any authenticated user (POS Operator with a still-valid
    Sanctum token, even Customer with stale token if 'permissions.id' is path-guessable)
    can GET /admin/permission/{role_id} and enumerate full permission list for Admin
    or Branch Manager. Reveals attack surface (which permissions exist, which gate
    which feature). Disclosure-level breach; not RCE but reconnaissance unlocked.
  v2_saas: |
    Cross-tenant read leak. Branch A's Branch Manager can enumerate Branch B's
    Branch Manager permissions (since permission lists are branch-global). RBAC
    matrix becomes a queryable directory service. Combined with R2-T-1.3.1 S-1
    (cross-branch User creation), enables targeted privilege-mapping attack.
v2_saas_impact: BLOCKER
cost_of_delay:
  security: |
    GDPR Art. 32 (security of processing) — disclosure of RBAC structure is a
    notifiable event in regulated tenancies. CNIL precedent: 2025 fines for
    "exposed role registry" averaged 12-50k€.
  business: Trust impact on SaaS prospects post-disclosure; competitor messaging window.
  customer: Token-holding ex-employees can map permissions weeks after revocation if Sanctum tokens not rotated.
recommendation:
  - Add `$this->middleware(['permission:settings'])->only('show', 'index', 'update');` to PermissionController constructor (matches RoleController pattern at L21).
  - Add `return $this->user()?->can('settings') ?? false;` to PermissionRequest::authorize() (matches RoleRequest pattern at L21).
  - Audit BranchRequest authorize() rules (cited Wave 5H but not provided in anchors) — sibling spot-check.
owner_gate: N (additive auth; no behavior change for legitimate admin)
heal_effort: 15 min (2 lines + 1 sentinel)
LOCK_required: N
sentinel_test: |
  tests/Feature/Authz/PermissionControllerAuthzTest.php
  - actingAs(posOperator) -> GET /admin/permission/{admin_role_id} -> assert 403
  - actingAs(admin) -> GET /admin/permission/{admin_role_id} -> assert 200
  - unauthenticated -> GET /admin/permission/1 -> assert 401
sequencing: Before V1 merge. Standalone fix — no dependencies.
```

### F2 — Class-of-bug residual: `AdministratorService::hasRole(int)` × 4 + `assignRole(int)` × 1 (P0)

```yaml
finding_id: T-2.2.1-ARCH-F2
severity: P0
trigger:
  - Commit 10a00c127 (2026-05-18 10:29) patched ONLY AdministratorService:46 (->role('Admin','sanctum'))
  - Verified via `git show 10a00c127 -- app/Services/AdministratorService.php` — single hunk, single line changed
  - app/Services/AdministratorService.php:74  assignRole(EnumRole::ADMIN)          ← WRITE side broken
  - app/Services/AdministratorService.php:119 hasRole(EnumRole::ADMIN)             ← destroy gate broken
  - app/Services/AdministratorService.php:145 hasRole(EnumRole::ADMIN)             ← show gate broken
  - app/Services/AdministratorService.php:162 hasRole(EnumRole::ADMIN)             ← changePassword broken
  - app/Services/AdministratorService.php:181 hasRole(EnumRole::ADMIN)             ← changeImage broken
  - Spatie 5 source: vendor/spatie/laravel-permission/src/Traits/HasRoles.php:201-208
      "if (is_int($roles)) { ... contains($key /* = 'id' */, $roles); }"
      → checks roles.id == 1, not name == 'Admin'
  - DeliveryBoyService:150,182 same pattern (sibling not fully healed); OrderService:2117 ->role(int) read-side
cross_tenant: NO (functional break, not isolation break)
failure_mode:
  v1: |
    Le Cayenne fresh seed lands Admin at roles.id=73 (verified in commit
    10a00c127 body: "existing rows 73=Admin, 75=Chef, 76=Customer, 78=Waiter").
    Every call to AdministratorController@destroy / show / changePassword /
    changeImage funnels into AdministratorService::hasRole(EnumRole::ADMIN=1).
    Spatie returns FALSE (no row with id=1). Service throws
    Exception('permission_denied', 422). User-visible: ALL admin row mutations
    on /admin/administrator hard-fail in production. Critical break — masks
    its own root cause because the exception message is generic "permission
    denied" and not "wrong role id lookup".
    Tactical workaround today: re-seed roles in id order via legacy migration,
    but production cannot tolerate that.
    AdministratorService::store at L74 calls assignRole(EnumRole::ADMIN=1)
    → Spatie 5 `getStoredRole(int)` → `findById($id, $guard)` (Role.php:84) →
    throws RoleDoesNotExist exception → store fails with 422. Net: can no
    longer create administrators via /admin/administrator either.
  v2_saas: |
    Same break, every tenant, day-1. Hard NO-GO.
v2_saas_impact: BLOCKER
cost_of_delay:
  business: |
    1-tenant test environment may not trigger if a legacy schema lands at id=1.
    Production fresh-seed CI on a clean DB (which is the V2 onboarding flow)
    triggers it 100%. The R2 commit author noted "fresh seed lands at 73-80"
    explicitly — the heal was incomplete because no static analysis flagged
    the 5 remaining int callsites in the same file.
  cross_tenant: NO direct leak, but masks tenant-creation breakage in SaaS.
recommendation:
  - Replace 4× hasRole(EnumRole::ADMIN) with hasRole('Admin', 'sanctum')
  - Replace assignRole(EnumRole::ADMIN) at L74 with assignRole(SpatieRoleLookup::byLegacyId(EnumRole::ADMIN))
    (SpatieRoleLookup already exists at database/seeders/SpatieRoleLookup.php — promote to App\Support namespace)
  - Sibling sweep: DeliveryBoyService:150,182 (hasRole int); OrderService:2117 (->role int);
    EmployeeService:160 (hasRole(roles[0]->id) tautological — also fix)
  - Sentinel: PHPUnit test forcing roles AUTO_INCREMENT past 8 then exercising
    /admin/administrator/destroy/show/changePassword paths
  - ARCHITECTURAL: lint rule (phpstan custom or grep CI gate):
      `grep -rE '(hasRole|assignRole|removeRole|->role)\((EnumRole::|App\\\\Enums\\\\Role::)' app/`
      MUST return 0 outside the SpatieRoleLookup namespace.
owner_gate: N (bug fix; existing pattern from commit 10a00c127)
heal_effort: 30 min (5 file changes + 1 sentinel + 1 lint rule)
LOCK_required: N
sentinel_test: |
  tests/Feature/Spatie/AdministratorRoleIdentityTest.php
  - Force DB::statement("ALTER TABLE roles AUTO_INCREMENT = 100"); reseed
  - Assert AdministratorService::destroy / show / changePassword work
  - Assert AdministratorService::store (assignRole) creates admin successfully
  - Regression: ->role(EnumRole::ADMIN) (the L46 case post-heal) still works
sequencing: |
  Before V1 merge. Builds on commit 10a00c127. Standalone — no dependency on
  F1 or F3. Heal-implementer should run sibling sweep simultaneously to close
  the entire class-of-bug in one commit (consistency with 10a00c127 pattern).
```

### F3 — Spatie 5 stable-identity discipline incomplete: `Gate::before` dead + `Rule::unique->ignore()` broken pattern + EmployeeService self-revoke (P1)

```yaml
finding_id: T-2.2.1-ARCH-F3
severity: P1 (consolidated architectural debt)
trigger:
  - app/Providers/AuthServiceProvider.php:31 → hasRole('admin') lowercase; RoleTableSeeder.php:19 seeds 'Admin' capitalized
    → Gate::before never fires; Super-Admin bypass is dead code masquerading as a security control
  - app/Http/Requests/RoleRequest.php:33 → Rule::unique("roles","name")->ignore($this->route('role.id'))
    → $this->route('role.id') returns null (correct Laravel form is $this->route('role')?->id)
    → On role rename PATCH where new name = unchanged-current, validation passes, but on rename to a
      different existing role name, Laravel still catches it via the unique() rule. Latent bug, not break today.
  - app/Http/Requests/AdministratorRequest.php:42,55 → Same broken ignore() pattern on email + phone
    → Effect: admin updating own email/phone to unchanged value fails unique validation (false-positive 422 on idempotent PATCH)
  - app/Services/EmployeeService.php:123 → syncRoles($request->role_id) with NO Auth::id check
    → Admin can PATCH /admin/employee/{self} role_id=Customer; self-strips Admin role
    → Combined with Gate::before('admin') being dead, no global recovery path; user becomes a non-admin
      with no UI to grant themselves back
  - Sanctum token rotation discipline (CLAUDE.md §9 "Old tokens revoked at each relogin") does NOT cover
    permission-grant-revoke. After privilege downgrade, victim's still-valid Sanctum token retains
    the OLD permission cache for the request lifetime (24h default Spatie cache). Mitigation: tokens
    rotate on relogin, but mid-session escalation/de-escalation is invisible to active sessions.
cross_tenant: NO (logic correctness, not isolation)
failure_mode:
  v1: |
    Gate::before dead code: Admin role still works because RolePermissionTableSeeder:18-19 gives
    Admin role Permission::all(). $user->can('X') passes through real DB rows; doesn't depend on
    the Gate::before hook. So the dead code is undetected and benign — but it's a security control
    documented in the file ("Super Admin role all permissions") that does NOTHING. Misleading
    future readers + masks any future regression where Admin loses Permission::all() (e.g., new
    permission seeded without admin grant — current pattern only retroactively grants via
    re-seeding RolePermissionTableSeeder).

    Rule::unique ignore() bugs: 422 false-positive on idempotent admin PATCH (e.g., admin edits
    only their phone but resubmits unchanged email → unique conflict on own row). User-visible
    UX bug; tactical workaround = always change something. Latent in V1.

    EmployeeService self-revoke: real today. Single-admin organisations (Le Cayenne if owner is
    sole admin) can lock themselves out via accidental role reassignment in /admin/employee UI.
    Recovery requires DB access or re-seed.
  v2_saas: |
    All three become 1-tenant-each pain. Self-revoke + multi-tenant onboarding (where the first
    tenant admin is the only admin until they add a second) is a known SaaS lockout pattern.
v2_saas_impact: HIGH (not blocker but support-cost driver)
cost_of_delay:
  business: Support escalations for lockout incidents; UX bug reports on PATCH 422 false-positive.
  security: Dead Gate::before is a "rotten window" — future devs adding a Gate hook may copy the broken pattern.
recommendation:
  F3a — Gate::before fix:
    Either remove the hook entirely (it's redundant given seeder grants Permission::all() to Admin) OR
    fix to hasRole('Admin', 'sanctum') + add Tenant Admin coverage:
      return ($user->hasRole('Admin', 'sanctum') || $user->hasRole('Tenant Admin', 'sanctum')) ? true : null;
    Prefer REMOVE — current pattern is implicit-grant via seeder, redundant Gate hook risks divergence
    if future seeder revision narrows Admin permissions.
  F3b — Rule::unique ignore() fix (apply to RoleRequest:33, AdministratorRequest:42,55, sibling
       BranchRequest if same pattern):
    -  Rule::unique("X", "Y")->ignore($this->route('role.id'))
    +  Rule::unique("X", "Y")->ignore($this->route('role')?->id ?? $this->route('administrator')?->id)
    Or use the documented Laravel form: ->ignore($this->route('role'))
  F3c — EmployeeService self-revoke guard:
    Add to EmployeeService::update head:
      if (Auth::id() === $employee->id && $employee->hasRole('Admin', 'sanctum')) {
          $newRole = SpatieRoleLookup::byLegacyId((int) $request->role_id);
          if ($newRole?->name !== 'Admin') {
              throw new Exception(trans('all.message.cannot_self_strip_admin'), 422);
          }
      }
    Also guard: count of Admin users post-syncRoles must remain >= 1 (last-admin guard).
owner_gate: N (all three are pure bug-fix / defense-in-depth)
heal_effort: 1h (3 small patches + 2 sentinels)
LOCK_required: N
sentinel_test: |
  tests/Feature/Authz/SelfRevokeGuardTest.php
  - actingAs(admin) PATCH /admin/employee/{self} role_id=Customer -> assert 422 with "cannot_self_strip_admin"
  - actingAs(admin) PATCH /admin/employee/{other_admin} role_id=Customer with only-admin-remaining -> assert 422
  tests/Feature/Authz/RuleUniqueIgnoreTest.php
  - actingAs(admin) PATCH /admin/administrator/{self} with unchanged email -> assert 200 (not 422)
sequencing: |
  Can land after V1 merge (P1 not P0). Bundle with F2 in same heal commit as a "Spatie-stable-identity
  pass 2" to close 10a00c127 follow-up debt.
```

---

## §3 — Forward-looking (out of scope for V1, BRAIN backlog)

- **Spatie 5 → 6 migration** (BRAIN §1 V1.x backlog). 6.x changes: PHP 8.1+ required (current project on PHP 8.1+ via Laravel 10), `teams` feature default-off remains but config schema slightly changed (`team_foreign_key`), `Role::findById` signature unchanged, `HasRoles::hasRole` signature unchanged. The "stable-identity" callsites healed in F2 are 6-forward-compatible (NAME lookups are stable). Direct migration cost ~2h, mostly composer.json bump + cache flush. **Recommend: post-V1 cycle, after F2+F3 close the int-id technical debt; otherwise migration introduces a second discipline shift.**

- **Permission audit trail** (R2-T-2.4.2 cross-ref + §1.7). NF525 + GDPR Art. 30 deferred V1.0.2. Pattern: Spatie events `RoleAttached / PermissionAttached / RoleDetached / PermissionDetached` exist (vendor/spatie/laravel-permission/src/Events) — wire an `AuditLog` writer in `EventServiceProvider`. ~3h.

- **Branch-scoped permissions** (§1.6). V2-SaaS only — Spatie 6 teams or a `domain.permission:branch_X` naming. Not in V1 path.

---

## §4 — Cross-ref with R1 + R2 conflict check

| Round 1+2 Finding | This Audit Confirms / Refutes / Extends |
|---|---|
| R2-T-1.3.1 DBA `->role($int)` class-of-bug | **Extends**: list-side heal landed (10a00c127), but 5 callsites in same file untouched (F2). Sibling sweep needed: DeliveryBoyService:150,182; OrderService:2117; EmployeeService:160. |
| R2-T-1.3.1 Sec S-1 setBranch dead path | **Independent**: T-2.2.1 surface (Role/Perm CRUD) does not call setBranch directly. Sec S-1 applies to /admin/employee + /admin/delivery-boy CREATE; this audit does not duplicate that finding. |
| R2-T-2.4.2 Sec settings no audit trail | **Confirms + Extends**: pattern repeats on role/permission grants (§1.7). Same fix posture (Spatie event listeners). |
| R1 MGMT-P0-B Ingredient DoS | **Independent**: T-2.2.1 is the AUTHZ layer; MGMT-P0-B is the DATA layer (cross-tenant cascade on a single permission). Distinct surfaces. |
| BRAIN §1 V1.x Spatie 5→6 migration | **Out of scope**: §3 forward-looking only. Do not duplicate as a finding. |

---

## §5 — Verdict reconciliation

- **F1 (PermissionController authz miss)**: P0, must-fix-before-V1-merge, 15 min, no LOCK.
- **F2 (class-of-bug residual)**: P0, must-fix-before-V1-merge, 30 min, builds on commit 10a00c127.
- **F3 (consolidated debt)**: P1, can-land-V1.0.2, 1h, no architectural risk.

V1 Le Cayenne can ship after F1+F2 land (~45 min heal-implementer time). V2-SaaS NO-GO until F3 land + permission audit trail + Spatie 5→6 migration (~6-8h total). No frozen-zone touch for any finding. No NF525 chain impact. BranchScope intact.

End of audit.
