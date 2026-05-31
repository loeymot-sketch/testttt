# LOCK — ZReportService F1 discount-VAT netting

**ID:** LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026-05-31
**Frozen file touched:** `app/Services/Fiscal/ZReportService.php` (CLAUDE.md §7 — NF525-critical)
**Owner gate:** GRANTED 2026-05-31 via AskUserQuestion — owner chose **"Fixer F1 maintenant sous lock-plan"** (re-split TVA on the post-discount base, re-enable coupons+loyalty) over keeping discounts disabled.
**Cycle:** GO-LIVE VAT-10 / F1-dormancy. Builds on the fiscal-convergence GO (`2c613b33b`).

---

## 1. Why a LOCK (the F1 defect)

At a non-zero VAT rate, the frozen `ZReportService::aggregate` computed the signed
TVA on the **pre-discount** base:
- `total_tva` summed `order->total_tax` (per-line tax, computed pre-discount);
- `total_by_tax_rate` summed `order_items.tax_amount` (per-line tax, pre-discount);
- `total_ttc` used `order->total` (discount-net).

The legal identity TTC = HT + TVA still held (because the `Order::getTotalHtAttribute`
accessor returns `total - total_tax`), **but the split was wrong**: a discounted order
**over-declared TVA** (the discount's tax portion stayed in TVA instead of reducing it),
and the per-rate VAT breakdown — the figure used for the VAT declaration — was incorrect.
Confirmed live at 10% by round-4 + documented (math-only) in `Vat10ZReconciliationTest`.

## 2. Scope (surgical, additive)

Three edits, all inside `ZReportService`:
1. **`applyOrderToTotals`** — net the per-order TVA by `ratio = (subtotal - discount)/subtotal`,
   and set per-order HT = `total - netTVA` (identity preserved on netted figures).
2. **new `orderDiscountRatio(Order): float`** — ratio clamped to [0,1]; returns **1.0**
   when `discount = 0` or `subtotal <= 0`.
3. **`taxBreakdownForOrders`** — now takes the orders collection, `GROUP BY (order_id, tax_rate)`,
   and scales each order's per-rate tax by its ratio before summation.

**ratio = 1 ⇒ non-discount orders are byte-identical** to the prior behaviour. No other
file, no signature/chain algorithm, no `sign()` / `computeSignature()` change.

## 3. Mathematical justification

For a global discount `D` on a gross goods total `S` (subtotal), allocating `D` across
tax-rate buckets proportionally (`D_r = D · S_r/S`) and recomputing each bucket's TVA on
its post-discount base is **algebraically identical** to scaling each bucket's pre-discount
TVA by `(S - D)/S`. Worked example (the documented F1 case): 2,00 discount on 10,00 TTC at
10% → ratio 0,8 → TVA 0,91 × 0,8 = **0,73** (== 8,00 · 10/110), HT = 8,00 − 0,73 = 7,27.

## 4. Safety / blast radius

- **Inert until discounts are enabled.** With `pos.manual_discount_enabled=false`
  (current V1 default), every order-creation path refuses a non-zero discount, so no
  discounted order exists → every production Z is non-discount → byte-identical. The fix
  only changes behaviour once discounts are re-enabled (the activation step).
- No frozen NF525 chain/signature logic touched. `php artisan fiscal:verify-chain --all`
  must remain CHAIN OK (existing closed Z rows unaffected — they were non-discount).

## 5. Evidence (TDD)

- New: `tests/Feature/Fiscal/ZReportDiscountNettingTest.php` — single-rate net (0,73),
  multi-rate proportional allocation (0,9 ratio), and a non-discount regression guard.
  RED on pre-fix code (returned 0,91), GREEN after.
- Regression cluster GREEN (38): `ZReportTaxBreakdownTest`, `OrderTotalHtDecompositionTest`,
  `Vat10ZReconciliationTest`, `ZReportCloseTest`, `ZReportTerminalBreakdownTest`,
  `RefundPostZTest`, `FiscalSealingHmacTest`, `NF525ComplianceE2ETest`.
- Full PHP suite + NF525 chain verified at commit time.

## 6. Rollback

`git revert` the F1-fix commit. The change is self-contained in `ZReportService` + the new
test file; reverting restores the prior (pre-discount-base) aggregation. Because the fix is
inert while discounts are disabled, rollback has no effect on any signed production Z.

## 6bis. Round 2 — advisor-driven refactor (same LOCK scope, 2026-05-31)

Post-review the advisor flagged a real defect in the round-1 fix: TVA was rounded at
**two levels** (per-order in `applyOrderToTotals` + per-rate in `taxBreakdownForOrders`).
With round-half-up these can diverge by a cent on a multi-rate discounted Z, producing
a signed payload whose `total_tva ≠ Σ total_by_tax_rate` — a fiscally inconsistent
document. Counter-example: order `total_tax=0,04` split `0,03 (10%) + 0,01 (5,5%)`,
ratio 0,5 → naïve `total_tva=0,02` vs `Σ buckets = round(0,015) + round(0,005) =
0,02 + 0,01 = 0,03`.

**Refactor (same LOCK scope, still inside ZReportService):**
- `total_by_tax_rate` becomes the SINGLE SOURCE OF TRUTH for the tax decomposition.
- `total_tva = array_sum(byTaxRate)` and `total_ht = round(total_ttc − total_tva, 2)` —
  the NF525 identity holds **EXACT** by construction.
- `applyOrderToTotals` simplified to only `&$totalTtc, &$byMethod` (no TVA/HT).
- Counter-entry refund mirrors are now included in `taxBreakdownForOrders` too
  (bonus correctness: pre-fix they hit `total_tva` via `applyOrderToTotals` but never
  reached `byTaxRate` — a pre-existing asymmetry).

**Net −7 LOC.** `ratio=1` when `discount=0` → every non-discount Z is still
byte-identical to the prior (pre-LOCK) behaviour. Frozen SHA-256 baseline updated
(new hash `675796bbea478e12e1628794c52e9958991c812a32cf8a0d9749a6f07d52b207`).

**Empirical proof (the advisor's E2E demand):**
- `test_total_tva_exactly_equals_sum_of_total_by_tax_rate` — **EXACT** equality
  (no delta) on the divergence-prone construction.
- `test_discounted_z_close_signs_and_chain_verifies` — flag ON → discounted order →
  REAL `close()` pipeline → `verifySignature` ✓ + `verifyChain.valid=true` ✓ +
  persisted identities EXACT + F1 values correct (TTC 8,00 / TVA 0,73 / HT 7,27).

Gates: PHP **2755/0**, fiscal cluster **55/55**, NF525 **CHAIN OK**, frozen diff =
only `ZReportService` (this LOCK).

## 7. Activation (separate, owner-controlled)

Re-enabling discounts (`pos.manual_discount_enabled=true`) is the activation step that makes
this fix matter. It also lifts the V1 dormancy: the discretionary-discount gates become a
config-controlled kill-switch (flag off ⇒ discounts refused). The dormancy sentinels are
updated accordingly (they assert the kill-switch behaviour, not a permanent default).
