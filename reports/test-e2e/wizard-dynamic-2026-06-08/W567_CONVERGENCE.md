# Wizard Builder — Waves 5/6/7 Convergence Report

**Goal**: `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` — owner: "continue tout le goal, tu gères
tous les sub-agents max power… till all green."
**Branch**: `heal/pre-cloud-exec-2026-06-05` · NO push. **Date**: 2026-06-08.

## What shipped (non-frozen, committed, green)

| Wave | Deliverable | Commit |
|---|---|---|
| **W5 backend** | `POST /composer/profiles/{profile}/personal-page` — construct-on-the-fly: creates an ItemExtra group + binds one extra_group step, atomically; category profiles replicate the group across all category items so it renders on every sibling | `6a9a1117c` |
| **W5 UI** | "Créer une page personnalisée" builder modal (item + category contexts), per-option price (0=free), saveDraft guard, reload-on-success | `111d77cae` |
| **W6** | Full-price box via the **non-frozen generic escape-hatch** (extra_group, non-registry step_key) — server subtotal proves full price; escape-hatch sentinel; addon-display gap surfaced as a LOCK doc | `a992fba50` |
| **W7** | Item-owned precedence already correct + tested across all 4 resolvers; added anti-drift sentinel locking the `resolveForItem` category-wins trap out of render | `337769da9` |
| **W5/W6 heal** | Adversarial-audit fixes (below) | `793df28e4` |

## Adversarial audit + heal (the loop)

A 3-cell adversarial audit (refute-oriented, empirical reproduction required) ran over the new W5/W6.
**The NF525/pricing cell was CLEAN.** Findings + resolution:

