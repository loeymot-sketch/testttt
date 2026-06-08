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

## Evidence (final gate)

- **PHPUnit (full, unfiltered): 3100 tests / 13975 assertions / EXIT 0 / 0 failures** (29 skipped + 2
  incomplete + 1 risky — all pre-existing).
- **Vitest (full): 302 files / 2082 tests / 0 failures** (3 skipped; the runner exit-1 is pre-existing
  teardown async noise — happy-dom AsyncTaskManager + a pre-existing `evil.tld` security spec + the
  known axios-unavailable kiosk-preview logs — not a test failure).
- **0 frozen-zone lines** touched across all of Waves 5/6/7 + heal (`git diff --stat` over the §7 list
  empty at every commit).
- Live: the box full-price math + the personal-page projection verified on the disposable
  `foodking_e2e` clone earlier in the W7 heal phase; the kiosk generic render proven by W6a + the box
  test + the escape-hatch sentinel.

## Honest convergence framing — "all green" scope

"All green" = **non-frozen scope: code + tests (PHP 3100/0 + JS 2082/0) + adversarial P0/P1=0**. Two
items remain **owner-gated (frozen, §7/§10)** and are documented LOCK requests, not shipped:
- **G-1 / GATE-G** — `PricingService` category-inherited composer enforcement (from the earlier W7 audit).
- **GATE-W6** — (a) `composerAddonTotal` for addon-linked box display, (b) POS `generic_choices`
  renderer in `pos-wizard.js` — both block the `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` flip.

Also owner-gated (per §3bis): running `provision --force` on the **operating** `foodking` catalog (the
W4 wizards + any new box/personal pages are reversible published-profile changes).

The kiosk surface — the live V1 path — is fully functional and tested. POS composer support and the
two PricingService/addon frozen edits await owner LOCK.
