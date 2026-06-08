# W7 Heal — Audit P1 Convergence Report

**Goal**: `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` — `/goal ... max discipline, test-e2e ultra audited in boucle till all green`.
**Branch**: `heal/pre-cloud-exec-2026-06-05` · **Commits**: `e65a37334` (heal) + `dbb3299ab` (--force) · NO push.
**Date**: 2026-06-08.

## Source of findings

The W4 adversarial audit (workflow `wcbqguw5n`, 6 dimensions) surfaced 3 P1s against the
turnkey template binding + per-option media added in Waves 1–4. All three are healed,
proven live on the disposable clone, and locked by regression tests.

## Heals

### P1-1 — Multi-attribute orphan (the only confirmed-LIVE finding)
- **Defect**: `ComposerTemplateService::bindSourcesToItem` used `firstMatch`, so a step whose
  hints matched **N** attributes (e.g. a "Big" item carrying `Viande 1` + `Viande 2`) bound only
  the first; the 2nd meat page was silently dropped. Verified live on Big Tacos #27.
- **Fix**: **expand** one bound step per matching attribute — the first keeps the template
  `step_key`/`label`/`min_select`; each extra becomes `step_key=<key>_2…` with `label` = attribute
  name and **`min_select=0` (optional, safe default)**. Guards: claimed-tracking (1 attribute →
  1 step), unique step_keys (DB `unique(profile_id, step_key)`), gap-free renumbered positions.

### P1-2 — `extra_group` match-all
- **Defect**: an unmatched `group_label` was left **active with empty `source_ref`**, which the
  projection (`ComposerProfileProjection::choices`, `$sourceRef === ''`) treats as match-all (EVERY
  extra of the item) — not "0 choices" as the (false) docstring claimed.
- **Fix**: unmatched `extra_group` is **deactivated** (`is_active=false`), symmetric with
  `item_attribute`; docstring corrected.

### P1-3 — `image_path` → frozen `pos-wizard.js` XSS
- **Defect**: the per-option stored `image_path` flows **unescaped** into the FROZEN
  `public/js/pos-wizard.js` `renderOptionIcon` innerHTML `src="..."` sink. Empirical probe
  (`https://evil/x.png" onerror="alert(document.cookie)`) → attribute-breakout XSS. `..` traversal
  not stripped either. The sink is frozen (un-patchable), so the guard must live at the output.
- **Fix**: new authoritative `App\Support\CatalogImagePath::safeResolve` (both `ItemVariation` and
  `ItemExtra` delegate — no drift) rejects attribute-breakout chars (`" ' < > \` \ ` whitespace),
  `..`, control chars, and non-http schemes (`javascript:`, `data:`) → `null` fallback to the safe
  config image. Request-layer regex on `ItemVariationRequest`/`ItemExtraRequest` is the first line.

### Bonus — `--force` duplicated published profile (found at re-verify; hygiene, not P1)
- **Defect**: `provision --force` created a *second* published category profile alongside the stale
  one. The runtime resolver `ComposerProfileService::showForCategory` is `->latest('id')`, so the
  fresh profile is already served (**no shadow** — the earlier "#26 shadows #32" was a
  non-deterministic unordered `->first()`). But stale published clutter + a non-deterministic
  `is_published` skip-guard remained.
- **Fix**: `--force` now `unpublish()`es prior published profile(s) (preserving sync-event + version
  semantics) before creating the replacement → exactly one published profile per category.

## Evidence

### Live convergence proof (disposable clone `foodking_e2e`, via real `resolveForItem`)
| Item | Resolved profile | Meat pages | Images | Price leak |
|---|---|---|---|---|
| Big Tacos #27 | #32 | `viande` (Viande 1, 4) + **`viande_2` (Viande 2, 4)** | 4 + 4 | **no** |
| Big Cayenne | #28 | `viande` + **`viande_2`** (+ sauce/garnitures/supplements) | all | no |
| Big Classique | #29 | `viande` + **`viande_2`** | all | no |

After fixed `--force`: `Tacos publishedProfiles=[38] resolverServes=#38` (single published).

### Tests
- Convergence-gate (real-catalog shape): multi-attribute expansion, `extra_group` deactivation,
  `CatalogImagePath` security matrix, `--force` single-published survivor.
- **Full unfiltered `vendor/bin/phpunit` = 3088 tests / 13882 assertions / EXIT 0** (29 skipped,
  2 incomplete, 1 risky — all pre-existing, none in scope).
- **0 frozen-zone lines** (`git diff --stat` over the §7 list = empty throughout).

## Honest convergence framing

"All green" = **code + tests + clone-verified**. The confirmed-LIVE bug (Big Tacos #27) lives on the
**operating `foodking` DB**; the `provision --force` on operating that actually fixes it is an
**owner gate (§3bis — published profiles are a real, reversible catalog change)** and was **not**
executed here. This report does not claim the operating catalog is changed.

## Post-convergence checks (binding-locked verification)

- **`description` is NOT an XSS sibling.** `grep description public/js/pos-wizard.js` → referenced only
  as parser input (`extractViandeCountFromText(data.description)`, line 265) + two comments; never
  written into an `innerHTML`/HTML sink. Kiosk Vue escapes `{{ description }}`. No guard needed.
- **Poor-sibling 0-choice step is skipped, not a dead page.** A regular Tacos (#26, single
  `Viande 1`) inherits the rich category profile #38 and gets `viande_2` **active with choices=0**.
  The kiosk `shouldShowComposerStep` (`KioskWizardComponent.vue:802-803`: `choices.length === 0 →
  return false`) filters it out — no empty "Viande 2" page. POS composer render is flag-OFF in V1.
  Worst-case P2 does not materialize; the inherited binding is clean across rich and poor siblings.

## Environment note
A macOS TCC denial over the whole `~/Downloads` tree (open() denied, stat allowed) intermittently
blocked reads/`git`/`artisan`/`phpunit` mid-session; restored via owner Full Disk Access, resumed
automatically. Committed work was durable in git throughout.
