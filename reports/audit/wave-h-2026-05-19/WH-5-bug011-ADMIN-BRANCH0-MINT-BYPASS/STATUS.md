# WH-5 — bug_011 / R3 T-2.2.1 Sec S-3 — Administrator Branch=0/null Mint Bypass

**Status**: GREEN (heal complete, sentinel locked)
**Date**: 2026-05-19
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Severity**: SECURITY P0 — privilege escalation
**Effort**: ~30 min

---

## 1. Recon — exploit path verified

`app/Http/Requests/AdministratorRequest.php` line 83 (pre-fix):
```php
if ($bid !== null && (int) $bid === 0
    && ! ($caller?->hasRole('Admin') || $caller?->hasRole('Tenant Admin'))) {
    // gate fires
}
```

The previous M-R3-P0-E heal (commit `935eaca25` per PR description) only
covered the case where the attacker supplied `branch_id=0` explicitly.
Omitting the `branch_id` field entirely bypassed the gate:

1. `rules()` declares `'branch_id' => ['nullable', 'numeric']` → validation passes.
2. `withValidator` calls `$bid = $this->input('branch_id')` → returns `null`.
3. `$bid !== null` is **false** → gate short-circuits, **no error added**.
4. `AdministratorService::store` line 70 writes `branch_id => null` via Eloquent
   (explicit NULL **bypasses the column default `0`**) and line 74 calls
   `assignRole(EnumRole::ADMIN)` unconditionally.
5. On the next request `DefaultAccessModelTrait::branch()` line 21 evaluates
   `(int) Auth::user()->branch_id === 0` → `(int) null === 0` → **true** →
   BranchScope returns early (`if ($userBranch === 0) return;`) → the
   freshly-minted user reads/writes ACROSS ALL BRANCHES = super-admin.

**Impact**: any user holding the `administrators_create` permission (e.g. a
Branch Manager) could mint a fully-privileged super-admin in ONE HTTP call by
omitting `branch_id` from the POST body. The PR description's claim that
R3 T-2.2.1 Sec S-3 was closed by `935eaca25` is therefore **incorrect** — the
fix only addressed the explicit-zero path.

---

## 2. Sentinel — RED proof + GREEN lock

File: `tests/Feature/Sentinels/AdministratorBranchZeroMintBypassSentinelTest.php` (NEW, 6 cases)

| # | Scenario | Pre-fix | Post-fix |
|---|---|---|---|
| 1 | Non-super-admin POST WITHOUT branch_id | **RED (201, exploit succeeds)** | GREEN (422) |
| 2 | Non-super-admin POST branch_id=0 explicit | GREEN (already covered by M-R3-P0-E) | GREEN (422) |
| 3 | Non-super-admin POST branch_id=42 valid | GREEN | GREEN (201) |
| 4 | Super-admin (Admin) POST WITHOUT branch_id | GREEN | GREEN (201) |
| 5 | PHP `(int) null === 0` semantic lock | GREEN | GREEN |
| 6 | Non-super-admin PATCH WITHOUT branch_id | **RED (200, update bypass)** | GREEN (422) |

Tests 1 + 6 are the **exact exploit signatures** — they capture the bug at
HTTP level (not unit level) via `actingAs($this->branchManager, 'sanctum')`
+ `postJson('/api/admin/administrator')` against the live Laravel router.

Test 1 asserts both 422 AND DB state (zero `User::role('Admin')->whereNull('branch_id')`
rows after the rejected request) so a silent regression where 422 fires but
the row still leaks would still fail.

Test 5 pins the PHP type-coercion semantic that `DefaultAccessModelTrait`
relies on — if a future refactor breaks that coupling, this sentinel fails
loudly and forces a re-audit of the gate.

Test 6 covers the UPDATE path explicitly. Both `AdministratorService::store`
(line 70) and `AdministratorService::update` (line 96) consume
`AdministratorRequest`, so a single gate fix in `withValidator` closes both
verbs — Test 6 proves this empirically rather than by assumption.

### RED proof (pre-fix run)

```
PASS  Tests\Feature\Sentinels\AdministratorBranchZeroMintBypassSentinelTest
✗ 1 non super admin post without branch id is blocked 422       FAIL (got 201)
✓ 2 non super admin post branch zero explicit is blocked 422
✓ 3 non super admin post branch id valid succeeds
✓ 4 super admin post without branch id is allowed
✓ 5 php null to int semantic is locked
✗ 6 non super admin patch without branch id is blocked 422      FAIL (no branch_id error)

Tests: 2 failed, 4 passed
```

### GREEN proof (post-fix run)

```
PASS  Tests\Feature\Sentinels\AdministratorBranchZeroMintBypassSentinelTest
✓ 1 non super admin post without branch id is blocked 422
✓ 2 non super admin post branch zero explicit is blocked 422
✓ 3 non super admin post branch id valid succeeds
✓ 4 super admin post without branch id is allowed
✓ 5 php null to int semantic is locked
✓ 6 non super admin patch without branch id is blocked 422

Tests: 6 passed
Time:  1.22s
```

