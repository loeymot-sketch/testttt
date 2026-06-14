# DELIVERY / LIVREUR-CASH coverage gap — AUDIT FINDINGS (supervisor follow-up)

**Date:** 2026-06-14 · **Run:** `wf_ceafb13f-d3d` (10 agents, 2 lanes + 2-skeptic dispute) · raw: `DELIVERY_AUDIT.json`.
**Context:** the supervisor audit flagged the DELIVERY/livreur-cash flow as NEVER E2E-tested. This closes that gap. **Result: the gap hid 1 P0 + 3 P1 — all confirmed 2/2 by adversarial dispute, 0 refuted.**

## ✅ What is SOUND (INFO — verified, no action)
- **Delivery FEE is backend-computed (NF525 SSOT)** and matches the owner rule exactly: 5€ ≤5km + 1€/km whole-km-up from 437 Rue Élie Gruyelle. *(Clears my earlier `PricingService:346 $req->deliveryCharge` concern — the fee is geocoded server-side before the request.)*
- KitchenReleaseRule gates DELIVERY correctly (UNPAID not board-released; PAID released).
- Branch isolation on delivery-boy read endpoints sound; OUT_FOR_DELIVERY/DELIVERED fire OrderStatusChanged→outbox (tracker/OSS)+loyalty with idempotency.
- *(Doc note: the real state path is ACCEPT→PREPARING→**PREPARED**→OUT_FOR_DELIVERY→DELIVERED; my GOAL doc skipped PREPARED — PREPARING→OUT_FOR_DELIVERY is correctly blocked.)*

## 🚩 CONFIRMED FINDINGS → OWNER GATES (NF525 / core-flow / product — not auto-healed)

### G-DELIV-FISCAL — **P0 (NF525 exhaustivity)** — COD delivery escapes the Z
`OrderService.php:1861-2009` (`deliveryBoyOrderChangeStatus` flips `payment_status→PAID` at DELIVERED, **no `FiscalSequenceService::next()` call**) · `ZReportService.php:337-341` (aggregate filters `whereNotNull('fiscal_sequence_no')`) · `FrontendOrderService.php:1345-1351` (auto-alloc gated to KIOSK only).
**Repro:** order id=113 (COD, 27,50€, PAID, DELIVERED) has `fiscal_sequence_no=NULL` → invisible to every daily Z. Cash entered the till, never appears in the fiscal close.
**Why it matters:** NF525 exhaustivity/gap-free violation — a collected sale outside the Z. *(My earlier "legacy data" read was half-right: the existing NULLs are old, but the CODE PATH genuinely never allocates for COD-at-doorstep — I'd only checked the counter-payment path.)*
**Fix (ready):** allocate `fiscal_sequence_no` via `FiscalSequenceService::next(branch_id)` in the COD-DELIVERED branch (OrderService, non-frozen call), with the kiosk path's `fiscal_alloc_error_at` + retry-cron fallback. **NF525-critical → owner gate.**
**V1-LOCAL reachability:** latent unless delivery-boy COD is a live flow; **must fix before delivery goes live.**

### G-DELIV-ORPHAN — **P1** — dispatch with NO assigned driver
`OrderStateMachine.php:65-74` (allows PREPARED→OUT_FOR_DELIVERY/DELIVERED unconditionally) · `OrderService.php:2256-2258` (admin/OSS changeStatus validates only ValidStatusTransition, no `delivery_boy_id` check).
**Repro:** 21 PAID PREPARING delivery orders all have `delivery_boy_id=NULL`; an operator can push OUT_FOR_DELIVERY/DELIVERED with no driver assigned. (The driver self-service path is safe — it requires `delivery_boy_id==auth`.)
**Fix (ready):** guard in `OrderService::changeStatus` (non-frozen) — OUT_FOR_DELIVERY on a DELIVERY order requires `delivery_boy_id IS NOT NULL` else 422. **Product dimension:** does the owner allow batch-dispatch-then-assign? → owner gate.

### G-DELIV-CASH — **P1** — COD cash skipped if no open shift → reconciliation under-counts
`OrderService.php:1954-1986` (mirror into DeliveryBoyCashSession is best-effort, `if ($openSession)` — no shift = no movement, no block) · `DeliveryBoyCashSessionService.php:228-235` (reconcile trusts movements, never cross-checks order-side COD totals).
**Fix (ready):** (a) make DELIVERED-COD strict (422 LIVREUR_SHIFT_NOT_OPEN) — the docblock says this was the intent — OR (b) variance probe in reconcile. **Product decision → owner gate.**

### G-DELIV-REFUND — **P1** — refund of delivered COD nets in neither the session nor the Z
`RefundWithCounterEntryService.php:59` (rejects orders with no `fiscal_sequence_no` → depends on G-DELIV-FISCAL) · `:297` (records to POS CashDrawerService, never DeliveryBoyCashSessionService).
**Fix (ready):** post a compensating OUT movement on the driver's session + route through the fiscal-aware counter-entry mirror (depends on the P0 fix). **Touches frozen Fiscal → owner gate.**

## SUPERVISOR NOTE
The delivery coverage gap was the single highest-value thing left to test — it surfaced a **P0 NF525 exhaustivity gap** that no green test suite would have caught (the COD-at-doorstep path had zero E2E coverage). This is the strongest evidence yet for the supervisor's "untested surface = latent risk" thesis. All 4 are owner-gated (fiscal/core/product); fixes are scoped + ready on owner go.
