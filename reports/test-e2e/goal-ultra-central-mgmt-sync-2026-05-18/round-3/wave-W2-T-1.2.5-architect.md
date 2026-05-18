# Wave W2 — T-1.2.5 — ARCHITECT specialist read-only audit
**Refund post-Z + Void pre-Z + Sealed-order mutation guard**
Date: 2026-05-18 — Round 3 — Read-only — Architect lens.
Anchors verified live (`find`/`grep`/`Read`) 2026-05-18.

---

## VERDICT

**GREEN-with-2-conditions** for V1 (Le Cayenne, single branch). **YELLOW** for V2 multi-operator/tenant.

The refund/void/sealed-guard triangle is architecturally **sound and self-consistent**. Pre-Z void/refund (state mutation) and post-Z refund (mirror order) are cleanly separated by a **single SSOT predicate** in `SealedOrderGuard::assertMutable`/`assertSealed`. Mirror pattern is correct NF525: parent immutable, mirror gets fresh `fiscal_sequence_no` via the atomic `FiscalSequenceService::next()` audited R1 T-1.2.1, and `ZReportService::aggregate()` (L378-405) picks the mirror via the standard half-open window query — no special-case aggregator branch. R1 F-FISC-008 **architecturally confirmed**: mirror seq > parent's, monotonic, gap-free; HMAC chain unbroken across the seal boundary.

Soft spots: (a) **`fiscal.sealed_z_guard_enabled=false`** symmetrically disables BOTH `assertMutable` AND `assertSealed` (L36-38, L84-88) → admin can fire counter-entry on a pre-Z parent during rollback → double-counting; (b) **partial-refund path not architected** — full-mirror only; (c) **concurrent-refund**: parent NOT lockForUpdate'd before mirror insert, two cashiers both pass duplicate-mirror check, both reserve distinct seq, both insert mirrors → phantom debit. `parent_order_id` FK has INDEX only, no UNIQUE.

---

## TOP FINDINGS

### F-T125-ARCH-01 — Refund vs Void semantics cleanly separated (GREEN)
- **Evidence**: `OrderStateMachine.php:60-71` (DELIVERED→RETURNED is the ONLY pre-Z return; CANCELED/REJECTED = pre-payment voids); `OrderService.php:1754-1777` (assertMutable fires ONLY on RETURNED); `ZReportService.php:378-382` (preZCancelCount=whereIn(CANCELED,REJECTED), preZRefundCount=where(RETURNED), two distinct counters); tests {VoidPreZ, RefundPreZ, RefundPostZ}Test cover all 3 cells.
- **Reasoning**: 4-cell matrix (pre/post-Z × void/refund) modeled correctly. Pre-Z void = state mutation, no counter-entry. Pre-Z refund = state mutation; original excluded from order_count via `terminalStatuses` whitelist (L349-353). **Post-Z void doesn't exist as separate path** — once sealed, order is immutable both directions; cashier MUST use refund-with-counter-entry. Post-Z refund = mirror order. Correct NF525 model — post-seal voids ARE refunds.
- **Fix**: Document 4-cell matrix in `docs/ARCHITECTURE.md`. Zero load impact.

### F-T125-ARCH-02 — Sealed-Z window is SSOT, no semantic drift (GREEN)
- **Evidence**: `SealedOrderGuard.php:46-52` vs `:97-103` — `assertMutable` and `assertSealed` use EXACT same predicate (branch_id + CLOSED + opened_at<created_at + closed_at>=created_at + fiscal_sequence_no NOT NULL). Pure inverse decision. `OrderService.php:2203-2215` — `destroy()` re-implements predicate INLINE (not via guard) but identical half-open semantic (docblock L2196-2202). `ZReportService.php:343-347` — aggregate uses `created_at>$from AND <=$to` — topologically inverse. Order at t=closed_at goes INTO closing Z, sealed from there. No double-count at boundary.
- **Reasoning**: 5-way contract holds by construction: destroy refuses == changeStatus refuses == changePaymentStatus refuses == counter-entry admits == aggregate counts. SealedOrderGuard is SSOT for 4 callers; destroy duplicates inline.
- **Fix**: Refactor `OrderService::destroy` (L2203-2209) to call `SealedOrderGuard::assertMutable($order, 'destroy')`. 6-line change, no behavioural delta, 5/5 callers through SSOT. Owner gate: NO (destroy is CRUD scaffold, not frozen).

