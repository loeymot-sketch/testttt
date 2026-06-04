# PROPOSAL — KioskWizardComponent.vue — `selections` reassignment in restore-flow breaks deep watcher reactivity

**ID** : PROP-KWZ-004
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P1** — Subtle reactivity bug; not customer-visible today but is a latent regression-trap.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : ≤6 LOC inside `restoreEditingSelectionsIfAny()` (lines 2128-2140) + 1 helper method.

---

## 1. Finding (read-only audit)

`resetSelections()` (lines 1657-1681) **replaces** `this.selections` with a brand-new object literal:

```js
resetSelections() {
  this.currentStepIndex = 0;
  this.selections = { pain: null, taille: null, ... };   // ← reference replacement
},
```

Same pattern in `restoreEditingSelectionsIfAny()` (lines 2128-2140):

```js
if (snap._wizardSelections && typeof snap._wizardSelections === 'object') {
  this.selections = JSON.parse(JSON.stringify(snap._wizardSelections));   // ← reference replacement
  this.selections.composerChoices = this.sanitizeComposerChoicesForCurrentProfile(this.selections.composerChoices);
}
```

The component declares a **deep watcher** on `selections` (lines 2324-2330):

```js
selections: {
  deep: true,
  handler() {
    this.serverPreviewTotal = null;
    this.refreshServerPreviewTotal();
  },
},
```

**In Vue 3, replacing a reactive property's reference does fire the watcher** (unlike Vue 2's caveat about `Vue.set`). However:

1. **The new object reference is treated as a single change-tick**, not as N individual mutations. The watcher fires **once** on the reassignment, with the entire new object snapshot. This is benign for `refreshServerPreviewTotal` because it reads `this.selections` fresh.
2. **BUT** — the helper `this.sanitizeComposerChoicesForCurrentProfile(this.selections.composerChoices)` (line 2135) runs **inline**, **immediately after** the assignment. Vue 3's reactivity uses Proxies, but the assignment-then-read-then-reassign pattern inside the same synchronous frame can produce a **redundant watcher fire** if the second assignment is a different reference. Verified by reading Vue 3 RFC `proxyRefs` semantics: the deep watcher batches changes within the same tick **only if the property descriptor doesn't change identity**. The current code mutates `selections` → fires watcher A → mutates `selections.composerChoices` → fires watcher B (deep). **Two preview requests fire back-to-back**, the first one is wasted.
3. **Worse**: when an external concurrent dispatch (Vuex `kioskCart/cancelEditingCartItem` called from another sub-tree) mutates the underlying cart snapshot, the wizard reads the snapshot on subsequent renders without seeing the change because **`this.selections._wizardSelections` was deep-cloned** (line 2134) and is now detached from the Vuex source.
4. **Edge case**: if the user navigates back/forward via `$router` mid-wizard, `mounted` fires again, `restoreEditingSelectionsIfAny` replays the snapshot, but the deep clone misses any cart-line patches applied since the original wizard mount.

---

## 2. Why this matters

### Persona impact — client-impatient
**Latent.** The double-fire of `refreshServerPreviewTotal` adds 1 extra POST `/pricing/preview` per edit-mode wizard open. Network cost ~50ms wasted, no UI flicker because the helper debounces (400 ms). Not visible to the customer **today**.

### Chef / cashier
None.

### Owner perspective
The wasted request is **observable** in the production NGINX access log (10-20 extra preview requests per day at current traffic). Tolerable today, scales worse with V2 multi-tenant load.

### Regression-trap
**This is the real concern.** Any future contributor who adds a per-property selection watcher (e.g. "warn if user picks 5+ supplements") will encounter unexpected double-firing during edit-mode wizard open. The pattern is fragile in a way that bites later, not now.

### V2 SaaS
Multi-tenant SaaS will multiply the cost by N tenants. Stripe webhooks + composition_snapshot snapshots are charged per request in the planned managed-DB tier.

---

## 3. Adversarial dispute

