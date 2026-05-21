# WAVE M — P5 Cross-Zone Interaction Audit

**Date**: 2026-05-19 · **Branch**: `heal/cms-pr1-quickwins-2026-05-18` · **Mode**: adversarial read-only audit, FIX-ready · **Commits shipped**: 0

## Executive

5 high-suspicion inter-zone interaction pairs were hunted between the seams that Wave K's single-lane agents could not see. **All 5 pairs VERIFIED-GREEN.** No commits shipped — the discipline here is "zero false heals", not "N commits". Two pre-existing V1.x backlog notes documented (data-drift observation + Z7 P1 scope-key collision known frozen).

The task framing ("FIX") pressured for code-shipping outputs. Wave L's catch #1 — a sentinel that encoded the defect itself — is the cautionary precedent: the right call when interactions are sound is to attest, not to manufacture a heal.

## Per-pair verdict

| # | Pair | Verdict | File:line evidence | Outcome |
|---|---|---|---|---|
| 1 | Z2 (Order lifecycle) × Z8 (Refund cascade) | **VERIFIED-GREEN** | EventServiceProvider:179-186 + PersistOrderPaymentStatusChangedOnRefundCreated:88-147 | Wave L D.3 already healed |
| 2 | Z3 (Outbox/Sync) × Z5 (NF525 fiscal) | **VERIFIED-GREEN** | OrderService:946-947 + 1075 / FrontendOrderService:1130-1212 / no JS consumer reads fiscal off outbox | No race in fact-pattern |
| 3 | Z6 (BranchScope) × Z7 (Idempotency) | **VERIFIED-GREEN** | IdempotencyKeyMiddleware:182-219 + PosLoyaltyController:36-56 | Wave L A.1 + branch-resolution handles admin path |
| 4 | Z1 (Stock cascade) × Z2 (Order lifecycle) | **VERIFIED-GREEN** | StockService:99-128 (DB UNIQUE `stock:sha1`) + AvailabilityService:313-325 (Cache::add SETNX) | Orthogonal stores + orthogonal idempotency mechanisms |
| 5 | Z4 (Pricing SSOT) × Z8 (Refund mirror) | **VERIFIED-GREEN** | RefundWithCounterEntryService:146 (byte-identical snapshot copy) | Builder NOT re-invoked on refund |

## Detail per pair

### Pair 1 — Z2×Z8 listener chain race (`RefundCreated`)

**Suspected interaction**: Wave L D.3 reordered listeners so `PersistOrderPaymentStatusChangedOnRefundCreated` runs FIRST. But could a throw in the new FIRST listener silently halt the downstream stock/availability release listeners and orphan stock counters?

**Verified**: `PersistOrderPaymentStatusChangedOnRefundCreated::handle` (lines 65-148) wraps every mutation site in nested try/catch envelopes:
- Lines 87-122: DB::transaction wrap around payment_status persist — try/catch absorbs any throw, logs, continues.
- Lines 128-147: OrderPaymentStatusChanged::dispatch wrap — try/catch absorbs any broadcaster throw.
- The handle method NEVER propagates a Throwable upward.

Laravel's `Illuminate\Events\Dispatcher::dispatch` halts on listener throw (vendor/.../Dispatcher.php:233-269), so the only way to silently re-open the WG-1 P1-1 broadcast hole is for the persist listener to throw. The envelope absorbs all throws → siblings (`ReleaseStockOnRefundCreated`, `ReleaseAvailabilityOnRefundCreated`) always run.

**Test coverage**: `tests/Feature/Refund/RefundListenerFailureIsolationTest.php` is the sentinel that binds a throwing `StockService::releaseForOrder` and asserts `OrderPaymentStatusChanged` still fires. Listener-order is the protected invariant.

**Cross-zone state correctness**: The persist listener's `DB::transaction(lockForUpdate)` (line 89-109) prevents a concurrent OrderStateMachine transition from interleaving — the parent's `payment_status` flip to REFUNDED is atomic and locked-row-based. FSM correctness preserved.

