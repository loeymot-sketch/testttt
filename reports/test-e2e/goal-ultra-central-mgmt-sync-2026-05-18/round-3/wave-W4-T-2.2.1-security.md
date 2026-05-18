# Wave W4 — T-2.2.1 Role / Permission CRUD — SECURITY AUDIT Round 3

**Specialist** : SECURITY (Round 3, READ-ONLY)
**Threat model** : Attacker holds a Sanctum session for a user carrying `permission:settings`. Goal = self-escalate to super-admin equivalence (dual-gate `branch_id=0 AND hasRole('Admin'|'Tenant Admin')`), grant cross-branch reach, lock out other admins, plant a persistent foothold, without leaving an audit trail.
**Date** : 2026-05-18
**Format** : Hostile mindset. Every assumption inverted.

---

## VERDICT

**BLOCK — RED-CRITICAL on production cut-over.**

The Role/Permission CRUD surface looks clean at the controller layer (mutating actions carry `permission:settings`), but the surface that gate protects is **architecturally broken** in three orthogonal ways:

1. **`Tenant Admin` is a shadow role.** Nine production files dual-gate on `hasRole('Admin') || hasRole('Tenant Admin')` (`AdminController.php:22,34`, `ItemResource.php:187`, `NormalItemResource.php:198`, `ItemController.php:158`, `ComposerProfileController.php:30,54`, `SyncOverviewController.php:50`), but **no seeder creates that role** and **no validator forbids its creation/rename**. `RoleController::store` accepts `{"name":"Tenant Admin"}` and persists it with `guard_name='sanctum'` — the dual-gate admits members immediately.
2. **`PermissionService::update` has no self-target guard.** `$role->syncPermissions(...)` accepts the attacker's *own* role-id; the route binds the role from the URL with zero check.
3. **Zero audit trail.** `RoleService`, `PermissionService`, `AdministratorService` write zero rows to `audit_logs` or `action_logs`. Every RBAC mutation invisible at the chain-signed layer (class-of-bug match R2 T-2.4.2).

**Threat-model fidelity correction**: the brief said `roles_manage`. That permission **does not exist** in the seeder. The actual gate is `permission:settings`, granted ONLY to Admin via `Permission::all()` (seeder line 19). Real attacker prerequisite = compromised Admin session, chained escalation via S-2 from a role mis-granted `settings`, or insider Admin. Narrower bar; findings unchanged.

---

## Cross-reference to R2 (do not re-argue)

| Vector | R2 coverage |
|---|---|
| `branch_id` request-controlled on user creation | R2 T-1.3.1 Sec **S-1** P0 (Employee/Chef/Waiter/DeliveryBoy) — same class lands on Admin path here as S-3. |
| Admin@branch=0 dual-gate verified | R2 T-1.3.1 Sec **S-3** PASS — conditional on no attacker-controlled mint path. This audit invalidates that condition. |
| Settings-mutation audit silence | R2 T-2.4.2 Sec — same class as S-5. |
| Spatie cache poisoning via Redis | R2 T-2.4.2 Sec Q2 — out of scope (app uses auto-invalidating mutators). |

---

## Finding S-1 — Tenant-Admin Hijack via Role Create/Rename (P0)

**Attack handle**: `Tenant-Admin Hijack`
**Evidence**: `RoleRequest.php:32-34` (only `name` uniqueness), `RoleService.php:67` (`Role::create + guard_name='sanctum'`), `RoleService.php:80` (`tap($role)->update($request->validated())`), `AdminController.php:22,34`, `RolePermissionTableSeeder.php` (no 'Tenant Admin' seed).
**Capability required**: compromised Admin session.
**Trigger**: `POST /api/admin/role {"name":"Tenant Admin"}` → 201. Then either (a) attach a low-trust user to it via `model_has_roles`, or (b) PATCH an existing role to rename it `"Tenant Admin"` in one call — `RoleService::update` accepts the same payload, and the dual-gate resolves by NAME at runtime, so every existing member of that role is instantly upgraded to dual-gate equivalence with zero pivot-table change.
**Failure mode**: defense absent at every layer — `RoleRequest::rules` has no `Rule::notIn(['Admin','Tenant Admin','Super Admin','Owner','Root'])`; `RoleService::store|update` has no whitelist; the 9 dual-gate sites resolve `hasRole('Tenant Admin')` purely by string match. Single-request exploit, zero chain.
**V2 SaaS impact**: per-tenant takeover primitive. Any Admin token leak → tenant pwned. Cross-tenant pivot plausible if `roles` table is shared in single-schema multitenancy.
**Cost of delay**: combined with S-5 — zero forensic evidence after exploit.
**Recommendation**: (1) `RoleRequest::rules` add `Rule::notIn([...protected literals])` on `name` for store; for update, allow the literal only if it matches the existing row name. (2) Stop using string-matched role names as a super-admin gate — bind to a stable Permission (e.g., `super_admin`) granted only via seed, or pin the Admin role ID at install. (3) Block user-to-protected-role assignment outside CLI seeders.

