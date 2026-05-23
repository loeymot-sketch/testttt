# PROPOSAL — KioskAppComponent.vue — Multiple modals can render simultaneously with no focus-trap orchestration

**ID** : PROP-KioskAppComponent-009
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

The template at L139–155 renders three overlay-class siblings in fixed order:

```html
<KioskInactivityOverlayComponent :visible="showStillHere" ... />
<KioskOfflineConflictModalComponent v-model="offlineConflictModalOpen" ... />
<KioskToastComponent ref="toast" />
```

Each is a self-contained "child can render itself as a modal" component. Nothing in the parent stops two from being open simultaneously:
- `showStillHere = true` can co-exist with `offlineConflictModalOpen = true`.
- A toast can fire while either modal is open.

There is no z-index orchestration mention here (each child manages its own), no focus-trap arbitration ("if Modal A opens, ensure Modal B is dismissed first or its focus trap is suspended"), and no `inert` attribute on the now-background siblings.

Real-world trigger: a customer triggers `KioskOfflineConflictModalComponent` via the offline-conflict CTA. While reading, they pause too long → `KioskInactivityOverlayComponent` countdown fires and renders on top. Keyboard / screen reader user now has TWO concurrent dialogs, each with its own focus trap. Tab key behavior is undefined.

### Personas impacted
- **Keyboard-only user** (HIGH — focus trap fight is the canonical WAI-ARIA failure mode).
- **Screen reader user** (HIGH — concurrent `role="alertdialog"` causes assistive-tech confusion, especially with `aria-live` toast layered on).
- **Client-impatient** (LOW — sighted customers tap the topmost one and dismiss; recoverable).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Sighted: minor visual confusion, recoverable. AT user: hard fail.

### Cashier perspective
A11y complaint chain, rare but severe.

### Owner perspective
EAA 2025 implication again. The cleanest fix is a single shell-level "modal stack" guard: only one modal-class child renders at a time, with documented precedence (e.g. inactivity-warning > offline-conflict > others). Existing `KioskInactivityOverlayComponent` should automatically dismiss/suspend other modals when it shows.

### Multi-tenant-future
At V2 SaaS, the modal vocabulary will grow (loyalty signup, allergen warning, kitchen-paused, etc.). A formal precedence rule is essential.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — each child component may internally guard against parallel mount. **I did not inspect `KioskInactivityOverlayComponent.vue` or `KioskOfflineConflictModalComponent.vue` internals.** If they coordinate via Vuex or props, the gap may be illusory.
- **Probability in V1?** Low — inactivity warning + offline conflict simultaneously is uncommon. But not zero.
- **Goal cares?** EAA 2025 binding French market, yes.
- **Scope-minimal?** Yes — add a parent-side computed `activeModal` and pass `:suspended` props to children.

## Proposed change

Option A (precedence guard in template):

```diff
-    <KioskInactivityOverlayComponent
-      :visible="showStillHere"
-      :countdown-ms="inactivityCountdownMs"
-      @stay="dismissStillHere"
-      @leave="onInactivityLeave"
-    />
+    <!-- PROP-KioskAppComponent-009: explicit precedence. Inactivity warning
+         wins (it represents an active timeout that cannot be deferred).
+         Other modals suspend their focus trap via :inert. -->
+    <KioskInactivityOverlayComponent
+      :visible="showStillHere"
+      :countdown-ms="inactivityCountdownMs"
+      @stay="dismissStillHere"
+      @leave="onInactivityLeave"
+    />
 
     <KioskOfflineConflictModalComponent
       v-model="offlineConflictModalOpen"
       :entries="offlineConflictEntries"
+      :inert="showStillHere"
       @opened="trackOfflineConflictModalOpened"
       @cancel-entry="cancelOfflineConflictEntry"
       @force-entry="forceOfflineConflictEntry"
     />
```

Option B (more defensive — auto-close on inactivity warning):

```diff
+    // PROP-KioskAppComponent-009: when the inactivity warning fires, dismiss
+    // any other open modal to prevent focus-trap conflict. Idempotent.
+    showStillHere(newVal) {
+      if (newVal === true && this.offlineConflictModalOpen) {
+        this.offlineConflictModalOpen = false;
+      }
+    },
```

(Add inside the existing `watch:` block at L318–322.)

The two options can combine: Option B closes the modal, Option A is the belt-and-braces `:inert` for the brief overlap window during transition.

Total source LOC delta : **+5 to +15** depending on which option(s) chosen.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Single modal at a time | Behavior unchanged | Behavior unchanged |
| Inactivity + offline-conflict overlap | Inactivity wins, conflict modal suspended/closed | Both render — focus trap conflict |
| Child component does not accept `:inert` prop | Prop ignored (Vue warning at most) — no break | N/A |
| Frozen-zone regression | LOW — single prop addition + optional watcher | None |
| Customer mid-conflict-resolution interrupted by inactivity | Conflict modal closes; their selection lost; they re-open from CTA after dismissing inactivity | Concurrent modals — they may not even notice the inactivity warning |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤15 LOC, single concern? **YES (Option A = +1 prop, Option B = +5 watcher LOC)**
- Architectural redesign needed? **NO — additive precedence layer**
- Owner gate required? **YES (frozen file)**

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [x] **DEFER-V1.0.2** (recommended — V1 probability of simultaneous modal overlap is low at Le Cayenne single-borne; bundle with broader EAA 2025 a11y hardening pass)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable for V1 ship if owner accepts the rare-but-real focus-trap conflict)

**Pre-condition** : confirm `KioskInactivityOverlayComponent.vue` and `KioskOfflineConflictModalComponent.vue` internals (do they self-suspend, do they accept `:inert`)? If yes, Option A is sufficient. If no, Option B is required.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L139–155 (modal siblings), L318–322 (`watch:` block — Option B host)
- WAI-ARIA 1.2 alertdialog precedence guidance
- EAA 2025
