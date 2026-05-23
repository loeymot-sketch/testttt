# PROPOSAL — OrderStateMachine::apply() unused status reality check (B3-ARCH-002)

**ID** : PROP-OSM-001
**Cross-reference** : B3-ARCH-002 (B3.1 Architect)
**Date** : 2026-05-23
**Phase** : B.5 — PROPOSAL AGENT for `app/Domain/Order/OrderStateMachine.php`
**Frozen file** : `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7 — backend
business-critical, "FROZEN §7 transitions controlled")
**Severity** : P2 — architectural drift risk, **NOT** dead code
**Touch** : ZERO (read-only audit, proposal only)

---

## 1. B3.1 Architect claim under review

B3.1 Architect noted (B3-ARCH-002 P2) :

> `apply()` method defined but unused — callsites still use legacy `recordTransition()`
> pattern (B3-ARCH-002 P2).

This proposal **refines the claim**. The accurate finding is :

> `apply()` is **not dead code**. It has **one production callsite** (`CleanupStalePendingKioskOrders`
> Job, line 60-65) and is the **declared "preferred entry point for NEW code"** per its
> own docblock (OrderStateMachine.php :169-171). However, the 14 historical callsites in
> `OrderService` / `FrontendOrderService` / `KitchenDisplaySystemOrderService` /
> `PaymentService` continue to use the legacy `$order->status = X; ->save();
> recordTransition(...)` pattern — including **three new callsites added in Wave S-1
> (2026-05-20)** for the AutoPrepareOnPaidPolicy. The "frozen-zone V1 rule" claim in
> the docblock is therefore being interpreted as "all existing AND adjacent NEW code in
> the same service classes inherits the legacy pattern" — which means `apply()` will
> remain effectively single-callsite indefinitely without an explicit migration plan.

The B3-ARCH-002 P2 verdict ("defined but unused") is **factually incorrect** ;
the correct framing is **"defined but disproportionately under-used vs documented
preferred path, with a creeping risk of subtle pattern divergence"**.

---

## 2. Verified callsite map (read-only count)

`apply()` callsites in production code (excluding tests, comments, e2e specs) :

| File | Line | Context |
|------|------|---------|
| `app/Jobs/CleanupStalePendingKioskOrders.php` | 60 | Auto-reject stale PENDING kiosk orders after 15 min (cron) |

**Total `apply()` production callsites : 1.**

`recordTransition()` direct callsites in production code (legacy pattern,
manual save then audit row) :

| File | Line | Context |
|------|------|---------|
| `app/Services/OrderService.php` | 714 | POS direct-sale auto-prepare (Wave S-1, 2026-05-20) |
| `app/Services/OrderService.php` | 1721 | `deliveryBoyOrderChangeStatus` driver flow |
| `app/Services/OrderService.php` | 1833 | Customer self-cancel path |
| `app/Services/OrderService.php` | 1939 | Admin / staff `changeStatus` |
| `app/Services/FrontendOrderService.php` | 600 | Kiosk auto-accept on create |
| `app/Services/FrontendOrderService.php` | 740 | Kiosk customer self-cancel |
| `app/Services/FrontendOrderService.php` | 1249 | `finalizePaidKioskOrder` auto-prepare (Wave S-1) |
| `app/Services/FrontendOrderService.php` | 1326 | `finalizePaidKioskOrder` ACCEPT promotion |
| `app/Services/KitchenDisplaySystemOrderService.php` | 299 | KDS chef bump-board mutation |
| `app/Services/PaymentService.php` | 286 | Counter-collect auto-prepare (Wave S-1) |
| `app/Services/PaymentService.php` | 537 | Counter payment cancel |

**Total `recordTransition()` production callsites : 11.**

Tests + e2e specs are excluded from these counts ; `apply()` is well-covered
there (`tests/Feature/Domain/OrderStateMachineApplyTest.php` 6 cases +
`tests/Feature/OrderStateMachineLockForUpdateTest.php` 4 cases).

---

## 3. Why apply() is NOT dead code

Two independent reasons :

### 3.1 The job callsite is load-bearing

`CleanupStalePendingKioskOrders::handle()` (line 60) :

```php
OrderStateMachine::apply(
    $locked,
    OrderStatus::REJECTED,
    null,
    'Auto-rejected stale pending kiosk order after 15 minutes.'
);
```

This is a real production path executed every cron cycle. It exists precisely
*because* the job runs without an authenticated user (`$actor=null`) and needs
the guard `requiresReason()` check, the lockForUpdate inside the transaction,
and the idempotent early-return — all of which legacy `recordTransition()`
callsites must hand-roll. The job's choice of `apply()` IS the test of the
"preferred entry point for NEW code" promise.

### 3.2 The contract is asserted by sentinel-grade tests

`OrderStateMachineLockForUpdateTest` (iter15 P0-12 LOCKFORUPDATE 2026-05-10)
performs **source-code regex assertions** that would scream loudly if `apply()`
were ever removed or unwrapped from its DB::transaction + lockForUpdate
discipline. The test exists because the fix matters.

---

## 4. The real architectural drift risk

Each of the 11 legacy callsites **re-implements** the apply() invariants by hand :

- 9 of 11 wrap mutate-path in `DB::transaction` ✅ (legacy callsites 2 + 3 may not — see below)
- 8 of 11 acquire `lockForUpdate` ✅
- 7 of 11 implement idempotent early-return when `$from === $next` ✅
- 5 of 11 explicitly check `OrderStateMachine::allows()` before mutating ✅
- 11 of 11 call `recordTransition()` after `->save()` ✅

The 3 Wave S-1 callsites added 2026-05-20 (`OrderService:714`, `PaymentService:286`,
`FrontendOrderService:1249`) **inherit the legacy pattern even though they are
NEW code written AFTER the docblock declared `apply()` to be the preferred path**.
This is the precise opposite of what the docblock intended.

If a 12th, 13th, 14th NEW callsite continues the legacy pattern, the divergence
compounds : a future race-condition fix or audit-row schema change made *only* in
`apply()` would silently miss the 11+ legacy paths, while the legacy paths' hand-
rolled invariants would slowly bit-rot away from the canonical `apply()` reference.

This is **not a P0 bug** — the legacy paths each have their own (well-commented)
lockForUpdate + idempotent guard. But it IS architectural debt that erodes the
"single source of truth" intent of the Domain layer.

---

## 5. Three resolution paths

### Option A — Accept reality, downgrade docblock language

Keep both paths. Rewrite the docblock to be honest :

```diff
- * This method is the preferred entry point for NEW code. Existing frozen-zone
- * call sites in OrderService / FrontendOrderService remain on the historical
- * pattern per the V1 frozen-zone rule.
+ * This method is the preferred entry point for INFRASTRUCTURE code (Jobs,
+ * Console commands, queue listeners) that lacks an HTTP request context.
+ * Service-layer callers in OrderService / FrontendOrderService /
+ * KitchenDisplaySystemOrderService / PaymentService continue to use the
+ * historical `$order->status = X; ->save(); recordTransition(...)` pattern
+ * because each of those callsites needs adjacent side-effects (payment_status
+ * flip, transaction creation, cashback dispatch, KDS dispatcher, AuditLogService
+ * write) that are not currently composable with apply()'s closed transaction
+ * boundary. See CleanupStalePendingKioskOrders::handle for the canonical
+ * apply() callsite.
```

**Pros:**
- Zero risk. Already the de-facto status quo.
- Frees future maintainers from a "should this be apply()?" cognitive tax.

**Cons:**
- Cements the divergence. Future invariant changes must be replicated 11+ times.
- Leaves the `isFillable('reason')` bug in apply() (see PROP-OSM-006) as a quietly-different semantic vs legacy paths.

### Option B — Migrate one legacy callsite as a probe (recommended)

Pick the **simplest** legacy callsite (criteria : single transition + no adjacent
side-effects + no fiscal seq + no payment flip) and migrate it to `apply()` ; observe
whether `apply()` needs widening to accommodate or whether the migration is clean.

The best candidate :
**`FrontendOrderService:600` — kiosk auto-accept on create (`PENDING → ACCEPT`)**.

```php
// Current pattern (lines 599-608)
if ($statusChangedAfterCreate) {
    OrderStateMachine::recordTransition(
        FrontendOrder::class,
        (int) $this->frontendOrder->id,
        OrderStatus::PENDING,
        OrderStatus::ACCEPT,
        null,
        null
    );
    $this->dispatchOrderStatusSignals($this->frontendOrder, OrderStatus::PENDING, OrderStatus::ACCEPT);
}

