# PROPOSAL — PosV5TrancheRow — "Aucun rendu" affirmative missing when tendered == amount

**ID**: PROP-PosV5TrancheRow-014
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (minor UX micro-affordance)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Lines 73-86 render the change-due banner:

```html
<div
  v-if="isCash && changeEur > 0"
  class="pos-v5-tranche-row__change"
  role="status"
  aria-live="polite"
>
  <span class="pos-v5-tranche-row__change-label" aria-hidden="true">✨</span>
  <span class="pos-v5-tranche-row__change-text">
    {{ $t('label.change_due') }}:
  </span>
  <span class="pos-v5-tranche-row__change-value pos-v5-tabular">
    {{ formatEur(changeEur) }}
  </span>
</div>
```

The condition `changeEur > 0` means: when tendered exactly equals amount (cashier received exact change), the banner does **not** render. The cashier sees no affirmative confirmation of "yes, exact change, no rendering needed".

### Why this matters (small but real)

A cashier mid-multi-tender, processing 3 tranches, wants visual confirmation each tranche is "complete" — including the exact-change case. The current UI is silent for exact-change cash. The cashier may second-guess and re-type tendered.

### Personas impacted
- **Cashier-multitask** (LOW friction, MEDIUM clarity gain)
- **Cashier-rush** (LOW)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
Confirmation = confidence = speed.

### Owner perspective
Borderline polish.

### Adversarial dispute
- **False positive?** Mild — many POS systems are equally silent. Counter: the V5 design has gentle micro-affordances elsewhere (the ✨ + green chip), so consistency calls for an affirmative state.
- **Scope-minimal?** YES — add a second `v-if` block for `tenderedCents == amountCents` exact-change case, with a different message + neutral color.

---

## 3. Proposed change (under LOCK)

```diff
     <div
       v-if="isCash && changeEur > 0"
       class="pos-v5-tranche-row__change"
       role="status"
       aria-live="polite"
     >
       <span class="pos-v5-tranche-row__change-label" aria-hidden="true">✨</span>
       <span class="pos-v5-tranche-row__change-text">
         {{ $t('label.change_due') }}:
       </span>
       <span class="pos-v5-tranche-row__change-value pos-v5-tabular">
         {{ formatEur(changeEur) }}
       </span>
     </div>
+    <div
+      v-else-if="isCash && exactCash"
+      class="pos-v5-tranche-row__change pos-v5-tranche-row__change--exact"
+      role="status"
+      aria-live="polite"
+    >
+      <span aria-hidden="true">✓</span>
+      <span>{{ $t('label.exact_cash') || 'Compte exact — pas de rendu' }}</span>
+    </div>
```

### Computed addition

```diff
+    exactCash() {
+      if (!this.isCash) return false;
+      const a = computeChangeCents.toCents ? computeChangeCents.toCents(this.tranche.amount) : null;
+      // Reuse the existing change calc — exact cash iff changeCents === 0 AND validation passes
+      if (this.changeCents !== 0) return false;
+      // tendered must be present AND match amount exactly
+      if (this.tranche.tendered === null || this.tranche.tendered === undefined) return false;
+      return this.validation.valid;
+    },
```

(Simpler form using existing computed:)

```diff
+    exactCash() {
+      return this.isCash
+        && this.validation.valid
+        && this.changeCents === 0
+        && this.tranche.tendered !== null
+        && this.tranche.tendered !== undefined
+        && Number(this.tranche.tendered) > 0;
+    },
```

### CSS (scoped)

```diff
+.pos-v5-tranche-row__change--exact {
+    background: var(--pos-v5-bg-subtle);
+    color: var(--pos-v5-ink);
+}
```

Total source LOC delta: **~16 lines** (template block + computed + CSS).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Tendered = amount (exact) | "Compte exact" banner shows | Silent |
| Tendered > amount | Existing change-due banner shows | Existing change-due banner shows |
| Tendered < amount | Validation error fires | Validation error fires |
| Card / TR / Other tranche | No banner (v-if isCash gates both) | No banner |
| Tendered empty / null | No banner | No banner |
| Frozen-zone regression | LOW — additive | None |

---

## 5. LOCK feasibility

- ≤16 LOC additive, single concern? **YES**
- Owner gate required (file FROZEN per `§7`)

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (paired with other V5 micro-affordance polish)
- [x] **DEFER-V1.0.2** (recommended — non-blocking polish)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (owner accepts silent exact-change state)

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:73-86`
- `resources/js/helpers/posSplitPayment.js:112-119` (computeChangeCents returns 0 for exact)
- `CLAUDE.md §7` Frozen Zones
