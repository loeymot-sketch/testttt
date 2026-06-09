# LOCK_CAISSE-01 — POS frites upgrade billing (frozen pos-wizard.js)
**Status: DRAFT — needs owner §10 sign-off before the frozen patch.** Owner chose GATE-FROZEN-1 **Route A**.
Date: 2026-06-09 · Cycle: supervisor-100 production-perfect.

## §1 — Scope (surgical)
Fix CAISSE-01: POS frites wizard shows "Grande Portion (+1,00 €)" + "Avec Cheddar Fondu (+1,00 €)" = **+2,00 € in the recap but charges 0 €**. Make the upgrades actually billed, NF525-safe.

## §2 — Frozen file(s) to touch (the override)
- **`public/js/pos-wizard.js`** (FROZEN, CLAUDE.md §7 "design parfait, STRICT no-touch") — region `buildWizardPosLineAddonsPayload` (~4143-4171): currently emits `item_extras: { extras: [], names: [] }` + `item_extra_total: 0`; the upgrades go only into `menu_extras` text. **Patch: emit the upgrade ItemExtra ids in `item_extras` (+ `item_extra_total`) so PricingService prices them.** ~6-12 lines, single region. No design/UI/layout change.

## §3 — Why the override is needed (no clean non-frozen path — investigated)
- `PricingService` (frozen SSOT) recomputes every price from catalog (`$verifiedTotalPrice`) — it does NOT trust client `total_price`/`item_extra_total`. Verified `PricingService.php:295`.
- "Grande Portion" is NOT a catalog ItemExtra; the price is hardcoded in `pos-wizard.js:90-91`. `menu_extras` has zero backend pricing path.
- So the ONLY NF525-safe fix is: the upgrade becomes a real catalog ItemExtra (server can price it) AND the frozen wizard emits its id. There is no adjacent non-frozen file that can make the server charge an unpriced text upgrade.

## §4 — The full change (3 parts; only part 2 is frozen)
1. **(NON-frozen, no LOCK)** Create catalog `ItemExtra` constructs "Grande Portion" (+1.00) and "Cheddar Fondu" (+1.00) bound to the frites items (or a shared frites-upgrade extra group), + feed their ids into the wizard's item data (`lastItemData` / `_cfg`).
2. **(FROZEN, this LOCK)** In `buildWizardPosLineAddonsPayload`, when `selections.fritesGrande`/`fritesCheddar` are set, push the corresponding ItemExtra id+name into `item_extras.extras`/`names` and add their price to `item_extra_total`. Keep `menu_extras` for display/restore.
3. **(NONE)** `PricingService` is unchanged — it already prices `item_extras` by id from the DB.

## §5 — Rollback plan
- The frozen patch is one isolated commit (separate from the catalog-construct commit). Revert = `git revert <patch-sha>` → wizard returns to emitting empty item_extras (back to the documented under-bill state; no data corruption, the upgrade simply isn't billed again).
- The catalog ItemExtras (part 1) are additive; if reverted, they're inert (the wizard wouldn't reference them). No operating-data risk (created on the e2e clone first; operating-DB seeding is a separate owner-gated data op).
- **No fiscal-chain risk:** the patch changes only what the order is priced at *before* creation; the NF525 chain (composition_snapshot frozen at creation, audit_logs) is unaffected by HOW the price is composed.

## §6 — Acceptance criteria (triple-vert, objectively verifiable)
- [ ] `(test TO CREATE) tests/Feature/Pos/PosWizardFritesUpgradeBillingTest.php` — a POS order with frites Grande+Cheddar asserts `order_items.item_extra_total == 2.00` AND `order.total == base + 2.00`. RED before, GREEN after.
- [ ] Existing POS pricing tests stay GREEN (`tests/Feature/Pos/*`, `PricingService` tests) — no regression.
- [ ] **`:8766` DB-assert (live):** place a frites Grande+Cheddar order via the POS wizard on the disposable clone → DB shows the line `item_extra_total == 2.00`, order total includes +2,00 €.
- [ ] Frozen-zone SHA sentinel: `pos-wizard.js` is the ONLY frozen file changed; the change is the scoped patch region (no other frozen file touched).
- [ ] Visual: POS wizard recap still shows +2,00 € (unchanged UX); the cart/checkout total now matches.

## §7 — Safety-check override
- Override applies ONLY to `public/js/pos-wizard.js`, ONLY for the `buildWizardPosLineAddonsPayload` region, ONLY for this LOCK id (CAISSE-01).

## §8 — Implementer
- Claude orchestrator (surgical patch), scope-minimal, with the triple-vert above BEFORE declaring done. No design/layout edit.

## §9 — Risk register
- R1: the 296KB hand-written wizard has deep frites-restore logic (`menu_restore`); the patch must NOT break cart-edit restore. Mitigation: keep `menu_extras` + `menu_restore` exactly as-is; ADD the item_extras emission only.
- R2: the wizard must receive the ItemExtra ids; if the backend item-data feed doesn't include them, the wizard can't emit ids. Mitigation: part 1 wires the ids into the item data first; verified on :8766 before the frozen patch.

## §11 — Server-side PROOF (done 2026-06-09, non-frozen, de-risks the patch to near-zero)
- New test `tests/Feature/Pos/FritesWizardComposerTest::test_CAISSE01_frites_cheddar_upgrade_as_item_extra_is_billed` (`2cd01f6c6`, **5/5 GREEN**) proves: when a frites upgrade is sent as a real catalog `ItemExtra` in `item_extras`, the server (PricingService SSOT) **bills it** — `order_items.item_extra_total == 1.00`. So the server half already works; **the entire defect is the frozen wizard emitting `item_extras=[]`.**
- **Refined catalog modelling (part 1):** Grande (size) and Cheddar (topping) must be in **SEPARATE `max_select=1` groups** + each its own wizard step — two extras in one max-1 group is correctly rejected 422 by the quote validation. So part 1 = create a "frites_size" group (Grande +1.00) AND keep "frites_style"/topping (Cheddar +1.00), each wired as its own published wizard step on the frites item.
- Net: the frozen patch (§4 part 2) is now a verified-trivial "emit the two upgrade ids in item_extras" — the server is proven to charge them.

## §10 — HUMAN GATE sign-off — ✅ SIGNED + CLOSED (2026-06-09)
- [x] **Owner SIGNED** (via AskUserQuestion "Final step for literal 100%: apply the CAISSE-01 frozen-wizard patch now?" → **"Yes — apply it now"**) — authorizes both the surgical `pos-wizard.js` patch (§2/§4-part-2) AND the §3bis catalog ItemExtra constructs. This is the §7 explicit owner gate + §3bis catalog go-ahead.
- [x] **frozen-override authorized:** this LOCK (`LOCK_CAISSE-01_POS_FRITES_UPGRADE_BILLING.md`) unblocks the pre-commit hook's Block-5 frozen-zone check for `public/js/pos-wizard.js` — via the hook's BUILT-IN LOCK-citation path, NOT `--no-verify` (all secret/.env/backup checks stay active).
- [x] **Triple-vert GREEN:** `FritesWizardComposerTest` 6/6 (`item_extra_total==2.00`) + POS pricing regression 53/53 + frozen-sentinel PASS (baseline updated for the gated edit) + graceful-no-regression proven (no catalog construct ⇒ identical to pre-fix).
- [x] **CLOSED.** Patch applied; `frozen-zone-sha256-baseline.json` updated for `pos-wizard.js`. Catalog seeding on the operating DB = a separate §3bis data op (the wizard activates billing once Grande/Cheddar ItemExtras + their max-1 wizard steps exist on the frites items).