---

## 3. Heal — Option A (smallest diff at the gate)

`app/Http/Requests/AdministratorRequest.php` `withValidator` block:

```diff
-$bid = $this->input('branch_id');
-if ($bid !== null && (int) $bid === 0
-    && ! ($caller?->hasRole('Admin') || $caller?->hasRole('Tenant Admin'))) {
+$bid = $this->input('branch_id');
+$isSuperAdmin = $caller?->hasRole('Admin') || $caller?->hasRole('Tenant Admin');
+if (($bid === null || (int) $bid === 0) && ! $isSuperAdmin) {
     $validator->errors()->add('branch_id', '…');
 }
```

The condition now treats `null` and `0` identically, matching the underlying
`(int) null === 0` PHP semantic that the rest of the codebase
(`DefaultAccessModelTrait`, `BranchScope`) already operates on. Non-super-admin
callers MUST supply a non-zero `branch_id`.

Comment updated to:
- credit bug_011 / WH-5 (correct attribution),
- explain why `null === 0` for authz purposes (couples explicitly with
  `DefaultAccessModelTrait::branch()` and `BranchScope`),
- document that this gate covers BOTH `store` and `update` verbs.

Why not Option B (prepareForValidation merge to caller's branch_id)? Two
reasons: (1) the task's Test 1 spec explicitly requires `422` for
non-super-admin omitting `branch_id` — deny-by-default is safer for a
privilege-escalation gate, (2) silently filling the value would hide
malicious payloads from the audit trail and make the security boundary less
obvious to future reviewers.

---

## 4. Frozen-zone discipline

- `BranchScope.php`: **untouched** (verified `git diff` → no app/Models or app/Traits changes).
- `DefaultAccessModelTrait.php`: **untouched**.
- `FiscalSequenceService.php` / `ZReportService.php` / `AuditLogService.php`:
  not in scope, untouched.
- `PricingService.php` / `OrderStateMachine.php` / `IdempotencyKeyMiddleware.php`:
  not in scope, untouched.

Total app/ changes:

```
app/Http/Requests/AdministratorRequest.php   (1 file, +24/-13 lines)
```

NF525 chain unchanged. Multi-tenant invariants reinforced (the fix tightens
the BranchScope coupling at the request edge, not loosens it).

---

## 5. Regression — `Administrator|Auth|BranchScope|Sentinel` suite

```
Tests:  7 failed, 2 skipped, 425 passed
Time:  58.43s
```

All 7 failures are **pre-existing** and **unrelated** to this fix (verified
by stashing the WH-5 changes and re-running the same filter):

```
× ComposerAuthzMinimalTest > branch admin cannot update foreign profile by forging payload scope
× ComposerAuthzMinimalTest > show defaults to actor branch and does not leak foreign latest profile
× ComposerAuthzMinimalTest > branch admin cannot mutate composer steps for other branch
× DeliveryBoyHardeningSentinelTest > p0 liv 01 select delivery boy rejects cross branch driver
× DeliveryBoyHardeningSentinelTest > p0 liv 01 select delivery boy rejects non delivery boy target
× DeliveryBoyHardeningSentinelTest > p0 liv 01 select delivery boy allows same branch driver
× SelectDeliveryBoyRoleByNameSentinelTest > select delivery boy succeeds when role id skipped past legacy enum
```

Root cause: pre-existing seed/role setup bugs (`"There is no role named 'Delivery Boy'"`, `"There is no role with id 3"`) — these failures reproduce on pre-fix `HEAD` and are tracked separately. Not blockers for WH-5.

The 6 NEW `AdministratorBranchZeroMintBypassSentinelTest` cases all pass.

---

## 6. Related-but-out-of-scope (flagged for follow-up)

- `AdministratorService::store` always calls `assignRole(EnumRole::ADMIN)`
  regardless of caller — so even after this fix, any user holding
  `administrators_create` can mint a *branch-scoped* Admin. That is likely
  intentional (this endpoint is named "administrator create"), but it
  means `administrators_create` itself MUST be a tightly-held permission.
  Authorization design question, not a WH-5 bug.
- The previous heal commit `935eaca25` (M-R3-P0-E) attribution in the PR
  description should be amended to "partial — closes explicit branch_id=0
  path only; null-omission path closed by WH-5 (this commit)".

---

## 7. Deliverables

1. `app/Http/Requests/AdministratorRequest.php` — Option A gate fix (1 file, ~24/-13 lines).
2. `tests/Feature/Sentinels/AdministratorBranchZeroMintBypassSentinelTest.php` — NEW 6-case sentinel.
3. `reports/audit/wave-h-2026-05-19/WH-5-bug011-ADMIN-BRANCH0-MINT-BYPASS/STATUS.md` — this file.
4. Commit: `fix(admin-authz-bug011): close branch_id-omitted super-admin mint bypass (R3 T-2.2.1 Sec S-3 actually closed)`.
