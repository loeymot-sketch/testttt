# PROPOSAL — PosV5TrancheRow — `min="0"` allows 0 but validation requires `> 0` (UX mismatch)

**ID**: PROP-PosV5TrancheRow-011
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (minor UX dissonance)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Inputs (lines 42-52, 59-69) have `min="0"` — HTML5 allows the value `0`.

Validation (helper `posSplitPayment.js:89-92`):

```js
const amount = Number(tranche.amount);
if (!Number.isFinite(amount) || amount <= 0) {
    errors.amount = 'amount_required';
}
```

The validator rejects `0`. So the cashier can type `0` (the HTML form accepts it), focus elsewhere, and only THEN sees the error fire.

### Why this matters

For a multi-tender wizard the cashier often:
1. Adds a tranche.
2. Sees the empty input (value="" → coerced to 0 by `onAmountInput`).
3. Confirm button is greyed (canConfirm false because amount 0).
4. Cashier wonders "why disabled?"

The minimal HTML constraint should mirror the JS constraint: `min="0.01"` makes the browser's own validation reject 0 BEFORE the helper validator runs, surfacing the constraint earlier.

### Personas impacted
- **Cashier-multitask** (LOW — friction is mild but real)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
Visible signalling earlier = faster recovery.

### Owner perspective
Borderline polish.

### Adversarial dispute
- **False positive?** No — the mismatch is empirical (HTML `min="0"` vs. JS `> 0`).
- **Scope-minimal?** YES — change `min="0"` to `min="0.01"` on both inputs (amount + tendered).
- **Side effects?** Possibly — the browser's built-in invalidity styling kicks in earlier, which could clash with the V5 design tokens. Verify visually before commit.

---

## 3. Proposed change (under LOCK)

```diff
         <input
           :id="amountId"
           type="number"
           inputmode="decimal"
           step="0.01"
-          min="0"
+          min="0.01"
           class="pos-v5-tranche-row__input pos-v5-tabular"
           ...
         />
```

Same change on the tendered input.

Total source LOC delta: **2 lines** (one attribute on each input).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Cashier types 0 | Browser-side invalid earlier; clearer constraint | Validation fires after blur — slight delay |
| Cashier types 0.5 | Identical | Identical |
| Cashier types empty | Identical (value `""` not affected by min) | Identical |
| Browser-native :invalid pseudo | May style red border (browser default) clashing with V5 invalid styling | None |
| Frozen-zone regression | LOW — attribute change only | None |
| Tests | None expected | None |

**Pre-flight check**: visual smoke test on the V5 invalid styling — confirm `:invalid` doesn't double-style.

---

## 5. LOCK feasibility

- ≤2 LOC, single concern? **YES**
- Owner gate required (file FROZEN per `§7`)

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (paired with other minor V5 polish items)
- [x] **DEFER-V1.0.2** (recommended — batch with other UX polish)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/helpers/posSplitPayment.js:89-92`
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:47, 64`
- `CLAUDE.md §7` Frozen Zones
