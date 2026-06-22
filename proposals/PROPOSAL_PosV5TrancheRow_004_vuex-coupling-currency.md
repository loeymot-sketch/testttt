# PROPOSAL — PosV5TrancheRow — Atom couples to Vuex `$store` for currency symbol (architectural smell)

**ID**: PROP-PosV5TrancheRow-004
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P2** (V2 SaaS architectural concern; V1 functional impact = zero)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

The component header documents itself as:

```js
// PosV5TrancheRow.vue:103-106 (excerpt)
// Design : POS V5 tokens. Atom autonome — ne mute PAS le tranche prop ;
// émet `update` avec un patch que le parent applique.
```

The intent is clear: **atom autonome**. Yet `formatEur` at lines 207-211 reaches directly into Vuex:

```js
formatEur(value) {
    const setting = this.$store?.getters?.['frontendSetting/lists'] || {};
    const sym = setting.site_default_currency_symbol || '€';
    return `${Number(value).toFixed(2)} ${sym}`;
},
```

This couples the atom to:
1. Vuex store presence (`this.$store`)
2. Specific getter path (`frontendSetting/lists`)
3. Specific shape (`site_default_currency_symbol`)

### Impact

- **Storybook / isolated test mounting**: Unit tests must stub a fake Vuex store with the exact getter path or `formatEur` silently returns `€`. Workable but couples test setup to global state.
- **V2 SaaS multi-tenant**: A different tenant could use a different store module name (e.g. `tenantSetting/...`), breaking the atom.
- **Component reuse**: Any other surface (KDS payment-summary, admin reports, OSS) that wants to render a tranche row must accept the same Vuex coupling.
- **Atom contract violation**: The docstring promises "atom autonome" but it's not — it has a hidden cross-cutting dependency.

### Personas impacted
- **Developer / test-engineer** (LOW today — known pattern across codebase per PaymentComponent)
- **V2 SaaS architect** (MEDIUM — accumulates technical debt)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
N/A.

### Owner perspective
V1 Le Cayenne — one tenant, EUR-only. Zero functional issue. The smell is real but invisible to end users.

### Multi-tenant-future
V2 SaaS tenant in another currency (CHF, USD, MAD, XOF...) hits this immediately. The fallback `'€'` would be wrong for non-EUR tenants. The full fix is per-tenant currency resolution; the cheap interim fix is **prop injection from parent**.

### Adversarial dispute
- **False positive?** Partly — the pattern is **endemic to the codebase** (PaymentComponent itself does the same). Singling out this one atom is selective. Counter: this atom advertises itself as "autonome"; the others don't. The violation is documented-vs-actual, not just a smell.
- **Scope-minimal?** YES — pass `currencySymbol` as a prop from PaymentComponent (which already reads the Vuex getter).
- **Sentinel risk?** None — no test relies on the Vuex coupling.

---

## 3. Proposed change (under LOCK)

### Props addition

```diff
   props: {
     tranche: { type: Object, required: true },
     index: { type: Number, default: 0 },
+    currencySymbol: { type: String, default: '€' },
   },
```

### Method change

```diff
     formatEur(value) {
-        const setting = this.$store?.getters?.['frontendSetting/lists'] || {};
-        const sym = setting.site_default_currency_symbol || '€';
-        return `${Number(value).toFixed(2)} ${sym}`;
+        return `${Number(value).toFixed(2)} ${this.currencySymbol}`;
     },
```

### Parent change (PaymentComponent.vue line ~282 — outside this file's scope but needed to wire)

```diff
         <PosV5TrancheRow
           v-for="(tr, idx) in tranches"
           :key="tr.id"
           :tranche="tr"
           :index="idx"
+          :currency-symbol="currencySymbol"
           role="listitem"
           @update="(patch) => updateTranche(idx, patch)"
           @remove="removeTranche(idx)"
         />
```

(PaymentComponent.vue would need a `currencySymbol` computed reading the same Vuex getter — adds ~4 lines, separate frozen-zone LOCK on PaymentComponent.)

Total source LOC delta in this file: **~5 lines** (1 prop + 4 lines method simplification).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| V1 Le Cayenne | Zero — `default: '€'` matches today's behavior | None |
| V2 multi-currency tenant | Solved (parent injects correct symbol) | Forces fallback `€` everywhere |
| Unit testing | Atom now mountable without Vuex stub | Continues coupling |
| Frozen-zone regression | LOW — atom contract widened additively | None |
| PaymentComponent touch | Requires its own LOCK (PaymentComponent is also frozen §7) | None |

**Pre-flight dependency**: This proposal requires touching PaymentComponent.vue (also §7-frozen) to wire the prop. The proposal cannot land in isolation.

---

## 5. LOCK feasibility

- ≤5 LOC in this file? **YES**
- Architectural redesign needed? **NO** — additive prop with default
- Owner gate required because BOTH this file AND PaymentComponent are FROZEN per `§7`
- **Compound LOCK**: PaymentComponent.vue + PosV5TrancheRow.vue in same surgical patch
- Sentinel impact: none

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (requires compound LOCK PaymentComponent + this file)
- [x] **DEFER-V2** (recommended — V1 EUR-only; V2 SaaS multi-currency is the natural trigger to refactor)
- [ ] DEFER-V1.0.2
- [ ] KEEP-AS-IS (accept the docstring lying about "atom autonome")

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:103-106` (docstring "atom autonome")
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:207-211` (Vuex coupling)
- `CLAUDE.md §7` Frozen Zones (both files)
