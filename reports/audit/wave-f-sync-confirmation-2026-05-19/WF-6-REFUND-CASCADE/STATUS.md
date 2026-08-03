# WF-6 — Refund Cascade Sync Confirmation — STATUS

**Wave**: F (Sync Confirmation) — Task WF-6
**Date**: 2026-05-19
**Scope**: read-only audit on refund cascade (NF525-adjacent, frozen-zones untouched)
**Discipline**: multi-agent cross-validation (Architect + Security + RED)
**Round**: 1

---

## 1. Cascade graph under audit

```
PaymentService::cashBack (pre-Z path)                          RefundWithCounterEntryService::execute (post-Z path)
            │                                                                          │
            ├── AuditLogService::write('payment.cash_back_issued')                     ├── FiscalSequenceService::next(branchId)  -> fresh mirror seq
            ├── recordCashBackMovement() (best-effort, cash drawer OUT)                ├── Order::create(mirror)   total/subtotal/tax NEGATED
            └── RefundCreated::dispatch($order)  (DispatchableAfterCommit)             ├── mirror_order_items     qty/tax NEGATED, composition_snapshot COPY
                            │                                                          ├── mirror_order_payments  amount NEGATED per tranche, terminal_id carry-forward
                            │                                                          ├── AuditLogService::write('order.refund.counter_entry') (HMAC chain extend)
                            │                                                          └── RefundCreated::dispatch($parent)  -- PARENT, not mirror (line 229)
                            │                                                                          │
                            └─────────────────────────────────┬────────────────────────────────────────┘
                                                              │
                            ┌─────────────────────────────────┴────────────────────────────────────────┐
                            │                                                                          │
            ReleaseStockOnRefundCreated                                         ReleaseAvailabilityOnRefundCreated
            └── StockService::releaseForOrder                                   └── AvailabilityService::releaseForOrderItems
                  (stock_levels++, stock_movements++)                                 (order_items.released_qty++)
```

**Listeners wired (EventServiceProvider.php:165-168):**
```
RefundCreated::class => [
    ReleaseStockOnRefundCreated::class,
    ReleaseAvailabilityOnRefundCreated::class,
],
```

---

## 2. Verification summary (refund_related tests)

```
$ php artisan test --filter "Refund|RefundCreated|RefundWithCounter"

Tests:  29 passed
Time:   4.20s
```

All 29 tests green, covering:
- PaymentStateMachine transitions (legal + illegal)
- Partial / full refund availability release
- NF525 E2E refund post-Z (full scenario)
- RefundPostZ counter-entry negative adjustment
- RefundPreZ standard pre-Z path
- Sealed order mutation guard (4 cases)
- Loyalty refund on cancel (note: only cancel path, NOT refund cascade — see R-2)
- Terminal_id wire-in on mirror tranches
- Split-payment mirror with negated tranches (3 cases)
- RefundCreated dispatch + idempotency (2 cases)
- Sentinel sealed-Z route exists
- Sealed-parent guard sentinels (4 cases)
- Stock release on refund event (2 cases)

---

## 3. Cross-validated verdict per cascade leg

| Cascade leg                                | Architect       | Security        | RED             | Cross-verdict     |
| ------------------------------------------ | --------------- | --------------- | --------------- | ----------------- |
| PaymentService::cashBack → RefundCreated   | CONFIRMED       | CONFIRMED       | CONFIRMED       | OK                |
| RefundWithCounterEntryService::execute     | CONFIRMED       | CONFIRMED       | CONFIRMED       | OK                |
| composition_snapshot copy-forward          | CONFIRMED       | CONFIRMED       | n/a             | OK (NF525)        |
| Fiscal sequence allocation (mirror)        | CONFIRMED       | CONFIRMED       | n/a             | OK (gap-free)     |
| AuditLogService chain extension            | CONFIRMED       | CONFIRMED       | n/a             | OK (HMAC chain)   |
| ReleaseStockOnRefundCreated                | CONFIRMED       | CONFIRMED       | P2 (R-4)        | OK + P2 backlog   |
| ReleaseAvailabilityOnRefundCreated         | CONFIRMED       | CONFIRMED       | P2 (R-4)        | OK + P2 backlog   |
| Mirror does NOT self-cascade               | CONFIRMED       | n/a             | CONFIRMED       | SAFE              |
| Outbox broadcast on refund                 | P2 (A-2)        | n/a             | P1 (R-1)        | GAP — see §5      |
| Loyalty point reversal on refund cascade   | n/a             | n/a             | P1 (R-2)        | GAP — see §5      |

