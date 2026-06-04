# PROPOSAL — PosV5TrancheRow — No `data-mode` attribute on root (test selector + theming gap)

**ID**: PROP-PosV5TrancheRow-013
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (test ergonomics + future theming)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

The root `<div>` (lines 2-7):

```html
<div
  :class="['pos-v5-tranche-row', invalid ? 'pos-v5-tranche-row--invalid' : '']"
  :data-testid="testid"
  role="group"
  :aria-label="ariaGroupLabel"
>
```

There's no `data-mode="cash|card|...|other"` attribute. To test a "cash tranche shows tendered field" scenario, Playwright must:
1. Find the row by testid.
2. Read its inner `<select>` value.
3. Compare against an integer enum value.

A `data-mode` attribute would make the test 1-line:

```js
const cashRows = page.locator('[data-testid^="pos-payment-tranche-row-"][data-mode="cash"]');
```

Theming: future per-mode visual treatments (e.g. "TR voucher rows get a yellow tint") need a CSS hook that doesn't require JS-driven class binding.

### Personas impacted
- **Test-engineer** (MEDIUM — current tests must inspect select values)
- **Theme/CSS-engineer V2** (LOW today)
- **End-user** (zero direct impact)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
N/A.

### Owner perspective
"No useless complexity V1" — borderline. Defensible as a 1-line addition that pays for itself in test churn.

### Adversarial dispute
- **False positive?** Possible — test setup CAN already work via select-value reading. The proposal is ergonomic improvement, not a bug.
- **Scope-minimal?** YES — 1 binding on the root div.

---

## 3. Proposed change (under LOCK)

```diff
   <div
     :class="['pos-v5-tranche-row', invalid ? 'pos-v5-tranche-row--invalid' : '']"
     :data-testid="testid"
+    :data-mode="modeSlug"
+    :data-invalid="invalid ? 'true' : 'false'"
     role="group"
     :aria-label="ariaGroupLabel"
   >
```

### Computed addition

```diff
+    modeSlug() {
+        const m = Number(this.tranche.mode);
+        if (m === posPaymentMethodEnum.CASH) return 'cash';
+        if (m === posPaymentMethodEnum.CARD) return 'card';
+        if (m === posPaymentMethodEnum.MOBILE_BANKING) return 'mobile_banking';
+        if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return 'ticket_restaurant';
+        return 'other';
+    },
```

Total source LOC delta: **~9 lines** (1 computed + 2 attrs).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Existing tests | None — additive | None |
| New Playwright tests | More ergonomic selectors | Verbose select-reading |
| Visual regression | None | None |
| Frozen-zone regression | LOW — additive | None |
| Sentinel | None | None |

---

## 5. LOCK feasibility

- ≤9 LOC additive, single concern? **YES**
- Owner gate required (file FROZEN per `§7`)

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (paired with other V5 ergonomics polish)
- [x] **DEFER-V1.0.2** (recommended — non-blocking)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:2-7`
- `CLAUDE.md §7` Frozen Zones
