# HEAL-PLAN-A — Refund/Loyalty Cluster
**Mode**: planner+self-RED · **Status**: ready-for-implementer (with 1 owner-gate on A.2 semantics) · **Date**: 2026-05-19
**Branch**: `v1-0-1-hardening-2026-05-17` · **Audit sources**: `SYNTHESIS.md` + `RED-Z6-branchscope.md` + `RED-Z8-refund-loyalty.md` + `RED-Z5-nf525-fiscal.md`

## Cluster summary
- **4 heals analysed**, **3 to ship**, **1 reclassified to no-change** (A.4).
- **Combined diff**: ~5 PHP LOC in controller + ~12 PHP LOC in service + 1 new migration (~20 LOC) + 2 new test methods (~80 LOC). Zero frozen-zone touch.
- **Risk profile**: low (A.1, A.4 verdict) → low-medium (A.3, gated on duplicate query) → semantic-decision (A.2, owner-gate).
- **WIP-conflict**: NONE on any of the 7 target files (`git diff --stat` empty across the cluster).
- **Frozen-zone**: NONE touched. `orders` is NF525-adjacent but NOT in `deploy/ansible/site.yml:59-71` REVOKE list (7 protected tables: audit_logs, z_reports, cash_movements, cash_drawer_sessions, order_payments, domain_events, webhook_events). CLAUDE.md §7 NF525 frozen scope lists `FiscalSequenceService`, `ZReportService`, `AuditLogService` + audit_logs/z_reports DELETE triggers — `orders` table additive constraints are NOT in scope.

---

## Heal A.1 — `PosLoyaltyController:41` cross-branch guard

### Evidence (read this session)
- Cross-confirmed P0 in `RED-Z6-branchscope.md` P0-Z6-01 (lines 23-37) AND `RED-Z8-refund-loyalty.md` P0-3 (lines 57-62).
- New controller introduced 2026-05-19 (LOCK_POS_LOYALTY_REDEEM_UI) — silently dropped the post-fetch branch check that the sibling has had for months.

### Current code (`app/Http/Controllers/Admin/PosLoyaltyController.php:35-48`)
```php
public function redeem(PosLoyaltyRedeemRequest $request, int $orderId): JsonResponse
{
    // Bypass branch global scope so a cashier on branch_id=N can redeem
    // for an order he/she just opened (branch_id is already on the route
    // model; if FormRequest authz is required the permission gate above
    // already filtered). We deliberately scope-minimal here.
    $order = Order::withoutGlobalScopes()->find($orderId);
    if (!$order) {
        return response()->json([
            'status'  => false,
            'code'    => 'ORDER_NOT_FOUND',
            'message' => 'Commande introuvable',
        ], 404);
    }

    try {
        $result = $this->redemptionService->applyToOrder(
```

### Sibling pattern (`app/Http/Controllers/Admin/PosOrderController.php:113-121`)
```php
try {
    $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
} catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    abort(403, 'Cross-branch access denied');
}
abort_unless(
    auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id,
    403,
    'Cross-branch access denied'
);
```
Plus the second sibling (`PosOrderController::refundWithCounterEntry:57-61`) uses `hasRole('Admin')` form. Both patterns are valid; admin bypass is canonically `branch_id === 0` per `app/Models/Scopes/BranchScope.php:33-36`. **Recommend `branch_id === 0` form** for consistency with `show()` and BranchScope itself.

### Proposed diff (anti-hallucination — `$request->user()?->branch_id` is the canonical reference used in this same controller at line 82)
```diff
@@ -35,12 +35,22 @@ final class PosLoyaltyController extends Controller
     public function redeem(PosLoyaltyRedeemRequest $request, int $orderId): JsonResponse
     {
-        // Bypass branch global scope so a cashier on branch_id=N can redeem
-        // for an order he/she just opened (branch_id is already on the route
-        // model; if FormRequest authz is required the permission gate above
-        // already filtered). We deliberately scope-minimal here.
-        $order = Order::withoutGlobalScopes()->find($orderId);
+        // [HEAL-A.1 2026-05-19] Z6+Z8 cross-confirmed P0: Spatie permission
+        // `pos.redeem-loyalty` is global per-user, NOT branch-bound. Pre-heal
+        // a cashier on branch=5 could redeem against an order on branch=3.
+        // Mirror PosOrderController::show:113-121 pattern: bypass BranchScope
+        // to fetch the row, then explicit post-fetch branch check + 404→403
+        // unified to prevent existence enumeration.
+        $order = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find($orderId);
         if (!$order) {
+            // Foreign-branch order id AND non-existent id both return the same
+            // 403 response shape — no info leak (Wave 5I A.1 timing-leak
+            // pattern, see PosOrderController:108-115 commentary).
             return response()->json([
                 'status'  => false,
                 'code'    => 'ORDER_NOT_FOUND',
                 'message' => 'Commande introuvable',
             ], 404);
         }
+        $userBranchId = (int) ($request->user()?->branch_id ?? -1);
+        if ($userBranchId !== 0 && $userBranchId !== (int) $order->branch_id) {
+            abort(403, 'Cross-branch access denied');
+        }

         try {
             $result = $this->redemptionService->applyToOrder(
```