### F-T125-ARCH-03 — `fiscal.sealed_z_guard_enabled=false` opens double-counting hole on inverse path (P1)
- **Evidence**: `SealedOrderGuard.php:36-38` (`assertMutable` no-ops on flag=false) AND `:84-88` (`assertSealed` ALSO no-ops, docblock L80-82 "honors same flag for symmetry"); `RefundWithCounterEntryService.php:70-71` (assertSealed is ONLY "parent-sealed" gate); L73-78 (only OTHER preflight is `parent->status===RETURNED` refuse — pre-Z DELIVERED passes both).
- **Reasoning**: When rollback knob fires, symmetric design that prevents drift on MAIN path (changeStatus refuses RETURNED post-seal) also DISABLES the INVERSE protection. Admin calls counter-entry on DELIVERED order INSIDE still-open Z → mirror lands in current Z + parent stays DELIVERED → both count in SAME closing Z (order_count=1 + refund_count=1, total_ttc=€0) instead of legitimate pattern (refund in NEXT Z, parent in PREVIOUSLY closed Z). WAVE5-POS-001 docblock (RefundWithCounterEntry L62-69) names this failure for flag=true; flag=false re-opens it.
- **Fix**: Decouple flags. Either (a) `assertSealed` REJECT (not no-op) when flag=false — rollback intent is "use legacy pre-Z RETURNED", counter-entry should NEVER be the answer; or (b) introduce `fiscal.counter_entry_enabled` (default true) gating `assertSealed` independently. **8-line change** in SealedOrderGuard. **V2: P0** — rollback eventually fires on a tenant subset; with symmetric no-op, that tenant's refunds degrade to double-counting during the window.

### F-T125-ARCH-04 — Concurrent refund race: parent NOT lockForUpdate'd before mirror insert (P1)
- **Evidence**: `RefundWithCounterEntryService.php:88` DB::transaction wraps mirror creation, but parent Order row NOT acquired with `lockForUpdate` inside tx. L73-78 duplicate-protection check `parent->status===RETURNED` reads in-memory parent BEFORE the transaction (L73 is OUTSIDE `connection->transaction` at L88) — two concurrent `Order::find($id)` both pass. L90 `FiscalSequenceService::next` is serialized per-branch (Round 1 F-FISC-008), so two mirrors get DISTINCT seq → chain unforked. Migration `2026_05_06_200000`: `parent_order_id` has INDEX only, no UNIQUE. `SealedOrderMutationGuardTest.php:142-162` `test_refund_double_call_throws` is SEQUENTIAL (manually sets `parent->status=RETURNED` between calls) — real concurrency NOT tested.
- **Reasoning**: Two cashiers A/B within ~50ms: T1-T2 both read parent DELIVERED seq=100; T3-T4 both pass L73; T5 A tx reserves seq=200 + mirror M_A; T6 B tx reserves seq=201 + mirror M_B; T7 both commit. Result: TWO mirrors for ONE parent (-€100 closing Z aggregate), parent's €50 debited twice. NF525 chain unforked but business audit reveals phantom €50 refund. `parent_order_id` FK enables forensic recovery via post-hoc query, NOT prevented at write-time.
- **Fix**: Inside DB::transaction (L88), acquire parent with `lockForUpdate` BEFORE seq reservation:
  ```php
  $locked = Order::whereKey($parent->id)->lockForUpdate()->firstOrFail();
  if ($locked->status === OrderStatus::RETURNED) throw '422 already RETURNED';
  if (Order::where('parent_order_id', $locked->id)->exists()) throw '422 mirror exists';
  // ... seq + mirror create ...
  $locked->status = OrderStatus::RETURNED; $locked->save(); // same tx
  ```
  Adds the row-level serialization that the test synthetically assumes. 15 lines, no schema change. Optional belt-and-braces: `UNIQUE(parent_order_id) WHERE NOT NULL` partial index (see DBA cross-question). **V2: P0** — multi-cashier admin consoles increase concurrent-refund surface; rare on Le Cayenne, weekly on SaaS 50-branch × 3-admin.

