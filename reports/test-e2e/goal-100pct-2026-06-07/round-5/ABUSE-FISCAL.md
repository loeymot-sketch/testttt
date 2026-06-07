# ABUSE-FISCAL — Round 5 (GOAL 100%) — Adversarial NF525 fiscal-core break attempt

**Agent:** ABUSE-FISCAL · **Date:** 2026-06-08 · **Clone:** `foodking_e2e` @ :8766 (disposable) · **HEAD:** `6b56e0b5d`
**Mandate:** find ANY hidden way the fiscal ticket / Z / chain can be made wrong on the healed core.
**Verdict:** **PASS — no divergence found.** The H7/refund-netting heals hold under every abuse case driven. 0 P0, 0 P1. 1 P3 policy note. 0 frozen/app drift.

## Method (production-faithful)
- Orders created with the codebase's OWN documented fiscal-test shape (gross pre-discount `order_items.tax_amount`, the exact pattern of `DiscountTicketTvaNettingTest::insertOrderItem` + `OrderService.php:504-562`), allocated a REAL fiscal sequence via `FiscalSequenceService::next`.
- The REAL netting code is then exercised: `OrderDetailsResource::buildTaxLines` (printed ticket), `ZReportService::aggregate`/`close` (the SIGNED Z `total_by_tax_rate`), `RefundWithCounterEntryService::execute` (the mirror), and `php artisan fiscal:verify-chain --all` after each step.
- **Gross-tax pre-check** on every order: persisted line `tax_amount` == rate% extracted from TTC (e.g. 0.91 = 10/1.1 extracted), NOT a pre-netted value → no double-netting fixture artifact (the advisor's named trap).
- **Z isolation** via double-close (flush backlog → create order → close to seal) so each signed Z ≈ my single order. Windows filter on `created_at` with half-open `(opened_at, closed_at]` — verified the window contains exactly the intended order(s).
- Pass criterion = ticket per-rate TVA **rounded to 2dp** == signed-Z `total_by_tax_rate` to the centime; sub-centime raw-vs-rounded deltas are the DESIGNED artifact, not divergence.
- Config confirmed: `pricing.tax_inclusive_prices=true` (TTC mode), `pos.manual_discount_enabled=true` on this clone (discounts reachable here; default-OFF in prod V1 — the F1-dormancy gate `assertDiscretionaryDiscountAllowed`).

## CASE 1 — DISCOUNT BOUNDARIES → PASS (all MATCH)
| scenario | ratio | ticket 10% | signed-Z 10% | verdict |
|---|---|---|---|---|
| disc=0 | 1.0 | 0.91 | 0.91 | MATCH |
| tiny disc 0.01 | 0.999 | 0.91 | 0.91 | MATCH |
| 100%-off, total=0 | 0.0 | 0 | 0 | MATCH (ratio clamp `max(0,…)` → 0 TVA) |
| multi-rate 10%+0% | 0.75 | 0.68 / 0%:0 | 0.68 / 0%:0 | MATCH |
- `discount > subtotal` is REJECTED at creation (`assertPosManualDiscountAllowed`: `$discount > $backendSubtotal` → 422) → unreachable; the `orderDiscountRatio` `max(0,min(1,…))` is verified defense-in-depth (100%-off → ratio 0 → 0 TVA on both ticket and Z).
- **Multi-rate 10%+5,5% is UNREACHABLE from the live catalogue** (taxes table has `VAT 5.5` id=7 but **0 items use it**; all 63 sellable items map to tax_id=1 (No-VAT 0%) or tax_id=3 (10%)). The reachable mix is 10%+0% (main + supplement), driven above = MATCH. The 10%+5.5% engine path is proven by `DiscountTicketTvaNettingTest::test_multi_rate_discount_ticket_allocates_proportionally` (PHPUnit, green). Both ticket and Z apply the SAME order-level ratio to each bucket from the same `order_items.tax_amount` → structurally cannot diverge.

## CASE 2 — ROUNDING (half-centime) → PASS (no divergence, by construction)
| scenario | net target | ticket 10% | signed-Z 10% | verdict |
|---|---|---|---|---|
| 0.91×0.5 | .455 | 0.46 | 0.46 | MATCH |
| 0.05×0.5 | .025 | 0.03 | 0.03 | MATCH |
| 0.15×0.5 | .075 | 0.08 | 0.08 | MATCH |
| 3-line acc ratio 0.667 | — | 0.18 | 0.18 | MATCH |
- For a single-order window ticket and Z use the IDENTICAL formula (`round(ratio×Σtax,2)`) → bit-equal. Confirmed.
- **HT+TVA → TTC reconciliation within the documented ≤1-centime per-bucket artifact**: e.g. net `ht 4.55 + tva 0.46 = 5.01` vs paid bucket TTC 5.00 = exactly 1c (the proportional-netting rounding artifact, NOT worse). Within bound on every bucket.

## CASE 3 — REFUND ABUSE (discounted order) → PASS (the #1 target; negates NET not gross)
Driven: flush-close → create discounted parent (sub 10 / disc 2 / ratio 0.8, gross line tax 0.91) → pay CASH → **seal-close** (parent now post-Z) → `RefundWithCounterEntryService::execute` → close the (contiguous) refund window.
- **Parent signed-Z `by_rate={"10":0.73}`** — NET (post-discount), not gross 0.91. ✓
- **Mirror `order_items.tax_amount = -0.728`** = -(0.91 × 0.8 ratio) = **NET negation**, NOT gross -0.91. (The `RefundWithCounterEntryService:159` `parentRatio` pre-scaling; mirror's negative subtotal makes its own `orderDiscountRatio=1.0`, so the pre-scale is what nets it.) ✓
- **Refund window isolation: order_ids in `(opened_at,closed_at]` = [mirror only]**, parent ABSENT (production-faithful contiguous windows). ✓
- **Refund SIGNED-Z `by_rate={"10":-0.73}` total_tva=-0.73 total_ttc=-8, refund_count=1, verifySignature=true** — the signed Z negates the **NET** -0.73, NOT gross -0.91. The advisor's hypothesised P0 (Z negates gross → over-refund on discounted order) is **DISPROVEN**. ✓
- **Double-refund REFUSED** (`SQLSTATE 23000` UNIQUE `parent_order_id` → 409 MIRROR_ALREADY_EXISTS). ✓
- **fiscal:verify-chain CHAIN OK** after flush / seal / refund-close (every step). Fiscal seq gap-free + monotonic across parent→mirror. ✓

### CASE 3-guards → PASS
- (a) refund **unpaid / no fiscal_sequence_no** → refused 422 ("Parent order has no fiscal_sequence_no"). ✓
- (b) refund a **pre-Z (still-open-window) order** via counter-entry → refused 422 ("not in a CLOSED Z window") — blocks the double-count hazard. ✓
- (c) **refund > paid** is structurally impossible: the mirror reverses the actual persisted `order_payments` 1:1 (negated); it cannot exceed what was paid.

## CASE 4 — NON-CASH + DISCOUNT → PASS (driven through the REAL `confirmCounterPayment`)
Driven the actual money path: built a discounted **PENDING_COUNTER** order with the canonical
counter-deferred marker triple (`source_surface=kiosk`, `payment_method=CASH_ON_DELIVERY(1)`,
`pos_payment_method=COUNTER_DEFERRED(6)`, `fiscal_sequence_no=NULL`) and called
`app(PaymentService::class)->confirmCounterPayment($o, $mode, $received, $note)` (the service behind
`POST /api/admin/pos/counter-collect/{order}/confirm`). Inspected REAL output:

| mode driven | result `pos_payment_method` | fiscal_seq (was NULL) | **cash_movements** | ticket 10% | Z 10% | drawer invariant |
|---|---|---|---|---|---|---|
| CARD | 2 | allocated gap-free | **0** | 0.73 | 0.73 | OK (no drawer line) |
| Ticket-Restaurant | 5 | allocated gap-free | **0** | 0.73 | 0.73 | OK (no drawer line) |
| CASH (no open session) | 1 | allocated gap-free | 0 | 0.73 | 0.73 | known M10-01/CASH-01 skip (marker-surfaced, not a fiscal defect) |
| **CASH (open drawer session)** | 1 | allocated gap-free | **1** (type=order_payment, dir=in, amount=8.00) | 0.73 | 0.73 | **OK — exactly 1 drawer IN** |

- The drawer discriminator is now PROVEN, not asserted: **CARD/TICKET write 0 cash_movements**; **CASH with an open session writes exactly 1** (`order_payment`, direction IN). Each allocates the fiscal sequence on confirm (gap-free), and the printed-ticket per-rate TVA == signed-Z `total_by_tax_rate` (0.73) regardless of mode. ✓
- (Direct counter-collect does not write an `order_payments` row on this path — by design the payment is carried by `pos_payment_method` + the cash `cash_movement`; matches the harness note "POS direct-cash payment is NOT in order_payments".)

## CASE 5 — NULL-TAX path (G7) → PASS (re-confirmed CLOSED, no new hole)
- Live catalogue: **0 items with NULL `tax_id`** (`OrderService.php:501` defaults a missed tax lookup to rate `0`, never NULL). All items → tax_id 1 (No-VAT 0%) or 3 (VAT 10%).
- A reachable **0%-rate line** (No-VAT supplement) appears in BOTH the ticket (a "0" bucket, tva=0) AND the Z (`"0":0`) — harmless, no divergence (`taxBreakdownForOrders`'s `whereNotNull('tax_rate')` passes a `0` rate; a `0` line contributes 0 TVA).
- The G7 silent-0% concern is **not reachable via any normal sellable item** — re-confirmed. (The genuinely-NULL `tax_id` path lives only in the frozen `PricingService` and is the already-GATED owner-policy item G7, NOT an autonomous heal.)

## Final integrity (whole clone, MCP-independent)
- Branch-1 fiscal sequence after all abuse + cleanup: **max=2067, count=2067, gap_count=0, dup_count=0**.
- `fiscal:verify-chain --all` = **CHAIN OK**. Z HMAC chain `verifyChain` = `valid:true, 0 errors`, **0 dangling OPEN Z**.
- **Orthogonal clean-path** (sqlite :memory: PHPUnit): `DiscountTicketTvaNetting | ZReportDiscountNetting | RefundWithCounterEntry | RefundCounterEntry | ZReportRefund | RefundMirror | ZReportClose` = **31 tests / 133 assertions GREEN**. Two independent layers agree.

## Scope notes (coverage honesty)
- **Case 3 drove `RefundWithCounterEntryService::execute` directly**, not the `POST /api/admin/pos-order/{id}/refund-with-counter-entry` controller. The fiscal logic (mirror netting, signed-Z negation, gap-free seq, chain) is fully exercised; the controller layer (`pos-refund` permission gate, cross-branch 403, the QueryException 23000→409 `MIRROR_ALREADY_EXISTS` mapping) was READ, not driven — the double-refund here hit the DB UNIQUE at the service layer, not the controller's 409 conversion. Fine for the fiscal mandate; flagged so the report doesn't imply HTTP-endpoint coverage.
- **Case 1 multi-rate 10%+5.5%** was validated by the codebase's PHPUnit `DiscountTicketTvaNettingTest::test_multi_rate_discount_ticket_allocates_proportionally` (green), not driven live — because **no live item uses the 5.5% rate** (engine-equivalence argument given above). The live-reachable mix (10%+0%) WAS driven.

## Drift / hygiene
- **0 frozen-zone or app-code edits** — `git diff --stat` over ZReportService / FiscalSequenceService / AuditLogService / PricingService / OrderDetailsResource / RefundWithCounterEntryService = empty.
- All abuse rows cleaned up **LIFO** (highest fiscal_seq first) → branch-1 sequence restored gap-free (max=2067, gap=0), test cash-drawer session removed, CHAIN OK. Non-app test harness artifacts (`storage/abuse_cases.php`, `/tmp/*.php`) removed post-run.
- **CLEANUP CAVEAT (clone-only):** deleting fiscally-allocated orders — including some that were aggregated into signed Z closes — is acceptable ONLY because `foodking_e2e` is a disposable clone reset by re-clone. On the OPERATING `foodking` DB this would create fiscal-sequence gaps and violate NF525 6-year retention. Do NOT copy this delete pattern onto the operating chain.

## P3 (policy note, not a defect)
On a multi-rate discounted order the discount ratio nets the HT base of a **0%-VAT (No-VAT supplement) line** proportionally too (e.g. a 2.00 supplement → 1.50 HT base after ratio 0.75). Ticket==Z (internally consistent, 0 fiscal impact since its TVA=0). Whether a discount should mathematically spread onto a no-VAT supplement line is an owner receipt/accounting **preference**, not a fiscal divergence.
