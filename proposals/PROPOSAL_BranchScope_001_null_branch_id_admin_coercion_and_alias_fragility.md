# PROPOSAL — `app/Models/Scopes/BranchScope.php`

**Phase**: B.5 — PROPOSAL AGENT (read-only audit, ZERO file edits)
**File**: `app/Models/Scopes/BranchScope.php` (FROZEN §7 multi-tenant)
**Status**: VERDICT = **PROPOSE-DEFENSE-IN-DEPTH** (V1.0.X / V1.0.2 backlog — V1 LOCAL low blast radius)
**Author**: PROPOSAL AGENT B.5
**Date**: 2026-05-23
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`

---

## 0. EXECUTIVE SUMMARY

`BranchScope::apply` is **functionally correct for the documented happy path** verified
by B3.3 DBA (20 scoped models / 10 exempted) and B3.2 Security (~57 `withoutGlobalScope`
callsites justified). Sentinel `BranchScopeCoverageSentinelTest` locks the discipline at
PR time and `BranchScopeTest::test_global_records_with_branch_id_zero_are_visible` (BS-06)
proves the FIX-54-8 invariant: **staff NEVER see `branch_id=0` rows, admin sees all**.

Integral re-read uncovered **3 defense-in-depth gaps** that are dormant in V1 LOCAL Le
Cayenne (single admin + single staff branch + DefaultAccessService gate at line 47-51)
but become exploitable / brittle in any of the following scenarios:

- a `User` row with `branch_id = NULL` (the column allows it — migration `2014_10_12_000000`)
- a future controller `->from('orders as o')` aliased query
- a future code path that writes `default_access` outside `DefaultAccessService`
  (e.g. data import, manual SQL, dev fixture, V2 SaaS onboarding script)

**Nothing here is a V1 ship-blocker.** Findings F1 + F2 fit V1.0.X cloud-prep
backlog or V1.0.2 hardening alongside the existing 10 BASELINE_V1 sentinel exemptions.
Finding F3 is INFO-only (documented behavior, but worth a comment to prevent future
drift).

Owner / B-phase synthesis agent decides whether to **lift the §7 frozen-zone freeze**
for a surgical 2-line patch or **defer to V1.0.X** with a documented test sentinel.

---

## 1. FILE READ INTEGRALLY (42 lines)

`app/Models/Scopes/BranchScope.php` end-to-end, plus collateral:

- `app/Traits/DefaultAccessModelTrait.php` (the `branch()` resolver — 51 lines)
- `app/Models/DefaultAccess.php` (the override row — 22 lines, `default_id` cast `'integer'`)
- `app/Models/Scopes/WizardProfileBranchScope.php` (the sister scope for nullable
  branch column — 59 lines)
- `app/Services/DefaultAccessService.php` (HTTP-facing write gate — 85 lines)
- `tests/Feature/BranchScopeTest.php` (BS-01..BS-08, 268 lines)
- `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` (sentinel + 12 exemptions)
- `database/migrations/2014_10_12_000000_create_users_table.php` (users.branch_id schema)

---

## 2. FINDINGS

### F1 — `(int) NULL === 0` coerces a NULL-branch user into admin (P1)

**Location**: `app/Traits/DefaultAccessModelTrait.php:14-30` (the `branch()` method
backing `BranchScope::apply` line 29).

**Code path**:

```php
// DefaultAccessModelTrait.php
if ((!App::runningInConsole() || App::runningUnitTests()) && Auth::check()) {
    $access = DefaultAccess::where(['user_id' => Auth::id(), 'name' => 'branch_id'])->first();
    if ($access) {
        return $access->default_id;          // integer-cast — safe
    } elseif ((int) Auth::user()->branch_id === 0) {  // <-- F1 here
        return 0;                            // ADMIN bypass
    } else {
        return Auth::user()->branch_id;
    }
}
```

**Schema reality** (`database/migrations/2014_10_12_000000_create_users_table.php:28`):

```php
$table->unsignedBigInteger('branch_id')->nullable()->default(0);
```

The column is **`nullable()`** with **default `0`**. The default fires on `INSERT`
**only when the column is omitted from the INSERT statement**. Any code path that
explicitly inserts `NULL` (or `UPDATE users SET branch_id = NULL`), or any pre-existing
row from a previous schema, leaves `users.branch_id IS NULL`.

PHP semantics:

```php
(int) null === 0   // true
(int) ""   === 0   // true
(int) "0"  === 0   // true
```

So a user with `branch_id IS NULL`, when authenticated, takes the `elseif` branch at
line 21, returns `0` → `BranchScope::apply` line 33 short-circuits at `if
($userBranch === 0) return;` → **NO BranchScope filter applied** → user reads all
branches. This is **identical to admin escalation**.

**Mitigations already in place** (defense in depth, partial):

- `DefaultAccessService::storeOrUpdate` lines 47-51 prevent the HTTP write path
  from setting `default_id=0` for a non-admin caller. Good. But this is **not**
  the path F1 exploits — F1 exploits the `branch()` *fallback* when **no
  `default_access` row exists**, which is the common case for any newly-created
  staff user.
- `EnsureChefLoginCommand` / `EnsurePosOperatorLoginCommand` console paths set
  `branch_id` explicitly (verified). Good.
- The owner-driven staff-creation flow likely never writes `NULL`. But the column
  TYPE is `nullable` — V2 SaaS onboarding / data import / SQL admin / dev fixture
  / failed migration rollback / Spatie permission migration all expose the surface.

**Blast radius in V1 LOCAL Le Cayenne** (per CLAUDE.md §8 / MEMORY.md `V1 LOCAL
single-resto`):

- **LOW** — single admin (branch_id=0), one Cayenne branch (branch_id=1), no V2
  SaaS multi-tenant onboarding yet. Owner-controlled user creation. No evidence
  of `branch_id IS NULL` user rows in the seeded production state.

**Blast radius V2 SaaS / cloud cutover**:

- **HIGH** — any single staff user landing in `branch_id IS NULL` (data import bug,
  legacy migration, support-tooling error, partial UPDATE) silently turns admin.
  Discovery only via audit logs that already leak data across tenants.

**Proposed fix** (defense-in-depth, 1 line in `BranchScope.php`, 1 line in
`DefaultAccessModelTrait.php`, both in §7 frozen zone — requires LOCK doc):

Option A — harden the trait so admin requires a real `0`:

```php
// DefaultAccessModelTrait.php — current:
} elseif ((int) Auth::user()->branch_id === 0) {
    return 0;
}