### F-T125-ARCH-05 — Partial-refund path not architected; full-snapshot mirror only (P2 V1 / P1 V2)
- **Evidence**: `RefundWithCounterEntryService.php:120-143` foreach iterates ALL `$parent->orderItems` with qty×-1; service signature L52 has no `$itemIds` param; `PosOrderController.php:52-54` validates only `reason`; mirror totals L102-104 negate parent FULL totals — no proration.
- **Reasoning**: V1 ships full-refund only. Operationally restrictive (returning 1 of 3 items requires full refund + new partial order). Data model already supports partial (composition_snapshot/item_extras per-item); only service signature needs `$itemIds`. Defensible V1 deferral.
- **Fix**: Add `executePartial(Order $parent, array $itemIds, string $reason)` mirroring only specified items. Same `assertSealed` admission, same `FiscalSequenceService::next`, same `RefundCreated::dispatch(parent)`. Parent stays DELIVERED. ~80 lines, no schema change. V1 defer with `docs/BUSINESS_RULES.md` note. **V2: P1** — SaaS QSR expects partial refund as SOP.

### F-T125-ARCH-06 — RefundCreated fires on PARENT (correct, contract undocumented) (P2)
- **Evidence**: `RefundWithCounterEntryService.php:224-229` — comment "Pass PARENT (positive qty); mirror has NEGATED qty which would corrupt released_qty ledger". Listeners `ReleaseStockOnRefundCreated`/`ReleaseAvailabilityOnRefundCreated` (EventServiceProvider:165-167) iterate `$event->order->orderItems`. `PaymentService.php:152` cashBack() also fires `RefundCreated::dispatch($order)` where $order=parent.
- **Reasoning**: Contract is correct (positive qty needed) but undocumented in event class. Cross-ref Round 2 F-T321-ARCH-02 (apply() doesn't auto-dispatch OrderStatusChanged): same asymmetry pattern.
- **Fix**: PHPDoc on `app/Events/RefundCreated.php` naming contract + sentinel test asserting `$event->order` is parent (not mirror) for both callsites. ~25 lines.

### F-T125-ARCH-07 — Pre-Z void: status field is SSOT, no soft-mark column (GREEN)
- **Evidence**: `OrderService.php:1817-1841` writes audit_logs with from/to_status, total, payment_status, fiscal_sequence_no. `OrderStateMachine.php:132-159` recordTransition() writes order_status_transitions row (best-effort try/catch). `ZReportService.php:378-382` uses status=RETURNED as discriminator. `ZReportService.php:387-397` post-Z adjustments via `updated_at>$from AND created_at<=$from`. NO separate voided_at/refunded_at column.
- **Reasoning**: Pre-Z void mutates `Order.status`; ZReportService re-derives counters from status. Status = operational marker; audit_logs = immutable forensic. Correct as long as (a) only path to status=RETURNED outside refund-with-counter-entry runs through `OrderService::changeStatus` (writes audit row), (b) audit row unforgeable (HMAC chain). Both hold. Architecture INTENTIONALLY avoids a voided_at column to prevent dual-SSOT consistency burden.
- **Fix**: Document the convention in `docs/ARCHITECTURE.md`.

---

## COVERAGE MAP

4 paths verified end-to-end:
1. **Pre-Z void** (CANCELED/REJECTED): `OrderService.changeStatus` → reason validate → status assign + save → `recordTransition` → AuditLog('order.cancelled') → `OrderCanceled.dispatch`. NO SealedOrderGuard (correct).
2. **Pre-Z refund** (RETURNED, no closed Z): `OrderService.changeStatus` → reason validate → `SealedOrderGuard.assertMutable` (no-op) → status assign + save → AuditLog('order.returned') → `PaymentService.cashBack` → `LoyaltyService.refundPoints` → `OrderCanceled.dispatch`.
3. **Post-Z refund** (mirror): `PosOrderController.refundWithCounterEntry` → permission:pos-orders + cross-branch check → `service.execute` → `assertSealed` → duplicate-mirror check → DB::transaction → `FiscalSequenceService.next` → Order.create(mirror) → OrderItem.create*N (negated) → OrderPayment.create*N (negated, terminal_id preserved) → AuditLog('order.refund.counter_entry') → `RefundCreated.dispatch(PARENT)`.
4. **Sealed destroy refusal**: `OrderService.destroy` → branch isolation → paid-permission gate → inline sealing predicate (F-T125-ARCH-02 fix) → abort(409) OR soft-delete + AuditLog('order.destroyed').

**Files Read** (full unless noted): `FiscalSealingService`, `RefundWithCounterEntryService`, `SealedOrderGuard`, `OrderStateMachine`, `PaymentStateMachine`, `FiscalSequenceService`, `ZReportService` (L200-450), `OrderService` (L1700-1850, 1885-2010, 2140-2275), `PaymentService` (L100-170), `PosOrderController` (L25-130), `OrderSealedException`, `EventServiceProvider` (RefundCreated subs), migration `2026_05_06_200000`, tests {SealedOrderMutationGuard, RefundPostZ, RefundPreZ, VoidPreZ}.

**Test gaps**: (i) no concurrent refund test (F-T125-ARCH-04); (ii) no flag-off failure test (F-T125-ARCH-03); (iii) no partial-refund sentinel locking V1 contract; (iv) no multi-branch isolation test for `withoutGlobalScopes()` on parent payment query (L163).

---

## CROSS-REFERENCES

- **R1 T-1.2.1 Architect** F-ARCH-T211-01 — `FiscalSequenceService::next()` collision-via-savepoint risk applies identically to mirror seq allocation. Same mitigation (cache lock + DB lockForUpdate + UNIQUE).
- **R1 T-1.2.1 Fiscal** F-FISC-008 — GREEN confirmed: mirror's fresh `fiscal_sequence_no` > parent's, monotonic, gap-free; HMAC chain extends through parent's AND mirror's signing Z.
- **R2 T-3.2.1 Architect** F-T321-ARCH-01 (admin escape hatch RETURNED→any) — STRONGLY RELATED to F-T125-ARCH-03: admin transitioning RETURNED→DELIVERED could "un-refund" a mirror's parent, creating phantom +€50 in closing Z with no matching debit. **Same V1 attack vector that collapses the 5-layer sealed-Z defense. Fix F-T321-ARCH-01 BEFORE V1 ship.**
- **R2 T-3.2.1 Architect** F-T321-ARCH-02 (apply() doesn't auto-dispatch) — mirrors F-T125-ARCH-06 contract-asymmetry. Same fix direction.

---

## OPEN QUESTIONS

- **DBA**: Add `UNIQUE(parent_order_id) WHERE parent_order_id IS NOT NULL` partial index to prevent F-T125-ARCH-04 at the storage layer? MySQL 8 + SQLite both support partial-unique. Additive migration, not frozen.
- **Security**: Verify `permission:pos-orders` + cross-branch check on `refund-with-counter-entry` (PosOrderController:48) is sufficient against IDOR (low-priv user crafting cross-tenant order_id). BranchScope should 404, confirm test exists.
- **Fiscal**: DGFiP tolerance for mirror in SAME Z as parent (F-T125-ARCH-03 flag-off mode) — §1840-J-bis violation or recoverable anomaly? Also confirm `order_serial_no='RTN-'.parent_serial_no` (L106) is JET XML compatible (R1 F-FISC-001 territory) — non-numeric prefix may break some parsers.
- **SRE**: `RefundCreated` is `DispatchableAfterCommit`. If mirror commits but queue worker is down, stock release delays. Confirm DLQ/poison-queue path for `ReleaseStockOnRefundCreated` (R1 F-T215-SRE-01 territory).
