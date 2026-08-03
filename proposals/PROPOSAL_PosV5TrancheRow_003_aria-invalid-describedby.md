# PROPOSAL — PosV5TrancheRow — Inputs missing `aria-invalid` + `aria-describedby` linking to error

**ID**: PROP-PosV5TrancheRow-003
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P2** (WCAG 2.1 SC 3.3.1 + 1.3.1 gap; not a P0 since error IS announced via role="alert")
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

When a tranche is invalid (validation msg surfaces at line 88-94):

```html
<p
  v-if="invalid && validationMsg"
  class="pos-v5-tranche-row__error"
  role="alert"
>
  {{ validationMsg }}
</p>
```

The error text **is** announced because `role="alert"` triggers an immediate live-region announcement. However:

1. The `<input>` fields themselves (lines 42-52 amount, 59-69 tendered) have **no `aria-invalid="true"`** when validation fails. Screen reader users cannot probe "is this field the broken one?" by focusing it.
2. The error message is not linked to its triggering input via `aria-describedby`. SR users who refocus the input after the alert flies past don't get re-announced what's wrong.
3. The error message is single — it could describe `tendered_required` while the actual error is on `amount`. There's no per-input error linkage.

### Personas impacted
- **Cashier-with-screen-reader** (LOW — atypical persona in QSR but a real legal exposure if/when applicable)
- **Cashier-blind-keyboard** (LOW today, real for tablet kiosks in V2 SaaS B2B reseller scenarios)
- **WCAG-audit-compliance** (V2 SaaS tier-2 contracts often require WCAG 2.1 AA conformance)

WCAG 2.1 SC 3.3.1 "Error Identification" — a clearly-identifiable error must be described in text. **Met**.
WCAG 2.1 SC 1.3.1 "Info and Relationships" — programmatic relationships must be exposed. **Not fully met**: the alert is announced once, but the relationship to the failing field is not exposed.
WCAG 2.1 SC 4.1.2 "Name, Role, Value" — `aria-invalid` should reflect input state. **Not met**.

---

## 2. Reasoning fort (multi-perspective)

### Cashier-multitask
No measurable impact — sighted cashier sees red border (`pos-v5-tranche-row--invalid` line 229-232).

### Owner perspective
V1 Le Cayenne single-resto — accessibility not contracted. **Real V2 SaaS B2B reseller path** WILL contract this; cheaper to fix here than as a V2-blocker remediation.

### Multi-tenant-future
V2 SaaS reseller pitching to large chain (or hospital café, or accessibility-mandated public-sector client) will need this in their compliance pack. Today's fix is one line per input.

### Adversarial dispute
- **False positive?** No — verified by reading lines 42-52, 59-69. No aria-invalid binding, no aria-describedby.
- **Effective audit value?** Real but P2. Not a daily-cashier friction.
- **Scope-minimal?** YES — three new bindings + a per-input error-id pattern.

---

## 3. Proposed change (under LOCK)

### Template change

```diff
         <input
           :id="amountId"
           type="number"
           inputmode="decimal"
           step="0.01"
           min="0"
           class="pos-v5-tranche-row__input pos-v5-tabular"
           :value="tranche.amount"
           :data-testid="amountTestid"
+          :aria-invalid="amountInvalid ? 'true' : 'false'"
+          :aria-describedby="amountInvalid ? errorId : null"
           @input="onAmountInput"
         />
```

```diff
         <input
           :id="tenderedId"
           type="number"
           inputmode="decimal"
           step="0.01"
           min="0"
           class="pos-v5-tranche-row__input pos-v5-tabular"
           :value="tranche.tendered"
           :data-testid="tenderedTestid"
+          :aria-invalid="tenderedInvalid ? 'true' : 'false'"
+          :aria-describedby="tenderedInvalid ? errorId : null"
           @input="onTenderedInput"
         />
```

```diff
     <p
       v-if="invalid && validationMsg"
+      :id="errorId"
       class="pos-v5-tranche-row__error"
       role="alert"
     >
```

### Computed additions

```diff
+    errorId() { return `tranche-error-${this.index}`; },
+    amountInvalid() {
+      const errs = this.validation.errors || {};
+      return !!errs.amount;
+    },
+    tenderedInvalid() {
+      const errs = this.validation.errors || {};
+      return !!errs.tendered;
+    },
```

Total source LOC delta: **~12 lines** (3 computed + 6 template attrs).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Sighted cashier | Zero | Zero |
| Screen-reader user | Field-level "invalid" probable on input refocus | Alert announces once, no re-probe possible |
| WCAG audit V2 SaaS | Compliant | Documented gap |
| Frozen-zone regression | LOW — additive aria attributes, no logic change | None |
| Tests | None affected — no test asserts absence of aria-invalid | None |
| V1 ship blocker | NONE | NONE |

---

## 5. LOCK feasibility

- ≤12 LOC additive, single concern? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because file is FROZEN per `§7`
- Sentinel impact: none

---

## 6. Owner recommendation

- [ ] **APPLY-WITH-LOCK** (recommended — cheap, future-proof, no behavior change)
- [x] **DEFER-V1.0.2** (acceptable — batch with other a11y polish items in a single LOCK)
- [ ] DEFER-V2 (recommended IF V2 SaaS B2B path is not in 12-month roadmap)
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- WCAG 2.1 SC 1.3.1, 3.3.1, 4.1.2
- `CLAUDE.md §7` Frozen Zones
