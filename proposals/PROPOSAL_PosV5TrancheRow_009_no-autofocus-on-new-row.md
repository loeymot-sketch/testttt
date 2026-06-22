# PROPOSAL — PosV5TrancheRow — No autofocus on newly-added row (cashier-rush persona friction)

**ID**: PROP-PosV5TrancheRow-009
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P2** (cashier-multitask persona friction; primary audit angle)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` (+ parent PaymentComponent for emission)
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

The atom does not expose a way for the parent (or itself) to autofocus the amount input when a row is freshly added.

Sequence today:
1. Cashier clicks "+ Ajouter une tranche" (PaymentComponent.vue:296-304).
2. Parent emits `addTranche()` → new tranche object pushed.
3. Vue renders new `<PosV5TrancheRow>`.
4. Focus stays on the "+" button.
5. Cashier must mouse/tap the amount input to start typing.

For a multi-tender split (3-person dinner, each pays their share), this is 1 extra tap × 3 = real measurable friction at the cashier-rush boundary.

WCAG 2.4.3 "Focus Order" — focus order should be logical. Adding a row should naturally pass focus to the new row's first input.

### Personas impacted
- **Cashier-multitask** (HIGH — primary audit angle; 3-person split common scenario)
- **Cashier-rush** (HIGH — lunch peak)
- **Keyboard-only-cashier** (HIGH — tab-stop cycle is one extra step per row)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
A muscle-memory cashier expects: click +, type amount, click +, type amount. The "click input to focus" step is friction.

### Chef perspective
N/A.

### Owner perspective
"No useless complexity V1" — but autofocus is the standard expectation; its absence is the deviation.

### Multi-tenant-future
Same.

### Adversarial dispute
- **False positive?** No — verified by reading. No `ref` on inputs, no `mounted()` hook with `$nextTick`+`focus()`.
- **Scope-minimal?** YES — `ref="amountInput"` + a `mounted()` lifecycle hook that focuses if a `autofocus` prop is true.
- **Could be parent's job?** YES — parent could `document.querySelector('[data-testid="pos-payment-tranche-amount-N"]').focus()` after adding. But the atom is the cleaner home for the focus knowledge.

---

## 3. Proposed change (under LOCK)

### Template change

```diff
         <input
+          ref="amountInput"
           :id="amountId"
           type="number"
           ...
         />
```

### Props addition

```diff
   props: {
     tranche: { type: Object, required: true },
     index: { type: Number, default: 0 },
+    autofocus: { type: Boolean, default: false },
   },
```

### Lifecycle addition

```diff
+  mounted() {
+    if (this.autofocus && this.$refs.amountInput) {
+      this.$nextTick(() => {
+        this.$refs.amountInput.focus();
+        this.$refs.amountInput.select();
+      });
+    }
+  },
```

### Parent usage (PaymentComponent.vue:282 — separate LOCK)

```diff
         <PosV5TrancheRow
           v-for="(tr, idx) in tranches"
           :key="tr.id"
           :tranche="tr"
           :index="idx"
+          :autofocus="idx === justAddedTrancheIndex"
           ...
         />
```

Parent tracks the index of the just-added row and clears it after first focus (or after a timeout).

Total source LOC delta in atom: **~10 lines**.

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Cashier adds first tranche | Input auto-focused, ready to type | Extra click |
| Cashier adds Nth tranche | Same | Same |
| Initial render (existing tranche) | No autofocus prop false → no effect | None |
| Keyboard-only user | Focus naturally lands on amount, then Tab → tendered → next row | One Tab cycle wasted on the "+" button focus |
| Screen reader | Announces new field on focus — supports SR users tracking the new row | Silent — SR user does not realise focus stayed on + button |
| Frozen-zone regression | LOW — additive prop + lifecycle | None |
| Tests | None affected (unless a unit test asserts focus does NOT move; unlikely) | None |

---

## 5. LOCK feasibility

- ≤10 LOC in atom + parent wiring? **YES** (atom-only LOCK; parent change is a separate trivial diff that doesn't require its own LOCK because focus-orchestration in PaymentComponent is below the frozen-logic threshold — verify with owner)
- Architectural redesign needed? **NO**
- Owner gate required because atom is FROZEN per `§7`. PaymentComponent is ALSO `§7`-frozen — compound LOCK.
- Sentinel impact: none

---

## 6. Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — cashier-multitask is the primary persona angle of this audit; cheap win)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- WCAG 2.4.3 "Focus Order"
- `resources/js/components/admin/pos/PaymentComponent.vue:282-290, 296-304`
- `CLAUDE.md §7` Frozen Zones