---

## 4. Findings consolidated (deduplicated, cross-confirmed)

### P1 — Sync/business gaps (V1.0.1 heal backlog)

**WF-6-P1-1 — Refund payment_status broadcast hole** *(RED R-1, Architect A-2)*
- `PaymentService::cashBack()` does NOT update `parent.payment_status = REFUNDED`.
- `PaymentService::cashBack()` does NOT dispatch `OrderPaymentStatusChanged`.
- `RefundWithCounterEntryService::execute()` also never dispatches `OrderPaymentStatusChanged` on the parent — it only sets `payment_status=REFUNDED` on the MIRROR.
- No `PersistRefundCreatedToOutbox` listener registered.
- **Consequence**: connected POS/admin/OSS clients listening on `private-branch.{id}` do NOT receive a realtime broadcast of the refund. Parent order DB row keeps `payment_status=PAID` (UI semantically wrong until refresh).
- **Heal options (V1.0.1)**:
  - Register `PersistRefundCreatedToOutbox` listener on `RefundCreated`, OR
  - In `cashBack()`, set `$order->payment_status = REFUNDED` + dispatch `OrderPaymentStatusChanged`, OR
  - Both (defense in depth).

**WF-6-P1-2 — Loyalty points NOT refunded by post-Z RefundWithCounterEntryService caller** *(RED R-2)*

Caller-layer audit (advisor-requested verification):
- `PaymentService::cashBack` callers: OrderService:1696, OrderService:1799, FrontendOrderService:701 — **ALL THREE pair with `LoyaltyService::refundPoints()` immediately after** (lines 1702, 1805, 707). Pre-Z cancel path is SAFE.
- `RefundWithCounterEntryService::execute` callers: `PosOrderController::refundWithCounterEntry` (line 64) **is the SOLE caller** and does NOT call `refundPoints()`. Post-Z refund path SKIPS loyalty reversal.

Consequence: a customer who redeems loyalty points on an order that is later refunded post-Z via the admin POS refund-with-counter-entry button loses BOTH the cash (refunded via mirror) AND the loyalty points (consumed permanently). Business-invariant violation specifically in the post-Z refund flow.

**Heal options (V1.0.1)**:
- Preferred: register `RefundLoyaltyPointsOnRefundCreated` listener on `RefundCreated` event — unifies cascade and removes future omission risk.
- Alternative: add `LoyaltyService::refundPoints($order, 'pos')` call inside `PosOrderController::refundWithCounterEntry` right after `$service->execute()`.
- Idempotency via `LoyaltyTransaction.type='manual_add'` lookup against the order_id.

**Test coverage gap**: `OrderCancellationLoyaltyTest` covers cancel path but no test covers loyalty reversal on `RefundWithCounterEntryService::execute` or on the `PosOrderController::refundWithCounterEntry` route.

### P2 — Resilience / backlog

**WF-6-P2-1 — RefundCreated listener exception in sync mode silently kills downstream listener** *(RED R-4)*
- Order: `ReleaseStockOnRefundCreated` BEFORE `ReleaseAvailabilityOnRefundCreated`.
- If `ReleaseStockOnRefundCreated` throws (e.g., missing StockLevel row), exception propagates → `ReleaseAvailabilityOnRefundCreated` never fires → availability counter stays decremented forever ("sold out" persists).
- **Mitigation V1.0.1**: wrap stock-release listener body in try/catch + Log::warning (defense in depth), OR reorder listeners (availability first, user-visible counter).

