# PROPOSAL — KioskWizardComponent.vue — 3104-line god-component + extraction roadmap

**ID** : PROP-KWZ-002
**Author** : PROPOSAL AGENT (Phase B.5, GOAL ULTRA-DEEP 2026-05-23)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P2** — Maintainability + V2 SaaS scalability debt; no V1 functional impact.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (CLAUDE.md §7)
**Touch** : Would require LOCK + multi-file refactor (helper extraction). NOT a quick-win. **Recommend DEFER-V1.0.2.**

---

## 1. Finding (read-only audit)

`KioskWizardComponent.vue` is **3104 lines total**, decomposed as:

| Section            | Lines       | Approximate LOC |
|--------------------|-------------|-----------------|
| `<template>`       | 1 – 241     | 240             |
| `<script>` imports | 243 – 339   | 97              |
| `data()`           | 395 – 436   | 42              |
| `computed`         | 437 – 776   | 340 (15 computeds, including the 100+ LOC `activeSteps` template-dispatch switch and the 35 LOC `canAdvance` gate-logic) |
| `methods`          | 777 – 2141  | 1365 (≈ 70 methods) |
| `mounted` / `beforeUnmount` | 2142 – 2281 | 140 (focus trap + analytics + debouncer init/destroy) |
| `watch`            | 2282 – 2365 | 84 |
| `<style scoped>`   | 2369 – 3103 | 735 |

Within `methods`, the largest sub-clusters are:

- **`buildCartItem`** lines 1757 – 2016 (260 LOC) — assembles the payload sent to `/api/orders`. Handles 6 distinct selection kinds (pain variation, viande variation+extra, sauce variation+surcharge, garnitures, supplements, composer choices) + 3 addon kinds (menu_full/frites/boisson, drink, legacy) + frites_style upgrade + composer addons. **Critical NF525 path** (composition_snapshot source).
- **`buildInstruction`** lines 2017 – 2103 (87 LOC) — assembles the kitchen-ticket text. Frozen by NF525 reprint integrity (must match composition_snapshot semantics).
- **`activeSteps` computed** lines 547 – 643 (97 LOC) — 8-template switch with inline filter chains, owner-comment-heavy (10+ comments referencing migration history).
- **Composition chip builders** (`compositionPainChip`, `compositionViandeChip`, `compositionSauceChip`, `compositionExtraGroupChip`, `compositionMenuChip`, `compositionComposerChips`) lines 1170 – 1286 — 6 chip factories with overlapping image-resolution logic, ~120 LOC total.
- **`refreshServerPreviewTotal`** lines 1717 – 1756 (40 LOC) — debounced pricing-preview wiring.
- **Focus-trap + analytics + cleanup** in `mounted` / `beforeUnmount` — 140 LOC, mixes 3 unrelated concerns.

**The wizard imports 11 helpers** from `../../../helpers/` (lines 251 – 278), which means significant pure logic already lives outside. The component remains 3104 LOC because **the orchestration glue between helpers is itself non-trivial** — not because the helpers were skipped.

---

## 2. Why this matters

### Persona impact — client-impatient
**None today.** The file size doesn't affect the customer-perceived UX. The wizard is fast (chunked async-loaded steps via `defineAsyncComponent`), the runtime cost is dominated by the child step rendering, not the parent file size.

### Bundle / network perspective
The component is **chunked into `kiosk-wizard.*.js`** via Webpack chunk naming (verified via the 9 `defineAsyncComponent` calls at lines 280 – 308 — each step is its own chunk). The parent itself is in the main kiosk chunk. **At 3104 LOC including 735 LOC CSS, the parent's gzipped weight is ≈ 28 KB.** Not trivial, but acceptable for a kiosk borne with cached assets.

### Maintainability
**Real impact.** A 3104-LOC component:
- exceeds the Vue style guide recommended ~250-500 LOC per SFC by a factor of 6 –10;
- has 70 methods, which makes "where do I add this fix?" non-obvious;
- mixes 4 concerns (template orchestration, payload assembly, focus/a11y, analytics) that should each be testable in isolation;
- makes git diffs noisy — frozen-zone reviews are harder because reviewers must scan a 3K-line file to confirm "only line N changed".

The CTO Global Audit 2026-05-16 flagged this kind of god-component as a structural V2 SaaS blocker.

### V2 SaaS readiness
**Direct impact.** When tenants upload custom `composer_profile` step kinds (already 30% in via `composerActiveSteps()`), each new step kind today requires:
1. A new `KioskStep*Component.vue` (clean — already extracted).
2. A change to the `STEP_KEY_REGISTRY` alias map (lines 315 – 339). Acceptable.
3. A new branch in `componentForStepType()` (lines 821 – 835). Acceptable.
4. A new chip in `compositionSummaryChips` (lines 677 – 713) + a new chip-builder method. **This is the bottleneck.** Each new step kind requires touching the parent wizard.

The "single shared cart-summary chip" pattern is the V2 SaaS handle that's currently buried inside KioskWizardComponent.

### Frozen-zone discipline
The file is frozen for a reason: it owns `composition_snapshot` assembly (the NF525-frozen JSON). Any logic change risks NF525 chain corruption. Extraction MUST preserve the exact `buildCartItem` byte-output. This makes "split into smaller files" a high-risk operation that needs **rigorous golden-master test coverage BEFORE refactor**.

---

## 3. Adversarial dispute (challenge yourself)

