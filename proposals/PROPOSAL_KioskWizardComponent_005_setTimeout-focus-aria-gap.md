# PROPOSAL — KioskWizardComponent.vue — `setTimeout(150)` initial focus + missing ARIA live announcement on wizard open

**ID** : PROP-KWZ-005
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P2** — WCAG conformance + screen-reader UX. No keyboard-only customer flow break today.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : ≤8 LOC in `mounted` (lines 2212-2218) + a CSS-visible `aria-live` injection.

---

## 1. Finding (read-only audit)

The wizard sets focus on the dialog root via a **fragile `setTimeout(150)`** (lines 2213-2218):

```js
this._wizardReturnFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
setTimeout(() => {
  const root = this.$refs.kioskWizardRoot;
  if (root && document.contains(root)) {
    root.focus({ preventScroll: true });
  }
}, 150);
```

The `150 ms` is a heuristic to wait for the `transition name="step-slide"` to complete (lines 2992-2993: `transition: opacity 0.22s + transform 0.22s` — actually 220 ms, not 150). If the wrapper mounts under heavy main-thread load, the focus call fires before the dialog is paintable.

**Worse, no ARIA-live announcement happens.** The dialog uses `role="dialog" aria-modal="true" aria-labelledby="kiosk-wizard-title"` (lines 2-7) — correct. But:

1. The `<h1 id="kiosk-wizard-title">` is `class="kiosk-wizard-sr-only"` (lines 2481-2491) — visually hidden, only announced to screen readers. ✓
2. **However**, on dialog open, **no `aria-live="polite"` region announces the current step's question** (e.g. "Choose your bread"). A screen reader user gets the item name once, then silence until they Tab into the step content.
3. **`<transition name="step-slide">`** wraps the step component (lines 142-149). When the user advances steps, the new question (`<div class="kiosk-step-question">`, line 137) is **NOT inside an aria-live region** — so screen readers don't auto-announce the new step on advance.

Comparison with the same component's abandon modal (lines 215-237): the modal correctly focuses the first button (line 2316-2319) AND uses `role="dialog" aria-modal="true" aria-labelledby="..."`. The modal pattern is correct; the **outer wizard dialog has the focus pattern but lacks the step-change announcement**.

---

## 2. Why this matters

### Persona impact — client-impatient (50 ans, presbyote, claustrophobe, mal aux pieds)
**Medium.** A 50-year-old with reduced eyesight standing at the borne may rely on the kiosk's screen-reader-like text-to-speech (if enabled per V1.0.X roadmap). Today, the kiosk does NOT have built-in TTS but **EAA 2025** (European Accessibility Act, in force since June 2025) requires public-touchscreen kiosks to support pluggable assistive tech.

The user can't hear "Now choose your sauce" when advancing → they're stuck wondering what changed.

### Owner perspective
EAA 2025 compliance is **mandatory for V1 production** (already cited in CLAUDE.md comments at line 27: "Safety FIC UE 1169/2011 + EAA 2025"). Missing the live-region announcement is a documentary risk.

### Chef / cashier
None.

### Multi-tenant-future
Same — every SaaS tenant inherits the gap.

---

## 3. Adversarial dispute

- **False positive? Le Cayenne kiosk has no installed screen reader.** Yes — but EAA 2025 audits don't require an installed screen reader, they require the **semantic markup** to be screen-reader-ready. The borne is "borderline compliant" today.
- **Counter**: even without TTS, the focus-via-setTimeout pattern is brittle. A flaky CI run might mount the wizard before the parent transition completes → focus is lost → subsequent Tab keypresses don't follow the trap.
- **Goal cares?** V1 Le Cayenne LOCAL: borderline (EAA compliance gap, no functional break). **V1.0.2 production-perfect** axis: YES.
- **Scope-minimal?** YES — see Option A.

---

## 4. Proposed change

### Option A (RECOMMENDED) — Replace setTimeout with requestAnimationFrame + add aria-live polite region

**Change 1: setTimeout → rAF**

```diff
   this._wizardReturnFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
-  setTimeout(() => {
-    const root = this.$refs.kioskWizardRoot;
-    if (root && document.contains(root)) {
-      root.focus({ preventScroll: true });
-    }
-  }, 150);
+  // [PROP-KWZ-005] rAF-based focus aligns with the 220 ms step-slide
+  // transition without coupling to a magic-number timer. Two rAFs ≈ 2 frames
+  // (~33 ms) is enough for Vue's mount + initial paint. preventScroll keeps
+  // the live composition strip in view on tall portrait kiosks.
+  requestAnimationFrame(() => requestAnimationFrame(() => {
+    const root = this.$refs.kioskWizardRoot;
+    if (root && document.contains(root)) {
+      root.focus({ preventScroll: true });
+    }
+  }));
```

**Change 2: Wrap the step-question + step-content in an aria-live region**

In the template, lines 137-150:

```diff
-  <div class="kiosk-step-question">
+  <div class="kiosk-step-question" aria-live="polite" aria-atomic="true">
     {{ currentStep.type === 'recap' ? $t('kiosk.wizard.recap_order_title') : getQuestionLabel(currentStep) }}
   </div>
```

(The `aria-live="polite"` on the question div is enough — screen readers will announce the new question text when `currentStep.type` changes via the `<transition>` and `<component :is="...">` swap. `aria-atomic="true"` ensures the full question is read, not just the diff.)

**Total**: 6 LOC change in script + 1 attribute on the template div.

### Option B — Add explicit `setFocus()` method, scheduled via Vue's `$nextTick`

Less idiomatic — `$nextTick` fires after DOM patch but before paint, which is too early for Webkit-based kiosk browsers. **rAF×2 is the proven pattern.**

### Option C — Skip the focus + announcement (status quo)

Owner accepts EAA borderline-compliance. **Not recommended.**

---

## 5. Risk analysis

| Scenario | Option A | KEEP-AS-IS |
|----------|----------|------------|
| Customer with TTS (V1.0.X EAA tooling) | Hears every step question | Silent after first step |
| Customer keyboard-only nav | Reliable focus on open | Occasional focus miss under load |
| EAA 2025 audit | Better — explicit aria-live | Borderline finding |
| Frozen-zone diff | 7 LOC, no logic | NONE |
| NF525 | NONE | NONE |
| Existing focus-trap tests | Unchanged | NONE |

---

## 6. LOCK feasibility

7 LOC scoped, single concern, no behavior break. **LOCK_KIOSK_WIZARD_ARIA_2026-05-23.md** lightweight.

---

## 7. Verification plan

- Playwright kiosk happy-path → verify focus is on `.kiosk-wizard` root within 2 rAFs.
- Manual screen-reader test (VoiceOver iPad emulation): step transitions announced.
- Frozen-zone diff = 7 LOC.
- axe-core: no new `aria-required-children` or `region` violation.

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A
- [ ] DEFER-V1.0.2 (batch with PROP-KWZ-008 emoji-icon a11y + PROP-KWZ-009 hidden-scrollbar)
- [ ] KEEP-AS-IS

**Signed** : ___________ **Date** : ___________

---

## 9. References

- WCAG 2.1 SC 4.1.3 Status Messages
- EAA 2025 (Directive (EU) 2019/882) — public-facing kiosk requirements
- ARIA Authoring Practices Guide — dialog pattern + live regions
