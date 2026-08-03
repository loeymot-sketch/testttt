# ULTRA-INTERSECTIONS — BranchScope (multi-tenant isolation)

HEAD 48050af80 · DB foodking_e2e · read-only audit · refute-by-default

## Shared function
`App\Models\Scopes\BranchScope::apply()` + its sibling `WizardProfileBranchScope`,
both fed by the SHARED trait `App\Traits\DefaultAccessModelTrait::branch()`.
20 models carry the scope; ~40 call-sites bypass it via `withoutGlobalScope(BranchScope::class)`.

## The core resolver (branch())
```
$access = DefaultAccess::where(user_id=..., name='branch_id')->first();
if ($access)                       return $access->default_id;   // (A) DefaultAccess wins
elseif ((int) Auth::user()->branch_id === 0) return 0;           // (B) admin
else                               return Auth::user()->branch_id;// (C) staff
```
BranchScope then: `userBranch===0` → no filter (admin); else `WHERE branch_id = userBranch`.

## Paths verified (isolation SECURE on every one — staff X never reads/mutes branch Y)
- **Order read (POS show / OrderHistory)** — `withoutGlobalScope` then explicit
  `abort_unless(user.branch_id===0 || order.branch_id===user.branch_id,403)`. Order.branch_id
  cast=int, User.branch_id cast=int → strict `===` sound. CONSISTENT.
- **PosLoyalty redeem** — bypass then `$userBranchId !== 0 && !== order.branch_id → 403`. CONSISTENT.
- **counter-collect/pending** — manual `if(branch_id>0) where('branch_id',branch_id)`. CONSISTENT.
- **counter-collect/confirm|cancel, collect-kiosk-cash, kds change-status** — route-model binding
  → BranchScope applies natively (staff scoped, admin all). CONSISTENT.
- **PosReceiptPrint increment/kitchen** — entry re-scopes `when(branch_id>0, where branch_id)`;
  `printThermalTicket` receives the order's OWN branch only after ownership proven. CONSISTENT.
- **Cash (CashSessionReport / CashOverview / resolveBranchFilter)** — bypass gated by `isGlobalAdmin`;
  non-admin filter forced to `user.branch_id`, `?branch_id=` hint honored for admin only. CONSISTENT.
- **DeliveryBoyCashSession open** — bypass to look up livreur, branch derived from livreur row,
  explicit `caller.branch_id!==0 && !==branchId → 403`. CONSISTENT.
- **ZReport show/pdf** (model exempt from scope) — `abort_if(z.branch_id !== resolveBranchId,403)`;
  staff = own branch, admin unpinned = 422. CONSISTENT.
- **Frontend kiosk confirm/reconcile** — bypass + `locked.branch_id !== kioskMachine.branch_id → 403`;
  transaction_id uniqueness check is global-by-design (boolean, no data leak). CONSISTENT.
- **DefaultAccess write (escalation vector)** — `storeOrUpdate`: for key `branch_id`, a non-admin
  (`branch_id != '0'`) has `$item` FORCED to `Auth::user()->branch_id`. A staff CANNOT pin themselves
  to another branch. Escalation CLOSED. VERIFIED live: 0 staff rows with default_id != branch_id.

**Security invariant HOLDS on all paths**: for staff (branch_id>0), `branch()` always equals
`branch_id` (path C, or path A forced-equal), so the DefaultAccess-aware scope and the
DefaultAccess-blind guards agree. Every `withoutGlobalScope` bypass I traced has an explicit
post-fetch branch re-check. No cross-branch read/mutation reproducible for any staff account.

## Finding 1 — LOGIC FAULT (P3, latent): NULL branch_id conflated with admin(0)
`branch()` path (B): `(int) Auth::user()->branch_id === 0`. PHP `(int) null === 0` → **true**
(verified). So a user with `branch_id = NULL` and **no** DefaultAccess row resolves to `0` = ADMIN
→ BranchScope applies NO filter → sees ALL branches.
LIVE: users id=87,95,114,115,116,117 have `branch_id=NULL`. Under an HTTP request (non-console),
`branch()` returns 0 for them = admin scope.
**Not reproduced as a live data leak**: every such user has roles=[] → fails the RBAC gates
(`can('pos')`, `can('cash-sessions-report')`, `pos-manage-fiscal`…) that guard the actual endpoints,
so no cross-branch payload is served. Pure defense-in-depth gap: the scope layer should treat
"no branch assigned" as "see NOTHING", not "see EVERYTHING". Fix: `is_numeric($branch_id) &&
(int)$branch_id === 0` (reject null), or in BranchScope treat a null/blank resolved branch as an
impossible filter (`where 1=0`).

## Finding 2 — INCONSISTENCY (P3): two sources of truth for "current branch"
Model-scoped reads resolve branch via `branch()` (DefaultAccess-AWARE); every re-check guard,
counter-collect, ZReport `resolveBranchId`, CashOverview `resolveBranchFilter`, and OrderService
write-guards resolve via raw `auth()->user()->branch_id` (DefaultAccess-BLIND).
- Staff: the two coincide → no effect (isolation secure).
- Admin (branch_id=0) with a **pinned filiale** (`DefaultAccess.default_id=N`, LIVE for **18 admin
  accounts incl. user 1**): `branch()`=N so model lists are SCOPED to branch N, but the raw-branch_id
  guards treat them as global admin(0). Divergence is LIVE: an admin pinned to branch 1 sees only
  branch-1 orders in POS/order lists, yet `ZReportController::resolveBranchId` (raw 0) 422s / ignores
  the pin, and `PosOrderController::show` (guard `branch_id===0`) returns 200 for a branch-7 order
  fetched by direct id even though that order never appears in their scoped list.
- Not a security leak (admin is authorized cross-branch). But it is a real correctness/UX
  inconsistency AND a latent staff-leak vector: IF a user is ever demoted admin→staff
  (branch_id 0→1) without their stale `DefaultAccess.default_id` (=2) being rewritten
  (UserObserver::setBranch never touches DefaultAccess), `branch()` returns the stale 2 →
  the branch-1 staff would read branch-2 data. Currently 0 such rows exist (verified), so
  latent only. Fix: single source of truth — either make guards use `branch()` too, or make
  `branch()` ignore DefaultAccess for non-admins (staff always = branch_id) and rewrite/clear
  DefaultAccess on any branch_id change.

## Verdict
Security isolation invariant: **COHERENT** (staff never crosses branches, all bypasses re-checked).
Correctness/defense-in-depth axis: **HAS_ISSUES** — 2× P3 (NULL→admin conflation; DefaultAccess-aware
vs -blind branch resolution). 0 P0/P1. For V1 mono-branche (branch 1 only) the real-world blast
radius is ~0; the findings matter for the "invariant must hold" mandate and any future multi-branch.