- **False positive? Is 3104 LOC actually a problem?**
  - The file works. The bug surface area is low (the helpers absorb most logic). Tests cover the critical paths (`KioskWizard.spec.js` is 1500+ LOC).
  - **Counter**: maintainability debt accrues silently. The Wave Z convergence (2026-05-16) and 13-Zone audit (2026-05-19) both found bugs that took 2+ hours to root-cause because the parent wizard's call graph is sprawling. The 3104 LOC is the proximate cause.

- **Goal cares?**
  - V1 single-resto: borderline. Owner mandate is "no useless complexity V1" — refactoring a working file violates this mantra.
  - V2 SaaS: yes — but V2 is explicitly archived per `feedback_no_cloud_until_owner_initiates.md`. **Don't pre-build V2 features.**

- **Scope-minimal possible?**
  - For V1: **NO scope-minimal** path exists. Extraction requires either (a) trusting golden-master tests on `buildCartItem`/`buildInstruction` or (b) accepting some risk of NF525-snapshot drift.
  - For V1.0.2 batched cleanup: **YES** — extract 4 helper files (composition-chips, focus-trap, analytics, payload-builder) → reduce parent to ~1500 LOC, behavior-preserving.

- **Architectural redesign?**
  - The 4-helper extraction is NOT a redesign — it's a refactor. The parent's public contract (props + emits + slots) stays identical.

---

## 4. Proposed change

### Option A (RECOMMENDED) — DEFER-V1.0.2 — extraction roadmap documented now

Do nothing today. Add a `// @v1-0-2-refactor-target` comment block at the top of the script section (1-2 lines), referencing this proposal, so a future contributor finds the roadmap. **Zero behavior change. Zero LOC change to behavior.**

**Roadmap (when V1.0.2 batched a11y/cleanup wave is approved):**

1. **Extract** `resources/js/components/frontend/kiosk/wizard/useCompositionChips.js` (~120 LOC).
   - Move `compositionPainChip`, `compositionViandeChip`, `compositionSauceChip`, `compositionExtraGroupChip`, `compositionMenuChip`, `compositionComposerChips` to a composable returning a function that takes `({ item, selections, currentStepIndex })`.
   - Test : `tests/js/helpers/useCompositionChips.spec.js`.

2. **Extract** `resources/js/components/frontend/kiosk/wizard/useWizardFocusTrap.js` (~80 LOC).
   - Move `mounted`'s `_wizardRootKeydown` + `_wizardReturnFocusEl` setup and `beforeUnmount` teardown.
   - Test : `tests/js/helpers/useWizardFocusTrap.spec.js` with jsdom focus assertions.

3. **Extract** `resources/js/components/frontend/kiosk/wizard/useWizardAnalytics.js` (~60 LOC).
   - Move `emitWizardStepEntered`, the `currentStepIndex` watcher, the `wizard_abandoned` track in `beforeUnmount`.
   - Test : `tests/js/helpers/useWizardAnalytics.spec.js` with mocked `kioskAnalytics`.

4. **EXTRACT WITH GOLDEN-MASTER** `resources/js/helpers/kioskWizardPayload.js` (~260 LOC).
   - Move `buildCartItem` + `buildInstruction` + helpers `attributeNameForVariation`, `findItemVariationById`, `findItemExtraById`.
   - **CRITICAL: write byte-equal golden-master tests on 10+ representative selection-bundles BEFORE moving a single line.**
   - Test : `tests/js/helpers/kioskWizardPayload.spec.js` + `tests/js/helpers/kioskWizardPayload.golden-master.spec.js`.
   - The golden-master test must capture the JSON.stringify(buildCartItem(item, selections)) for every wizard_template × menuChoice × frites_style permutation found in current production seed data.

5. **After all four extractions**, parent file drops to ~1500 LOC (template 240 + script 525 + style 735). Still large, but no longer a god-component.

### Option B — Defer entirely, no roadmap, no comment

Accept the 3104 LOC as the new normal. Document in BRAIN §1 as "intentional V1 trade-off".

### Option C — Refactor now (NOT RECOMMENDED for V1)

Pros: cleaner code today.
Cons: HIGH risk of NF525 composition_snapshot drift. Owner mandate explicitly says no.

---

## 5. Risk analysis

| Scenario | Risk if Option A | Risk if NOT applied (KEEP-AS-IS) |
|----------|------------------|----------------------------------|
| V1 ship | NONE — only adds a comment block | NONE |
| Future contributor finds the work | HIGH — roadmap visible | LOW — future contributor reinvents the analysis |
| NF525 composition_snapshot drift | NONE today (extraction deferred) | NONE |
| V1.0.2 cleanup velocity | Good — plan ready | Slower start — replanning needed |

---

## 6. LOCK feasibility

- Option A: **NO LOCK needed** — adding a 2-line comment block above the script export is a documentation change, no behavior touched. Recommend owner sign-off in plan format, not LOCK doc.
- Option C (if ever): **YES LOCK required** — multi-file refactor on frozen + NF525-adjacent path.

---

## 7. Verification plan (post-implement Option A)

- `git diff resources/js/components/frontend/kiosk/KioskWizardComponent.vue` shows only the +2-LOC comment block.
- All existing tests still green (no behavior change).

---

## 8. Owner sign-off block

| Approver | Date | Option chosen | Notes |
|----------|------|---------------|-------|
|          |      |               |       |

- [ ] APPLY Option A (recommended — defer + document roadmap)
- [ ] DEFER (no roadmap)
- [ ] APPLY Option C (refactor now — owner accepts high risk)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 9. References

- CLAUDE.md §7 Frozen Zones, §8 NF525 Fiscal Invariants
- `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` — god-component anti-pattern
- `feedback_no_cloud_until_owner_initiates.md` — V2 deferral mandate
- `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` — V1.0.2 axes
