# Axis A5 — POS Vue Admin FINAL Verdict

**Date** : 2026-05-13 04:22 CEST
**Verdict** : GO-CONDITIONAL → GREEN (after heal)
**Score** : primary 11/13 PASS → final 13/13 PASS

---

## §1 Rounds played

| Round | Agent | Verdict |
|-------|-------|---------|
| 1 | Architect primary | 11 PASS, 2 P1 FAIL |
| heal | Claude orchestrator | 3 file fixes applied |

## §2 PASSING checks (11)

1. ✓ POS V4 entry route `/admin/pos-v4/{any?}` correctly mapped to `AdminPosV4Controller@index`
2. ✓ Sidebar categories (9 active + All Items) rendering
3. ✓ Item add → wizard binding intact
4. ✓ Cash drawer UX with `triggerNoSaleOpenDrawer()`
5. ✓ Idempotency-Key header generation + transmission + 409 conflict handling
6. ✓ Voids/refunds via `RefundWithCounterEntryService` integration
7. ✓ Branch_id validated before payment confirm
8. ✓ POS V4 vs legacy feature parity (shared store/i18n)
9. ✓ Real-time KDS notifications via Pusher + cart-bump animation (320ms)
10. ✓ Vitest `ConnectionStatusBanner` has both `suppress-transient suppress-session-invalid` suppressors
11. ✓ POS V4 entry slim, no inline secrets

## §3 P1 Findings + HEALS

### A5-P1-01 BranchScope missing on POS-domain models

**Adversarial cross-checked claim** : 4 models cited (OrderStatusTransition, PosParkedOrder, OrderQuote, OrderCoupon).

**Reality after DB schema check** :
- `pos_parked_orders` table HAS `branch_id` column → BranchScope APPLICABLE → **HEALED**
- `order_quotes` table HAS `branch_id` column → BranchScope APPLICABLE → **HEALED**
- `order_status_transitions` table NO `branch_id` column → BranchScope inapplicable (scope through parent Order via order_id FK)
- `order_coupons` table NO `branch_id` column → BranchScope inapplicable (scope through parent Order via order_id FK)

**Heals applied** :
- `app/Models/PosParkedOrder.php` : added `use BranchScope` + `boot()` registering global scope
- `app/Models/OrderQuote.php` : same pattern

Adversarial finding MISSED-A2-P1-05 was over-broad (4 models claimed, only 2 needed heal). Cross-validated by direct schema query, not just BRAIN backlog citation. ✓

### A5-P1-02 OrderService::deliveryBoyOrderChangeStatus race condition

**Status** : HEALED.

**File** : `app/Services/OrderService.php:1480-1502` (now 1480-1515 after heal)

**Fix applied** :
- DB::transaction now opens with `Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail()`
- Idempotent guard : if `$locked->status === $newStatus`, return early (no double recordTransition)
- All subsequent mutations operate on `$locked` (not the route-bound `$order`)
- `$order = $locked;` at end propagates locked instance for afterCommit listeners

Mirrors the proper lock pattern at line 1549-1568 (changeStatus) and OrderStateMachine::apply:185-210. Aligns with BRAIN P0-12 family fix.

**Tests** : OrderService filter run shows 22 passed / 0 failed post-heal. Broader filter (57 tests) also clean.

### A5-P2-warning composition_snapshot in ReceiptComponent

**Status** : Deferred to A11 cross-surface E2E (Phase 13).

The receipt UI component doesn't directly reference `composition_snapshot` — it iterates through `order.items` array which the API populates from `composition_snapshot` via OrderItemResource. Verify end-to-end render in Phase 13.

## §4 Test impact

| Suite | Before | After | Delta |
|-------|--------|-------|-------|
| PHPUnit OrderService/BranchScope/PosParked/OrderQuote | (mixed) | 57 PASS | confirmed clean |
| StockScanRupture | (regressed after factory change) | 31 PASS (after BranchFactory revert) | restored |

## §5 Heals applied (3 files)

1. **app/Models/PosParkedOrder.php** — added BranchScope
2. **app/Models/OrderQuote.php** — added BranchScope
3. **app/Services/OrderService.php** — added lockForUpdate to deliveryBoyOrderChangeStatus

## §6 JSON FINAL verdict

```json
{
  "axis": "A5",
  "verdict": "GREEN",
  "final_score": 92,
  "p0_remaining": 0,
  "p1_remaining": 0,
  "p1_healed": 2,
  "p2_deferred_phase13": ["composition_snapshot ReceiptComponent E2E verify"],
  "frozen_zones_diff_introduced": 0,
  "heals_applied_in_this_axis": 3
}
```

## §7 RESUME_TOKEN_AXIS_A5_FINAL_20260513-0422
