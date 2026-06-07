# HEAL-H7 — CP-1 (P1) discounted-ticket TVA netting + CP-2 (P3) TR label

**Date:** 2026-06-07 · **Agent:** HEAL-H7 · **DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766
**Verdict:** CP-1 **FIXED + proven** (backend + live render + Z-equality). CP-2 **FIXED + proven** (live render).
**Frozen-clean:** 0 frozen files touched (FrozenZoneSha256BaselineSentinel **1/1, 5 assertions**).
**Open decision surfaced for supervisor:** per-LINE tax/HT on the ticket are still gross and now visually disagree with the (correctly) netted summary — see §"Open decision". CP-1 scope was `buildTaxLines` + header; this is OUT of that scope and is a supervisor ruling, not a silent ship.

---

## Defect recap (CP-1)
On a discounted order, `OrderService` stores the **pre-discount** per-line `tax_amount`; the coupon/loyalty discount is applied at order level only (`OrderService.php:504-562`). The signed Z and the refund counter-entry both **net** this by `orderDiscountRatio = (subtotal − discount)/subtotal` (`ZReportService::orderDiscountRatio` + `taxBreakdownForOrders`; `RefundWithCounterEntryService`). `OrderDetailsResource::buildTaxLines` summed the raw per-line `tax_amount` with **no** ratio → the printed NF525 ticket showed gross 0,91 / 9,09 while the paid 8,00 contains TVA 0,73 on HT 7,27 (the signed Z value). The printed ticket is itself a fiscal document, so its per-rate TVA/HT must equal the collected/Z TVA.

## Files changed (2 source + 1 phpunit test + 2 e2e specs)
| File | Frozen? | Change |
|------|---------|--------|
| `app/Http/Resources/OrderDetailsResource.php` | NO (verified vs memory/reference_frozen_zones.md + CLAUDE.md §7) | +77/-5: added `orderDiscountRatio()` (mirrors the frozen ZReportService formula EXACTLY — do not diverge), netted per-rate `base_ht`+`tax` in `buildTaxLines()`, derived header `total_tax` + `subtotal_without_tax_currency_price` from the SAME netted buckets (computed ONCE, reused). |
| `resources/js/components/admin/posOrders/PosOrderShowComponent.vue` | NO | +5: added `[posPaymentMethodEnum.TICKET_RESTAURANT]: $t("label.ticket_restaurant")` (CP-2). |
| `tests/Feature/Receipt/DiscountTicketTvaNettingTest.php` | new | 4 tests, asserts the `toArray()`-exposed header + per-rate, real-shaped + multi-rate + non-discount, EQUALS the Z. |
| `tests/e2e/zz-heal-h7-discount-ticket-tva-2026-06-07.spec.js` | new | renders the `#print` modal (emulateMedia print), bound screenshot 7s. |
| `tests/e2e/zz-heal-h7-cp2-tr-label-2026-06-07.spec.js` | new | CP-2 TR label on the admin show page. |

### Design decision (advisor-gated) — header derived from buckets, NOT `order.total_tax × ratio`
`order.total_tax` is **gross** on a real production order (`OrderService.php:562`) but is **already netted** on the synthetic clone row #4225 (header 0,73, lines 0,91). Scaling `order.total_tax × ratio` would double-net #4225 (0,73×0,8=0,58) and break the ticket. Deriving the header from the per-line **gross** tax × ratio (= Σ rounded netted buckets) is correct for **both** shapes and is the exact construction the signed Z uses for `total_tva` (array_sum of buckets) → ticket header == Σ tax_lines == Z. `subtotal_without_tax` rebased from `subtotal − total_tax` to `total − netted_total_tax` (= the Z's `total_ht = total_ttc − total_tva`); on a non-discount order `total == subtotal`, so it is unchanged in practice.

> Note on "non-discount unchanged": the value is now **recomputed from the per-line tax** rather than read from the `total_tax` column. For app-created orders these are equal (Σ line `tax_amount` == header `total_tax`), confirmed on #4160 → 0,14 / 1,36. Strictly: equal-in-practice for real orders, not a literal column passthrough.