// Proposed (post-migration)
if ($statusChangedAfterCreate) {
    // The status was already mutated inside the closure (line 580); apply()
    // here would be a redundant re-lock + re-write. So either:
    //   (a) refactor the closure to call apply() instead of $order->status = X;
    //   (b) keep recordTransition for this specific create-path callsite where
    //       the mutation is part of the same DB::transaction as the order create.
}
```

Verdict : even this "simplest" callsite is **NOT cleanly migrable** without
restructuring the create transaction. Which is the actual finding : `apply()`
assumes the order already exists and is the SOLE mutation in the transaction.
Real callsites bundle status-change with payment_status flip, transaction row
creation, audit log writes, and KDS broadcast registration — all of which would
have to be moved either INSIDE or OUTSIDE `apply()`'s closed transaction.

### Option C — Replace `apply()` with a thinner helper

Acknowledge that the closed-transaction abstraction is too rigid for the service
layer. Refactor `apply()` to expose its three discrete operations as separate
helpers callable inside an existing transaction :

```php
final class OrderStateMachine {
    /** Asserts allows() + requiresReason(); throws IllegalTransitionException. */
    public static function assertTransition(Model $order, int $next, ?Authenticatable $actor, ?string $reason): void;

    /** Mutates status on a row that is ALREADY locked. Caller owns the transaction. */
    public static function applyToLocked(Model $locked, int $next, ?string $reason): void;

