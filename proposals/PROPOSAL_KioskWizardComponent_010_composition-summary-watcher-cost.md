# PROPOSAL — KioskWizardComponent.vue — `compositionSummaryChips` recomputed on every selection mutation (perf + V2 SaaS scaling)

**ID** : PROP-KWZ-010
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P3** — Minor perf opportunity; V2 SaaS multi-tenant scaling.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : Cache-key changes inside chip builders + a `computed` decomposition. Effort > 9 LOC quick-win.

---

## 1. Finding (read-only audit)

`compositionSummaryChips` (lines 677-713) recomputes the full chip array on every selection mutation, even when the trigger only affects one chip kind. The function:

1. Calls 7 chip builders (`compositionPainChip`, `compositionViandeChip`, `compositionSauceChip`, `compositionExtraGroupChip × 2`, `compositionMenuChip`, `compositionComposerChips`).
2. Each builder calls `kioskResolveImageSrc`, `kioskFindSauceVariation`, `kioskViandeCatalogForItem`, etc. — all of which traverse `item.variations` and `item.extras`.
3. Composer entries go through `currentComposerAllowedChoiceKeys()` which itself traverses `composerActiveSteps().flatMap(...)` → another full traversal.

For a typical tacos with 4 viandes × 5 sauces × 3 supplements × 1 menu addon:

- `kioskViandeCatalogForItem(item)` iterates `item.variations + item.extras` → ~12-40 rows.
- `kioskFindSauceVariation` iterates sauce variations → 13 rows.
- `partitionKioskExtras(item)` iterates extras → 30+ rows.

**Per mutation, ~10 list-traversals × ~30 rows = 300 row scans.** For Le Cayenne V1 (single resto, ≤50 catalog items per wizard mount), this is **imperceptible** (sub-millisecond).

For V2 SaaS (multi-tenant catalog with 200+ items + 100+ extras + 50+ variations), per-mutation cost climbs to ~2000 row scans × N tenants → **observable** on Android-borne hardware.

Compound with the `selections: deep` watcher (lines 2324-2330) firing **once per mutation** triggering `refreshServerPreviewTotal` AND **Vue re-rendering** the composition strip → 2 work units per keystroke.

---

## 2. Why this matters

### Persona impact — client-impatient
**Imperceptible on V1 hardware (kiosk borne 4GB RAM, Android 11+).** On low-end V2 SaaS tenant hardware (Android-go-style 2GB) — noticeable lag, especially with custom composer profiles.

### Owner
**V2 SaaS direct.** Premature scaling concern; not V1 priority.

### Chef / cashier
None.

### V2 SaaS readiness
**Direct concern.** Tenant hardware variability is the main V2 perf risk.

---

## 3. Adversarial dispute

- **False positive?** Yes for V1. Definitely premature optimization.
- **Counter**: documenting the optimization roadmap NOW reduces V2 onboarding friction.
- **Goal cares?** V1: no. V2 SaaS: yes.
- **Scope-minimal?** No — perf optimization requires cache + invalidation logic = >9 LOC.

---

## 4. Proposed change

### Option A (RECOMMENDED) — DEFER-V2 — document roadmap inline

Add a 2-LOC `// @v2-saas-optimization-target` comment marker above `compositionSummaryChips`, pointing to this proposal. No code change. Future V2 wave inherits the analysis.

**Roadmap (when V2 SaaS perf wave is approved):**

1. Decompose `compositionSummaryChips` into one `computed` per chip kind. Vue 3 caches each computed by dependency; the chip array re-renders only the affected chip.
2. Memoize `kioskViandeCatalogForItem(item)` and `partitionKioskExtras(item)` via a `WeakMap` keyed by `item` reference.
3. Memoize `kioskFindSauceVariation` lookup with a `Map` keyed by `(item.id, sauceKey)`.
4. Move the composer-active-step traversal out of `currentComposerAllowedChoiceKeys` into a `computed` that depends only on `publishedComposerProfile()`.

### Option B — Apply Option A roadmap now (V1)

Risk: premature optimization touches frozen-zone code → NF525 composition_snapshot path indirectly affected (via shared helpers). **NOT recommended for V1.**

### Option C — Keep as is, no roadmap

---

## 5. Risk analysis

| Scenario | Option A (defer + roadmap) | Option B (optimize now) | KEEP-AS-IS |
|----------|----------------------------|-------------------------|------------|
| V1 perf | Identical | Slightly faster (imperceptible) | Identical |
| V2 SaaS onboarding | Plan ready | N/A | Slower start |
| Frozen-zone | Comment only — no LOCK | Multi-file refactor — LOCK heavy | NONE |
| NF525 | NONE | Risk of helper drift | NONE |

---

## 6. LOCK feasibility

Option A: no LOCK — just a comment marker.

---

## 7. Verification plan

- `git diff` shows only +2 LOC comment.
- All existing tests green.

---

## 8. Owner sign-off

- [ ] APPLY Option A (recommended — defer + document)
- [ ] APPLY Option B (optimize now — owner accepts risk)
- [ ] KEEP-AS-IS

**Signed** : ___________ **Date** : ___________