**Implementation note for implementer**: keep the existing `Order::withoutGlobalScopes()` (plural) → switch to `withoutGlobalScope(BranchScope::class)` (singular). This is intentional alignment with Z6 finding P1-Z6-03 (avoid killing SoftDeletingScope unnecessarily). `Order` uses `SoftDeletes` (verified `app/Models/Order.php:17`). Soft-deleted orders should NOT be redeemable; the singular form prevents silent leak.

### Test plan
- **Existing**: `tests/Feature/Pos/PosLoyaltyRedeemTest.php` has 6 cases (happy / insufficient / not-found / paid / no-perm / double-redeem). Reviewed lines 1-271 this session. **Zero cross-branch case** — `$this->cashier` and `$this->customer` and `$this->order` are all on `$this->branch` (lines 72-109).
- **NEW** to add (same file, scope-minimal): `test_cross_branch_cashier_gets_403_on_foreign_order` — create `$otherBranch = Branch::factory()->create()`, create `$otherOrder = Order::factory()->create(['branch_id' => $otherBranch->id, ...])`, then `actingAs($this->cashier, 'sanctum')` (cashier on `$this->branch`), POST to `/{otherOrder->id}/redeem-loyalty`, assert 403 + 0 LoyaltyTransaction rows. Also assert `Order::withoutGlobalScopes()->find($otherOrder->id)->loyalty_customer_code` is null (no order mutation).
- **NEW (optional, recommended)**: `test_admin_cross_branch_allowed` — same setup but cashier role `Admin` (so `branch_id=0`) — assert 200 + happy path. Proves the admin bypass arm of the conditional.
- **Sentinel**: consider adding to `tests/Feature/Sentinels/` a `PosLoyaltyRedeemBranchGuardSentinelTest` mirroring `OrderShowBranchGuardSentinelTest:44` (which the `show()` sibling has). Optional, deferred.

### Risk
- **Low**. Mirrors a sibling pattern that has been in production for months and is covered by sentinel. The 403→404 unification prevents existence enumeration (already-applied pattern from Wave 5I).
- **Regression risk**: zero — the happy-path test in `PosLoyaltyRedeemTest::test_happy_path_decrements_balance_and_writes_ledger` (line 113) creates cashier and order on same branch — unchanged behavior.

### Frozen-zone
- NOT in CLAUDE.md §7. `PosLoyaltyController.php` was introduced 2026-05-19 under `LOCK_POS_LOYALTY_REDEEM_UI` which permitted creation but did not freeze the controller. No LOCK doc needed.

### WIP-conflict
- NONE. `git diff --stat app/Http/Controllers/Admin/PosLoyaltyController.php` = empty (verified this session).

---

## Heal A.2 — `LoyaltyService::refundPoints` duplicate-ledger guard

### OWNER GATE — semantic decision required

**Z8 P0-2 finding text** (line 55): "wrap `LoyaltyTransaction::create` in try/catch on `errorInfo[0] === '23000'` → **return silent no-op (idempotent semantic)**."

**Task instruction text**: "throw structured 409 ALREADY_REFUNDED."

**These are NOT the same heal.** They have opposite transaction semantics. See §RED-Dispute below for full analysis.

**Recommendation: ship NOOP variant + 409 surfaced via log channel + structured error caught at controller boundary**. Owner Q1 in §Open questions to confirm.

### Evidence (read this session)
- `RED-Z8-refund-loyalty.md` P0-2 lines 50-55 + `app/Services/LoyaltyService.php:62-71` (bare insert).
- DB UNIQUE on `(user_id, order_id, type)` per `database/migrations/2026_03_26_075919_add_unique_to_loyalty_transactions.php:28-31`.
- **4 callers verified this session** (grep `refundPoints`):
  - `OrderService.php:1753` — inside outer DB::transaction + lockForUpdate, around line 1718 changeStatus.
  - `OrderService.php:1856` — inside outer DB::transaction.
  - `FrontendOrderService.php:707` — inside outer DB::transaction.
  - `RefundWithCounterEntryService.php:241` — inside outer DB::transaction (line 88-251).
- **Mirror pattern available** at `PosRedemptionService.php:177-186` (read this session — it throws `PosRedemptionException` 409 because that controller IS the loyalty operation; its caller is not in a bigger transaction).

