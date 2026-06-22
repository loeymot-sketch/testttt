# WG-1 WF-6 Refund Broadcast/Sync Heal — STATUS

**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Base HEAD before heal**: `50bdd5150`
**HEAD after heal**: `8edc72a36`
**Date**: 2026-05-19
**Wall-clock**: ~50 minutes (recon + 2 RED-then-GREEN cycles + verification)

## Verdict: GREEN — 2 P1 holes closed

Both WF-6 P1 audit findings are now closed. 5 new sentinel cases (3+2) all
pass. 34 refund-suite tests pass. 29 critical sentinels pass. F-010
sentinel green. 570 broader Order-suite tests pass. 0 frozen-zone touch.

## Heal Commits

| # | SHA | Title |
|---|-----|-------|
| 1 | `3b0776f7c` | `fix(refund-WF-6-P1): broadcast OrderPaymentStatusChanged on RefundCreated` |
| 2 | `8edc72a36` | `fix(refund-WF-6-P1): companion refundPoints inside RefundWithCounterEntryService` |

## P1 #1 — Refund payment_status broadcast hole

**Audit finding**: PaymentService::cashBack and
RefundWithCounterEntryService::execute dispatched RefundCreated (for
stock + availability release) but NEITHER mutated parent.payment_status
nor dispatched OrderPaymentStatusChanged. Connected POS / admin / OSS
clients NEVER received realtime refund event.

**Heal**: NEW listener `App\Listeners\PersistOrderPaymentStatusChangedOnRefundCreated`
on `RefundCreated` event (Strategy A: dirty-aware new-listener pattern).

- Pre-Z path (parent mutable): persists payment_status = REFUNDED on parent
  inside `DB::transaction` with `lockForUpdate`, then dispatches broadcast.
- Post-Z path (parent SEALED by closed Z window): NF525 immutability —
  broadcast-only. Uses `SealedOrderGuard::assertMutable` as single source
  of truth for sealed-ness.
- Registered in `EventServiceProvider::$listen[RefundCreated::class]`
  alongside the existing `ReleaseStockOnRefundCreated` +
  `ReleaseAvailabilityOnRefundCreated`.
- F-010 sentinel compliance: uses `Order::whereKey($id)` (PK-only) — no
  `::query()` entry-point.

**Files touched** (commit 1):
- `app/Listeners/PersistOrderPaymentStatusChangedOnRefundCreated.php` (NEW)
- `app/Providers/EventServiceProvider.php` (+8 lines: import + registration)
- `tests/Feature/Refund/RefundBroadcastsPaymentStatusChangedTest.php` (NEW — 3 cases)

**Cited line evidence**:
- `app/Services/PaymentService.php:152` — RefundCreated::dispatch existing, but no OrderPaymentStatusChanged sibling
- `app/Services/Order/RefundWithCounterEntryService.php:229` — same pattern, same gap
- `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php:22-92` — existing outbox listener that the new bridge feeds
- `app/Services/Order/SealedOrderGuard.php:34-64` — assertMutable predicate

## P1 #2 — Loyalty point reversal hole on post-Z refund path

**Audit finding**: PosOrderController::refundWithCounterEntry (sole prod caller
of RefundWithCounterEntryService::execute) did NOT companion-call
LoyaltyService::refundPoints. The 3 cashBack() callers in
OrderService.php:1702/1805 and FrontendOrderService.php:707 correctly
call refundPoints right after cashBack — the post-Z path was a silent
loss vector.

**Heal**: Call `LoyaltyService::refundPoints($parent, 'pos')` inside
the existing `DB::transaction` in `RefundWithCounterEntryService::execute()`,
right before `RefundCreated::dispatch($parent)`. Placement inside the
transaction guarantees atomicity with mirror order + mirror payments
+ audit row.

`refundPoints()` is no-op-safe when `loyalty_customer_code` is null
(LoyaltyService.php:23-25 early-return) — unconditional call is correct
and idempotent.

**Files touched** (commit 2):
- `app/Services/Order/RefundWithCounterEntryService.php` (+19 lines including comment block)
- `tests/Feature/Refund/RefundWithCounterEntryRefundsLoyaltyPointsTest.php` (NEW — 2 cases)

**Cited line evidence**:
- `app/Services/OrderService.php:1702` — refundPoints companion on REJECTED/CANCELED self-cancel
- `app/Services/OrderService.php:1805` — refundPoints companion on RETURNED/CANCELED/REJECTED admin path
- `app/Services/FrontendOrderService.php:707` — refundPoints companion on kiosk path
- `app/Services/LoyaltyService.php:23-25` — early-return when loyalty_customer_code null
- `app/Http/Controllers/Admin/PosOrderController.php:47-91` — refundWithCounterEntry endpoint (NO companion call pre-heal)

## Sentinel Test Results

