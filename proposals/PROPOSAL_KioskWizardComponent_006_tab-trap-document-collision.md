# PROPOSAL — KioskWizardComponent.vue — Document-level Tab-trap collision risk with concurrent modals

**ID** : PROP-KWZ-006
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P2** — Edge case, not customer-visible today but breaks the "compose-then-show-toast" pattern.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : ≤8 LOC inside `mounted` (line 2219-2238) + `beforeUnmount` (line 2272-2275).

---

## 1. Finding (read-only audit)

The wizard installs **TWO concurrent document-level Tab-traps**:

**Trap A — root wizard** (lines 2219-2238 in `mounted`):

```js
this._wizardRootKeydown = (e) => {
  if (e.key !== 'Tab') return;
  if (this.showAbandonConfirm) return;   // ← early-return if abandon-modal is open
  const root = this.$refs.kioskWizardRoot;
  if (!root || !root.contains(document.activeElement)) return;
  ...
};
document.addEventListener('keydown', this._wizardRootKeydown, true);
```

**Trap B — abandon modal** (lines 2290-2314 in `watch.showAbandonConfirm`):

```js
this._abandonDocKeydown = (e) => {
  if (!this.showAbandonConfirm) return;
  if (e.key === 'Escape') { ...; return; }
  const root = this.$refs.abandonModalEl;
  if (!root || !root.contains(document.activeElement)) return;
  if (e.key !== 'Tab') return;
  ...
};
document.addEventListener('keydown', this._abandonDocKeydown, true);
```

Both are installed on `document` with the **capture phase** flag (`true`). Both will fire for every keydown. The defensive guards (`return early`) work for the abandon-modal scenario.

**Risk surface — collision scenarios**:

1. **Toast-during-wizard** (real today): the `showToast` injected at line 387 fires from `_kioskPricingPreview onError` (lines 2172-2188). If the toast is a focusable element (snack-bar with close button), Trap A will steal Tab from the toast.
2. **Third-party modal-in-modal** (V2 SaaS): a tenant plugin renders an info modal over the wizard. The wizard's Tab trap will steal focus from the tenant's modal.
3. **Concurrent route guard** (V1.0.X livreur scope): if a "session timeout" warning modal pops up (planned for V1.0.X), the wizard's Tab trap collides with the warning's trap.
4. **`beforeUnmount` cleanup order**: lines 2240-2280 first cleans `_abandonDocKeydown`, then `_wizardRootKeydown`. If a custom plugin patches `removeEventListener` (rare but possible in extension stores), one cleanup can miss → memory leak.

**The collision today** is mitigated by the `showAbandonConfirm` early-return AND by toasts (kiosk-toast-component verified) not being keyboard-focusable.

---

## 2. Why this matters

### Persona impact — client-impatient
**Edge case.** A 50-year-old keyboard-only user using Tab to navigate would be stuck inside the wizard even if a toast appeared. Bornes typically don't have keyboards, but EAA pluggable assistive tech may have.

### Owner
**Real for V2 SaaS.** Plugins must coexist with the wizard. The current pattern doesn't expose a "release Tab control" hook.

### Chef / cashier
None.

---

## 3. Adversarial dispute

- **False positive?** Today, yes. Toast is not focusable, no concurrent modal exists.
- **Counter**: V1.0.X is adding pluggable warning modals (session-timeout, fiscal-alloc-error). Need the pattern to be defensive.
- **Goal cares?** V1.0.2 — borderline.
- **Scope-minimal?** YES — add a focus-stack manager OR scope the trap to the wizard root listener (not document).

---

## 4. Proposed change

### Option A (RECOMMENDED) — Scope the Tab trap to the wizard root, not document

```diff
   this._wizardRootKeydown = (e) => {
     if (e.key !== 'Tab') return;
     if (this.showAbandonConfirm) return;
+    // [PROP-KWZ-006] Defensive — bail if any aria-modal=true descendant of
+    // document.body OTHER than this wizard is open. Avoids stealing Tab
+    // from concurrently-open modals (toasts, session-timeout warnings,
+    // V2 SaaS plugin overlays).
+    const otherModal = document.querySelector('[role="dialog"][aria-modal="true"]:not(#kiosk-wizard-title)');
+    // (kiosk-wizard-title is the labelledby on THIS dialog; the abandon
+    // modal already has its own dedicated trap.)
+    if (otherModal && !this.$refs.kioskWizardRoot?.contains(otherModal)) return;
     const root = this.$refs.kioskWizardRoot;
     if (!root || !root.contains(document.activeElement)) return;
     ...
   };
-  document.addEventListener('keydown', this._wizardRootKeydown, true);
+  // [PROP-KWZ-006] Attach to wizard root (not document) — naturally scopes
+  // the trap and avoids capture-phase precedence over concurrent overlays.
+  this.$refs.kioskWizardRoot?.addEventListener('keydown', this._wizardRootKeydown);
```

And cleanup mirror:

```diff
   if (this._wizardRootKeydown) {
-    document.removeEventListener('keydown', this._wizardRootKeydown, true);
+    this.$refs.kioskWizardRoot?.removeEventListener('keydown', this._wizardRootKeydown);
     this._wizardRootKeydown = null;
   }
```

**Total**: 8 LOC change.

### Option B — Introduce a global focus-stack manager

Build `resources/js/helpers/focusStack.js`. Each modal/dialog pushes/pops itself. Trap fires only for the top of the stack. **Bigger scope, V1.0.2 cleanup.**

### Option C — Keep document-level trap, add explicit allowlist

`if (e.target.closest('.kiosk-toast,.kiosk-warning-modal')) return;` — fragile, requires maintaining a class list.

---

## 5. Risk analysis

| Scenario | Option A | KEEP-AS-IS |
|----------|----------|------------|
| Today (no concurrent modal) | Identical behavior | Works |
| V1.0.X session-timeout modal | Coexists cleanly | Tab trap collision possible |
| V2 SaaS plugin modal | Plugin works | Plugin breaks |
| Frozen-zone diff | 8 LOC, no logic change | NONE |

---

## 6. LOCK feasibility

8 LOC, single concern, focus-related. **LOCK_KIOSK_WIZARD_TABTRAP_2026-05-23.md**.

---

## 7. Verification plan

- Playwright: open wizard + dispatch a toast (force preview-error path) → Tab cycles through wizard, not toast.
- Future plugin smoke test (when V1.0.X warnings exist).
- `vitest tests/js/KioskWizard.spec.js` regressions green.

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A
- [ ] DEFER-V1.0.2 (batch with focus-stack manager Option B)
- [ ] KEEP-AS-IS

**Signed** : ___________ **Date** : ___________
