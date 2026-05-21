# Adversarial RED — POS Heals Wave 2 — Wave 3 dispute

**Branch:** v1-0-1-hardening-2026-05-17 — HEAD `f24b49c42`
**Commit under review:** `5df225ffa` (concurrent merge of Heal 4 EmployeeRequest authorize + Heal 6 CashDrawerSession ownership)
**Stance:** hostile. Goal: find what the implementer missed.
**Scope:** local Le Cayenne V1. No cloud findings. Read-only.

---

## Heal 4 — `EmployeeRequest::authorize()` defense-in-depth

### Verdict: ACCEPT WITH P2 GAPS

Permission names are correct. `employees_create` and `employees_edit` exist with `guard_name='sanctum'` in `database/seeders/PermissionTableSeeder.php:489` / `:497`, also referenced by middleware in `app/Http/Controllers/Admin/EmployeeController.php:29-30`. Admin role gets `Permission::all()` (`database/seeders/RolePermissionTableSeeder.php:19`), Branch Manager explicitly listed (`:59-62`). Anonymous user null-safety handled (`EmployeeRequest.php:23-26`).

### Findings

**POS-ADV3-01 — P2 — Verb/permission asymmetry leaves edit-only users able to create**
File: `app/Http/Requests/EmployeeRequest.php:27`.
The FormRequest authorize uses an `OR` between `employees_create` and `employees_edit`. A user holding ONLY `employees_edit` will pass authorize() on a `POST /api/admin/employee/` (store) request. In production the controller constructor middleware (`EmployeeController.php:29` `permission:employees_create` on store) catches it. But the stated heal goal is "defense-in-depth for future route mis-wire". If a future route mis-wire drops the controller-level middleware, the FormRequest accepts both verbs and an edit-only user becomes able to CREATE. Real verb-aware defense would inspect `$this->isMethod('POST')` vs `PUT/PATCH` and route capability accordingly.

**POS-ADV3-02 — P2 — `destroy` left without FormRequest gate**
File: `app/Http/Controllers/Admin/EmployeeController.php:62`.
`destroy(User $employee)` accepts no FormRequest at all. Wave 5H pattern (per heal commit message) is "FormRequest as defense-in-depth"; this layer is silently absent on delete. If route mis-wire bypasses `permission:employees_delete` (`:31`), the delete path has zero FormRequest fallback. Heal #4 mirror is incomplete — only store+update covered.

**POS-ADV3-03 — P2 — Pre-existing cross-branch employee creation not addressed**
File: `app/Services/EmployeeService.php:80`, `:115`.
`store()` and `update()` write `branch_id => $request->branch_id` verbatim from the request body. Rules at `EmployeeRequest.php:65` accept arbitrary `'numeric'` branch_id. A Branch Manager holding `employees_create` (granted by `RolePermissionTableSeeder.php:59`) can POST `branch_id=99` and create an employee outside their branch. The heal mentions "defense-in-depth"; an actor with the gate can still cross branches. Out-of-scope for this heal but the commit message inviting "future route mis-wire" framing makes the silence on this real path notable.

**POS-ADV3-04 — P3 — Role escalation to other Branch Manager via `role_id`**
File: `app/Services/EmployeeService.php:25`, `:72`, `EmployeeRequest.php:67`.
`$blockRoles` excludes Admin/Customer/Delivery/Waiter/Chef but **not** Branch Manager or POS Operator. A user with `employees_create` can create a peer Branch Manager. `role_id` rule is only `numeric`. Pre-existing; not regressed by this heal. Flagged for context.

### Negative space (probed clean)

- Permission name spelling: matches seeders exactly (no hyphen/colon variant). Both names sanctum-guarded.
- Spatie guard binding via `HasRoles` trait (`User.php` line where `HasRoles` is used) — confirmed. `$user->can()` works on Sanctum auth.
- Null user → returns false (no exception, no fail-open).
- Admin (`branch_id=0`) auto-passes via `Permission::all()` grant.
- Test file `tests/Feature/Admin/EmployeeRequestAuthorizeTest.php` covers the four authorize() paths in isolation; pattern matches `OrderRequestKioskAbilityTest.php` as claimed.

---