**Self-RED**: Could the lockForUpdate inside the listener deadlock with a concurrent `changeStatus` lockForUpdate elsewhere?
- The refund flow's outer transaction (cashBack at PaymentService:127 or refundWithCounterEntry at RefundWithCounterEntryService:98) calls `RefundCreated::dispatch` inside the closure. DispatchableAfterCommit defers listener execution to after the OUTER tx commits → the listener's lockForUpdate runs in its OWN transaction, never interleaved with the refund flow's writes. No deadlock topology.

### Pair 2 — Z3×Z5 outbox-before-fiscal race (`OrderCreated`)

**Suspected interaction**: If outbox event for `OrderCreated` is built before `fiscal_sequence_no` allocation completes, KDS could see an order with no fiscal sequence.

**Verified — the race does not exist**:

POS path (`OrderService::posOrderStore`):
1. Line 939-947: `fiscal_sequence_no` allocated INSIDE `DB::transaction` via `FiscalSequenceService::next($branchId)`.
2. Line 1066: `});` — `DB::transaction` closure ends, commit triggers.
3. Line 1075: `OrderCreated::dispatch($order)` — fires AFTER commit.
4. Listener `PersistOrderCreatedToOutbox::handle` runs synchronously, reads `$order->fiscal_sequence_no` (already on the persisted row, $order in-memory model).

Kiosk path (`FrontendOrderService::finalizePaidKioskOrder`):
1. Line 1130-1190: `fiscal_sequence_no` allocated inside `DB::transaction` closure (line 1090 starts the closure).
2. Line 1195: `});` — closure ends, commit triggers.
3. Line 1212: `dispatchNewOrderSignals($frontendOrder)` calls `OrderCreated::dispatch` at line 1226.

So `fiscal_sequence_no` is ALWAYS on the persisted row by the time `OrderCreated` fires.

**Secondary check — does the outbox payload itself need fiscal_sequence_no?**

The payload at `PersistOrderCreatedToOutbox:32-43` deliberately OMITS `fiscal_sequence_no`. I checked all known consumers:
- `public/js/admin-kds.js` — no `fiscal_sequence_no` reference (grep empty)
- `public/js/kiosk-shell.js` — no `fiscal_sequence_no` reference
- `public/js/admin-oss.js` — no `fiscal_sequence_no` reference
- `resources/js/store/modules/kds.js` — no `fiscal_sequence_no` reference
- `resources/js/helpers/posReceiptBuilder.js:115-116` reads it but from `order.fiscal_sequence_no` (order object fetched via REST, not broadcast payload)
- `resources/js/components/admin/pos/PosComponent.vue:1193` comments that server is SSOT for fiscal_sequence_no

The omission is **correct by design**: the OrderCreated broadcast is a sync trigger ("refresh this order"); clients then fetch the canonical row via REST/Eloquent. `PersistOrderPaymentStatusChangedToOutbox:57` and `PersistOrderPaidAtCounterToOutbox:38` DO carry `fiscal_sequence_no` because those events fire when the seq has just been allocated and clients need the payload-level field for printer dispatch / fiscal display.

**Z5 P1-C interaction (fiscal_alloc_error_at)**: When kiosk fiscal alloc fails (line 1154-1189), `fiscal_alloc_error_at` is persisted INSIDE the same transaction and the listener function returns BEFORE `OrderStateMachine::recordTransition` and before `dispatchNewOrderSignals`. Status stays PENDING. No OrderCreated is dispatched on that path. KDS never sees an order with `fiscal_alloc_error_at !== null` until the retry cron clears it. Cross-zone correctness preserved.

### Pair 3 — Z6×Z7 idempotency scope branch leak

**Suspected interaction**: Admin (`branch_id=0`) creates idempotent request with cross-branch payload. Does the scope-key collision cross with Z6 P0 cross-branch leak (Wave L A.1 closed PosLoyaltyController)?

**Verified — separate concerns, both correctly handled**:

`IdempotencyKeyMiddleware::resolveBranchId` (lines 182-219):
- Admin (auth `branch_id=0` + `hasRole('Admin')`): scopes idempotency to `payload.branch_id` (line 188-195). Cross-branch admin requests get cross-branch idempotency keys — correct, prevents an admin's POS retry against branch-3 from hitting cached state from branch-5.
- Branch user (`branch_id>0`): scopes to user's branch unconditionally (line 197-199).
- Kiosk (`branch_id=0` non-admin): resolves to KioskMachine pivot branch (line 204-217).

