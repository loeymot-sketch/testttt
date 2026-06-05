# LOCK_POS_WIZARD_FRITES — POS frites Grande/Cheddar structured pricing (M3-02)

> Frozen-zone override authorization. Contract: Owner (human gate) · Claude (planner) · implementer (PR/sub-agent) · safety-check.sh. **DRAFT — owner sign-off pending (§10).**

## §1. Identification
- **LOCK ID**: `LOCK_POS_WIZARD_FRITES`
- **Created**: 2026-06-05
- **Cycle**: pre-cloud goal (`plans/GOAL_100_CLOUD_READY_LECAYENNE_2026-06-05.md`, Wave W2)
- **Phase**: PLAN → (EXECUTE on sign-off)
- **Status**: `DRAFT`

## §2. Frozen file(s) targeted
| Path | Why frozen | Lines targeted |
|---|---|---|
| `public/js/pos-wizard.js` | POS wizard design parfait, strict-no-touch (CLAUDE.md §7) | ~4153, ~4159 (cart-line build) |

## §3. Justification
**Problem (M3-02, verified real):** POS frites **Grande (+1,00)** and **Cheddar (+1,00)** are client-config-priced booleans (`:90-91`, `:1325-1326`) emitted to the server as **`menu_extras` TEXT only** (`:4159`), with `item_extras:{extras:[],names:[]}` empty (`:4153`) — no structured `ItemExtra` id. The server SSOT (`PricingService`) re-tariffs from structured ids per NF525 (§8); `menu_extras` is parsed by **0 files in app/** → the **+2,00 € is dropped** → under-billing + receipt-vs-display mismatch. Evidence: `FROZEN_RISK_AUDIT.md §M3-02`.

**Why no clean non-frozen alternative:** the upgrade signal must leave the wizard as structured data. A non-frozen translate in `PosController::normalizePosRuntimePayload` could map Cheddar (a topping → seedable `ItemExtra`), but **Grande is a SIZE**, not a topping, and cannot co-exist in the `frites_style max_select=1` group — it needs size-modeling at the source. The kiosk already does this correctly (`fritesStyleExtraId` + items #402/#403). The minimal correct fix sends structured data from the POS wizard (the frozen file), so the server can re-tariff. **POS-only** (kiosk unaffected).

## §4. Scope — surgical
**Tasks:**
1. Seed `ItemExtra` rows (`group_label='frites_style'`: Cheddar fondu 1,00 / Cheddar+Oignons 2,00) on the POS frites addon item — **non-frozen seeder** (idempotent, mirrors `MenuResetLeCayenneCommand`).
2. Model **Grande** as a size: prefer the kiosk pattern (item #402 Grande / #403 Normale) OR a priced size variation — **owner decision G2**.
3. In `pos-wizard.js` cart-line build (`:4153`): push the selected upgrade(s) as structured `item_extras{id}` (resolve id from `lastItemData.extras`) instead of the empty array; keep `menu_extras` text display-only.
4. Guard: `menu_extras` must remain non-price-bearing (no double-charge).

## §5. Files to modify
| File | Lines | Change |
|---|---|---|
| `public/js/pos-wizard.js` | ~4153 | push structured `item_extras{id}` for frites upgrades |
| `database/seeders/AlignFritesAddonExtrasSeeder.php` | new | **NON-frozen** seed (no LOCK) |
| (Grande size model — per G2 decision) | — | item/variation seed, non-frozen |

**Read for context:** `KioskWizardComponent.vue` (correct structured path #402/#403 + fritesStyleExtraId), `FritesWizardComposerTest.php:211-228` (proves PricingService prices structured cheddar).
**NOT touched:** `PricingService` (already re-tariffs structured extras correctly), kiosk components, standalone Frites #361/#402/#403 pricing.

## §6. Acceptance criteria (binary)
- [ ] `tests/Feature/Pos/FritesWizardComposerTest.php::test_frites_addon_with_grande_and_cheddar_upgrades` (CREATE) → POST `/api/admin/pos` frites+Grande+Cheddar → `grand_total` includes **+2,00 €**.
- [ ] `FritesWizardComposerTest.php:211-228` (existing) still PASS.
- [ ] `frites_style` `max_select=1` not violated (no multi-inject).
- [ ] `menu_extras` remains display-only (grep: no downstream pricing of `menu_extras`).
- [ ] Kiosk #402/#403 + standalone #361 pricing unchanged (regression).
- [ ] **Playwright E2E**: POS → frites → Grande + Cheddar → wizard preview shows +2,00 → place → receipt shows +2,00 (screenshot Read + analyzed).

## §7. Rollback
1. **Code**: `git revert <patch-sha>` (pos-wizard.js).
2. **Data**: `php artisan db:seed --class=AlignFritesAddonExtrasSeeder` is idempotent; to undo, delete the seeded `ItemExtra` rows by name/group_label (targeted, NOT `migrate:fresh`).
3. **Bundle**: pos-wizard.js is hand-written non-Mix → revert = file restore; hard-refresh POS. (No `npm run` needed for this file.)
4. **Notification**: dev/worktree only; no push without owner.

## §8. Sub-agent + execution path
- **Implementer**: approved remote PR (primary) OR `foodking-complex-implementer` under this LOCK.
- **Verification**: Claude runs §6 + Playwright E2E per goal mandate.

## §9. Safety-check override
- LOCK at `tasks/pre-cloud-goal/LOCK_POS_WIZARD_FRITES.md`.
- Scope marker: `// [LOCK_POS_WIZARD_FRITES] M3-02 structured frites extras — safety-check approved 2026-06-__`.

## §10. Owner sign-off (HUMAN GATE)
> **DO NOT modify the frozen file until APPROVED below.**
- **Owner**: Kossay (owner)
- **Signed at**: ___________________
- **Decision**: [ ] APPROVED  [ ] REJECTED  [ ] NEEDS CHANGES
- **G2 size-model decision** (Grande as item #402/#403 vs priced variation): ___________________
- **Comments/conditions**: ___________________
- Patch sha after APPLIED: ___________________

---
**End of LOCK_POS_WIZARD_FRITES** — risk MEDIUM, POS-only (kiosk already correct). Note: this LOCK + the remote PR are the SAME work — do not double-apply (anti-duplication GOAL §0.5).