    /** Existing best-effort audit row writer (unchanged). */
    public static function recordTransition(...): void;
}
```

`apply()` becomes a convenience method that composes the three for callers that
don't need adjacent side-effects (i.e. only the cron job).

**Pros:**
- Service-layer callsites can adopt `assertTransition()` + `applyToLocked()`
  inside their existing transaction WITHOUT collapsing payment-flip / cashback
  side-effects.
- The `isFillable('reason')` bug (PROP-OSM-006) gets centralised in
  `applyToLocked()` and fixed once.
- `apply()` itself remains for infrastructure code.

**Cons:**
- Touches `OrderStateMachine.php` — frozen-zone gate required.
- Adds two new public methods to the SM API surface. More risk of API drift.
- Migration of 11 callsites is still a multi-week effort.

---

## 6. Recommendation

**Option A** (downgrade docblock language) — for V1.0.X.

The architectural debt is real but the operational risk is bounded : each legacy
callsite has been independently audited (iter13 / iter15 / Wave M / Wave S-1)
and carries its own lockForUpdate + idempotent-guard pattern. The cost of Option
B or C is high (frozen-zone touch, multi-callsite migration, regression risk)
relative to the benefit (cosmetic API convergence).

**Defer Option C to V1.0.2 / V2 SaaS multi-tenant**, when the additional pressure
of cross-tenant invariants will make a thinner shared abstraction more valuable.

---

## 7. LOCK feasibility (if Option A pursued)

- ≤10 LOC docblock-only change ? **YES**
- Architectural redesign ? **NO**
- Frozen file ? **YES** — `app/Domain/Order/OrderStateMachine.php` per CLAUDE.md §7
- Owner gate ? **REQUIRED** — frozen file even for docblock change ; produces a
  `LOCK_OSM_DOCBLOCK_<date>.md` doc with the diff + safety-check override.

---

## 8. Risk if NOT acted upon

| Risk | Likelihood | Impact |
|------|-----------|--------|
| 12th new callsite written in legacy pattern, deepening drift | HIGH (every Wave adds 1-3) | LOW — each callsite has its own lock |
| Invariant change made in `apply()` only, missing 11 paths | MEDIUM (when audit-row schema evolves) | HIGH — silent audit-row inconsistency |
| `apply()` regarded as dead code and silently removed | LOW (this proposal documents otherwise) | CRITICAL — cron job breaks immediately |
| Future B3.X audit re-discovers "B3-ARCH-002 still open" | HIGH | LOW — but tooling-bloat |

The principal threat is invariant drift between `apply()` and the 11 legacy
callsites. Documenting Option A in the docblock turns that from a silent risk
into an explicit acknowledged decision.

---

## 9. Companion findings

This proposal references and intentionally does NOT subsume :

- **PROP-OSM-002** — Identity transition skipping `requiresReason()` (analytic edge case)
- **PROP-OSM-003** — `apply()` re-query drops global-scope exemption (real bug)
- **PROP-OSM-004** — Admin terminal-reset to `PENDING` requires no reason (asymmetric audit)
- **PROP-OSM-005** — `recordTransition()` swallows exceptions silently (NF525-adjacent)
- **PROP-OSM-006** — `apply()`'s `isFillable('reason')` silently drops reason on model row

---

## 10. Owner sign-off

- [ ] APPLY-OPTION-A (downgrade docblock language ; LOCK doc required)
- [ ] APPLY-OPTION-B (migrate one probe callsite ; LOCK doc required)
- [ ] DEFER-V1.0.2 (Option C — thin-helper refactor)
- [x] **DEFER-V1.0.X backlog** (recommended : Option A as a `LOCK_OSM_DOCBLOCK` in a
       future doc-only wave ; status quo otherwise safe)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 11. References

- `app/Domain/Order/OrderStateMachine.php` :14-24, :179-254 (apply() definition + docblock)
- `app/Jobs/CleanupStalePendingKioskOrders.php` :60-65 (sole `apply()` callsite)
- `tests/Feature/Domain/OrderStateMachineApplyTest.php` (6 contract tests)
- `tests/Feature/OrderStateMachineLockForUpdateTest.php` (4 source-regex + behavioural tests)
- `CLAUDE.md` §7 Frozen Zones — backend file
- B3-ARCH-002 (B3.1 Architect input)
