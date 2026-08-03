# LOCK — Refund-mirror discount-ratio TVA netting (ZRPT-SEM-01)

**Date:** 2026-06-01
**Author:** Claude (second brain) — under owner decision "Author LOCK + fix + test" (AskUserQuestion 2026-06-01)
**Status:** ⏳ IMPLEMENTED — awaiting owner countersign (§6)
**Scope file (NON-frozen):** `app/Services/Order/RefundWithCounterEntryService.php`
**Frozen file READ-only (NOT edited):** `app/Services/Fiscal/ZReportService.php` (CLAUDE.md §7)

---

## 1. Why this LOCK exists
`RefundWithCounterEntryService` is **not** a CLAUDE.md §7 frozen file, but the rows it
writes (the refund counter-entry **mirror** order + its `order_items`) are aggregated
into the **signed NF525 Z-report** (`ZReportService::aggregate`). A change to the mirror's
per-line `tax_amount` therefore changes a **legally-binding fiscal output**. Per §8 / §10
that requires an explicit owner gate — hence this LOCK, even though the edited file is
not itself in the frozen list.

## 2. The defect (ZRPT-SEM-01, P1, ×3-verified by audit wfaxuj9ie)
When a **discounted** order is refunded **after** its Z-close (cross-window), the signed Z
**understates per-rate TVA** (and the daily `total_tva`).

Mechanism (verified file:line):
- `ZReportService::orderDiscountRatio` = `(subtotal − discount) / subtotal`, clamped [0,1],
  and returns **1.0** when `subtotal <= 0` (guard, line ~684).
- `ZReportService::taxBreakdownForOrders` sums each order's per-rate `order_items.tax_amount`
  **× that order's ratio** (line ~720-725). The discounted **parent** therefore contributes
  its **post-discount** TVA: `Σ tax × parentRatio`.
- The mirror (`RefundWithCounterEntryService`) is created with `discount = 0`, `subtotal =
  −parent.subtotal` (negative) and per-line `tax_amount = −item.tax_amount` (full pre-discount).
- In the Z, the mirror is fed through `taxBreakdownForOrders(..., +1, ...)` (line ~49 of aggregate),
  but `orderDiscountRatio(mirror)` hits the **negative-subtotal guard → 1.0**. So the mirror
  reverses the **full** TVA: `Σ(−tax) × 1.0`.
- **Net across the two Z windows:** `Σtax×parentRatio − Σtax×1.0 = −Σtax×(1 − parentRatio) ≠ 0`.
  Example: 20% discount (ratio 0.8) on 10€ TVA → Z understates TVA by `10 × 0.2 = 2€`.

(The same imbalance also occurs **same-window** if close + refund land in one Z — the parent
is scaled by ratio, the mirror by 1.0.)

## 3. Why the obvious fix does NOT work
"Set `mirror.discount = −parent.discount`" so `orderDiscountRatio(mirror) == parentRatio`
**fails**: the mirror's `subtotal` is negative, so `orderDiscountRatio` short-circuits to 1.0
**before** looking at `discount`. The ratio cannot be carried via the order-level fields.

## 4. The fix (scope-minimal, in the NON-frozen mirror writer)
Pre-scale the **mirror's per-line `tax_amount` by the parent's discount ratio** before negating:

```php
$parentRatio = ($parentDiscount > 0 && $parentSubtotal > 0)
    ? max(0.0, min(1.0, ($parentSubtotal - $parentDiscount) / $parentSubtotal))
    : 1.0;                                   // replicates ZReportService::orderDiscountRatio
...
'tax_amount' => -1 * (float) ($item->tax_amount ?? 0) * $parentRatio,
```

Then in the Z (ratio(mirror)=1.0): mirror contributes `Σ(−tax×parentRatio)×1.0`, the parent
contributes `Σ(tax)×parentRatio` → **net per-rate TVA = 0**. NF525 identity
`total_tva == Σ total_by_tax_rate` continues to hold by construction.

**Backward-compatible:** for a NON-discounted order `parentRatio = 1.0`, so `tax_amount =
−item.tax_amount` exactly as before → existing refund flows are byte-identical. Only
discounted-order refunds change (they were wrong).

Zero edits to any frozen file. `ZReportService` unchanged.

## 5. Proof (test)
`tests/Feature/Fiscal/RefundDiscountTvaNettingTwoWindowSentinelTest.php`:
- discounted order sealed in **Z#N**, counter-entry refund sealed in **Z#N+1**;
- assert **Σ per-rate `total_by_tax_rate` across both signed Z = 0.00** (and `total_tva` nets);
- a non-discounted control proving byte-identical behavior.
NF525 chain (`fiscal:verify-chain`) remains OK; HMAC append-only.

## 6. Owner countersign (REQUIRED before this is considered closed)
- [ ] Owner reviews §2–§4 and confirms the per-rate TVA netting semantic is the intended one.
- [ ] Owner countersigns (commit trailer or sign-off here) authorizing the mirror `tax_amount`
      scaling as a permitted change to signed-Z input.
- Until countersigned: the code + test are committed for review; treat as **pending gate**.