This intersects safely with the Z6 BranchScope: BranchScope filters DB reads; IdempotencyKeyMiddleware scopes Redis cache keys. Admin reads the row via `withoutGlobalScope(BranchScope::class)` (PosLoyaltyController:45) and then does an explicit post-fetch branch check (line 54-56, Wave L A.1). The idempotency key scope is separate from the row-read scope and does not affect it.

**Z7 P1 (scope key omits route path) status**: known V1.x backlog per SYNTHESIS.md §F Q22. Frozen middleware (CLAUDE.md §7) — heal requires LOCK plan. Same key reused across 2 routes with same body still creates a cross-route collision; this is documented and accepted for V1 Le Cayenne local single-resto.

**The interaction in the task framing** ("admin creates idempotent request — does cross-branch lookup work") is correctly handled: admin's idempotency scope IS the payload branch, and the row lookup explicitly enforces a branch check post-fetch via Wave L A.1. No cross-zone scope leak.

### Pair 4 — Z1×Z2 stock vs availability double-decrement race

**Suspected interaction**: Order creation decrements stock (Z1) AND availability (Z2). Wave L C.1 added Cache::add SETNX on availability — but does it race with stock_movements decrement on same order?

**Verified — orthogonal stores, orthogonal idempotency**:

`StockService::decrementForOrder` → `stock_movements` table:
- Idempotency: DB UNIQUE on `idempotency_key = sha1('order_created|FQCN|order_id|line_uid|stockable_type|stockable_id')` (line 97-104 + 119-128).
- Failure mode: `StockMovement::query()->where('idempotency_key', $movementKey)->exists()` short-circuits before any mutation. Persistent (DB-backed). Survives cache flush.

`AvailabilityService::decrementForOrder` → `item_branch_availability.daily_consumed_qty`:
- Idempotency: Cache::add SETNX on `availability:decremented:{fe|o}:{branchId}:{orderId}` (line 313-325).
- Failure mode: 24h TTL; cache flush in same day could allow ONE re-decrement. Blast radius bounded to that order's quota, observable via dashboard.

**The two listeners run sequentially** (EventServiceProvider:154-155: `DecrementItemAvailabilityOnOrder` first, then `DecrementStockOnOrderCreated`). They write to different tables, use different idempotency stores, and operate on different counters (`item_branch_availability.daily_consumed_qty` for UX auto-86 vs `stock_movements.delta` for NF525-adjacent SSOT).

**No race because**:
1. Same order → both listeners fire ONCE per order on the happy path.
2. Replay/retry of `OrderCreated` event → availability short-circuits at Cache::add, stock short-circuits at DB UNIQUE existence check. Both idempotent.
3. They are NOT mirrors of each other; they write to different domains. No "double-counted decrement" is possible at the data level.

**Wave L C.1 acknowledgment**: The HEAL-PLAN-C-order-lifecycle.md §3 note about "blast radius bounded to one order's quota" remains accurate — the stock_movements counter has the harder NF525-adjacent invariant and uses the harder mechanism (DB UNIQUE).

### Pair 5 — Z4×Z8 refund snapshot byte-identical

**Suspected interaction**: Refund counter-entry creates mirror items with snapshot. Wave L D.1 hardened `CompositionSnapshotBuilder` against role-injection. If snapshot is RECOMPUTED on refund, D.1 must re-apply. If COPIED, sealed parent snapshot rides through.

**Verified — byte-identical copy, builder NOT re-invoked**:

`RefundWithCounterEntryService::execute` line 131-152:
```php
foreach ($parent->orderItems as $item) {
    OrderItem::create([
        ...
        'composition_snapshot' => $item->composition_snapshot,  // line 146
        ...
        'allergens_snapshot' => $item->allergens_snapshot,       // line 151
    ]);
}
```

`composition_snapshot` is copied as a JSON value from parent to mirror — the `CompositionSnapshotBuilder` is NOT called. The Wave L D.1 role-injection defense (CompositionSnapshotBuilder lines 137-152 + ValidatesAddonRoles trait) applies at order *creation* time. Refund time, the immutable sealed snapshot from creation rides through.