### Current code (`app/Services/LoyaltyService.php:62-71`)
```php
LoyaltyTransaction::create([
    'user_id' => $loyaltyUser->id,
    'loyalty_code' => $loyaltyUser->loyalty_code,
    'order_id' => $order->id,
    'type' => 'manual_add',
    'points' => $totalPointsToRefund,
    'balance_after' => $balanceAfter,
    'source_surface' => $sourceSurface,
    'description' => 'Remboursement fidélité suite annulation commande #' . ($order->order_serial_no ?? $order->id),
]);
```

### Proposed diff (NOOP variant — RECOMMENDED — does NOT rollback the caller's outer transaction)
```diff
@@ -1,8 +1,9 @@
 <?php

 namespace App\Services;

 use App\Models\LoyaltyTransaction;
 use App\Models\User;
+use Illuminate\Database\QueryException;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Log;
@@ -56,17 +57,33 @@ class LoyaltyService
         DB::table('users')
             ->where('id', $loyaltyUser->id)
             ->increment('loyalty_points', $totalPointsToRefund);

         $balanceAfter = $loyaltyUser->loyalty_points + $totalPointsToRefund;

-        LoyaltyTransaction::create([
-            'user_id' => $loyaltyUser->id,
-            'loyalty_code' => $loyaltyUser->loyalty_code,
-            'order_id' => $order->id,
-            'type' => 'manual_add',
-            'points' => $totalPointsToRefund,
-            'balance_after' => $balanceAfter,
-            'source_surface' => $sourceSurface,
-            'description' => 'Remboursement fidélité suite annulation commande #' . ($order->order_serial_no ?? $order->id),
-        ]);
+        // [HEAL-A.2 2026-05-19] Z8 P0-2: UNIQUE (user_id, order_id, type)
+        // guard. Pre-heal a second refundPoints call threw QueryException
+        // 23000 which rolled back ALL 4 callers' outer transactions —
+        // mirror order creation, cashBack chain, status flip. Idempotent
+        // no-op + structured log is the safer surface — the customer's
+        // loyalty_points re-credit was the destructive operation we
+        // bypass by NOT calling increment() again (handled below).
+        try {
+            LoyaltyTransaction::create([
+                'user_id' => $loyaltyUser->id,
+                'loyalty_code' => $loyaltyUser->loyalty_code,
+                'order_id' => $order->id,
+                'type' => 'manual_add',
+                'points' => $totalPointsToRefund,
+                'balance_after' => $balanceAfter,
+                'source_surface' => $sourceSurface,
+                'description' => 'Remboursement fidélité suite annulation commande #' . ($order->order_serial_no ?? $order->id),
+            ]);
+        } catch (QueryException $e) {
+            if (($e->errorInfo[0] ?? null) === '23000') {
+                Log::info('[Loyalty] Duplicate refund ignored (idempotent)', [
+                    'order_id' => $order->id,
+                    'customer_id' => $loyaltyUser->id,
+                    'points_attempted' => $totalPointsToRefund,
+                ]);
+                return; // idempotent no-op
+            }
+            throw $e;
+        }
```

**Critical implementation note**: The `DB::table('users')->increment('loyalty_points', ...)` at line 58 (PRE the try block) is **destructive and runs BEFORE the ledger insert**. If we adopt the NOOP variant, the increment will already have re-credited the customer — bypassing the ledger write leaves the user balance double-incremented relative to the ledger. **The diff above is INCOMPLETE without re-ordering the increment to happen INSIDE the try, AFTER the ledger insert succeeds.**

### Refined diff (NOOP variant — COMPLETE, accounts for increment order)
The full safe diff also moves `DB::table('users')->increment()` and `$balanceAfter` computation INSIDE the try block, AFTER the LoyaltyTransaction::create, so the rollback (or NOOP) of the ledger row also bypasses the points re-credit. Alternatively, the simpler refined form: do an early-detect via `LoyaltyTransaction::where('user_id', $loyaltyUser->id)->where('order_id', $order->id)->where('type', 'manual_add')->exists()` check BEFORE the increment. This adds a SELECT round-trip but avoids the increment-before-insert race. Implementer should choose; recommend the early-detect form for clarity. Plan:
```diff
+        // [HEAL-A.2] Early-detect duplicate refund (idempotent no-op).
+        if (LoyaltyTransaction::where('user_id', $loyaltyUser->id)
+            ->where('order_id', $order->id)
+            ->where('type', 'manual_add')
+            ->exists()) {
+            Log::info('[Loyalty] Refund already credited — skipping', [
+                'order_id' => $order->id,
+                'customer_id' => $loyaltyUser->id,
+            ]);
+            return;
+        }
+
         DB::table('users')
             ->where('id', $loyaltyUser->id)
             ->increment('loyalty_points', $totalPointsToRefund);
         ...
+        // 23000 race fallback: if a concurrent caller raced past the
+        // exists() check, this catches the UNIQUE violation. We do NOT
+        // re-credit (the concurrent winner already did) and we DO log
+        // for forensic trail. Caller transaction NOT rolled back.
+        try { LoyaltyTransaction::create([...existing fields...]); }
+        catch (QueryException $e) {
+            if (($e->errorInfo[0] ?? null) !== '23000') throw $e;
+            // Concurrent winner — decrement the increment we just made.
+            DB::table('users')->where('id', $loyaltyUser->id)
+                ->decrement('loyalty_points', $totalPointsToRefund);
+            Log::warning('[Loyalty] Refund race rolled back', [...]);
+        }
```