// proposed:
} elseif (Auth::user()->branch_id !== null && (int) Auth::user()->branch_id === 0) {
    return 0;
}
// NULL → falls through to the `else` and returns null → BranchScope WHERE
// branch_id = NULL matches nothing → fail-closed.
```

Option B — harden BranchScope directly with role-gated admin (preferred but bigger
diff):

```php
// BranchScope.php — current:
if ($userBranch === 0) {
    return;  // admin: no filter
}

// proposed (defense in depth — admin bypass requires real admin role):
if ($userBranch === 0 && Auth::user()?->hasRole('Admin')) {
    return;
}
if ($userBranch === 0) {
    // NULL or coerced-0 from a non-admin: fail closed — show nothing
    $builder->whereRaw('1 = 0');
    return;
}
```

Option B is the **production-grade fix**: it makes the admin bypass **explicit
and role-gated** rather than relying on `branch_id === 0` as a proxy for role.
It also closes a related drift: a future Spatie role rebinding that moves a
staff user to `branch_id=0` (e.g. data fix) no longer silently escalates.

**Acceptance criteria** (V1.0.2 backlog or LOCK_BRANCHSCOPE_NULL_HARDEN):

1. New regression test `BranchScopeTest::test_null_branch_id_user_does_not_see_cross_branch`
   — create a User with `branch_id => null`, actingAs, assert
   `Order::all()->count() === 0` (or whatever fail-closed semantic the owner
   chooses).
2. Existing BS-01..BS-08 stay green.
3. `BranchScopeCoverageSentinelTest` stays green.
4. Frozen-zone diff: exactly 2 lines (trait + scope) — LOCK doc lists files +
   reason.
5. NF525 fiscal chain integrity unchanged (boot/sequence/Z-report untouched).

**Recommendation**: **V1.0.X cloud-prep backlog** alongside `UNI-03` (the existing
cache-driver guard list widening for cloud cutover). V1 LOCAL has no exposure;
cloud / V2 SaaS exposure is REAL.

---

### F2 — `sprintf('%s.%s', $builder->getQuery()->from, 'branch_id')` is alias-fragile (P2)

**Location**: `app/Models/Scopes/BranchScope.php:28`.

```php
$field = sprintf('%s.%s', $builder->getQuery()->from, 'branch_id');
```

The qualified-column reference is good practice (prevents JOIN ambiguity), but it
assumes `$builder->getQuery()->from` is a bare table name string. In Laravel's
`Illuminate\Database\Query\Builder`, `$from` may also be:

- An `Illuminate\Database\Query\Expression` (raw SQL) — PHP cast to string returns
  the expression's value, possibly safe but possibly not.
- A `JoinClause` / sub-query name set via `->fromSub($query, 'alias')` — gives
  `(SELECT ... ) as alias`, which `sprintf` turns into `(SELECT ... ) as alias.branch_id`
  — broken SQL.
- An aliased table set via `Order::from('orders as o')` — gives `orders as o`,
  which `sprintf` turns into `orders as o.branch_id` → MySQL parse error
  `Unknown column 'o.branch_id'` because the alias is `o`, not `orders as o`.

**Empirical V1 status**: a quick grep across `app/Http/Controllers` and `app/Models`
did NOT surface any `->from('table as alias')` against a BranchScope-bound model.
So this is **dormant in V1**, but a future contributor adding an alias would silently
break BranchScope on that query path — and the failure mode is a 500, not a security
hole, so the test surface is wide.

**Proposed fix** (1-line defensive cast, V1.0.2 backlog):

```php
// BranchScope.php — current:
$field = sprintf('%s.%s', $builder->getQuery()->from, 'branch_id');