**Sole-callsite check**: `grep "OrderItem::create" | grep refund_or_mirror_or_counter` → only `RefundWithCounterEntryService:133`. No other refund-path mirror creator. Single point.

**Subtle V1.x observation (data drift, NOT a heal target)**:
- Orders created BEFORE commit `7bf30658b` (Wave L D.1) may have a forged-payload ratio'd snapshot persisted.
- If such an order is refunded post-heal, the mirror inherits the pre-heal value byte-identically.
- This is **data drift from a historical defect window**, not a current code defect. Refund flow is doing the correct thing (carrying immutable sealed snapshot). The historical orders are NF525-immutable so cannot be retroactively patched.
- Surface for V1.x backlog if anomaly observed in Z reconciliation.

## Self-RED dispute (intra-Wave-M-P5)

For each pair I checked: could a heal break the OTHER zone's invariants?

- **Pair 1**: A second wrap layer around `OrderPaymentStatusChanged::dispatch` would mask broadcast errors needed for observability. Skip.
- **Pair 2**: Adding `fiscal_sequence_no` to OrderCreated outbox payload would couple a sync trigger to a fiscal value clients don't currently consume. Premature. Skip.
- **Pair 3**: Adding route-path to IdempotencyKeyMiddleware scope is a known V1.x heal (Z7 P1) requiring LOCK plan; out of Wave M P5 scope.
- **Pair 4**: Cache::add → DB UNIQUE conversion would change failure-mode trade-off (persistent vs ephemeral). Not warranted — UX quota counter does not need NF525-grade persistence.
- **Pair 5**: Re-invoking the builder on refund would VIOLATE NF525 immutability of the sealed snapshot. The byte-identical copy is correct-by-design.

## Frozen-zone diff attestation

**0 lines** modified across CLAUDE.md §7 files. No file in `app/Services/Fiscal/*`, `app/Models/Scopes/BranchScope.php`, `app/Domain/Order/OrderStateMachine.php`, `app/Http/Middleware/IdempotencyKeyMiddleware.php`, `app/Services/Pricing/PricingService.php`, kiosk Vue files, or POS Vue/Vanilla JS files was touched.

## NF525 chain attestation

`php artisan fiscal:verify-chain` — chain integrity unchanged from Wave L final state (count + last_hash bit-identical post-Wave-L commit `7bf30658b`). Not re-run in Wave M P5 because zero code mutations were applied.

## Confidence

**9.5/10** — Empirical: all 5 interactions traced file:line-by-file:line. Two independent discriminating checks (JS consumer grep + sole-callsite grep) confirmed advisor's a priori reasoning. The remaining 0.5 accounts for:
- I did NOT run the full test suite in this audit pass (read-only scope). Wave L's `914/914 final smoke` from PROJECT_BRAIN baseline + the listener-order sentinel test give high transitive confidence.
- The "data drift" V1.x observation in Pair 5 cannot be empirically verified without DB scan of pre-7bf30658b orders — flagged as observation, not heal.

## V1.x backlog additions (this wave)

1. **OBS-1**: Composition snapshot data-drift window. Orders created before commit `7bf30658b` (2026-05-19, Wave L D.1) may carry ratio'd snapshot values that were possible under the pre-heal role-injection vector. If post-Z refund anomalies appear in Z reconciliation, query `orders.created_at < '2026-05-19 14:00' AND fiscal_sequence_no IS NOT NULL` and audit composition_snapshot.role fields against ItemAddon.role. NF525-immutable — no retroactive patch possible; observation only.

2. **OBS-2** (re-confirms Wave L SYNTHESIS.md Q22): Z7 P1 idempotency scope-key route-path collision still open. Single-key reused across 2 distinct routes with identical body collides. Known frozen-middleware constraint. V1.0.2 with LOCK plan.

## Commits shipped

**0**.

## Recommendation

Mark Pair 1-5 CLOSED in cross-zone status tracking. No code action required. The seams between Wave K's single-lane zones are coherent post-Wave-L heals.

The discipline-validation result is the deliverable: Wave M P5 verified that Wave L's heals composed correctly across zone seams, and that the 5 high-suspicion interaction patterns flagged in the task brief are factually GREEN under primary-source code reading. Zero false heals shipped.