---

## Finding S-2 — Self-Permission Sync (P0)

**Attack handle**: `Self-Permission Sync`
**Evidence**: `PermissionController.php:48-55`, `PermissionService.php:39-47` (`$role->syncPermissions(...)` — WRITE, not merge), `PermissionRequest.php:15-18` (`return true`), `routes/api.php:461-464`.
**Capability required**: any session whose role carries `permission:settings`. By default seeder Admin only — but: chains with S-1 (Tenant Admin given `settings`), with ops misgrant, or with a future role drift granting `settings` accidentally.
**Trigger**: `GET /api/admin/permission/{myRoleId}` → enumerate all permission IDs. `PUT /api/admin/permission/{myRoleId} {"permissions":[<every-id>]}`. `syncPermissions` REPLACES — attacker need not preserve existing entries. Spatie auto-invalidates cache; next request the attacker holds every capability ever defined (`super_admin`, `pos-manage-fiscal`, `pos-reopen-z`, `cash.reconcile.variance.override`, future ones).
**Failure mode**: no `$role->id !== Auth::user()->roles->first()?->id` guard. No protected-role blocklist (`Admin` itself can be re-synced — actually neutral since it already has all perms, but `Tenant Admin` and any custom role are wide open). `PermissionRequest::authorize()` returns `true` — defense-in-depth absent.
**V2 SaaS impact**: identical primitive in every tenant. Any leaked session whose role drifted to acquire `settings` becomes full super-admin in ≤2 HTTP calls.
**Cost of delay**: no audit, no alert, no rate limit, no threshold. Red-team-in-prod completes in seconds.
**Recommendation**: (1) `PermissionService::update` guard: throw 403 if `$role->id === Auth::user()->roles->first()?->id` OR `in_array($role->name, ['Admin','Tenant Admin'])`. (2) Mirror R7 heal on `PermissionRequest::authorize()` → `$this->user()?->can('settings') ?? false`. (3) Quarantine the `settings` capability from non-seeded roles by filtering it out of `PermissionResource` collection responses.

---

## Finding S-3 — Admin@Branch0 Mint (P0, cross-reference R2 S-1)

**Attack handle**: `Admin@Branch0 Mint`
**Evidence**: `AdministratorRequest.php:57` (`'branch_id' => ['nullable','numeric']` — R2 S-1 mitigation never applied to this validator), `AdministratorService.php:58-82` (store writes `branch_id` verbatim, line 70; unconditional `assignRole(EnumRole::ADMIN)`, line 74), `AdministratorService.php:87-110` (update mutates `branch_id` verbatim, line 96). Cross-ref: R2 T-1.3.1 Sec S-1.
**Capability required**: legitimate Admin session with `administrators_create` (or `administrators_edit`).
**Trigger**: `POST /api/admin/administrator {"name":"Phantom","email":"x@y.z","password":"...","password_confirmation":"...","branch_id":0,"status":1,"country_code":"FR"}`. Service mints user with `branch_id=0 + role=Admin` → passes dual-gate immediately.
**Failure mode**: V1.0.1 R7 hardened the `authorize()` (line 22-27 — `administrators_create OR administrators_edit`), but did NOT add the branch-scoping `Rule::in(...)` R2 explicitly recommended. R2 dismissed Administrator as gated; this audit elevates because the gate IS the only defense, and once any Admin is compromised, additional Admins are mintable indefinitely. No cascade revocation on parent-Admin deactivation.
**V2 SaaS impact**: persistent backdoor. Each minted phantom survives token rotation; tenant remains owned until manual user purge.
**Cost of delay**: compounds with every Admin compromise. Combined with S-5, breach is invisible.
**Recommendation**: (1) Apply R2 S-1 mitigation on `AdministratorRequest.php:57` — `Rule::in(...)` scoped to caller's branch. (2) In `AdministratorService::store`, gate with `if (Auth::user()->branch_id !== 0 || !Auth::user()->hasRole('Admin'))` → 403. (3) Emit `audit_logs` row `admin_user.created` per S-5.

---

## Finding S-4 — Manager-Role Wipe DoS (P1)

