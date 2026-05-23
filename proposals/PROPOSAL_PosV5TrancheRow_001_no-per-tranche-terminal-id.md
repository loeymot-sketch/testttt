# PROPOSAL — PosV5TrancheRow — No per-tranche terminal_id selector blocks multi-TPE branches

**ID**: PROP-PosV5TrancheRow-001
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P0** (real-world blocker for branches with 2+ active TPE)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row, frozen per BRAIN §2 (V1 untouched protected file)

---

## 1. Finding (read-only audit)

`PosV5TrancheRow.vue` exposes three editable fields per tranche: `mode`, `amount`, `tendered` (cash only). The `<select>` for `mode` allows the cashier to pick `CARD` for any individual tranche (line 21).

The parent `PaymentComponent.vue:282-290` mounts the row, and `PaymentComponent.vue:654-664` auto-attaches `terminal_id = selectedTerminalId` to every CARD tranche in the buildSplitPayload step:

```js
// PaymentComponent.vue:654-664 (excerpt)
// [2026-05-18 PR-A V1 GO-LIVE blocker heal] Auto-attach terminal_id
// Without this, every multi-tender CARD tranche 422s on
// "A valid payment terminal is required for every CARD tranche."
if (Number(merged.mode) === posPaymentMethodEnum.CARD
    && (merged.terminal_id === undefined || merged.terminal_id === null || Number(merged.terminal_id) <= 0)
) {
    merged.terminal_id = this.selectedTerminalId;
}
```

The `selectedTerminalId` is **branch-wide singular** — picked once at modal open from the dropdown `terminalSelect` (PaymentComponent.vue:122). All CARD tranches in a single multi-tender are therefore stamped with the **same** TPE.

### Real-world breakage

Le Cayenne currently has 1 active TPE so the bug is latent. But any V1 branch with 2+ active TPE (multi-cashier line, second-counter setup, busy lunch with dedicated kitchen vs counter terminals) cannot split a payment across two TPE devices. Example:

- Customer 1: 30 € card on TPE-A (front counter)
- Customer 2: 20 € card on TPE-B (back counter)
- Same order, same cashier session, multi-tender modal → both stamped TPE-A.
- NF525 / reconciliation impact: TPE-B is never debited; manual reconciliation needed.

V2 SaaS scenario: a multi-tenant restaurant with hospitality + take-away counters MUST be able to direct each tranche to the right TPE.

### Personas impacted
- **Cashier-multitask** (HIGH — cannot work around without aborting split-payment)
- **Owner Le Cayenne** (LOW today — 1 TPE — but the moment a 2nd TPE is provisioned this becomes a P0)
- **V2 SaaS tenant** (HIGH — multi-tenant invariant violation)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
The current UX hides the terminal choice from the tranche level. Cashier sees a per-tranche `mode` select but no TPE field. Even if a 2nd TPE exists, they cannot pick it. Worst case: order goes through with wrong terminal, manual ZReport reconciliation later.

### Chef perspective
N/A.

### Owner perspective
"V1 single-resto, single TPE" today → no blocker. But owner has stated V2 SaaS readiness as a north-star. This is one of the cheapest gaps to close BEFORE V2 freezes the multi-tender contract.

### Multi-tenant-future
V2 contract for multi-TPE branches needs `terminal_id` carried per tranche. Backend (`SplitPaymentService` per the F-SPLIT-PHANTOM-CARD-001 heal note) already accepts terminal_id per tranche entry in the payload — the gap is purely UI.

### Adversarial dispute (challenge yourself)
- **False positive?** No — verified by reading PaymentComponent buildSplitPayload (line ~840-846): comment explicitly says "The split path already carries terminal_id per tranche via buildSplitPayload" but the per-tranche selection point is non-existent in the UI.
- **Out of scope V1?** Le Cayenne is 1-TPE so live-customer impact today is zero. **Defer-V1.0.2** acceptable IF owner explicitly accepts the constraint of 1 TPE per multi-tender.
- **Scope-minimal possible?** YES — a single `<select v-if="isCard">` block in PosV5TrancheRow plus a `terminals` prop from PaymentComponent + an `update` patch carrying `terminal_id`.

---

## 3. Proposed change (under LOCK)

### Template addition (after the cash tendered block, ~line 71)

```diff
+    <div v-if="isCard && terminals && terminals.length > 1" class="pos-v5-tranche-row__field">
+      <label :for="terminalId" class="pos-v5-tranche-row__field-label">
+        {{ $t('label.payment_terminal') || 'TPE' }}
+      </label>
+      <select
+        :id="terminalId"
+        :value="tranche.terminal_id || defaultTerminalId"
+        class="pos-v5-tranche-row__mode-select"
+        :data-testid="`pos-payment-tranche-terminal-${index}`"
+        @change="onTerminalChange"
+      >
+        <option v-for="t in terminals" :key="t.id" :value="t.id">{{ t.label }}</option>
+      </select>
+    </div>
```

### Props addition

```diff
   props: {
     tranche: { type: Object, required: true },
     index: { type: Number, default: 0 },
+    terminals: { type: Array, default: () => [] },
+    defaultTerminalId: { type: [Number, String, null], default: null },
   },
```

### Computed + method additions

```diff
+    isCard() {
+      return Number(this.tranche.mode) === posPaymentMethodEnum.CARD;
+    },
+    terminalId() { return `tranche-terminal-${this.index}`; },
```

```diff
+    onTerminalChange(e) {
+      const v = Number(e.target.value);
+      this.$emit('update', { terminal_id: Number.isFinite(v) && v > 0 ? v : null });
+    },
```

Total source LOC delta: **~22 lines** (template + props + computed + method).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Single-TPE Le Cayenne | Zero — `v-if="terminals.length > 1"` hides the selector when only one TPE | None |
| Multi-TPE branch | Cashier can route per-tranche correctly | Wrong TPE stamped on tranche; manual reconcile |
| Backend payload | helper `serializeTranches` already includes `terminal_id` (line 235-240) | None — backend ready |
| V2 SaaS | Forward-compatible | Gap re-opens at V2 freeze |
| Frozen-zone regression | LOW — additive (no existing logic touched); pure new code path | None |
| V1 ship blocker | NONE today (1 TPE) | NONE today |

---

## 5. LOCK feasibility

- ≤22 LOC additive, single concern? **YES**
- Architectural redesign needed? **NO** — additive prop + select
- Owner gate required because the file is FROZEN per `§7` POS V5 protected list
- Sentinel impact: none — atom contract additive (props default to safe `[]` / `null`)

---

## 6. Owner recommendation

- [ ] **APPLY-WITH-LOCK** (recommended IF a 2nd TPE provisioning is on V1.0.1 roadmap; cheapest fix BEFORE V2 freezes contract)
- [x] **DEFER-V1.0.2** (acceptable IF owner confirms Le Cayenne stays 1-TPE for V1)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (owner accepts manual reconciliation if 2nd TPE provisioned mid-V1)

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `CLAUDE.md §7` Frozen Zones
- `resources/js/components/admin/pos/PaymentComponent.vue:654-664` (auto-attach selectedTerminalId)
- `resources/js/components/admin/pos/PaymentComponent.vue:840-846` (buildSplitPayload comment)
- `resources/js/helpers/posSplitPayment.js:230-240` (serializeTranches accepts terminal_id)
- Project memory `project_pos_payment_fix_2026-05-18` (CARD terminal_id was a P0 GO-LIVE heal)