**WF-6-P2-2 — BranchScope withoutGlobalScopes() on OrderPayment lookup — depends on controller-layer authz** *(Security S-3)*
- `RefundWithCounterEntryService:163` bypasses BranchScope to support cross-branch admin refund tooling.
- Rationale is documented inline; current admin scope is single-org, so practical risk LOW.
- **Backlog V1.0.1+**: explicit controller-layer permission audit when multi-org SaaS lands.

**WF-6-P2-3 — Customer deletion does NOT block refund mirror — orphaned mirror possible** *(RED R-5)*
- Mirror order's `user_id = parent.user_id` without `User::withTrashed()` guard. Low-frequency edge case.
- **Backlog V1.0.2**.

### INFO — Confirmed correctness

- WF-6-A-1 — Cascade dispatch topology documented and consistent
- WF-6-A-3 — composition_snapshot copy-forward respects NF525 immutability
- WF-6-A-4 — Mirror order does NOT spuriously fire OrderCreated (no observer)
- WF-6-S-1 — NF525 parent immutability preserved across refund mirror creation
- WF-6-S-2 — Audit chain extends with HMAC-signed `order.refund.counter_entry` row
- WF-6-S-4 — DispatchableAfterCommit ensures listeners never fire on rolled-back mirror
- WF-6-R-3 — Mirror order does NOT self-cascade (RED concern dismissed)
- WF-6-R-6 — Idempotency on cashBack() prevents double-RefundCreated dispatch (test proven)
- WF-6-R-7 — Idempotency on RefundWithCounterEntryService::execute caller-dependent (acceptable)
- WF-6-R-8 — `RefundCreated::dispatch($parent)` passes PARENT (positive qty), not mirror — design choice documented and correct

---

## 5. WF-6 verdict

**FISCAL CASCADE: CONFIRMED GREEN.** NF525 invariants intact (parent immutability, gap-free sequence, HMAC chain extension with `order.refund.counter_entry` action). Mirror order created atomically with DispatchableAfterCommit safety. Composition snapshots copy-forward correctly. 29/29 refund tests pass.

**BUSINESS / SYNC CASCADE: INCOMPLETE.** Two P1 gaps (caller-layer audit precision included):
1. **Refund broadcast hole** — connected POS/admin/OSS clients receive no realtime event on refund; parent `payment_status` stays PAID until next poll. Affects BOTH pre-Z and post-Z paths.
2. **Loyalty point reversal hole** — POST-Z REFUND PATH ONLY. `PosOrderController::refundWithCounterEntry` (sole caller of `RefundWithCounterEntryService::execute`) does NOT companion-call `LoyaltyService::refundPoints`. Pre-Z cancel path is safe (all 3 `cashBack` callers pair with `refundPoints`).

**RECOMMENDATION**:
- V1 Le Cayenne: **ACCEPTABLE** to ship — fiscal cascade is complete, single-resto scope means broadcast hole is tolerable (admin can re-poll), loyalty hole only impacts customers who redeem then get refunded **specifically via the post-Z admin refund-with-counter-entry button** (rare edge: customer redeems + day closes + refund needed).
- V1.0.1 hardening cycle: **MANDATORY** to heal both P1 gaps before SaaS multi-resto landing.
- V1.0.2: address P2 backlog (resilience + multi-org authz audit + deleted-customer guard).

**Frozen-zone touch count**: 0
**NF525 chain bit-identity**: PRESERVED (audit-only audit)
**Tests run**: 29 PASS / 0 FAIL

---

## 6. Deliverables

- `architect.json` — architecture parity + cascade topology + dispatch correctness + test coverage map
- `security.json` — NF525 invariants + authz review + transaction atomicity + chain extension
- `red.json` — adversarial failure-mode probing (8 findings: 2 P1, 2 P2, 4 INFO)
- `STATUS.md` — this file (cross-validated synthesis)

Discipline: **multi-agent cross-validation completed**. Findings deduplicated; no contradiction between specialists. Two P1 gaps surfaced and confirmed against both code reading and test-coverage observation.