| Sev | Finding | Resolution |
|---|---|---|
| **P1** | personal-page step_key slug could equal a kiosk `STEP_KEY_REGISTRY` key ("Sauce"→`sauce`) → page hijacked by a FROZEN specialized component that ignores its options (live, kiosk) | **FIXED** — `personalPageStepKey()` prefixes any reserved-key collision (`sauce`→`page_sauce`); + drift sentinel keeping the PHP reserved list synced to the JS registry |
| P2 | createPersonalPage used the READ-tier branch guard → a non-admin Branch Manager could mutate the global catalog | **FIXED** — `authorizeWritableBranchScope` (matches every sibling write); + test (Branch Manager → 403) |
| P2/P3 | `firstOrCreate` kept stale option prices on re-submit; `uniquePersonalStepKey` suffixed a duplicate step | **FIXED** — `updateOrCreate` (price refresh) + reuse the step already bound to the label; + test |
| P2 | POS `pos-wizard.js` has no `generic_choices` renderer (reads `step.items`) → box/personal page blank/crash **when** `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` is flipped (default-OFF, frozen) | **DOCUMENTED** — hard blocker on the flag flip (GATE-W6 doc); kiosk path fully correct; disclose-not-block (P2) |
| **P0/P1 cluster** | **personal-page → catalog overwrite** (the builder could rewrite a real catalog group's prices). Surfaced over 3 adversarial rounds; every attempt to allow *idempotent re-edit* safely was empirically broken | **RESOLVED by reversion to a conservative create-only guard** (cannot overwrite by construction) + re-edit escalated as an owner design gate — see the **Collision-guard saga** section below + `WIZARD_W5_PERSONAL_PAGE_REEDIT_DESIGN_GATE.md` |

## Collision-guard saga — 3 falsified rounds → conservative landing (the loop, honestly)

We wanted re-submitting the same personal-page label to **update** its options idempotently. Making that
safe means distinguishing *"a group THIS builder created"* from *"a pre-existing catalog group"* using a
signal that survives the editor's `steps()->delete()`+recreate flow and that a client can't forge. Three
adversarial rounds each broke the attempt empirically:

| Round | Mechanism tried | Broken by (reproduced) | Sev |
|---|---|---|---|
| 1 | Guard keyed on `source_type='extra_group' AND source_ref=label` | a NORMAL step bound to a real group has the same shape → disarms the guard → overwrite (0.80→99.00, rogue option) | P1 (≈P0) |
| 2 | `is_personal_page` provenance column on the step | full-profile editor "Save draft" deletes+recreates via `normalize()` (omits the flag) → marker erased → own page falsely 422 | P1 |
| 3 | Re-stamp the flag in `ComposerProfileService::update` keyed on `source_ref`/`step_key` | both keys are client-resendable → re-arm the flag on a normal step → overwrite, silent 201 (escalation for compose-only role) | P0/P1 |

Root cause: **step-level provenance is the wrong place** — the editor rebuilds steps destructively and
the rebuild payload is client-controlled. Per CLAUDE.md §10 (healing cap = 3 cycles → escalate, don't
auto-pick an architecture), the marker/re-stamp approach was **reverted** and the feature landed on a
**conservative create-only guard**: reject (422) any label whose `group_label` already exists on a
target item, *before* the transaction — so `updateOrCreate` can only INSERT, never overwrite. No marker,
no client-resendable key, **no bypass class** (overwrite-proof by construction). The guard folds case in
PHP with `mb_strtolower` — the **same** folding the kiosk projection uses — so `guard ⊇ projection` on
ANY database (SQLite test == MySQL prod), not by collation coincidence (this closed a P2 the
verification found: a case-variant label otherwise projected a duplicate kiosk page that cross-rendered
its option into a real catalog group — a render artifact, never a row overwrite). Trade-off: re-POSTing
the same label to re-edit is no longer supported here (edit options via the catalog/step editor).
Restoring safe in-builder re-edit is an **owner design gate** (options A–D in
`WIZARD_W5_PERSONAL_PAGE_REEDIT_DESIGN_GATE.md`; recommended interim = create-only, recommended
enhancement = re-edit by server-trusted step id).

The committed guard-v1 (`5fd1b58f9`) carried the round-1 overwrite path; this reversion supersedes it.

### Independent verification of the conservative guard (4-cell adversarial Workflow)

Because my track record on this cluster was 3/3 wrong, the conservative guard was not trusted on
by-construction reasoning alone — a 4-cell Workflow attacked it empirically. **Verdict: CLEAN (no
P0/P1), blocking_count 0.**

| Cell | Verdict | Evidence |
|---|---|---|
| overwrite-path | **clean** | INSERT-only proven: guard keys on `group_label` (superset); `updateOrCreate` on the `(item_id,name,group_label)` triple (subset); same connection/scope/trimmed-label; no overwrite across trim, case, category-partial, soft-delete, exotic step-reuse, TOCTOU |
| authz / privilege | **clean** | the decisive P0 (a `catalog.compose`-only Branch Manager *without* `items_edit` overwriting a catalog price) **refuted empirically** — 422, guard is role-independent; branch-scope 403/404 confirmed; no client `branch_id_scope` forge path |
| NF525 / projection / render | finding → **fixed** | found the **P2** case-folding divergence (case-variant label → duplicate projected step leaking an option into a catalog group's render; *not* a price/overwrite issue) and applied the `mb_strtolower` guard fix + permanent regression test |
| regression | **clean** | `ComposerPersonalPageTest` 10/10, `Composer\|Wizard\|MenuProjection\|ComposerStep\|ComposerProfile` clean, sentinels 13/13, no dangling `is_personal_page` reference |

The single finding (P2 case-folding) was healed in-place during verification; the row-overwrite invariant
held in every cell.

## §6 Visual evidence (builder modal)

The W5 "Créer une page personnalisée" modal was captured on **:8767** (verified `lsof` cwd = this
worktree `pre-cloud-exec`; bundle `public/js/app.js` contains `submitPersonalPage`) and **Read +
analyzed** per the §6 mandate. Artifacts:
`reports/test-e2e/wizard-dynamic-2026-06-08/e2e-personal-page-modal/{01-modal-empty,02-modal-filled}.png`.

- **Empty state** — title "Créer une page personnalisée"; NF525-safe subtitle *"chaque option porte son
  propre prix (0 = offert)"*; "Titre de la page" input; "OPTIONS DE LA PAGE" with "Ajouter une Option";
  one option row (Nom / **Prix (€) per option** / Description); Min/Max selects; visibility toggles
  "Caisse (POS)" + "Borne (Kiosk)"; Annuler / Créer La Page. **Price is per-option only — no page-level
  price field exists in the UI** (matches the NF525 backend `price=prohibited`).
- **Filled state** — label "Suppléments Maison" + two option rows ("Cheddar maison", "Oeuf") each with a
  remove control; layout intact.
- **No raw i18n keys, no layout break, branding intact, 0 console errors** in either state.

## Evidence (final gate)

- **PHPUnit (full, unfiltered): 3101 tests / EXIT 0 / 0 failures** (29 skipped + 2 incomplete + 1 risky
  — all pre-existing). The conservative-guard landing nets +1 test vs the 3100 baseline
  (`ComposerPersonalPageTest` = 10: drops the 2 reverted marker tests, adds the create-only contract,
  the normal-step-bound regression, and the case-folding-parity regression). Targeted re-green:
  `ComposerPersonalPageTest` 10/10, `Composer|Wizard|MenuProjection|ComposerStep|ComposerProfile` clean.
  (Full-suite number re-confirmed at the final gate before commit — running.)
- **Vitest (full): 302 files / 2082 tests / 0 failures** (3 skipped; the runner exit-1 is pre-existing
  teardown async noise — happy-dom AsyncTaskManager + a pre-existing `evil.tld` security spec + the
  known axios-unavailable kiosk-preview logs — not a test failure).
- **0 frozen-zone lines** touched across all of Waves 5/6/7 + heal (`git diff --stat` over the §7 list
  empty at every commit).
- Live: the box full-price math + the personal-page projection verified on the disposable
  `foodking_e2e` clone earlier in the W7 heal phase; the kiosk generic render proven by W6a + the box
  test + the escape-hatch sentinel.

## Honest convergence framing — "all green" scope

"All green" = **non-frozen scope: code + tests + adversarial P0/P1=0**, where the personal-page overwrite
risk is closed by the conservative create-only guard (not by an idempotent-re-edit feature, which is
escalated as a design gate — see the saga above). The convergence loop genuinely ran: 3 adversarial
rounds each falsified a re-edit mechanism; the 4th landing (reversion to create-only) is overwrite-proof
by construction and was **confirmed CLEAN (no P0/P1) by an independent 4-cell adversarial verification**
(one P2 case-folding render artifact found + fixed in-flight).

Owner-gated items (decisions, not blockers to the safe baseline):
- **W5 re-edit design gate** — `WIZARD_W5_PERSONAL_PAGE_REEDIT_DESIGN_GATE.md` (A/B/C/D; interim D = create-only, shipped).

Two items remain **owner-gated (frozen, §7/§10)** and are documented LOCK requests, not shipped:
- **G-1 / GATE-G** — `PricingService` category-inherited composer enforcement (from the earlier W7 audit).
- **GATE-W6** — (a) `composerAddonTotal` for addon-linked box display, (b) POS `generic_choices`
  renderer in `pos-wizard.js` — both block the `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` flip.

Also owner-gated (per §3bis): running `provision --force` on the **operating** `foodking` catalog (the
W4 wizards + any new box/personal pages are reversible published-profile changes).

The kiosk surface — the live V1 path — is fully functional and tested. POS composer support and the
two PricingService/addon frozen edits await owner LOCK.
