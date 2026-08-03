# v4ma-rbac-privesc — RBAC / privilege-escalation surface

HEAD 61e9ea7b7 + working tree. Posture refute-by-default. Live 127.0.0.1:8766 (foodking_e2e).

## Verdict: IMPROVABLE — 1 P3 (latent privesc / IDOR-consistency gap). Core privesc REFUTED.

## Attacks run and outcomes

1. **BM/POS-Operator self-assign Admin via `/admin/employee`** → REFUTED. `EmployeeService::store/update` blocks core roles (`blockRoles = [ADMIN,CUSTOMER,DELIVERY_BOY,WAITER,CHEF]`) and requires `callerMayGrantRole()` — non-`settings` callers may grant only a role whose permission set is a *strict subset* of their own (USR-RBAC-01 heal).
2. **Peer-cloning (BM mints another BM)** → REFUTED. `$targetPerms->count() < $callerPerms->count()` (strict `<`) blocks equal-permission clone.
3. **Assign core role id (ADMIN) through employee endpoint** → REFUTED by `blockRoles`.
4. **Self role-tamper via `PUT /profile`** → REFUTED. `ProfileService::update` writes only name/phone/email/country_code — no role field, no mass-assign.
5. **Unauth/low-priv role+permission enumeration/edit** (`/admin/role`, `/admin/permission/{role}`) → REFUTED. `RoleController` gates all mutations with `permission:settings`; `PermissionController` gates all 3 methods incl. `index` with `permission:settings` (GOAL-CMS heal). Branch Manager/POS Operator lack `settings` (seeder confirmed).
6. **Non-admin creates Admin via `/admin/administrator` POST** → REFUTED. Gated `administrators_create`; BM/POS/Chef seeds don't hold it.
7. **Cross-role IDOR/takeover on Waiter/Chef/DeliveryBoy/Customer update+changePassword+changeImage** → REFUTED. Each service calls `assertTargetRole()` (WAVE5-SEC-001) — 403 unless route-bound user actually has the expected role.
8. **Super-admin mint via `branch_id=0`/omitted branch_id** → REFUTED. `AdministratorRequest::withValidator` (bug_011 heal) blocks null/0 branch for non-super-admins.
9. **Live differential** (admin token, admin@lecayenne.fr): `GET /admin/administrator/show/4` (user 4 = Chef) → `422 "La permission est refusée."` (guard present on `show`). Confirms the role-scoping invariant is enforced on the sibling methods.

## FINDING (P3) — `AdministratorService::update` missing target-role guard → cross-role account takeover / latent privesc

`app/Services/AdministratorService.php:87-110` (`update`) is the ONLY mutating method in the file, and the ONLY staff-service mutation method in the whole app, that does **not** verify the route-bound `{administrator}` user is actually an Admin before writing name/email/phone/status/branch_id/**password**.

- `show` (L145), `changePassword` (L162), `changeImage` (L181), `destroy` (L119) all guard with `if ($administrator->hasRole(EnumRole::ADMIN))`.
- Every sibling (`CustomerService`, `ChefService`, `DeliveryBoyService`, `WaiterService`) calls `assertTargetRole()` on update/changePassword/changeImage (WAVE5-SEC-001).
- `update()` has neither guard. Route binding `Route::match(['post','put','patch'],'/{administrator}', ...)` binds `{administrator}` to *any* `User` id with no role scoping.

Consequence: a holder of `administrators_edit` can `POST /admin/administrator/{anyUserId}` with a valid payload (non-zero branch_id + 12-char password) and:
- reset the password of any non-admin user (Chef/Waiter/POS Operator/Customer) → account takeover through the "administrators" endpoint, bypassing the per-role endpoints' `assertTargetRole` guards;
- reset the password of super-admin **user id=1** — `destroy` explicitly protects `id != 1`, but `update` has no such protection (inconsistent).

**Why P3 (not P2):** in the default seed only the `Admin` role holds `administrators_edit`, so no *non-admin* can reach it today → not a live non-admin escalation. It becomes a real privilege escalation the moment `administrators_edit` is delegated to any sub-admin custom role (a plausible "admin manager" that should not equal super-admin): such a user could reset user id=1's password and log in as full super-admin. It is also an IDOR/consistency defect — an incomplete WAVE5-SEC-001 heal — and enables admin-on-super-admin takeover.

**Live repro (differential, read-only):**
- Login: `POST /api/auth/login {admin@lecayenne.fr/123456}` → token; user id=1, branch_id=0.
- `GET /api/admin/administrator/show/4` → `422 "La permission est refusée."` (target = Chef, guard fires).
- `GET /api/admin/chef/show/4` → 200 (user 4 = Chef Le Cayenne, confirmed `getRoleNames()=Chef`).
- Code: `AdministratorService::update` (L87-110) reaches `->save()` with the request's password for the SAME user id 4 with no `hasRole(ADMIN)` / `assertTargetRole` check. (Positive write not executed — no-DB-write policy.)

**Fix proposal:** at the top of `AdministratorService::update`, mirror the siblings:
```php
if (! $administrator->hasRole(EnumRole::ADMIN)) {
    throw new Exception(trans('all.message.permission_denied'), 422);
}
```
and add an `id === 1` super-admin protection consistent with `destroy` (block or require super-admin caller). Add a regression test asserting `POST /admin/administrator/{chefId}` → 422 and that id=1 password cannot be reset by a non-super-admin caller.