**Attack handle**: `Manager-Role Wipe`
**Evidence**: `RoleService.php:21-23` (`roleArray` = `[ADMIN, CUSTOMER, DELIVERY_BOY, WAITER, CHEF]`), `RoleService.php:90-102` (`if (!in_array($role->id, $this->roleArray)) $role->delete()`). `BRANCH_MANAGER=6`, `POS_OPERATOR=7`, `STUFF=8` from `app/Enums/Role.php` — all absent from `roleArray`, all deletable.
**Capability required**: `permission:settings` (Admin by default seeder).
**Trigger**: `DELETE /api/admin/role/{branchManagerRoleId}`. Service deletes the row; Spatie cascade nulls every binding in `model_has_roles` for that role.
**Failure mode**: every Branch Manager / POS Operator / Stuff user becomes role-less. Their next request 403s from every `permission:*` middleware. Cashiers cannot open registers, managers cannot approve discounts, no one can reopen Z reports. NF525 close cadence breaks (fiscal day stays open past midnight). Recovery requires re-seed + manual `model_has_roles` rebind from backup.
**V2 SaaS impact**: tenant operations DoS in one DELETE.
**Cost of delay**: operational — fiscal chain untouched (chain is per-branch, not role), but daily Z deferred → compliance drift.
**Recommendation**: extend `roleArray` to include every seeded role enum; better, derive from seeder source-of-truth. Additionally return 409 if `withCount('users')->users_count > 0`.

---

## Finding S-5 — Audit-Trail Void on All RBAC Mutations (P1)

**Attack handle**: `Audit-Trail Void`
**Evidence**: zero `Audit::` / `AuditLogService` calls in `RoleService.php`, `PermissionService.php`, `AdministratorService.php` (grep negative across all three). Cross-ref: R2 T-2.4.2 Sec.
**Capability required**: any successful S-1, S-2, S-3, S-4 execution.
**Failure mode**: none of the RBAC services write to `audit_logs` (chain-signed) or `action_logs` (best-effort observability). An Admin creating "Tenant Admin", minting a phantom Admin, self-syncing every permission, and deleting Branch Manager leaves zero rows in the chain-signed audit. Post-breach forensics relies on rotating Laravel HTTP access logs (tamper-trivial).
**V2 SaaS impact**: tenant incident response collapses — support cannot answer "who granted X to Y on Z" with cryptographic certainty.
**Cost of delay**: turns each P0 from "detectable post-mortem" to "deniable indefinitely".
**Recommendation**: chain-signed writes in `RoleService::{store,update,destroy}`, `PermissionService::update`, `AdministratorService::{store,update,destroy,changePassword}` using `AuditLogService` action types `role.created|updated|deleted`, `role_permissions.synced`, `admin_user.created|updated|deleted|password_changed`. Per CLAUDE.md §8.

---

## Finding S-6 — PermissionRequest Authorize-True Echo (P2)

**Attack handle**: `Authorize-True Echo`
**Evidence**: `PermissionRequest.php:15-18` (`return true`); contrast with R7-healed `RoleRequest.php:21` (`$this->user()?->can('settings')`) and `AdministratorRequest.php:22-27`.
**Capability required**: future route mis-config that removes controller middleware.
**Failure mode**: V1.0.1 R7 heal applied to RoleRequest and AdministratorRequest, but PermissionRequest was missed. Asymmetric defense — any future bypass exposes a wide-open authorize().
**Recommendation**: mirror R7: `return $this->user()?->can('settings') ?? false`.

---

## Dismissed (verified non-issues)

- **Spatie cache poisoning (brief #8)**: app uses `syncPermissions` / `assignRole`, which call `forgetCachedPermissions()` automatically. In-memory pollution requires direct Redis access — covered by R2 T-2.4.2 Q2 (network isolation).
- **GuestSignup / Signup role injection (brief #9)**: `GuestSignupController.php:126,151` and `SignupController.php:97` hard-code `EnumRole::CUSTOMER`. No client-controlled role; no escalation path via public signup.
- **Spatie integer-vs-name attack (brief #3)**: confirmed unmitigated in `AdministratorService:74` (`assignRole(EnumRole::ADMIN)` = `assignRole(1)`) and 4 sibling services. Direction is **DoS / role-drift**, not escalation — `findById(1)` returns whatever role lands on id 1 after seed-rollback (could be Customer, Delivery Boy, etc.) → new admins get the WRONG role and are denied access, not granted higher privilege. Already on R2 DBA backlog as the SpatieRoleLookup-in-services refactor. P1 DoS, not P0 escalation.

---

## Summary table

| # | Handle                  | Sev  | Single-call? | Audited? | Detection |
|---|-------------------------|------|--------------|----------|-----------|
| S-1 | Tenant-Admin Hijack   | P0   | Yes          | No       | ~0%       |
| S-2 | Self-Permission Sync  | P0   | Yes          | No       | ~0%       |
| S-3 | Admin@Branch0 Mint    | P0   | Yes          | No       | ~5%       |
| S-4 | Manager-Role Wipe     | P1   | Yes          | No       | High (ops break) |
| S-5 | Audit-Trail Void      | P1   | meta         | No       | n/a       |
| S-6 | Authorize-True Echo   | P2   | Future       | No       | n/a       |

**3 P0 + 2 P1 + 1 P2 — BLOCK on production.** Heal order: S-1 → S-2 → S-3 → S-5 → S-4 → S-6. Every Admin token compromise is a tenant-takeover primitive until S-1 + S-2 land; S-3 closes persistence; S-5 makes any future incident forensically tractable.