```
PASS  Tests\Feature\Refund\RefundBroadcastsPaymentStatusChangedTest
  ✓ cashback dispatches order payment status changed and mutates parent
  ✓ counter entry dispatches order payment status changed without mutating parent
  ✓ refund chain persists domain events row for broadcast

PASS  Tests\Feature\Refund\RefundWithCounterEntryRefundsLoyaltyPointsTest
  ✓ counter entry refund credits back redeemed loyalty points
  ✓ counter entry refund without loyalty code is noop safe

Tests:  5 passed
```

## Regression Verification

| Suite | Before | After | Delta |
|-------|--------|-------|-------|
| `--filter Refund` | (baseline) | 34 passed | +5 new |
| `--filter PushNotification\|ReceiptDataServiceWireIn\|MyOrderDetails\|LoyaltyQrSign\|PosLoyaltyRedeem` | 29 passed | 29 passed | 0 |
| `--filter Outbox` | 102 passed | 102 passed | 0 |
| `--filter Order` (broad) | (baseline) | 570 passed | +5 new |
| F-010 sentinel | passed | passed | 0 |

## Frozen-Zone Diff Check

```
git diff app/Services/Fiscal/ app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php
=> 0 lines (empty)
```

POS Vanilla JS wizard, kiosk Vue wizard, audit_logs/z_reports migrations:
all untouched.

## Design Calls Resolved

1. **Sealed-vs-mutable branching in the new listener**: advisor flagged
   the test spec asymmetry — case 1 asserts parent mutation, case 2
   asserts no mutation. SealedOrderGuard::assertMutable used as sealed
   predicate via try/catch (no public `isSealed()` predicate exists).

2. **Broadcast on sealed path**: emit
   `OrderPaymentStatusChanged($parent, currentPaymentStatus, REFUNDED)`
   conceptually rather than echoing the unchanged parent state. Clients
   refetching the parent see PAID + a separate mirror order with
   REFUNDED — both truths coexist; the broadcast carries the intent.

3. **F-010 sentinel**: initial impl used `Order::query()->whereKey($id)`
   which trips the regex even though `whereKey` is PK-safe. Switched to
   `Order::whereKey($id)` (no `::query`) — sentinel green.

4. **Companion loyalty placement**: per advisor, placed inside
   `RefundWithCounterEntryService::execute` rather than the controller
   so the call is atomic with the mirror creation and benefits future
   non-controller callers (admin scripts, future API endpoints).

5. **Two commits vs one**: kept the two heals as separate commits per
   advisor — different concerns (broadcast wiring vs loyalty atomicity),
   different revert surfaces.

## Constraints Honored

- 0 frozen-zone touch (Fiscal services, BranchScope, IdempotencyKeyMiddleware, PricingService, OrderStateMachine, POS Vanilla JS, kiosk Vue wizard)
- 0 DIRTY file touch (PaymentService.php / PosOrderController.php / DashboardService.php untouched — Strategy A bypasses them via NEW listener)
- TDD-first (RED before GREEN on both heals; only `assertStringContains` typo found in test before impl was correct)
- 2 heal commits, 2 sentinel files
- Wall-clock ~50 min (within 1-2h budget)

## Notes for Reviewer

- The listener is registered at the END of `RefundCreated::class`
  listener array — so the existing stock + availability releases run
  first, and the broadcast bridge runs last. Order is meaningful for
  failure isolation: a broadcast hiccup never blocks stock release.
- The bridge listener's `OrderPaymentStatusChanged::dispatch` will
  re-enter `PersistOrderPaymentStatusChangedToOutbox`, which writes a
  fresh `domain_events` row idempotently scoped to the originating
  request (correlation_id-keyed). Verified by case 3 of the broadcast
  sentinel (`refund_chain_persists_domain_events_row_for_broadcast`).
- The post-Z `OrderPaymentStatusChanged` broadcast for a sealed parent
  carries a "synthetic" old/new pair (parent's stored payment_status →
  REFUNDED). Clients refetching the parent will see the order still in
  PAID + a separate mirror RETURN_OF order with REFUNDED — this is the
  NF525-mandated truth. The broadcast exists for UX-immediacy ("a
  refund was just processed on order #N"), not state synchronization.
- Audit canonical: the existing
  `app/Services/PaymentService.php:123-137` writes a
  `payment.cash_back_issued` audit row at refund time (HMAC-chained).
  The new listener's `payment_status` mutation does NOT add a separate
  `order.payment_status_changed` audit row — the cash_back row IS the
  state-change record. Duplicating would be noise.

## Out-of-Scope Follow-ups (NOT for this heal)

- `RefundCreated` listener isolation: Laravel default dispatcher means
  a throw in `ReleaseStockOnRefundCreated` or
  `ReleaseAvailabilityOnRefundCreated` would prevent the new broadcast
  listener from firing (it's the 3rd entry in the listener array). Same
  defect class as the sibling commit `8bdfb7bbd fix(listeners-WF-4-PK1-P1)`
  addressed for `OrderCreated`. Candidate for a follow-up WF-4-style
  unified failure-isolation wrapper. Out of scope for WG-1.