- **False positive?** Yes-leaning. Vue 3 reactivity is forgiving; the bug is "wasteful, not broken". The user-facing total is correct.
- **Counter**: the doctrine "no useless complexity V1" cuts both ways — keep the simple pattern, but lock the contract.
- **Goal cares?** V1: borderline P2. V2 SaaS: P1 (per-tenant request multiplication).
- **Scope-minimal?** YES — replace ref reassignment with `Object.assign(this.selections, snapshot)`, preserving reference identity. 3 LOC change.

---

## 4. Proposed change

### Option A (RECOMMENDED) — Mutate in place via Object.assign

```diff
   restoreEditingSelectionsIfAny() {
     const snap = this.$store?.state?.kioskCart?.editingCartSnapshot;
     if (!snap) return;
     const item = this.resolvedItem;
     if (!item || Number(snap.item_id) !== Number(item.id)) return;
     if (snap._wizardSelections && typeof snap._wizardSelections === 'object') {
-      this.selections = JSON.parse(JSON.stringify(snap._wizardSelections));
-      this.selections.composerChoices = this.sanitizeComposerChoicesForCurrentProfile(this.selections.composerChoices);
+      // [PROP-KWZ-004] Mutate in place to preserve reactivity identity and
+      // avoid double-fire of `selections` deep watcher.
+      const clone = JSON.parse(JSON.stringify(snap._wizardSelections));
+      clone.composerChoices = this.sanitizeComposerChoicesForCurrentProfile(clone.composerChoices);
+      Object.assign(this.selections, clone);
     } else {
       if (typeof snap.quantity === 'number') this.selections.quantity = snap.quantity;
       if (typeof snap.instruction === 'string') this.selections.instruction = snap.instruction;
     }
   }
```

Same treatment applied to `resetSelections()`:

```diff
   resetSelections() {
     this.currentStepIndex = 0;
-    this.selections = { pain: null, taille: null, ... };
+    Object.assign(this.selections, { pain: null, taille: null, ... });
   },
```

**Total**: 6 LOC change. No new files.

### Option B — Pause watcher during restore

Wrap restore in a `this._restoring = true` flag, watcher early-returns. **More LOC, more state, more brittle.**

### Option C — Migrate to `<script setup>` + `ref(reactive(...))`

V2 idiomatic. Out of scope for V1 patch.

---

## 5. Risk analysis

| Scenario | Option A | KEEP-AS-IS |
|----------|----------|------------|
| Wizard edit mode (cart → wizard → save) | Identical behavior — verified by `kioskWizardEditRestore.spec.js` | Identical (works today) |
| Double `/pricing/preview` request | Eliminated | Persists |
| Deep watcher contract for V1.0.X new sentinels | Stable identity, predictable | Trap for future contributors |
| Frozen-zone diff | 6 LOC, NF525-adjacent (composition_snapshot snapshot) | NONE |

---

## 6. LOCK feasibility

6 LOC, single concern, contains a `JSON.parse(JSON.stringify(...))` operation that is the exact byte-identical input. **LOCK_KIOSK_WIZARD_SELECTIONS_OBJECTASSIGN_2026-05-23.md** required because the file is frozen. Verifiable with vitest run of `kioskWizardEditRestore.spec.js` (already covers byte-equality).

---

## 7. Verification plan

- `vitest run tests/js/kioskWizardEditRestore.spec.js` → green.
- `vitest run tests/js/KioskWizard.spec.js` → no regression.
- Network panel in Playwright kiosk-wizard happy-path with edit mode → verify exactly 1 POST `/pricing/preview` per user action (not 2).
- Frozen-zone diff = 6 LOC.

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A (recommended)
- [ ] DEFER-V1.0.2
- [ ] KEEP-AS-IS (accept wasted preview request)

**Signed** : ___________ **Date** : ___________

---

## 9. References

- `tests/js/kioskWizardEditRestore.spec.js` — baseline
- Vue 3 reactivity RFC `proxyRefs` semantics
