# PROPOSAL — apply() re-query inside transaction does NOT preserve global-scope exemptions

**ID** : PROP-OSM-003
**Date** : 2026-05-23
**Phase** : B.5 — PROPOSAL AGENT for `app/Domain/Order/OrderStateMachine.php`
**Frozen file** : `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7)
**Severity** : P1 — multi-tenant invariant fault that masquerades as 404
**Touch** : ZERO (read-only audit, proposal only)

---

## 1. Finding (read-only)

`OrderStateMachine::apply()` at lines 200-210 :

```php
$modelClass = get_class($order);
$orderKey = $order->getKey();
if ($orderKey === null) {
    throw new IllegalTransitionException(
        sprintf('Cannot apply transition to unsaved %s instance.', $modelClass)
    );
}

DB::transaction(function () use ($order, $modelClass, $orderKey, $next, $actor, $reason): void {
    /** @var Model $locked */
    $locked = $modelClass::query()->whereKey($orderKey)->lockForUpdate()->firstOrFail();
```

The `$modelClass::query()` call inside the transaction creates a **fresh
Eloquent query builder** that applies **all global scopes** registered on the
model. For `Order` and `FrontendOrder`, this includes :

- `BranchScope` (`app/Models/Scopes/BranchScope.php`) — multi-tenant filter
- `SoftDeletingScope` (via `SoftDeletes` trait on `Order` only)

If the **caller-provided** `$order` instance was loaded with
`withoutGlobalScope(BranchScope::class)` — which is the standard pattern for
console jobs / cron / system actors — then `$order` exists in memory but the
**inner re-query DOES re-apply BranchScope and DOES re-apply
SoftDeletingScope**, potentially throwing `ModelNotFoundException` on
`firstOrFail()` even though the row exists in the database.

---

## 2. Why the cron job path is currently safe (and why it is FRAGILE)

`CleanupStalePendingKioskOrders::handle()` is the sole production `apply()`
callsite. It works **today** because :

```php
FrontendOrder::withoutGlobalScope(BranchScope::class)
    ->whereNull('deleted_at')
    ->where('status', OrderStatus::PENDING)
    // ...
    ->each(function (FrontendOrder $order): void {
        $oldStatus = null;
        $rejected = false;

        DB::transaction(function () use ($order, &$oldStatus, &$rejected): void {
            $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();
            // ... own guard ...

            OrderStateMachine::apply(
                $locked,
                OrderStatus::REJECTED,
                null,
                'Auto-rejected stale pending kiosk order after 15 minutes.'
            );
```

Two facts save the call :

1. The Job **runs in console / queue context** where `auth()->user()` is null,
   so `BranchScope::apply()` short-circuits to "no filter" anyway
   (see `BranchScope::apply()` for the `Auth::check()` guard).
2. The job pre-locks the row WITHOUT BranchScope before calling `apply()`, so
   even if `apply()`'s inner re-query DID apply BranchScope, the system's
   no-auth-user state means BranchScope is a no-op.

**BUT** :

3. The `apply()` inner query is `firstOrFail()` not `first()` — any future
   path that passes a `FrontendOrder` loaded with `withoutGlobalScope` from
   inside an **authenticated branch-staff context** would hit the trap.
4. If a future operations-staff member (branch_id=2) calls a manual cleanup
   endpoint that loads cross-branch stale orders with `withoutGlobalScope`,
   `apply()`'s inner re-query would `firstOrFail` because BranchScope would
   filter the row OUT.

---

## 3. Worst-case scenario (hypothetical but plausible)

A V1.0.2 admin dashboard adds an "Auto-reject all stale orders globally"
button accessible to branch-2 staff with `permission:settings`. The handler
might naturally write :

```php
// hypothetical /admin/orders/bulk-reject endpoint
public function bulkRejectStale(): JsonResponse {
    Order::withoutGlobalScope(BranchScope::class)   // see all branches
        ->where('status', OrderStatus::PENDING)
        ->where('created_at', '<', now()->subMinutes(15))
        ->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                try {
                    OrderStateMachine::apply($order, OrderStatus::REJECTED, auth()->user(), 'bulk_stale');
                } catch (ModelNotFoundException) {
                    // silently dropped — but WHY?
                }
            }
        });
}
```

Now the inner `$modelClass::query()->whereKey($orderKey)->lockForUpdate()->firstOrFail()` :

- `auth()->user()` IS the branch-2 staff member
- `BranchScope::apply()` adds `WHERE branch_id = 2`
- Rows for branch 3, branch 4, etc. throw `ModelNotFoundException`

The endpoint silently drops cross-branch orders from the bulk action.
The branch-2 staff member sees "23 orders rejected" when 187 should have
been. NO error logged. NO indication something is wrong.

**This is a SILENT multi-tenant isolation FAULT** because the caller's
intent was explicitly "see all branches" via the outer scope-removal, but
the inner re-query silently undid that intent.

---

## 4. SoftDeletingScope variant

The same issue applies to soft-deleted orders. `Order` uses `SoftDeletes`
(line 17 `use SoftDeletes;`). If a caller does :

```php
$order = Order::withTrashed()->find(123);
OrderStateMachine::apply($order, OrderStatus::DELIVERED, ...);
```

The inner `Order::query()->whereKey(123)->lockForUpdate()->firstOrFail()`
applies `SoftDeletingScope` (the default `whereNull('deleted_at')` filter)
and **misses the soft-deleted row**, throwing `ModelNotFoundException`. The
caller had explicitly opted into seeing trashed rows ; `apply()` silently
revokes that opt-in.

In V1 this is **less critical** because nobody applies status transitions to
soft-deleted orders. But it IS another latent surprise.

---

## 5. Three resolution paths

### Option A — Re-query through the caller-provided model's query builder

```diff
DB::transaction(function () use ($order, $modelClass, $orderKey, $next, $actor, $reason): void {
    /** @var Model $locked */
-   $locked = $modelClass::query()->whereKey($orderKey)->lockForUpdate()->firstOrFail();
+   $locked = $order->newQueryWithoutScopes()
+       ->whereKey($orderKey)
+       ->lockForUpdate()
+       ->firstOrFail();
```

`Model::newQueryWithoutScopes()` builds a query stripped of ALL global
scopes. Combined with the caller's `whereNull('deleted_at')` already enforced
upstream (e.g. by the Job), this puts the responsibility for scope handling
back where it belongs : the caller.

**Pros:**
- Preserves caller intent. The job that explicitly stripped BranchScope upstream
  no longer gets it re-applied.
- Defensive against future authenticated-context callsites.

**Cons:**
- Strips ALL scopes — including SoftDeletingScope. A future caller passing a
  soft-deleted `Order` would now lock + mutate a deleted row, which is arguably
  worse semantically (touching deleted data without explicit opt-in).
- Touches `OrderStateMachine.php` — frozen-zone gate required.

### Option B — Preserve caller's scope removal explicitly

Capture which scopes the caller has removed and replay them on the inner query :

```diff
+ // Mirror the caller's scope-removal intent on the inner re-query.
+ $removedScopes = $order->getRemovedScopes() ?? [];   // pseudo-API
+ $lockedQuery = $modelClass::query();
+ foreach ($removedScopes as $scope) {
+     $lockedQuery->withoutGlobalScope($scope);
+ }
+ $locked = $lockedQuery->whereKey($orderKey)->lockForUpdate()->firstOrFail();
```

Eloquent does NOT expose a public API to introspect removed scopes on an
instance ; this would require subclassing Builder or reflecting into internals.

**Pros:**
- Most-faithful preservation of caller intent.

**Cons:**
- Reflection / internal-API dependency = future Laravel upgrade risk.
- Higher complexity.

### Option C — Document the constraint + add a sentinel

Keep `apply()` as-is. Document the constraint in the docblock :

```diff
+ * IMPORTANT — Scope semantics:
+ * The inner re-query inside the DB::transaction applies ALL global scopes
+ * registered on the model (BranchScope, SoftDeletingScope, ...). If the
+ * caller-provided $order was loaded with withoutGlobalScope(...) the inner
+ * re-query will RE-APPLY those scopes and may throw ModelNotFoundException
+ * on firstOrFail. Callers from console / queue context that need cross-tenant
+ * visibility MUST ensure auth()->user() is null (BranchScope short-circuits)
+ * OR must lock the row themselves with the scope-removed query and call the
+ * legacy `recordTransition()` pattern instead of apply().
```

And add `tests/Feature/Sentinels/ApplyScopeSemanticSentinelTest.php` that
asserts this behaviour explicitly so future maintainers don't silently rely on
the wrong assumption.

**Pros:**
- ZERO behaviour change ; pure documentation.
- Zero risk for V1 ship.

**Cons:**
- The hypothetical bulk-reject endpoint would still silently fail when added.
- Documentation can be ignored.

---

## 6. Recommendation

**Option C** for V1 (document + sentinel). **Option A as V1.0.2 backlog item.**

Reasoning :

1. Today **no callsite is broken**. The cron job works because of the auth-null
   short-circuit. Touching frozen code to fix a hypothetical future endpoint is
   not warranted in V1.
2. Option A is the correct long-term fix but requires careful regression
   review of all `apply()` paths (especially when SoftDeletes interaction with
   future callsites is unclear).
3. The documentation + sentinel turn the silent risk into an explicit contract,
   so anyone writing the V1.0.2 bulk-reject endpoint is forewarned.

---

## 7. LOCK feasibility

**If Option A is pursued :**
- ≤5 LOC change ? **YES** (replace one `$modelClass::query()` line with
  `$order->newQueryWithoutScopes()`)
- Architectural redesign ? **NO**
- Frozen file ? **YES**
- Owner gate ? **REQUIRED** — domain SM file

**If Option C is pursued :**
- ≤15 LOC docblock addition + 1 new sentinel test file.
- Frozen file change is docblock-only — still requires LOCK doc per CLAUDE.md §7.

---

## 8. Verification plan (post-implement, Option C)

- New sentinel `tests/Feature/Sentinels/ApplyScopeSemanticSentinelTest.php` :
  ```php
  public function test_apply_reapplies_branch_scope_in_authenticated_context(): void {
      // Authenticated as branch-2 staff
      $staff = User::factory()->create(['branch_id' => 2]);
      $this->actingAs($staff);

      // Branch-3 order, loaded with withoutGlobalScope
      $branch3Order = Order::withoutGlobalScope(BranchScope::class)
          ->find(/* branch-3 order id */);

      // apply() should throw ModelNotFoundException — documenting the trap
      $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
      OrderStateMachine::apply($branch3Order, OrderStatus::ACCEPT, $staff, null);
  }
  ```
- Regression : full `OrderStateMachineApplyTest` + `OrderStateMachineLockForUpdateTest` + Job test.

---

## 9. Owner sign-off

- [ ] APPLY-OPTION-A (newQueryWithoutScopes ; LOCK required)
- [ ] APPLY-OPTION-B (scope-replay reflection ; complexity heavy)
- [x] **APPLY-OPTION-C (document + sentinel) recommended for V1**
- [ ] DEFER-V1.0.2 (no documentation either ; accept hidden risk — NOT recommended)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 10. References

- `app/Domain/Order/OrderStateMachine.php` :200-210
- `app/Models/Order.php` :92 — `addGlobalScope(new BranchScope())`
- `app/Models/FrontendOrder.php` :23 — `addGlobalScope(new BranchScope)`
- `app/Models/Scopes/BranchScope.php` — multi-tenant filter
- `app/Jobs/CleanupStalePendingKioskOrders.php` :47-65 — current safe callsite
- `CLAUDE.md` §9 Multi-Tenant + Auth Invariants — BranchScope on 20 models baseline