## Heal 6 — `CashDrawerSessionController::assertSessionVisibleToUser` ownership

### Verdict: ACCEPT WITH P1 RESIDUAL RISK

Lookup uses `CashDrawerSession::query()->find($sessionId)` (`CashDrawerSessionController.php:236`). `CashDrawerSession` registers `BranchScope` globally (`app/Models/CashDrawerSession.php:68`) and `User` is exempted from the scope (`app/Models/Scopes/BranchScope.php:21`). Permission name `cash.reconcile.variance.override` matches seeders (`PermissionTableSeeder` + `RolePermissionTableSeeder.php:78` + `tests/TestCase.php:149`). Role strings `'Admin'` and `'Branch Manager'` match `database/seeders/RoleTableSeeder.php:19` and `:50` exactly (case included).

### Findings

**POS-ADV3-05 — P1 — Belt-and-suspenders role check defeats permission revocation**
File: `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:251-253`.
The `OR`-chain is `can(permission) || hasRole('Admin') || hasRole('Branch Manager')`. The variance gate in the service layer uses **permission only** (`app/Services/Cash/CashDrawerService.php:154`, `actorCanOverrideVariance` at `:490-505`). So an admin who revokes `cash.reconcile.variance.override` from Branch Manager (via permission management UI) will see:
- `closeSession` reconcile gate → blocks (permission-only path).
- `assertSessionVisibleToUser` → STILL passes via `hasRole('Branch Manager')`.

A Branch Manager whose override permission was revoked can still close a peer cashier's drawer (and act on it), even though by intent the revocation should remove that capability. This is an inconsistency between two enforcement layers in the same domain. Either layer must be authoritative; the heal silently introduced a divergence.

**POS-ADV3-06 — P1 — Closing-amount=0 misattribution scenario NOT closed for managers**
File: `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:254`, commit message claim.
The heal commit explicitly says it prevents "cashier B could close cashier A's drawer ... with closing_amount=0 → variance mis-attribution". Post-heal: a malicious Branch Manager (legitimate role) can still POST `/{session}/close` with `closing_amount=0` against any cashier's drawer in the branch. The audit log captures the manager as `closed_by_user_id` (`CashDrawerService.php:188`) — forensic IS improved, but the actual variance misattribution (cashier A's expected vs declared) still happens. The heal narrows the attack surface from any-same-branch-staff to manager-or-owner; it doesn't eliminate it. Commit framing overstates the fix.

**POS-ADV3-07 — P1 — `assertSessionVisibleToUser` regression on routine close UX when manager gate enabled**
File: `app/Services/Cash/CashDrawerService.php:151-160` (`cash.manager_gate_routine_close`).
When `cash.manager_gate_routine_close=true`, the service requires manager perm for ALL closes. Heal 6 layered on top means: even an owner-cashier (who passes `assertSessionVisibleToUser` via `$isOwner`) is then refused by the service. Net result with the config flag on: cashier can NEVER close own drawer; only managers can. Two gates in cascade, each with different semantics. No reference to this interaction in the heal commit. Owner needs to confirm the intended composition.

**POS-ADV3-08 — P1 — Test coverage gap: cross-branch admin + permission-revoked manager + dead-code path**
File: `tests/Feature/Pos/CashDrawerSessionOwnershipTest.php` (entire).
The new test asserts 3 scenarios (non-owner blocked, owner allowed, manager allowed). Missing:
- Admin (`branch_id=0`) closes a `branch_id=2` session → should succeed via early return at `CashDrawerSessionController.php:243`. Untested.
- Branch Manager with `cash.reconcile.variance.override` revoked but role intact → still passes (POS-ADV3-05). Untested; would expose the divergence.
- POS Operator in branch=3 attempts to close branch=2 session → BranchScope returns null → 404. The explicit `Cross-branch access denied` 403 at `:247` is **never reachable** by staff (BranchScope filters before, 404 wins). The test as written can't distinguish 404 vs 403 behaviour because cashier-B is on the SAME branch as the session.