// proposed (defensive — fall back to alias-less qualification):
$from = $builder->getQuery()->from;
$table = is_string($from) ? $from : $builder->getModel()->getTable();
// Strip "table as alias" → use alias for qualification
if (is_string($from) && stripos($from, ' as ') !== false) {
    [, $alias] = preg_split('/\s+as\s+/i', $from, 2);
    $table = trim($alias);
}
$field = sprintf('%s.%s', $table, 'branch_id');
```

Or simpler — always qualify via `$builder->getModel()->qualifyColumn('branch_id')`
which Eloquent ships for exactly this case:

```php
$field = $builder->getModel()->qualifyColumn('branch_id');
```

`qualifyColumn` respects alias if set via `$this->setTable()`. The expression-cast
edge case (rare in practice) would still need the `is_string` guard.

**Acceptance**:

1. New regression test that creates an aliased query (`Order::from('orders as o')->all()`)
   actingAs branch-A user, asserts scope still applies.
2. BS-01..BS-08 stay green.
3. No change to generated SQL on the non-aliased path (the dominant case).
4. Frozen-zone diff: 1 line in `BranchScope.php`. LOCK doc minimal.

**Recommendation**: **V1.0.2 backlog**. Low priority — no current code path
triggers it, but it's a 1-line robustness win that prevents a future drift.

---

### F3 — Strict identity `=== 0` is correct but coupling-fragile (P3, INFO)

**Location**: `app/Models/Scopes/BranchScope.php:33`.

```php
if ($userBranch === 0) {
    return;
}
```

The strict identity check is intentional and **correct** given the
`DefaultAccess::default_id` integer cast (line 19 of `DefaultAccess.php`). But:

- The Settings fallback `Settings::group('site')->get('site_default_branch')` in
  `DefaultAccessModelTrait.php:28` returns a string from Smartisan-settings JSON
  storage. The path is gated by `Auth::check()` so it never reaches the
  `=== 0` check (this path runs in non-authenticated context only). Confirmed
  safe by reading.
- The `else { return Auth::user()->branch_id; }` at line 25 of the trait returns
  the **raw** column value. If `User::$casts` is later changed (or the
  `getBranchIdAttribute` accessor returns a string), the return type drifts
  from `int` to `string` and `$userBranch === 0` becomes always-false →
  staff would silently receive a `WHERE branch_id = "0"` filter that MySQL
  coerces to integer at query time — still functionally correct, but the
  admin bypass at line 33 would be silently lost.

**Mitigation**: no code change needed; **add a docblock or cast** to make
the contract explicit and prevent silent drift.

**Proposed mitigation** (V1.0.2 backlog, comment-only — does NOT touch §7 frozen zone
hot code path, only adds a docblock):

```php
public function apply(Builder $builder, Model $model)
{
    // [F3 INFO 2026-05-23] $this->branch() returns int|null|string. The strict
    // `=== 0` check at line 33 below relies on DefaultAccess::default_id being
    // integer-cast (DefaultAccess.php:19). DO NOT change DefaultAccess casts
    // without updating this scope. F1+F2 explore the broader nullability
    // contract; this is the documentation pointer.
    ...
}
```

**Recommendation**: **V1.0.2 INFO** — pure docblock. No behavior change.
Skip if F1 fix lands (the role-gated bypass in Option B sidesteps F3 entirely).

---

## 3. WHAT WAS VERIFIED GREEN (no findings)

For audit completeness — these were checked and pass:

- **NF525 invariant**: BranchScope is read-only on the auth side, never touches
  `audit_logs`, `z_reports`, `fiscal_sequence_no`, `composition_snapshot`. The
  3 NF525-critical models (`AuditLog`, `ZReport` + `Order`) are scoped correctly
  per sentinel: `AuditLog` + `ZReport` are intentionally exempt (BASELINE_V1
  exemption — manual scope today), `Order` is scoped.
- **Sanctum recursion guard** (line 21-23): correct. `if ($model instanceof User)
  return;` prevents the infinite loop documented in the comment. Confirmed in
  `User.php:90` that `User` carries `addGlobalScope(new BranchScope())` and the
  guard prevents it from biting. (Note: `User` is in the 20-model
  sentinel-confirmed scoped list per CLAUDE.md §9 even though the scope itself
  no-ops on `User` — the discipline is to declare the scope so a future change
  removing the User-skip in BranchScope automatically picks up the User table.)
- **Console-bypass logic** (line 27): `App::runningInConsole() &&
  !App::runningUnitTests()` correctly skips the scope in queue workers,
  schedulers, and Tinker — those run as admin and need cross-branch reads. Unit
  tests (`runningUnitTests()`) still apply the scope, matching the
  `actingAs()` HTTP test contract.
- **`->where` closure-safe**: BS-05 (`test_orwhere_does_not_break_isolation`)
  proves `->where('status', X)->orWhere('status', Y)` does NOT leak across
  branches. Eloquent wraps global-scope wheres in parens automatically when
  followed by `orWhere` — empirically confirmed by the green test.
- **Index alignment / performance**: every scoped table has either a
  `branch_id` index or a composite index starting with `branch_id` (verified
  by spot-checking migrations for `Order`, `OrderItem`, `OrderPayment`,
  `StockLevel`, `KioskMachine`). Single equality on indexed column = optimal
  query plan.
- **Auth-state coherence**: `Auth::check()` and `$this->branch()` (which calls
  `Auth::id()` + `Auth::user()`) both go through the same Sanctum guard in a
  single PHP request — no observable race.

---

## 4. RISK MATRIX

| Finding | Severity V1 LOCAL | Severity V2 SaaS / cloud | Diff size | Frozen-zone impact | Recommendation |
|---------|-------------------|--------------------------|-----------|--------------------|----------------|
| **F1** — NULL→admin coercion | Low (no NULL rows in seed) | **HIGH** (data-import drift) | 1-2 lines | YES (trait + scope) — LOCK | V1.0.X cloud-prep |
| **F2** — alias fragility | Dormant (no aliased queries) | Dormant (defense-in-depth) | 1 line | YES (scope only) — LOCK | V1.0.2 |
| **F3** — strict `=== 0` coupling | Info | Info | 0 lines (docblock) | NO (comment only) | V1.0.2 INFO, optional |

---

## 5. CROSS-REF B3.2 / B3.3 ATTESTATIONS

- **B3.3 DBA attested 20 models scoped + 10 exempted per BranchScopeCoverageSentinelTest**:
  confirmed verbatim by reading sentinel constant `EXEMPTED_MODELS` — 12 entries
  (2 architectural + 10 BASELINE_V1). 20 = the `addGlobalScope(new BranchScope)`
  grep result (21 matches counting WizardProfileBranchScope alias, less 1 =
  20 BranchScope-proper). The sentinel ratchet pattern is sound.
- **B3.2 Security verified ~57 `withoutGlobalScope` callsites justified**:
  current grep shows 72 total `withoutGlobalScope*` and 21
  `withoutGlobalScope(BranchScope::class)`. Sampled 15 of 21 callsites
  (PaymentReconcileController, KioskMachineLoginController,
  StockRuptureDashboardController, CashOverviewController, OrderController,
  PosOrderController, GuestSignupController, DeliveryBoyCashSessionController):
  all carry an inline comment or rely on the pre-auth-lookup pattern documented
  in CLAUDE.md §9. Spot check confirms B3.2's attestation; F1/F2/F3 are
  **orthogonal** to the callsite list — they target the scope internals, not
  the bypass surface.

---

## 6. NO FILE EDITS PERFORMED

Per agent contract:

- ZERO edits to `app/Models/Scopes/BranchScope.php` (§7 frozen).
- ZERO edits to `app/Traits/DefaultAccessModelTrait.php` (collateral).
- ZERO edits to `tests/Feature/BranchScopeTest.php` or sentinel.
- Only file created: this proposal at
  `proposals/PROPOSAL_BranchScope_001_null_branch_id_admin_coercion_and_alias_fragility.md`.

If owner / synthesis agent green-lights F1 or F2, a LOCK doc + GStack TDD cycle
is required per CLAUDE.md §7 + §10. Suggested LOCK doc naming:

- `plans/LOCK_BRANCHSCOPE_NULL_HARDEN_2026-05-23.md` (F1)
- `plans/LOCK_BRANCHSCOPE_ALIAS_QUALIFY_2026-05-23.md` (F2)

---

## 7. RETURN PAYLOAD (for orchestrator)

- **File audited**: `app/Models/Scopes/BranchScope.php` (42 lines, integral read)
- **Proposal path**: `proposals/PROPOSAL_BranchScope_001_null_branch_id_admin_coercion_and_alias_fragility.md`
- **Finding count**: 3 (F1 P1, F2 P2, F3 P3)
- **Verdict**: PROPOSE-DEFENSE-IN-DEPTH (V1.0.X / V1.0.2 backlog — no V1 ship blocker)
- **Frozen-zone touch attempted**: 0 (read-only)
- **Sentinel impact**: 0 (existing baseline-lock unchanged)
- **NF525 impact**: 0 (orthogonal subsystem)
