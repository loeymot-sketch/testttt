# PROPOSAL — PosV5TrancheRow — `tranche` prop accepts any object (no shape validation)

**ID**: PROP-PosV5TrancheRow-006
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (developer-experience; no end-user impact)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Props declaration (lines 117-120):

```js
props: {
    tranche: { type: Object, required: true },
    index: { type: Number, default: 0 },
},
```

`tranche` accepts any object. There's no `validator` function asserting the documented shape from `posSplitPayment.js:30-38`:

```js
//  {
//    id:       string,
//    mode:     number,
//    amount:   number,
//    tendered: number | null,
//    note:     string | null,
//  }
```

If a parent (refactor, new caller, sub-team) passes `{ id, mode: '1', amount: '20.00' }` (string instead of number), the component **mostly works** because `Number(this.tranche.mode)` coerces, but edge cases silently fail (`validateTranche` calls `Number.isFinite(amount)` which is true for string "20.00" after coercion — but `amount <= 0` test fires on the post-Number value, not on the prop directly).

### Real-world risk

V2 SaaS multi-team scenario: a new feature plugs an additional caller (e.g. mobile POS app) and passes a slightly different shape. The atom accepts it silently. Defects surface only at validation message rendering or backend payload assembly.

### Personas impacted
- **Developer / new-caller-team** (HIGH — silent accept of bad shape)
- **Test-engineer** (LOW — unit tests already mock the shape correctly)
- **End-user** (zero direct impact)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
N/A.

### Owner perspective
"No useless complexity V1" — borderline. A `validator` adds ~10 lines but pays back in V2 reseller integration scenarios.

### Multi-tenant-future
V2 SaaS — many integrating teams. Defensive props are the cheap insurance.

### Adversarial dispute
- **False positive?** Possible — many Vue components in the codebase don't validate Object props (codebase pattern). Singling out this one is selective. Counter: this atom is a public contract between PaymentComponent and any future caller; the helper docstring TREATS the shape as a public contract. The contract deserves enforcement.
- **TypeScript would solve this** — yes, but the codebase is JS. Validator is the JS-idiomatic equivalent.

---

## 3. Proposed change (under LOCK)

### Props change

```diff
   props: {
-    tranche: { type: Object, required: true },
+    tranche: {
+      type: Object,
+      required: true,
+      validator(v) {
+        if (!v || typeof v !== 'object') return false;
+        const okId = typeof v.id === 'string' && v.id.length > 0;
+        const okMode = typeof v.mode === 'number' && Number.isFinite(v.mode);
+        const okAmount = typeof v.amount === 'number' && Number.isFinite(v.amount);
+        const okTendered = v.tendered === null || (typeof v.tendered === 'number' && Number.isFinite(v.tendered));
+        const okNote = v.note == null || typeof v.note === 'string';
+        return okId && okMode && okAmount && okTendered && okNote;
+      },
+    },
     index: { type: Number, default: 0 },
   },
```

Total source LOC delta: **+10 lines**.

Vue 2 emits a console warning (dev only) if the validator returns false. Production users see nothing. The atom continues to render (it's a warning, not an error).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Existing PaymentComponent caller | Zero — already passes correct shape | None |
| New caller with bad shape | Console warning in dev; safe fallback | Silent silent silent (chosen for dramatic effect — issues surface late) |
| Production runtime | Zero (warning is dev-only or stripped) | None |
| Frozen-zone regression | LOW — additive validator | None |
| Sentinel impact | none | none |

---

## 5. LOCK feasibility

- ≤10 LOC additive, single concern? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because file is FROZEN per `§7`

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK
- [ ] DEFER-V1.0.2
- [x] **DEFER-V2** (recommended — V2 SaaS reseller integration is the natural trigger; V1 has one known caller)
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/helpers/posSplitPayment.js:30-38` (canonical tranche shape)
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:117-120`
- `CLAUDE.md §7` Frozen Zones