**POS-ADV3-09 — P2 — Dead-code 403 message**
File: `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:247`.
For staff (`branch_id > 0`), `CashDrawerSession::find()` is BranchScope-filtered (`BranchScope.php:39`). A cross-branch lookup returns `null` → 404 at `:237` BEFORE reaching `:247`. The `Cross-branch access denied` 403 at line 247 is reachable ONLY by admin (`branch_id=0`)… but admins skip the whole block via the early return at `:244`. So the line is dead. Not a security hole — but the heal added explanatory text suggesting it's an active gate; it's not.

**POS-ADV3-10 — P2 — Hardcoded role strings brittleness**
File: `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:252-253`.
`hasRole('Admin')` and `hasRole('Branch Manager')` are hardcoded. The codebase has an `App\Enums\Role` enum referenced by `database/seeders/RolePermissionTableSeeder.php:18` (`EnumRole::ADMIN`, `BRANCH_MANAGER`). The heal didn't reuse the enum. Future rename or new "Assistant Manager" role would silently fail this check. Mild.

**POS-ADV3-11 — P2 — `hasRole` checks redundant under current seeder state**
File: `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:251-253`.
Admin gets `Permission::all()` (`RolePermissionTableSeeder.php:19`) → already passes `can('cash.reconcile.variance.override')`. Branch Manager is explicitly granted the same permission (`:78`). Both `hasRole` branches in the OR chain are unreachable under default seeder state. They exist only to handle the permission-revoked corner case — and that corner case is precisely POS-ADV3-05 (the divergence with the service layer).

### Negative space (probed clean)

- Permission spelling `cash.reconcile.variance.override` matches all three sources (PermissionTableSeeder, RolePermissionTableSeeder, TestCase.php).
- Role names `'Admin'` and `'Branch Manager'` case-exact match `RoleTableSeeder.php:19`/`:50` — no `'admin'` vs `'Admin'` silent miss.
- `Auth::user() returns null` — `$user` null-guarded at `:240`.
- Admin (`branch_id=0`) early return at `:244` — unchanged, behaviour intact.
- `open()` endpoint (`:38`) reads `(int) $user->id` and `$user->branch_id` from auth — not from request body, so no spoofed-owner attack on session creation.
- Movement add endpoint: none. `recordMovement` is service-internal, called only by `PaymentService`, `SplitPaymentService`, `CashDrawerController::open` (hardware drawer pop, `CashDrawerController.php:50`) — no direct HTTP route for arbitrary movement add. Cross-cashier movement injection vector closed by API surface.
- Race on dual close (owner + manager simultaneous): `closeSession` uses `lockForUpdate` (`CashDrawerService.php:165-167`); second writer hits idempotent no-op at `:177`. Safe.
- Idempotency middleware on `/close` and `/reconcile` (`routes/api.php:820-825`). Replay safe.
- `current` endpoint queries `findOpenSessionForUser(branch, user_id)` (`CashDrawerService.php:475`) — restricted to caller's own session. No leak.

---

## P0 / P1 findings list (for owner cross-validation)

| ID | Sev | Surface | One-line |
|----|-----|---------|----------|
| POS-ADV3-05 | P1 | CashDrawer | Role OR Permission divergence vs service-layer permission-only gate |
| POS-ADV3-06 | P1 | CashDrawer | Heal commit overstates fix — manager can still zero-close peer drawer |
| POS-ADV3-07 | P1 | CashDrawer | Cascade of controller-gate + service-gate when `manager_gate_routine_close=true` blocks owner-cashier close |
| POS-ADV3-08 | P1 | CashDrawer | Test coverage gaps: cross-branch admin, revoked-permission manager, dead-code 403 |

P0 — **none**. Heal does what the commit message claims at the surface-level; residual risks are P1 design/coverage concerns rather than exploitable security holes for V1 single-restaurant Le Cayenne deploy.

## Owner gate recommendation

Heal 4: ACCEPT. POS-ADV3-02 follow-up ticket (destroy symmetry).
Heal 6: ACCEPT-WITH-RESERVES. POS-ADV3-05 + POS-ADV3-07 deserve owner clarification before V1 merge (design composition questions). POS-ADV3-08 test gap closable in small follow-up.

No frozen-zone files touched. NF525 chain logic unmodified (`AuditLogService` calls preserved). Read-only audit complete.