## before / after — order #4225 (subtotal 10,00 · discount 2,00 · total 8,00 · VAT-10)
| field | BEFORE (gross, defect) | AFTER (netted) | Z (z#9) |
|-------|------------------------|----------------|---------|
| per-rate `tax` | **0,91** | **0,73** | 0,73 |
| per-rate `base_ht` | **9,09** | **7,27** | (HT 7,27) |
| header `total_tax` | 0,73* | **0,73** | total_tva 0,73 |
| `subtotal_without_tax_currency_price` | 9,27** | **7,27 €** | total_ht 7,27 |

\* header on #4225 was coincidentally already 0,73 (synthetic row) — which is exactly why the header fix is proven by the phpunit test on a **real-shaped** order (gross 0,91 header + 0,91 lines), not by #4225.
\*\* pre-fix `subtotal − total_tax` = 10,00 − 0,73 = 9,27 (neither gross 9,09 nor net 7,27 — a third wrong number).

## ticket == Z proof
- phpunit `DiscountTicketTvaNetting`: per-rate ticket TVA `EQUALS z['total_by_tax_rate']['10']` and header `EQUALS z['total_tva']` (asserted by running the real `ZReportService::aggregate` against the same order). **4/4, 21 assertions.**
- live backend render (clone): #4225 → `total_tax=0,73 / subtotal_without_tax=7,27 € / tax_lines tax=0,73 base_ht=7,27`. Matches z#9 `total_tva=0,73 total_by_tax_rate={"10":0,73}`.
- live rendered `#print` ticket DOM (#4225): `Sous-total: 7,27 € · Total taxes: 0,73 € · VAT (10%) · Base HT 7,27 € … 0,73 € · Remise: 2,00 € · Total: 8,00 €`.

## non-discounted control #4160 (1,50 TTC, VAT-10) — UNCHANGED
- backend: `total_tax=0,14 / subtotal_without_tax=1,36 € / tax_lines tax=0,14 base_ht=1,36`.
- live `#print` DOM: `Total taxes: 0,14 € · VAT (10%) · Base HT 1,36 € … 0,14 €`. ratio=1.0 preserved.

## CP-2 (P3) — TR label
`PosOrderShowComponent.posPaymentMethodEnumArray` was missing index 5 (TICKET_RESTAURANT) → blank label on the admin order-show page. (Spec's claim that index 4 OTHER was also missing is **wrong** — line 594 already had OTHER; only index 5 added, mirroring `ReceiptComponent.vue`.) Live proof: #4212 admin show page now reads **"Type de paiement: Ticket Restaurant"** (was blank).

## Verification matrix
| Check | Result |
|-------|--------|
| `vendor/bin/phpunit --filter DiscountTicketTvaNetting` | **OK 4/4, 21 assertions** |
| locked SSOT `ZReportDiscountNetting` (frozen-service, must stay green) | **OK** (within the 12/12 + 15/15 + 24/24 regression batches below) |
| regression `TaxLine\|ReceiptData\|OrderDetailsResource\|Vat10ZReconciliation\|ZReportTaxBreakdown` | **OK 15/15** (then 24/24, 110 assertions incl. the new test) |
| e2e `zz-heal-h7-discount-ticket-tva` (print-media render, :8766) | **2/2 passed** (0,73/7,27 present; 0,91/9,09/9,27 absent; #4160 0,14) |
| e2e `zz-heal-h7-cp2-tr-label` (:8766) | **1/1 passed** ("Ticket Restaurant") |
| `vendor/bin/phpunit --filter FrozenZoneSha256BaselineSentinel` | **OK 1/1, 5 assertions** |
| source diff (`app/** resources/js/** tests/**`) | 2 source + 3 test files; **0 of the 13 frozen files** |
| NF525 chain on clone (`fiscal:verify-chain --all`, read-only) | **CHAIN OK** (no fiscal rows mutated — read-only renders only) |

## Open decision for the supervisor (advisor-caught; OUT of CP-1 scope)
The printed ticket's **per-LINE** tax/HT come from `OrderItemResource` (`tax_currency_amount`, `total_without_tax_currency_price`), which `buildTaxLines` does **not** touch. On #4225 the lines still print **gross**: `Sandwich 6,36 € VAT 0,64 €` + `Sprite 2,73 € VAT 0,27 €` → lines sum **0,91 / 9,09**, directly above the now-netted `Sous-total 7,27 / Total taxes 0,73`. The ticket is now **internally inconsistent** (lines 0,91 ≠ summary 0,73). Before this fix the whole ticket was 0,91 (self-consistent, but disagreed with the Z); after, the summary agrees with the Z but disagrees with its own lines.

This does **not** affect the CP-1 fix (which named only `buildTaxLines` + header and is correct) nor any fiscal binding artifact (the Z is and was correct). It is a **ticket-presentation** decision the supervisor must rule on — likely answers:
1. Net the line items too (lines → 0,51 + 0,22, HT 5,09 + 2,18) so the whole ticket reads coherently — BUT then list prices change (a 7€ sandwich prints ~5,6€, usually undesirable).
2. Keep lines at list price; show the discount per-line or suppress per-line tax so nothing contradicts the netted summary.
3. Accept summary-only netting as the intended CP-1 scope and the line/summary visual mismatch.

Flagging, not burying. Recommendation: option 3 (CP-1 text says "per-rate" + "header" = a defensible summary-only scope) with a follow-up ticket for line presentation if the supervisor wants ticket-internal coherence.

## Cleanup
Added only: 1 phpunit test + 2 e2e specs + this report. No fiscal rows mutated on the clone (read-only renders). Scratch probe test removed. `nettedTotalTax()` helper inlined (compute-once) and removed to avoid two code paths.
