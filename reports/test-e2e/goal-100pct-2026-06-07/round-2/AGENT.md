# AGENT CLUSTER-PAY — Round 2 — non-CASH money paths + refund + discount→VAT

**Date:** 2026-06-07 · **DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766
**Scope:** the NEVER-TESTED money paths (round-1 validated CASH only).
**Verdict:** Checks 1-5 **PASS**. Check 6a (Z) **PASS**. Check 6b = **P1 (CP-1)** printed-ticket TVA not netted on discounted orders → **agent pass=FALSE, blocker=CP-1** (audit role: reported for supervisor to sequence the heal).
**Baseline:** chain OK · audit_logs=2828 (lastId 2829) · max fiscal_seq br1=2019.
**Final state:** chain **OK** after all mutations; fiscal sequence **gap-free** (br1 1..2024, 0 missing, 0 dup).

---
## Data-model fact (load-bearing — resolves the spec's "OrderPayment" wording)
`PaymentService::confirmCounterPayment` (app/Services/PaymentService.php:193-456) is the
counter-collect path for kiosk PENDING_COUNTER orders. It does **NOT** write an
`OrderPayment` row. It writes:
- `order.pos_payment_method = $mode`, `payment_status = PAID(5)`, `fiscal_sequence_no` allocated
- exactly 1 `Transaction(order_id, type='payment')`, `payment_method = counter_<mode>` (PaymentService.php:384)
- 1 audit row `order.counter_payment_confirmed` (PaymentService.php:397)
- **CashMovement ONLY when mode===CASH** (PaymentService.php:450). CARD/TICKET/MOBILE never touch the drawer.

So "1 OrderPayment method=CARD" maps onto the **Transaction** row + `order.pos_payment_method`;
the substantive invariant is **0 CashMovement** (no drawer inflation), verified directly below.
Confirmed no backfill: `PersistOrderPaidAtCounterToOutbox` creates no OrderPayment.

---
## Checks 1-3 — CARD / TICKET-RESTAURANT / MOBILE  → **PASS**
Driven via authenticated axios by specific order id + X-Idempotency-Key
(`tests/e2e/zz-cluster-pay-nonash-2026-06-07.spec.js`, 1 passed), then DB-verified.

| Check | Order | mode | HTTP | payment_status | pos_payment_method | fiscal_seq | Transaction | OrderPayment | CashMovement | audit |
|-------|-------|------|------|----------------|--------------------|-----------|-------------|--------------|--------------|-------|
| (1) CARD | #4213 | 2 | 200 | 5 PAID | 2 | 2020 | 1 (counter_card, +8.50) | 0 | **0** | 1 (user_id=1, method=2) |
| (2) TICKET | #4212 | 5 | 200 | 5 PAID | 5 | 2021 | 1 (counter_ticket_restaurant, +3.00) | 0 | **0** | 1 (user_id=1, method=5) |
| (3) MOBILE | #4211 | 3 | 200 | 5 PAID | 3 | 2022 | 1 (counter_mobile_banking, +10.00) | 0 | **0** | 1 (user_id=1, method=3) |

