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

## §10. Owner sign-off (HUMAN GATE) — APPROVED 2026-06-05, but ⛔ EXECUTION HALTED (approach technically invalid)
> Owner gave explicit countersign this session (AskUserQuestion): **"APPROVED + model Grande as size"**,
> implementer = apply-locally. **However, execution is HALTED before any frozen edit** because §11
> (post-approval verification) proves the approach this LOCK scoped is **technically invalid** — it would
> modify the frozen file and still NOT bill the +2 €. Re-escalated to owner with corrected options. The
> frozen `pos-wizard.js` was NOT touched.
- **Owner**: Kossay (owner)
- **Signed at**: 2026-06-05 (approval received; execution blocked pending re-decision per §11)
- **Decision**: [x] APPROVED (scope as written) — but [x] NEEDS CHANGES (scope is invalid, see §11)
- **G2 size-model decision** (Grande as item #402/#403 vs priced variation): owner chose "model Grande as size" — but see §11: no priced channel exists for addon-item extras regardless of size modeling.
- **Comments/conditions**: DO NOT apply the §4 fix as written. Awaiting owner re-decision among §11 options (DEFER recommended).
- Patch sha after APPLIED: — (none; not applied)

## §11. POST-APPROVAL VERIFICATION FINDING — the §4 fix is technically invalid (2026-06-05)
**Empirical trace (worktree `pre-cloud-exec`, this session) overturns the §3 premise:**
- The POS wizard's frites line is dispatched (`pos-wizard.js:4218` `data-wizard-pos-line-addons` → `ItemComponent.vue:1344`) into **`pos_line_addons`**, NOT into the priced `items[]`.
- **`grep -rn "pos_line_addons" app/` = 0 hits.** The server NEVER reads `pos_line_addons` → the frites line's `item_extras` (whatever shape) is **pure client display metadata, never priced.**
- The only server-priced channels (`PricingService.php`): main-line **`item_extras[]`** (`:169-191`) and **`item_addons[]`** (`:193-228`), both summed into `$unitSum` (`:233`).
- **`item_extras` is cross-item-guarded** (`:182` → 422 `"Extra n'appartient pas à l'article"`): a frites ItemExtra cannot be attached to the sandwich main line. Even with the guard off it would mis-attribute the extra on the fiscal record (NF525 defect) — not a clean path.
- **`item_addons` prices only `dbAddon->addonItem->price`** (fixed, role-ratio'd, `:224-228`): the frites BASE rides this via the menu-formule addon, but there is **no priced channel for addon-item upgrades** (Grande/Cheddar).
- **`grep -rn "menu_extras" app/` = 0 hits** → confirmed never priced.

**Consequence:** populating `item_extras{id}` at `:4153` (the §4 fix) touches the frozen file for nothing — the +2 € still drops. The defect is REAL (kiosk avoids it by modeling frites as standalone items #402/#403 on the priced `items[]`), but the V1 fix requires one of:
- **(A) DEFER** — document as known minor post-V1 under-billing (+2 € only on the optional frites upgrade; pattern of S13-02/M3-01/M8-01). **RECOMMENDED** — lowest risk, matches V1 envelope.
- **(B) Server-side addon-extra pricing** — make the server price `pos_line_addons[].item_extras`. Touches **`PricingService` (a DIFFERENT frozen file this LOCK never named, owner never signed)** → needs its own LOCK + countersign.
- **(C) Frites-as-standalone-item rearchitecture** (kiosk #402/#403 pattern in POS) — large frozen `pos-wizard.js` change + cart restructure; highest risk.

**Discipline note:** routing into `PricingService` under THIS pos-wizard LOCK would exceed the countersigned scope (the exact class of violation the harness blocked at `06e3f305f`). Therefore HALT + re-escalate, not silent scope-creep.

## §12. OWNER RE-DECISION 2026-06-05 — DEFER (post-V1 backlog)
Presented §11's corrected options (AskUserQuestion). **Owner selected option (A): DEFER — document as known
minor post-V1 under-billing.** Rationale: +2 € only on the optional frites Grande/Cheddar upgrade; cost of
a clean fix (new frozen `PricingService` LOCK, or a frozen wizard rearchitecture) is disproportionate to a
V1-LOCAL single-restaurant envelope. Same disposition pattern as S13-02 (deferred) / M3-01 / M8-01.
- **Backlog item**: `M3-02` — POS frites Grande/Cheddar upgrade (+2 €) not billed (pos_line_addons unpriced).
  Revisit if/when frites pricing is reworked or POS moves to the kiosk standalone-item model. NOT a V1 blocker.
  **Scope note (broader than the two booleans):** the root cause is structural — `addonToPayload` builds the
  whole frites line into `pos_line_addons`, which the server never prices. ANY surcharge on that addon line
  (e.g. extra "Sauce frites") rides the same unpriced path; main-item supplements are fine (they price via the
  Vue checkbox-sync). So the eventual fix is "**price the addon line**", not "price the two frites flags".
- **No code changed.** Frozen `pos-wizard.js` + `PricingService` untouched (frozen-diff 0).
- **Remote PR**: must NOT apply the §4 fix (invalid). If frites pricing is addressed later, use §11 option B or C under a fresh, correctly-scoped LOCK.

---
**End of LOCK_POS_WIZARD_FRITES** — **STATUS: DEFERRED post-V1 (owner decision 2026-06-05, §12).** Approved scope was invalid (§11); no frozen edit applied. Risk MEDIUM, POS-only. Anti-duplication: this LOCK + the remote PR are the SAME work — neither applies the invalid §4 fix (GOAL §0.5).