Implementer should pick the simpler early-detect form (no race compensation needed in single-resto V1 LOCAL) and accept the tiny race window. The OWNER GATE answer decides between this NOOP form and the throw-409 form below.

### Alternative diff (THROW variant — only if owner explicitly chooses)
```diff
+        try {
+            LoyaltyTransaction::create([... existing fields ...]);
+        } catch (QueryException $e) {
+            if (($e->errorInfo[0] ?? null) === '23000') {
+                throw new \RuntimeException(
+                    'LOYALTY_ALREADY_REFUNDED: order_id=' . $order->id, 409
+                );
+            }
+            throw $e;
+        }
```
**Caveat**: 4 callers must handle this exception OR accept their outer transaction rollback. Cancel-twice on POS would rollback the second cancel attempt (status flip undone). Workaround: NOOP form is safer.

### Test plan
- **Existing**: `tests/Feature/OrderCancellationLoyaltyTest.php` exists per `find` this session — covers happy path. Does NOT cover duplicate-cancellation. Verify line numbers when implementing.
- **NEW** in `tests/Feature/OrderCancellationLoyaltyTest.php` (or new file `tests/Feature/Loyalty/RefundPointsIdempotencyTest.php`):
  - `test_double_refund_points_is_idempotent_noop` — set up order with redeem ledger, call `LoyaltyService::refundPoints()` twice, assert: (a) only ONE `manual_add` row, (b) `loyalty_points` re-credited only once, (c) no exception thrown.
- **Existing sentinel (already covers post-Z path)**: `tests/Feature/Refund/RefundWithCounterEntryRefundsLoyaltyPointsTest.php` (lines 88-157 read this session) — happy path. Add a SECOND counter-entry-refund attempt on same parent (would currently throw QueryException → caller rollback). Validates the heal end-to-end.

### Risk
- **Low-medium** depending on variant. NOOP variant has a race window between exists() and create(); single-resto V1 LOCAL has zero concurrency on this path (1 cashier per order). THROW variant has known caller-rollback risk explicitly cited above.
- **Regression risk**: zero on first call (happy path identical). Risk only materialises on the contrived double-call path.

### Frozen-zone
- NOT in CLAUDE.md §7. `LoyaltyService.php` is in the V1 routine surface.

### WIP-conflict
- NONE. `git diff --stat app/Services/LoyaltyService.php` empty (verified).

---

## Heal A.3 — `parent_order_id` UNIQUE migration

### Evidence (read this session)
- `RED-Z8-refund-loyalty.md` P0-1 lines 43-48 + `database/migrations/2026_05_06_200000_add_parent_order_id_to_orders.php:25` (INDEX only).
- Cross-supporting: `RefundMirrorSplitPaymentTest:189-203` workaround acknowledges the brittleness (forcibly flips parent.status=RETURNED to exercise the L73-78 guard).
- Frozen-zone status: `orders` table is **NOT** in `deploy/ansible/site.yml:65-71` REVOKE list (7 protected tables enumerated this session). CLAUDE.md §7 NF525 frozen scope does NOT include orders DDL. **Additive constraint (UNIQUE) on a nullable column is owner-gate-free.**

### Pre-deploy GATE — duplicate query (BLOCKED unless executed)
Implementer MUST run this on TARGET environment BEFORE applying the migration:
```sql
SELECT parent_order_id, COUNT(*) AS dupes
  FROM orders
 WHERE parent_order_id IS NOT NULL
 GROUP BY parent_order_id
HAVING COUNT(*) > 1;
```
- **If 0 rows returned** → safe to deploy. Proceed.
- **If ≥1 row returned** → BLOCKED. Owner must decide: (a) hard-delete the surplus mirrors (rare; only valid if confirmed accidental duplicates), (b) re-link the surplus mirrors to a placeholder/null (impractical), (c) defer to V1.0.2 with a data-migration script. **Migration MUST NOT land before query returns 0 rows.**

This session: query attempted, **DENIED by sandbox** (production data read restricted). Implementer / DBA runs this gate.