- `pos_received_amount = NULL` for all three (cash-only field) — correct.
- Operator stamped `operator_name="Admin Le Cayenne"` (editor_id=1), NOT "Client passage" — kiosk→counter operator-identity fix holds for non-CASH.
- `fiscal:verify-chain --all` → CHAIN OK after the batch.
- **Z bucketing (F6):** core Z `total_by_method` reads `order.pos_payment_method` for single-tender
  orders that have no OrderPayment (ZReportService.php:697-698), so a counter-collected CARD sale
  IS attributed to the CARD bucket — proven below (z#9 `total_by_method={"2": 8}`). The OrderPayment
  absence only skips the optional card-FEE enrichment (ZReportCashEnrichmentService), not core revenue.

---
## Check 4 — receipt payment-mode label (CARD ≠ "Espèces")  → **PASS** (+1 P3)
Path: `ReceiptComponent.vue:225 paymentMethodLabel(method)` → `posPaymentMethodEnumArray` (391-396):
CARD(2)=`label.card`, MOBILE_BANKING(3)=`label.mobile_banking`, 5=`label.ticket_restaurant`, CASH(1)=`label.cash`.
FR (resources/js/languages/fr.json): card="Carte", cash="Espèces", mobile_banking="MFS", ticket_restaurant="Ticket Restaurant".
Rendered evidence (`zz-cluster-pay-showlabel-2026-06-07.spec.js`, 3 passed, pos-orders show page):
- #4213 CARD → "Type de paiement: **Carte**" (NOT Espèces) ✅
- #4211 MOBILE → "Type de paiement: **MFS**" ✅
- #4212 TICKET → "Type de paiement: **(blank)**" — **P3 finding** (below). Actual POS receipt label = source-verified (ReceiptComponent index 5 → "Ticket Restaurant"); the receipt MODAL capture did not render in the harness, so the printed-ticket TR label is source-verified, not render-verified.

**P3** — `PosOrderShowComponent.posPaymentMethodEnumArray` (resources/js/components/admin/posOrders/PosOrderShowComponent.vue:589-596) is missing index 5 (TICKET_RESTAURANT) → the admin **order-show page** shows a blank payment-type label for Ticket-Restaurant orders. The legal POS **ReceiptComponent** (the printed ticket) DOES have index 5, so the ticket is correct; only the admin summary line is blank. Cosmetic.

---
## Check 5 — REFUND (NF525 counter-entry)  → **PASS** (service-driven)
Scenario (real Z lifecycle on the clone): opened Z1 → built+encashed a sealed CARD parent #4225
inside Z1 → closed Z1 → opened Z2 → refunded #4225 → closed Z2.
Drove `RefundWithCounterEntryService::execute` directly (the **service** behind the "Rembourser"
button, wiring: PosOrderShowComponent.vue:184-185 → POST `/api/admin/pos-order/{id}/refund-with-counter-entry`
→ `PosOrderController::refundWithCounterEntry`). NOTE: service-driven, not button-driven — the
controller-layer authz (`pos-refund`), idempotency middleware, and 409 MIRROR_ALREADY_EXISTS handler
were NOT exercised here; the NF525 fiscal substance was.

Evidence:
- **Mirror #4226**: serial `RTN-CP-SEAL-…` (the **remboursement marker** — data-verified via serial + RETURNED/REFUNDED status, not render-verified), status=22 RETURNED, payment_status=20 REFUNDED, fiscal_seq=**2024** (fresh — NOT a gap), total=**−8.00**, parent_order_id=4225.
- **Parent #4225 IMMUTABLE**: status unchanged, fiscal_seq=2023 preserved, total=+8.00. (NF525: parent never mutated.)
- **Fiscal sequence PRESERVED + gap-free** incl. mirror: br1 min=1 max=2024 count=2024 **missing=0 dups=0**.
- **The refund discriminator (the verdict-flipping number):** parent Z (z#9) `total_by_method={"2": 8}`; mirror Z (z#10) `total_by_method={"2": -8}`. CARD bucket is correctly NEGATED on the mirror (no double-negation). Net across the two Z = +8 −8 = 0. No method/drawer inflation.
- mirror Z (z#10): total_ttc=−8.00, total_tva=−0.73, total_by_tax_rate={"10": −0.73}, refund_count=1.
- audit `order.refund.counter_entry` #2841 (parent=4225, mirror_seq=2024, mirror_total=−8, reason captured).
- **chain OK** after the full refund cycle.
- OrderPayment note: a counter-collected parent has 0 OrderPayment, so the mirror gets negated
  total/tax but no per-mode OrderPayment row. This is **expected, not a defect** — the Z nets via the
  `pos_payment_method` fallback (proven above). A literal refund OrderPayment row only appears when
  the parent was split/POS-paid (RefundWithCounterEntryService.php:192-221).

---
## Check 6 — DISCOUNT → VAT (the historical coupon-VAT-10 area)
### 6a — Z report (fiscally-binding artifact) → **PASS (CORRECT)**
Parent Z (z#9) for the discounted #4225 (subtotal 10.00, discount 2.00, total 8.00):
`total_ht=7.27 · total_ttc=8.00 · total_tva=0.73 · total_by_tax_rate={"10": 0.73}`.
TVA is netted to the **post-discount** base: ratio (10−2)/10=0.8 → 0.91×0.8≈0.73. The signed Z is
fiscally correct (matches the locked `ZReportDiscountNettingTest` SSOT: net 0.73, NOT pre-discount 0.91).
The historical coupon-VAT-10 P0 (wrong Z on discounted order) is FIXED — `pos.manual_discount_enabled`
default flipped false→true on 2026-05-31 precisely because F1 is fixed (config/pos.php:161-176).

### 6b — printed RECEIPT tax_lines → **P1 finding (CP-1)** — Z is correct, the legal TICKET is not netted
PRIMARY evidence (source, rejection-proof — `pos.manual_discount_enabled` default is **ON**
(config/pos.php:172-176), so real discounted orders exist in V1):
- `OrderService.php:504-522` — per-line `tax_amount` computed from the **pre-discount** line total; the coupon discount is applied AFTER, at order level only.
- `OrderService.php:562` — order header `total_tax = Σ pre-discount per-line tax` (gross).
- `OrderDetailsResource.php:211-250 buildTaxLines()` — sums raw per-line `tax_amount`, applies **no** discount-netting ratio (unlike `ZReportService::orderDiscountRatio` and `RefundWithCounterEntryService`, which both net). That asymmetry IS the defect.
- `ZReportDiscountNettingTest.php:79-84` — canonical real shape: a discounted order stores header `total_tax=0.91` (gross) and per-line tax_amount=0.91; only the Z nets to 0.73.
- `ReceiptComponent.vue:172-191` — the printed ticket renders header `total_tax_currency_price` (line 177) AND per-rate `tax_lines` with `tax_rate`%, `base_ht_currency`, `tax_currency` (lines 180-191), then a discount row (193-196) and total (205-211).

**Precise defect (NOT an arithmetic break):** the bill reconciles — subtotal 10.00 − discount 2.00 = total 8.00 (the discount row at line 193 makes it sum). The defect is that the printed ticket's **per-rate TVA line shows 0.91 and base_HT 9.09 (pre-discount), while the TVA actually contained in the paid 8.00 is 0.73 on a post-discount HT of 7.27** (per the signed Z and the legal post-discount base). On a discounted order the fiscal ticket therefore **overstates the per-rate TVA/HT** — the binding Z nets it, the printed ticket does not.

**Severity = P1.** Under NF525 the **printed ticket is itself a fiscal document**; a wrong per-rate TVA on it is not neutralized by a correct Z. It is the GOAL's #1 deliverable ("le ticket") and lands in the exact historical coupon-VAT-10 P0 area. It manifests whenever a discount/coupon/loyalty is applied (flag ON by default). Mitigations to note (do NOT downgrade the finding): `POS_MANUAL_DISCOUNT_ENABLED=false` is the documented kill-switch that disables all discounts and eliminates the case; and the signed Z (the period fiscal declaration) is correct. Heal direction (supervisor to sequence): apply the same `orderDiscountRatio` netting in `buildTaxLines` that ZReportService/RefundWithCounterEntryService already use.
(No order-level reproduction included: the synthetic #4225 is unrepresentative — its authored header 0.73 ≠ lines 0.91, whereas a real order is 0.91/0.91 — so it is intentionally NOT cited as proof. Proof rests on the source chain above + the locked test.)

---
## Frozen-zone / cleanup
- Edited **0** source/frozen files. Added only: `tests/e2e/zz-cluster-pay-{nonash,receipt,showlabel}-2026-06-07.spec.js` + report.
- Test orders on the **disposable clone**: #4211/4212/4213/4225 PAID+fiscalised, mirror #4226, Z#9/#10.
  These are append-only NF525 rows now in the verified HMAC chain — **deleting them would break the
  chain** I confirmed OK. On the disposable clone, leaving them (chain integrity) is the correct call.
  Scratch PHP scripts removed.

## Findings summary
| # | Sev | Title | Location | Verdict impact |
|---|-----|-------|----------|----------------|
| CP-1 | **P1** | Discounted-order printed ticket per-rate TVA/HT NOT netted (shows pre-discount 0.91/9.09; collected TVA in the paid 8.00 is 0.73). Printed ticket = NF525 fiscal doc; Z report is correct, the ticket is not. | OrderDetailsResource.php:211-250 (buildTaxLines, no netting ratio) · rendered by ReceiptComponent.vue:180-191 | **Check 6b — BLOCKER (CP-1).** Heal: apply orderDiscountRatio in buildTaxLines. Mitigation: POS_MANUAL_DISCOUNT_ENABLED=false kill-switch. |
| CP-2 | **P3** | Admin order-show page blank payment label for Ticket-Restaurant (enum missing index 5); legal receipt correct | PosOrderShowComponent.vue:589-596 | Check 4 cosmetic |
