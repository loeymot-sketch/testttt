# RESUME — Wizard WYSIWYG Visual Builder

**Branch:** `goal/wizard-wysiwyg-builder-2026-06-14` (worktree `.claude/worktrees/wizard-wysiwyg-2026-06-14`)
**Plan:** `plans/GOAL_WIZARD_WYSIWYG_VISUAL_BUILDER_2026-06-14.md`
**Read-only capture server:** `:8766` → `foodking_e2e` (clone, GET-only). Other sessions: `integration-v1` worktree on `:8770/8774/8780` (avoid).
**Disk:** ~4G free (100%). vendor/node_modules SYMLINKED in worktree (0 cost). No new worktree. Avoid big rebuilds.

## Phase status
- [x] ORCHESTRATE — read state, SYSTEM_MAP, prior wizard work, decomposition (3 agents), captures (idle + live wizard item41)
- [x] PLAN — mega-plan written + committed (+ supervisor audit: dropped G-PRICE, D-2→iframe→direct-mount)
- [x] W0 scaffold (route + shell) — `746da44c0` / `48fb029d0`
- [x] W1 LIVE PREVIEW DONE — `48fb029d0`+`5cb5311ec`+`de6d2a5a5`. Backend `previewProjection` endpoint (read-only, NF525-safe, route OUTSIDE per-item guard); frontend direct-mounts the REAL frozen KioskWizardComponent fed the draft (defineAsyncComponent). VISUALLY PROVEN live on foodking_e2e (category 1 'Sandwich Cayenne', 6 steps vertical render, 0 console errors). Vitest 3/3 + PHPUnit artifact. Frozen diff 0. Screenshot: wizstudio-03-category-live-fixed.png.
  - NOTE item-studio needs FEATURE_WIZARD_PER_ITEM_DEMO (existing gate G-WIZ-1); CATEGORY studio works unflagged (V1 path).
  - WORKTREE SERVE NOTE: needed real vendor (cp -Rc un-shadow — symlink caused vendor-shadow), worktree .env (APP_ENV=local to skip boot guards, DB=foodking_e2e), + storage/framework dirs. Server :8766 = worktree code on foodking_e2e (local env). admin@lecayenne.fr / 123456.
- [ ] W2 structure edit (drag/add/reorder) + reloadPreview() wired + 409 UX
- [ ] W3 selection rules UI
- [ ] W4 inline options + images (additive migration)
- [ ] W5 billing modes Free + Each-priced (non-frozen)
- [ ] W6 templates turnkey (fix source_ref='')
- [ ] W7 POS preview tab
- [ ] W8 e2e end-to-end loop
- [ ] GATES owner: G-PRICE / G-POS-COMPOSER / G-MEDIA / G-PUSH

## Owner gates (do NOT self-sign)
- G-PRICE: PricingService (frozen) for data-driven first-N-free/quota/bundle billing.
- G-POS-COMPOSER: flip FK_POS_WIZARD_COMPOSER_AWARE_ENABLED.
- G-MEDIA: option image storage (default: storage/app/public symlink).
- G-PUSH: push branch.

## Next action
Start W0: scaffold `WizardStudio` route + shell component (copy, new route), parity-test stub.
</content>
