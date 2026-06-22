# Wizard Studio — UI/UX ultra-review + SUPERVISOR hardening — CONVERGED
**Date:** 2026-06-15 · Branch `goal/wizard-wysiwyg-builder-2026-06-14` HEAD `4a045f37e` (LOCAL, push = owner gate G-PUSH).
Owner: « ultra review the UI et UX et améliore » → « continue la mission as supervisor ».

## VERDICT : ✅ CONVERGED — final adversarial round 0 P0/P1/P2 (agents ran for real, not a rate-limit false-green).

## Method
5-lens UI/UX ultra-review (visual / interaction / a11y / IA-copy / craft) → design-lead synthesis → 19 ranked
elevations, applied + live-verified (Playwright on :8766, this worktree's bundle, real DOM inspection).
Then SUPERVISOR loop: 4 adversarial rounds, each verify-before-heal, live DOM evidence per claim.

## Round-by-round (every heal verified; all prior heals held on re-check)
| Round | Method | Real defects → healed |
|---|---|---|
| Elevation | 5-lens review | VIS-01 dead-space→lit-canvas stage · VIS-03 premium device · VIS-05 row hierarchy/full-width names/contained editor · VIS-04 yellow actionable 0-option flag · VIS-02 SVG icons · VIS-06 responsive · VIS-MOTION+reduced-motion · VIS-PREVIEW crossfade+✓Enregistré · A11Y-E1 inert preview · E2 list semantics+announce · E3 CRUD focus · E4 focus-into-panel · IA-01/02/04/05/06 FR copy (+removed a "prix" leak) (`ea8b8ed38`) |
| 1 (manual+live) | live DOM | stray "↳" breadcrumb in create-state; **A11Y-E4 focus didn't land** → `@after-enter` (`300d74465`,`9addfc490`) |
| 2 (independent wf) | 5 agents | **fragment-root: leading template comment → `this.$el` is a comment node → movePage/removePage focus = silent no-op** (I'd masked the symptom with a `?.()` guard); announce-repeat; empty-bleed; justSaved timer leak (`f1924e55f`) |
| 3 (confirmation wf) | 4 agents | publish-409 dead-end; save-error-in-preview-frame; **WS-MAX "sans limite" WYSIWYG lie**; step_key vs label flag (`4a045f37e`) |
| 4 (final wf) | 3 agents | **0 findings — CONVERGED** |

## Two supervisor saves worth remembering
1. **verify-before-heal rejected a bad agent fix.** The WS-MAX suggestion ("preserve `max_select=0`") would 422:
   `ComposerStepService` REJECTS `max<min`, so "min≥1 + unlimited" is unsupported server-side. Correct fix =
   make `ruleSummary` show the EFFECTIVE saved max (what `payloadForStep`'s `Math.max(raw,min)` persists), so the
   chip never promises a "sans limite" the borne won't honour. Live-verified: no misleading chip remains.
2. **Independent agents out-found a thorough manual+live pass.** The fragment-root `$el`-is-a-comment bug was the
   root cause of focus no-ops; my earlier "fix" was a `?.()` guard that silenced the test error without fixing
   the behavior. Lesson: a stubbed-green Vitest AND a rate-limited "clean" workflow are both non-verification.

## Evidence
- **Vitest 35/35 EXIT 0** (added specs: WS-INT-01 `$el.nodeType===1` + movePage focus via attachTo, announce
  clear-then-set, focusRulePanel, WS-PUBLISH-409, WS-SAVEERR, WS-MAX honest chips).
- **frozen diff 0** across all 15 frozen files (whole wizard-studio branch). **NF525** preserved (no price path;
  removed a stray "prix" mention from copy).
- **Live-verified** at 1440px & 800px: lit-canvas stage; inert preview (6 controls focus-blocked); keyboard
  reorder focus follows the handle; honest rule chips; 0-option flag by step_key; create-state; rule-editor focus.

## Residuals (non-blocking)
- Owner-gated: **G-PUSH** (branch not pushed), G-MEDIA (option image upload), W5 (catalog price), G-POS-COMPOSER.
- System constraint (not a bug): "unlimited upper bound with min≥1" is unsupported (backend `max<min` reject) —
  the UI now honestly reflects this instead of lying.

**Ship-ready V1 sub réserve des seuls gates owner.**
