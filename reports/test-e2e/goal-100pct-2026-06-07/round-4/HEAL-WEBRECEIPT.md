# HEAL-WEBRECEIPT — on-screen receipt per-LINE tax suppression (NF525 coherence)

**Date:** 2026-06-07 · **Agent:** HEAL-WEBRECEIPT (last non-owner-gated GOAL-100% item)
**DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766 · **Operating DB `foodking` never touched.**

## VERDICT
- **Per-line tax suppressed?** YES — on BOTH on-screen receipt components.
- **Netted per-rate summary intact?** YES — unchanged (H7's `tax_lines` 0,73 / HT 7,27 == signed Z still renders).
- **Non-discount (#4160) unchanged?** YES — summary + line price render, no layout regression.
- **Frozen-clean?** YES — `FrozenZoneSha256BaselineSentinel` **1/1, 5 assertions**; `git diff --name-only HEAD` over all 13 §7 files = **0**.
- **Spec green?** YES — new `zz-heal-webreceipt-coherence` **2/2**; existing `zz-heal-h7-discount-ticket-tva` still **2/2** (no regression); receipt Vitest **39/39**.
- **Mix build:** compiled successfully (full `npx mix --production`).

NOT GATED — a clean suppression was possible without an owner layout decision. The change removes the H7-surfaced line-vs-summary contradiction on the **two POS receipt components that carry the H7 netted summary**, by mirroring the physical ESC/POS paper (which itemizes no per-line tax). One PRE-EXISTING residual (line-price vs netted-subtotal) is surfaced below, not introduced by this edit, not blocking.

**Scope precision (verified repo-wide):** 4 OTHER on-screen receipt components also render per-line `tax_currency_amount`, but NONE of them render the H7 netted `tax_lines` summary — so on those surfaces there is NO line-vs-summary contradiction (per-line gross tax is their sole TVA breakdown, self-consistent). They are deliberately LEFT UNTOUCHED (removing per-line tax there would delete their only per-rate VAT breakdown — an NF525 regression, not a fix). See §"Repo-wide scope verification". The accurate headline claim is therefore: *the two POS receipt components now mirror the paper*, not "all on-screen receipts".

---

## The defect (from HEAL-H7 §"Open decision for the supervisor")
H7 netted the on-screen receipt's per-RATE TVA *summary* + header to the post-discount base (== signed Z). But the per-LINE tax (`item.tax_currency_amount`) is GROSS (pre-discount, stored by `OrderService`). On a discounted order the per-line taxes summed ABOVE the netted summary — a screen-internal contradiction:

- **#4225 (subtotal 10,00 · discount 2,00 · total 8,00 · VAT-10):** lines printed `Sandwich 6,36 € VAT 0,64 €` + `Sprite 2,73 € VAT 0,27 €` → line taxes **0,64 + 0,27 = 0,91 (gross)**, directly above the netted summary **0,73**. The summary agreed with the Z but disagreed with its own line items.

The physical ESC/POS ticket (`PosReceiptEscPosRenderer`, G5 branch) prints **NO per-line tax** — only the netted per-rate ventilation summary (verified: renderer prints per line `name + qty + total_price`; `taxLines()` is the sole tax surface, netted). The on-screen receipt is the only surface that still itemized per-line gross tax.

## Fix (minimal — remove the per-line tax `<div>` only)
Suppress the per-LINE tax block so the on-screen receipt mirrors the paper: line = **description + qty + price**; the NF525-required per-RATE ventilation (`order.tax_lines`, netted == signed Z) stays in the totals block. **Line prices unchanged. Netted summary untouched. No backend touched.**

NF525 note: the law (CGI art. 242 nonies A) requires per-*rate* HT base + tax (the summary we keep), **not** per-*line* tax — so removing the per-line tax is legally fine.

## Files changed (2 source + 1 new spec)
| File | Frozen? | Change |
|------|---------|--------|
| `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue` | NO (verified vs CLAUDE.md §7 + memory/reference_frozen_zones.md) | −4 rendered (`v-if="item.tax_rate > 0"` per-line tax `<div>` w/ `tax_name`/`tax_currency_rate`/`tax_type`/`tax_currency_amount`) +explanatory comment. **Renders `/admin/pos-orders/show/{id}` `#print` modal.** |
| `resources/js/components/admin/pos/ReceiptComponent.vue` | NO (this is `admin/pos/ReceiptComponent.vue`, distinct from frozen `admin/pos/PaymentComponent.vue`) | identical per-line tax `<div>` removed + comment. **Live POS post-payment modal `#print-receipt-client` (`/admin/pos`).** |
| `tests/e2e/zz-heal-webreceipt-coherence-2026-06-07.spec.js` | new | 2 tests — #4225 suppression (discriminating) + #4160 control. |

`git diff --stat HEAD` (source): 2 files, +24 / −12 (insertions are the doc comment; the rendered per-line tax block is removed).

## before / after — #4225 `#print` textContent (DISCOUNTED, the only discriminating case)
**BEFORE** (per-line gross tax contradicts netted summary):
```
… Qté Article Description Prix
1 Sandwich Cayenne 6,36 € VAT (10.00 %) 0,64 €
1 Sprite 33cl 2,73 € VAT (10.00 %) 0,27 €
Sous-total: 7,27 € Total taxes: 0,73 € VAT (10%) · Base HT 7,27 € 0,73 €
Remise: 2,00 € Total: 8,00 € …
```
**AFTER** (per-line tax gone; netted summary + line prices intact):
```
… Qté Article Description Prix
1 Sandwich Cayenne 6,36 €
1 Sprite 33cl 2,73 €
Sous-total: 7,27 € Total taxes: 0,73 € VAT (10%) · Base HT 7,27 € 0,73 €
Remise: 2,00 € Total: 8,00 € …
```
- GONE: `0,64`, `0,27` (per-line gross tax), `VAT (10.00 %)` per-line label.
- KEPT: `0,73` (summary tax), `7,27` (net HT), `6,36` + `2,73` (line prices), `2,00` (remise), `8,00` (total) — all string-asserted in the spec.

## non-discount control #4160 (1,50 TTC, VAT-10) — no regression
AFTER: `1 Coca-Cola 33cl 1,36 €` + instruction line; `Sous-total: 1,36 € · Total taxes: 0,14 € · VAT (10%) · Base HT 1,36 € · 0,14 € · Remise: 0,00 € · Total: 1,50 €`. Per-line `VAT (10.00 %) 0,14 €` removed; summary + line price + total intact.
(Note: on a non-discount order per-line tax 0,14 == summary 0,14, so the string cannot *prove* suppression on #4160 — #4225 is the discriminating proof; #4160 only confirms the summary + line price still render.)

## Verification matrix
| Check | Result |
|-------|--------|
| `zz-heal-webreceipt-coherence` (clone :8766, `#print` print-media textContent) | **2/2** — 0,64/0,27/`VAT (10.00 %)` absent on #4225; 0,73/7,27/6,36/2,73/2,00/8,00 present; #4160 summary intact |
| `zz-heal-h7-discount-ticket-tva` (re-run, must stay green = summary untouched) | **2/2** |
| Vitest receipt suite (`receiptAddonsRenderingSentinel` renders ReceiptComponent + `posReceiptPrintFlow` + 2 markers) | **39/39, 4 files** |
| `vendor/bin/phpunit --filter FrozenZoneSha256BaselineSentinel` | **OK 1/1, 5 assertions** |
| `git diff --name-only HEAD` over 13 §7 frozen files | **0 files** |
| `npx mix --production` (full build — admin-shell.js + pos-shell.js rebuilt) | **compiled successfully** |
| Backend / fiscal | **0 backend files touched** — netted summary fed by H7's `OrderDetailsResource` (untouched); NF525 chain not exercised (read-only renders) |

## PRE-EXISTING residual surfaced (NOT introduced here, NOT blocking)
After suppression, the visible line PRICES (`total_without_tax_currency_price`) remain **gross HT** (6,36 + 2,73 = **9,09**) and do not sum to the H7-netted subtotal (**7,27**) on a discounted order. This gap is **pre-existing**: H7 netted the subtotal; line prices are gross and the task explicitly forbids changing them. This edit does NOT introduce it — it only removes the line-tax-vs-summary contradiction.

Crucially, this matches the **physical paper**: `PosReceiptEscPosRenderer` prints per-line `total_price` (gross line price) + a netted per-rate summary — i.e. the paper ALSO shows gross line prices above a netted summary. So the on-screen receipt now mirrors the paper exactly (no per-line tax, gross line prices, netted per-rate summary). Reconciling line price to the netted base would change displayed list prices (a 7€ item printing ~5,6€) — out of scope and undesirable per H7's own analysis (its option 1). Flagging per `feedback_advisor_check_fiscal_doc_consistency`, not fixing, not blocking.

## Repo-wide scope verification (the discriminating check)
`grep -rn "tax_currency_amount\|item.tax_rate > 0" resources/js/components/` found per-line tax in **6** components total: the **2 healed** here, plus **4 others**:
- `frontend/account/myOrder/FrontendOrderReceiptComponent.vue` (customer "my orders" receipt)
- `admin/tableOrders/TableOrderReceiptComponent.vue`
- `admin/onlineOrders/OnlineOrderReceiptComponent.vue`
- `table/order/OrderReceiptComponent.vue`

Decisive distinction (verified): **none of the 4 render `order.tax_lines` (the H7 netted per-rate summary) — confirmed by grep returning zero `tax_lines`/`base_ht` across all 4.** Their totals block has only `subtotal` + a single `total_tax` line. So on those surfaces the per-line gross tax is the **sole** VAT breakdown → no line-vs-summary contradiction (the H7 defect does not exist there; they are self-consistent).

Therefore they are **intentionally LEFT UNTOUCHED**:
1. There is no contradiction to remove (none has the netted summary that conflicts).
2. Removing per-line tax there would leave the receipt with **no per-rate VAT breakdown at all** on a discounted order — an NF525 *regression*, not a fix.
3. The task's named defect files were exactly the 2 POS components, and H7 added the netted summary to exactly those 2 — the scope is correct as healed.

If the supervisor later wants those 4 also netted, the correct path is the H7 treatment (add a netted `tax_lines` summary AND net/remove per-line), not a bare per-line removal — a larger, separate change. Flagged here, not silently expanded into.

## Coverage honesty
- `/admin/pos-orders/show/4225` renders **PosOrderReceiptComponent only** — that component's suppression is e2e-proven on #4225.
- `ReceiptComponent.vue` (live POS post-payment modal, `/admin/pos`) is **not** exercised by that route. It received the **identical** edit, is covered by Vitest (`receiptAddonsRenderingSentinel` mounts it — green), and is verified by the production build. Stated honestly: e2e proves PosOrderReceiptComponent; ReceiptComponent is proven by identical-edit + Vitest + build, not by the #4225 e2e.

## Safety / cleanup
- No commit (supervisor commits). No push. No `git add .`.
- Added only: 1 e2e spec + this report + screenshot dir. Scratch capture spec removed.
- No fiscal rows mutated on the clone (read-only renders). Operating DB `foodking` never touched. `vendor/bin/phpunit` only (never `php artisan test`).
