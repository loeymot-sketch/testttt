# PROPOSAL — KioskWizardComponent.vue — Emoji glyphs used as semantic icons (a11y + brand neutrality)

**ID** : PROP-KWZ-008
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P2** — WCAG 1.1.1 (Non-text Content) borderline + brand identity weakness; no functional break.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : ≤12 LOC across `getStepIcon`, composition-chip builders, abandon-modal close button (lines 43, 689, 1187, 1203, 1217, 1243, 1269, 1284, 1505-1516).

---

## 1. Finding (read-only audit)

The component uses **inline Unicode emoji** as step icons and composition-chip icons:

| Where | Glyph | Line |
|-------|-------|------|
| `getStepIcon` map | `🥖 📏 🥩 🥫 🥗 🧀 🍟 ✓ ＋` | 1505-1516 |
| `compositionPainChip` | `🥖` | 1187 |
| `compositionViandeChip` | `🥩` | 1203 |
| `compositionSauceChip` | `🥫` | 1217 |
| `compositionExtraGroupChip` | `🧀` / `🥗` | 1243 |
| `compositionMenuChip` | `🍟` | 1269 |
| `compositionComposerChips` | `＋` (full-width plus) | 1284 |
| `taille` chip | `📏` | 689 |
| Generic-choices step icon | `＋` | 1505 |
| Close button | `×` (multiplication sign) | 43 |
| Step-visual-index ::before | `✓` | 2609 |

These render as **system emoji fonts** — Apple Color Emoji on Safari, Segoe UI Emoji on Edge, Noto Color Emoji on Chromium-Linux. The rendering is:

- **Inconsistent across platforms** — `🥩` looks dramatically different on Apple vs. Microsoft devices.
- **Vulnerable to font fallback** — if the kiosk borne ships a stripped font set (common on Android-borne builds to save flash), some emoji glyphs render as `□` Tofu boxes.
- **Not announced semantically** — screen readers pronounce `🥖` as "bread", `🥫` as "canned food", `🥗` as "green salad". A user with TTS hears the wizard list step labels followed by "bread, meat, canned food, green salad" — confusing and culturally biased.
- **Brand-inappropriate** — system emojis don't match the Le Cayenne flat/minimal palette (noir/rouge/jaune/blanc per `feedback_design_flat_organized.md`). Apple Color Emoji is glossy 3D; brand wants flat.

Already mitigated in places: the **step visual** strip in the template (lines 47-66) prefers a real product image via `getStepVisualImage(step)`, falling back to the emoji `<span v-else class="kiosk-step-visual-fallback">`. So the emoji rarely shows visually — but ALL composition chips use the emoji unconditionally (chip definitions pass `icon: '🥖'` etc., and the template renders the emoji when no image is available, lines 113-121).

The `×` close button (line 43) is technically the MULTIPLICATION SIGN (U+00D7), not the LATIN SMALL LETTER X — but the surrounding `aria-label="$t('kiosk.wizard.close_aria')"` saves the semantic.

---

## 2. Why this matters

### Persona impact — client-impatient
**Low.** A 50-year-old standing at the borne sees product images on the chip thumb most of the time — the emoji is a fallback. The visible impact is rare.

### Persona impact — multicultural customer
**Real.** A customer using Arabic UI (admin app supports it per `kiosk_confirmation_i18n_fix`) hears the screen reader translate the emoji literally → "خبز" (bread) when the chip already says "Pain" → redundant + confusing.

### Owner perspective
**Brand-coherence** — see `feedback_design_flat_organized.md`. Flat-minimal palette wants flat-minimal icons. System emoji breaks the palette.

### Chef / cashier
None.

### Multi-tenant-future
Each tenant has their own brand palette. Hard-coded emojis don't scale.

---

## 3. Adversarial dispute

- **False positive?** Yes-leaning — most chips display a real image. The emoji surfaces rarely.
- **Counter**: rarely-surfaced bugs are the worst because they appear unpredictably in production and erode brand quality silently.
- **Goal cares?** V1 production-perfect: borderline. V1.0.2 a11y batch: YES.
- **Scope-minimal?** YES — see Option A.

---

## 4. Proposed change

### Option A (RECOMMENDED) — Replace emojis with SVG icon-name strings + add `aria-hidden`

1. Define a small SVG icon set as inline `<svg>` data-URIs or icon-font glyphs in `resources/css/kiosk-icons.css` (already exists per Wave Polish Final 2026-05-21).
2. Replace each emoji string in `getStepIcon` and chip builders with a CSS class name (`'kiosk-icon-bread'`, `'kiosk-icon-meat'`, etc.).
3. In template, render `<span :class="chip.icon" aria-hidden="true"></span>` instead of `{{ chip.icon }}`.
4. The existing `aria-hidden="true"` on `<span class="kiosk-live-composition-thumb" aria-hidden="true">` (line 112) is already there → screen reader stays clean.

**Total**: ~12 LOC in the component (emoji map → class-name map) + N icon definitions in kiosk-icons.css.

### Option B — Wrap emojis with `<span aria-hidden="true">` only (cheap a11y, no brand fix)

```diff
-  <span v-else class="kiosk-live-composition-icon">{{ chip.icon }}</span>
+  <span v-else class="kiosk-live-composition-icon" aria-hidden="true">{{ chip.icon }}</span>
```

Cheap: 1 attribute change ×4 sites = 4 LOC. Addresses screen-reader announcement but not brand or platform-rendering.

### Option C — Defer entirely (status quo)

---

## 5. Risk analysis

| Scenario | Option A | Option B | KEEP-AS-IS |
|----------|----------|----------|------------|
| Screen-reader user | Clean | Clean | Hears emoji descriptions |
| Brand identity (Le Cayenne, V2 SaaS) | Aligned | Inconsistent | Inconsistent |
| Frozen-zone diff | ~12 LOC + new CSS | 4 LOC | NONE |
| Existing tests | No regression | No regression | NONE |

---

## 6. LOCK feasibility

Option A: ~12 LOC + CSS file (kiosk-icons.css NOT frozen) — moderate scope. **LOCK_KIOSK_WIZARD_ICONS_2026-05-23.md**.
Option B: 4 LOC, trivial — quick-win LOCK template.

---

## 7. Verification plan

- axe-core run on /kiosk wizard surface → zero `image-alt` / `aria-hidden-focus` warnings.
- Manual screen-reader read-through (VoiceOver) — emoji chars not pronounced.
- Visual: chips render branded flat icons (Option A) or unchanged emoji but silent (Option B).

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A (full brand alignment)
- [ ] APPLY-WITH-LOCK Option B (a11y-only quick win)
- [ ] DEFER-V1.0.2
- [ ] KEEP-AS-IS

**Signed** : ___________ **Date** : ___________

---

## 9. References

- WCAG 2.1 SC 1.1.1 Non-text Content
- `feedback_design_flat_organized.md`