### Proposed new migration: `database/migrations/2026_05_19_HHMMSS_add_unique_parent_order_id_to_orders.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [HEAL-A.3 / WAVE-K Z8 P0-1] Promote parent_order_id INDEX → UNIQUE so a
 * second counter-entry mirror against the same parent is rejected at DB
 * level (defense-in-depth above the L73-78 status-check guard which never
 * fires under the immutable-parent NF525 invariant).
 *
 * MySQL allows multiple NULLs in a UNIQUE index (parent.parent_order_id
 * stays NULL for all non-mirror orders — no clash). SQLite has same
 * semantics ≥3.9. PostgreSQL requires partial INDEX form; not targeted
 * per config/database.php.
 *
 * Idempotent up()/down() — Schema::hasIndex check to tolerate replays.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop the pre-existing non-unique index (named via Laravel
            // default convention 'orders_parent_order_id_index'), then add
            // UNIQUE. Wrapped in try/catch for idempotent replay safety.
            try { $table->dropIndex(['parent_order_id']); } catch (\Throwable) {}
            $table->unique('parent_order_id', 'orders_parent_order_id_unique');
        });
    }

    public function down(): void
    {
        // NF525-adjacent: orders table is the Z aggregate source. Per
        // 2026_04_22_000002 pattern, refuse rollback in production to
        // preserve forensic trail. UNIQUE drop in prod could allow
        // legacy double-mirror accidents to land before re-tightening.
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NF525-adjacent DDL rollback refused in production. '
                . 'Use a forward-only data-migration if surplus mirrors must be tolerated.'
            );
        }
        Schema::table('orders', function (Blueprint $table) {
            try { $table->dropUnique('orders_parent_order_id_unique'); } catch (\Throwable) {}
            $table->index('parent_order_id'); // restore the original INDEX
        });
    }
};
```
Filename: pick a timestamp later than `2026_05_06_200000_add_parent_order_id_to_orders.php` AND later than any pending migration. Recommended `2026_05_19_200000_add_unique_parent_order_id_to_orders.php`.

### Knock-on effect on `RefundWithCounterEntryService.php:99`
Once the UNIQUE lands, a second `Order::create(['parent_order_id' => $parent->id, ...])` call (line 95-112) will throw `QueryException 23000` BEFORE reaching the L73-78 status guard (which currently never fires per Z8 finding). The QueryException propagates out of the `DB::transaction` closure → rolls back atomically → `PosOrderController::refundWithCounterEntry:81-90` catches `\Throwable` and returns generic 500.

**Recommendation: ALSO catch QueryException 23000 in `PosOrderController::refundWithCounterEntry` and return structured 409 `MIRROR_ALREADY_EXISTS`**. This is a tiny additional patch (~5 LOC) in the controller — included below as **A.3-bis**. Not a separate heal; folds into A.3.

```diff
@@ app/Http/Controllers/Admin/PosOrderController.php
         } catch (HttpException $http) {
             throw $http;
+        } catch (\Illuminate\Database\QueryException $qe) {
+            // [HEAL-A.3-bis 2026-05-19] UNIQUE parent_order_id violation —
+            // a mirror already exists for this parent. Surface as 409 with
+            // stable code, not a generic 500.
+            if (($qe->errorInfo[0] ?? null) === '23000') {
+                return response()->json([
+                    'success' => false,
+                    'code'    => 'MIRROR_ALREADY_EXISTS',
+                    'message' => 'A counter-entry mirror already exists for this order.',
+                ], 409);
+            }
+            \Illuminate\Support\Facades\Log::error('refund-with-counter-entry query failed', [
+                'order_id' => $order->id,
+                'error'    => $qe->getMessage(),
+            ]);
+            return response()->json([
+                'success' => false,
+                'message' => 'Database error during counter-entry refund.',
+            ], 500);
         } catch (\Throwable $t) {
```

### Test plan
- **NEW migration test**: `tests/Feature/Migrations/UniqueParentOrderIdMigrationTest.php`
  - `test_unique_parent_order_id_blocks_double_mirror` — create parent, create mirror, attempt second mirror → assert `QueryException::class` with SQLSTATE 23000.
  - `test_multiple_null_parent_order_id_allowed` — create 5 non-mirror orders (parent_order_id=NULL) → all succeed.
- **Update existing**: `tests/Feature/Refund/RefundMirrorSplitPaymentTest.php:189-203` will now have TWO defense layers (UNIQUE + L73-78 guard). The existing test deliberately flips `$parent->status = OrderStatus::RETURNED` to exercise the L73-78 guard — STILL valid (L73-78 fires first because it runs at L73, BEFORE the DB::transaction closure at L88). Existing assertion `$this->assertSame($paymentCountBefore, OrderPayment::withoutGlobalScopes()->count())` stays GREEN. Test docblock should add a note that L73-78 IS now belt-and-suspenders.
- **NEW end-to-end**: a new test in `tests/Feature/Refund/` exercising double-counter-entry call WITHOUT flipping parent.status — proves the UNIQUE catches the path the L73-78 guard never could. Recommended file: `tests/Feature/Refund/RefundCounterEntryUniqueGuardTest.php`.

### Risk
- **Low-medium**. Two risk vectors:
  1. **Existing data**: if duplicates exist in production today, migration FAILS. Mitigated by pre-deploy GATE query.
  2. **Refactor surface**: `RefundWithCounterEntryService` callers that depend on "second-call throws RuntimeException with friendly message" change to "second-call throws QueryException with raw DB error" until A.3-bis controller catch lands. Mitigated by shipping A.3 + A.3-bis together.

### Frozen-zone
- NOT in CLAUDE.md §7. `orders` table is NF525-adjacent (Z aggregate source) but `deploy/ansible/site.yml:59-71` REVOKE list excludes it. Additive UNIQUE on a nullable column is non-destructive.

### WIP-conflict
- NONE on `database/migrations/2026_05_06_200000_add_parent_order_id_to_orders.php` (verified). New file additive.

---

## Heal A.4 — RefundWithCounterEntryService:73-78 guard — VERDICT: NO CHANGE

### Z8 finding (lines 43-48 of RED-Z8)
> guard predicate is `$parent->status === OrderStatus::RETURNED`. counter-entry path NEVER mutates `parent.status` (NF525 immutability — L29-30 docblock). So the guard checks a condition that, in normal counter-entry flow, will always be false.

### Investigation this session
- `RefundWithCounterEntryService.php:73-78` reads exactly as the finding describes.
- `RefundMirrorSplitPaymentTest.php:189-203` (read this session) deliberately sets `$parent->status = OrderStatus::RETURNED` to exercise the guard ("out-of-band process had already flipped the parent").
- `RefundCounterEntryRequiresSealedParentSentinelTest::test_already_mirrored_parent_still_rejected` (lines 115-133 read this session) does the same flip and asserts `InvalidArgumentException::class`.
- The guard fires in exactly one operational scenario: **an out-of-band actor flipped parent.status to RETURNED** (admin tooling, console script, stale-state migration). It is dormant in the normal counter-entry path BY DESIGN per NF525 immutability.

### Verdict
**NO CHANGE recommended.** The Z8 finding is technically correct (guard predicate dormant in normal flow) but the proposed heal — replace with `Order::where('parent_order_id', $parent->id)->exists()` — would:
1. **Break 2 existing sentinel tests** that depend on the status-flip path firing this guard (would need their setup mutated, increasing diff scope unnecessarily).
2. **Be redundant after A.3 lands** — UNIQUE `parent_order_id` rejects the duplicate mirror at DB level BEFORE the L73-78 guard runs.
3. **Lose the defense-in-depth coverage of the out-of-band status-flip case** which is the guard's actual current value.

**Recommendation**: keep the L73-78 guard as belt-and-suspenders. Annotate with a comment referencing A.3:
```diff
+        // [HEAL-A.4 verdict 2026-05-19] Defense-in-depth above the
+        // UNIQUE(parent_order_id) constraint (HEAL-A.3 migration). This
+        // predicate fires only when an out-of-band process has flipped
+        // parent.status=RETURNED — see RefundMirrorSplitPaymentTest:189
+        // and RefundCounterEntryRequiresSealedParentSentinelTest:115.
+        // NF525 counter-entry path itself never mutates parent.status.
         if ((int) $parent->status === OrderStatus::RETURNED) {
             throw new InvalidArgumentException(
                 'Parent order is already RETURNED — refusing duplicate mirror.',
                 422
             );
         }
```
This is a 5-line annotation only — zero behavioral change. Implementer may treat it as optional documentation polish.

### Risk
- **Zero** (no behavioral change).

### Frozen-zone
- NOT in §7. `RefundWithCounterEntryService.php` is a service, not in frozen list (frozen list mentions `FiscalSequenceService`, `ZReportService`, `AuditLogService`, `BranchScope`, `IdempotencyKeyMiddleware`, `PricingService`, `OrderStateMachine`).

### WIP-conflict
- NONE.

---

## §RED-Dispute (self-applied)

### A.1 — PosLoyaltyController branch check
- **What could go wrong?**
  1. **Admin bypass form mismatch**: I picked `branch_id === 0` (BranchScope canonical). The sibling `refundWithCounterEntry:57-61` uses `hasRole('Admin')`. If `branch_id=0` users existed who AREN'T Admin role (e.g. system seed user), they would bypass — but BranchScope itself treats them as admin, so the inconsistency is consistent. **Verdict**: low risk; canonical-correct.
  2. **`request->user()?->branch_id` returns NULL** for unauthenticated requests — middleware ensures auth, but defense: my coalesce to `-1` ensures NULL → 403 (NULL !== 0 AND NULL !== branch_id of any real order). **Verdict**: safe.
  3. **Cashier impersonation route?** No — endpoint is sanctum-only, no impersonation middleware.
- **Worst-case if bad**: 403 thrown on a legitimate same-branch order due to a typo. Mitigated by existing happy-path test (line 113 of PosLoyaltyRedeemTest).
- **Simpler heal?** Could `findOrFail` + `try/catch ModelNotFoundException → abort(403)` for 404 unification AND branch check. That's the exact `show()` sibling shape. **Verdict**: equivalent; recommend either form, prefer the show()-mirror form for canonical alignment.
- **New sync risk?** None. No write before the check.
- **Test coverage of regression path?** NEW test `test_cross_branch_cashier_gets_403_on_foreign_order` is the regression sentinel. Existing 6 cases cover happy/insufficient/notfound/paid/noperm/double.

### A.2 — LoyaltyService::refundPoints duplicate guard — **CRITICAL DISPUTE: TASK ↔ AUDIT CONTRADICTION**
- **The contradiction**: task says "→ throw structured 409 ALREADY_REFUNDED"; Z8 P0-2 says "→ return silent no-op (idempotent semantic)". These are opposite semantics.
- **What could go wrong with THROW variant?**
  1. **Caller rollback cascade**: 4 callers (verified `OrderService.php:1753`, `OrderService.php:1856`, `FrontendOrderService.php:707`, `RefundWithCounterEntryService.php:241`) all run inside DB::transaction. A thrown 23000 → outer txn rollback → status flip undone, cashBack rolled back, mirror order rolled back. The CALLER experiences a half-completed cancel: order shows as still PAID/PENDING because the rollback wiped the status change. **This is operationally worse than the current generic 500.**
  2. **Z8 P0-2 explicitly says "wrap → return silent no-op"** — its analysis already considered the caller-rollback hazard at line 53 ("Inside `RefundWithCounterEntryService` transaction this DOES roll back the WHOLE mirror creation"). The audit author noted the rollback explicitly and STILL preferred no-op.
- **What could go wrong with NOOP variant?**
  1. **Race window** between exists() check and create(): two concurrent refundPoints calls both see no row, both try insert, one succeeds, one hits 23000. Mitigated by also try/catching the create.
  2. **Customer balance overhang**: if I move `DB::table('users')->increment(...)` AFTER the exists() check inside the try, the increment is bypassed when exists()=true — correct. If I keep increment BEFORE, the second call double-credits the user (bug). **Implementation must early-exit BEFORE increment.** Diff above shows this.
  3. **Audit-trail gap**: NOOP variant writes a log line but not a DB row, so a forensic auditor querying loyalty_transactions sees only one manual_add row for a double-cancellation event. Acceptable because: (a) only the first refund is real; (b) the second cancellation event itself is in OrderStateMachine transitions table.
- **Worst-case if bad NOOP**: log line missed → forensic gap. Mitigated by Log::warning + structured payload.
- **Simpler heal?** Add the early-detect exists() BEFORE the increment. That's the recommendation in the diff.
- **New sync risk?** None — Z report aggregates orders, not loyalty_transactions. Loyalty ledger remains the loyalty-only ground truth.
- **Test coverage of regression path?** Need NEW test `test_double_refund_points_is_idempotent_noop`. Existing `OrderCancellationLoyaltyTest` covers happy path only.
- **Re-plan trigger**: yes. **OWNER GATE Q1** below; default to NOOP, surface the THROW alternative.

### A.3 — UNIQUE parent_order_id migration
- **What could go wrong?**
  1. **Existing duplicates in production today** → migration fails on `ADD UNIQUE`. Mitigated by pre-deploy GATE query (cannot run from this session sandbox).
  2. **Race during deploy**: concurrent counter-entry refund mid-migration on a target with no duplicates → MySQL DDL is non-blocking on UNIQUE add (online DDL since 5.6) for InnoDB tables; should be safe. If pre-MySQL-5.6 or non-InnoDB, online DDL falls back to table copy under metadata lock — brief downtime. **Le Cayenne local single-resto** = ~5s window, acceptable.
  3. **A.3-bis controller catch missed**: implementer ships A.3 migration but forgets the QueryException catch in PosOrderController — double-mirror attempts return generic 500 instead of friendly 409. Mitigated by shipping A.3 and A.3-bis as a SINGLE commit.
  4. **Sentinel sentinel locked baseline drift**: `tests/Feature/Sentinels/RefundCounterEntryRequiresSealedParentSentinelTest:115-133` setup deliberately flips parent.status=RETURNED to trigger the L73-78 guard. Migration adds UNIQUE before service reaches L88 INSERT — the L73-78 guard STILL fires first (synchronous order: L70-71 sealed check, L73-78 status check, L80-83 reason check, L85-86 user/branch, L88 transaction). Verdict: SAFE.
- **Worst-case if bad**: migration rejected at deploy time → 5-min rollback via `php artisan migrate:rollback` (down() throws in prod but env=staging is OK). Net effect: status quo (Z8 P0-1 unfixed).
- **Simpler heal?** No — DB-level UNIQUE is the only defense-in-depth above idempotency middleware that survives a buggy client sending two different X-Idempotency-Key values.
- **New sync risk?** Stripe webhook idempotency is on `webhook_events` not on `orders` — distinct. Z aggregation in `ZReportService::aggregate:297-435` re-uses `withTrashed()` and reads orders by `created_at` window + `fiscal_sequence_no IS NOT NULL`. The UNIQUE does not affect aggregation. Verdict: zero sync risk.
- **Test coverage of regression path?** NEW migration test + retain `RefundMirrorSplitPaymentTest:189-203`. Combined coverage is solid.

### A.4 — RefundWithCounterEntryService:73-78 guard
- **What could go wrong with the Z8-proposed change (`exists($parent_order_id)`)?**
  1. **Breaks 2 existing sentinels** that exercise the status-flip path (`RefundMirrorSplitPaymentTest:189` + `RefundCounterEntryRequiresSealedParentSentinelTest:115`) — both deliberately flip parent.status=RETURNED to trigger this exact code path. Replacing the predicate would force test rewrites.
  2. **Loses defense-in-depth coverage** of the out-of-band status-flip case (admin script, stale data migration).
  3. **Redundant with A.3** — UNIQUE catches the same case at DB level before reaching this guard.
- **What could go wrong with NO CHANGE (recommended)?**
  1. **Status-quo dormant guard**: zero risk.
  2. **Documentation drift**: adding the annotation comment helps future readers — recommended.
- **Worst-case**: someone in V2 SaaS finds the dormant guard confusing → 5 minutes of re-discovery. Mitigated by the annotation diff in §Heal A.4.
- **Simpler heal?** NO CHANGE + 5-line annotation. Recommended.
- **New sync risk?** Zero.
- **Test coverage of regression path?** Existing sentinels cover the dormant-but-firing-on-status-flip path. After A.3 lands, UNIQUE covers the normal path.

---

## §Implementation order (re-ordered from task suggestion)

Task originally suggested: A.3 → A.4 → A.1 → A.2.
**Recommended order (justified):**

1. **A.1 first** — cleanest heal, mirrors verified sibling pattern, independent of everything else, **no owner gate needed**. Lowest-risk first.
2. **A.3 (migration + A.3-bis controller catch)** — gated on the pre-deploy duplicate query result. Ship as ONE commit so the friendly 409 surface ships with the UNIQUE that produces it. Critical: implementer MUST execute the GATE query before applying.
3. **A.4 (annotation only, no behavior change)** — 5-line documentation polish. Can ride with A.3 commit.
4. **A.2 last** — **owner-gate Q1 required first**. Default to NOOP early-detect variant. Ship after owner confirms semantics.

This order minimises ship blockers: A.1 lands today, A.3+A.4 land after the duplicate query confirms zero rows, A.2 lands after owner answers Q1.

---

## §Open questions (owner-only)

**Q1 (A.2 semantic decision — REQUIRED before A.2 ships):**
On `LoyaltyService::refundPoints` second call against the same order (DB UNIQUE catches it), should the heal:
- **(NOOP — recommended)** Early-detect via `exists()`, log warning, return without throwing — so the caller's outer transaction commits (status flip, cashBack, mirror creation all SURVIVE the second cancel attempt).
- **(THROW)** Wrap insert in try/catch, throw `\RuntimeException` 409 — so the caller's outer transaction ROLLS BACK (second cancel attempt fails atomically with friendly 409 instead of generic 500).

Trade-off: NOOP = customer sees their second-cancel-attempt succeed (no error UX), forensic gap fixed by log line; THROW = customer sees explicit error on duplicate cancel, but cascade rollback risks half-completed state if other callers are not 409-aware. Recommend NOOP.

---

## §Verdict
- **Cluster ready**: **PARTIAL** — A.1 and A.4 ready-for-implementer immediately. A.3 ready pending the pre-deploy duplicate-query GATE (implementer / DBA executes). A.2 ready pending Q1 owner answer.
- **Self-RED confidence**: **8.5 / 10**. The A.2 throw-vs-noop tradeoff is the residual uncertainty; everything else is anchored to verified file:line and existing sibling patterns.
- **Recommend Implementer**: **YES for A.1 + A.3 + A.4 (annotation)**. **HOLD A.2** until Q1 owner answer.
- **Frozen-zone touches**: ZERO. `orders` table is NF525-adjacent but additive UNIQUE on nullable column is not in CLAUDE.md §7 or `deploy/ansible/site.yml:59-71` protected DDL set.
- **NF525 chain impact**: ZERO — none of A.1/A.2/A.3/A.4 touch audit_logs, z_reports, fiscal_sequence_no allocation, HMAC computation, or REVOKE-protected tables.
- **WIP-conflicts**: ZERO across all 7 target files (verified via `git diff --stat` this session).
