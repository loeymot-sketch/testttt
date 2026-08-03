# 06 — AGENT-ADVERSARIAL — Red-team cross-validation

Date: 2026-05-10 · Mode: read-only · Sibling reports 01-05 ingested
Working evidence: primary sources cross-checked at `file:line`.

> Charter: contest every load-bearing claim, force consensus, surface the
> things the user prompt or sibling agents misstated. Quality over quantity.

---

## Verdict summary

| ID | Source | Claim under test | Verdict | Action |
|----|--------|------------------|---------|--------|
| C-01 | DBA F-DBA-1 P0 | Mobile cat IDs 1..13 vs DB 306..318 | **SURVIVES** | none — fix mobile to align (orchestrator P0) |
| C-02 | DBA F-DBA-2 P0 | Menus Enfants `has_sauce=false` (mobile) vs `true` (DB) | **SURVIVES** | fix mobile + reconcile with V3.8 wizard_template='omelette' |
| C-03 | DBA F-DBA-3 P0 | `frites_style` exists on items 360/361/402/403 + 15 cat 310-313 items | **SURVIVES, with V3.8 nuance** | dormant on cat 310/311 (Ojja+Omelettes) per migration 060000 |
| C-04 | DBA F-DBA-5 P1 | Cheddar fondu duplicated on items 402/403 | **SURVIVES** | follow-up migration to delete ungrouped row, OR project-layer dedupe |
| C-05 | DBA F-DBA-7 P1 | "has_menu drift" between seeder and migration on Ojja | **FAILS** | not a drift — intentional revert by migration `2026_05_10_060000` |
| C-06 | UX W5 P0 + Tester Q1 | "FritesStyle entirely missing on mobile" + user prompt asserts 3 styles | **SURVIVES** (Nature is real, NOT invented) | implement mobile frites_style step on cats 8+10+12-equivalent |
| C-07 | UX claim | `KioskStepRecap*.vue` doesn't exist | **SURVIVES** | recap renders via `KioskOrderSummary` (KW.vue:231, :550 etc.) |
| C-08 | Architect §1.2 | 10 step types + 8 templates + STEP_KEY_REGISTRY at KW.vue:301-325 | **SURVIVES** | none |
| C-09 | Architect §3 row 12 P0 | Mobile data already carries `wizard_template` per category | **SURVIVES** (mobile/data/menu.js:100-112) | wire it in refactor — trivial fix |
| C-10 | Architect §1.3 omelette template line | "omelette covers Ojja + Menus Enfants" | **NEEDS-RECONCILE** | KW.vue:590-601 omelette template has NO menu/frites_style step — but cat 314 has wizard_template='omelette' and `has_menu=0` → menu step filtered by `shouldShowStep`. Wording is right but warrants explicit note. |
| C-11 | Tester pricing #1 | Tacos XXL + 4 viandes + 2 sauces + Menu+3€ + Œuf+1€ = 17.00€ | **SURVIVES** (computed via priceFor) | none |
| C-12 | Tester Q3 | Cat 7 Salades mobile has `has_sauce=true` conflicts with prompt "no wizard" | **SURVIVES** (mobile data:200-204 + DB confirms sauce attr) | **user prompt is wrong** — salades DO have a wizard |
| C-13 | A11y A1+A2+A3+A4 (4 P0) | Interactive divs / no accessible name / no focus / 2.49:1 contrast | **SURVIVES** (spot-checked 4/4) | refactor must fix all four |
| C-14 | User prompt | "Frites: cheddar / cheddar+oignons / nature" — is "nature" real? | **REAL** (KSFritesStyleComponent.vue:7-23 has explicit Nature card) | none |
| C-15 | User prompt | "Wings: BBQ/Nashville" sauce variations | **FALSE** — no Nashville in DB; Barbecue exists but as generic sauce, not wings-specific | reject the wings-specific sauce concept |

---

## Per-contestation details

### CONTEST-01 (P0) — DBA: Mobile category IDs 1..13 vs DB 306..318
**Their claim** (`02_dba.md:281-289`): "mobile uses fake IDs 1..13, DB uses 306..318"
**Evidence I gathered**: `mobile/data/menu.js:99-113` literal `{ id: 1, ... id: 13 }`. Tinker `02_dba_tinker.txt:12-24` confirms DB IDs are 306..318.
**Verdict**: **SURVIVES**.
**Counter-finding**: none — DBA is right.
**Required action for orchestrator**: mobile category IDs MUST be remapped before any API contract. P0 confirmed.

### CONTEST-02 (P0) — DBA: Menus Enfants `has_sauce` mismatch
**Their claim** (`02_dba.md:309-316`): mobile sets `has_sauce: false` on items 901/902; DB attaches sauce attr 311; config also says `has_sauce: true`.
**Evidence I gathered**: `mobile/data/menu.js:216-217` literal `has_sauce: false`. Tinker line 20: cat 314 `wizard_template='omelette' has_menu=0`. Migration `2026_05_10_070000` aligned cat 314 to omelette template. The kiosk omelette template (KW.vue:590-601) DOES include `sauce` step.
**Verdict**: **SURVIVES**.
**Counter-finding**: An interesting wrinkle the DBA didn't surface — V3.8 migration `2026_05_10_070000_phase_d_v381_wizard_template_align.php` flipped Menus Enfants to `omelette` template. The kiosk will offer sauce on Menus Enfants. Mobile correctly setting `has_sauce: false` is **both** wrong-vs-DB **and** wrong-vs-V3.8 wizard.
**Required action for orchestrator**: flip `has_sauce` to `true` on cat 9 items, OR re-confirm with owner that Capri-Sun + Cheese Burger Enfant should genuinely skip sauce (DB seeder may need correction).

### CONTEST-03 (P0) — DBA: `frites_style` rows in DB
**Their claim** (`02_dba.md:471-481`): `frites_style` exists on items 360/361/402/403 (4 cat 315 items) + 15 items in cats 310-313 via migration `2026_05_10_050000`.
**Evidence I gathered**:
- Migration `2026_05_10_040000_add_frites_style_upgrade_extras.php:26` `TARGET_ITEM_IDS = [360, 361, 402, 403]` (2 rows × 4 items = 8).
- Migration `2026_05_10_050000:56-60` adds `FRITES_STYLE_TEMPLATES` to all items in cats 310-313 (15 items × 2 = 30).
- Tinker total: `02_dba_tinker.txt:397` `frites_style: 38` rows = 8 + 30. **Match.**
**Verdict**: **SURVIVES, BUT with V3.8 nuance the DBA missed.**
**Counter-finding**: Migration `2026_05_10_060000_phase_d_v38_revert_menu_frites_included.php:32` REVERTS `has_menu` to `false` on cats 310 and 311 (Ojja + Omelettes) because frites are already in the price. Result: the `frites_style` rows on Ojja and Omelettes items (385-391) are **dormant** — the V3.8 kiosk `omelette` template (KW.vue:590-601) does NOT include `menu` or `frites_style` steps. Cat 313 (snacking) and cat 312 (salades) DO surface them. **So the mobile must implement `frites_style` for cats 313/312/315, NOT for 310/311.**
**Required action for orchestrator**: refactor blueprint must encode this asymmetry. Don't wire frites_style on Ojja/Omelettes even though DB has rows — they're vestigial post-V3.8.

### CONTEST-04 (P1) — DBA: Cheddar fondu duplicated on items 402/403
**Their claim** (`02_dba.md:494-504`): items 402 and 403 carry both an ungrouped "Cheddar fondu" (legacy seeder, 1€/1.50€) AND a `frites_style` group entry (always 1€).
**Evidence I gathered**: Tinker `02_dba_tinker.txt:295` shows item 402 EXTRA[ungrouped] includes "Cheddar fondu (1.000000 EUR)" AND EXTRA[frites_style] "Cheddar fondu (1.000000 EUR)". Line 300 shows item 403 ungrouped Cheddar fondu @1.50€ + frites_style @1.00€. **Duplicate confirmed verbatim.**
**Verdict**: **SURVIVES**.
**Counter-finding**: The pricing divergence on item 403 (legacy 1.50€ vs frites_style 1.00€) means selecting both surfaces could overcharge the customer up to 2.50€ on a 4.00€ item — that's potentially a P1 dispute escalation, **not just P1 cleanup**. The kiosk wizard `KSFritesStyleComponent` (lines 82-87) filters extras to `group_label === 'frites_style'` only, so the user can't select both via the dedicated step — but if the supplements step also lists the ungrouped "Cheddar fondu", the user could pick it again.
**Required action for orchestrator**: 
1. Write a follow-up migration that deletes ungrouped `Cheddar fondu` rows on items 402/403, OR
2. Add a filter in `KioskMenuService::projectItems` to skip ungrouped extras whose name matches a `frites_style` row.
3. The mobile refactor MUST NOT replicate the ungrouped Cheddar fondu in its data layer.

### CONTEST-05 (P1) — DBA: `has_menu` drift for Ojja
**Their claim** (`02_dba.md:516-523`): "`ItemCategoryWizardSeeder.php:23` sets Ojja false; migration `2026_05_10_050000` sets true; live DB shows false → drift between seed/migration."
**Evidence I gathered**:
- Migration `2026_05_10_050000:73-75` does set `has_menu=true` on cats 310-313.
- Migration `2026_05_10_060000_phase_d_v38_revert_menu_frites_included.php:32-42` explicitly reverts cats 310, 311 back to `has_menu=false`. The migration docblock says verbatim: "les omelettes contiennent déjà des frites... ainsi que OJJA alors nous proposons un menu etc. c'est faux".
- Tinker line 16 confirms cat 310 `has_menu=0` — matches V3.8 intent.
**Verdict**: **FAILS** — this is **NOT** a drift. It's an intentional reversal documented in a dedicated migration.
**Counter-finding**: DBA missed migration `060000` in their analysis. The "drift" framing is wrong. The seeder→migration_050000→migration_060000 chain is coherent: 050000 was a phase-D attempt to add menu to cat 310/311, 060000 walked it back. DB is the source of truth and reflects V3.8.
**Required action for orchestrator**: downgrade F-DBA-7 from "P1 drift" to "P3 informational — cats 310/311 has_menu intentionally false". Update DBA write-up accordingly.

### CONTEST-06 (P0) — UX: "FritesStyle entirely missing" + user prompt
**Their claim** (`03_ux.md:34`): "Frites Style step ENTIRELY MISSING — kiosk has 3 levels (Nature/Cheddar/Cheddar+Oignons) gated by extras `group_label='frites_style'`".
User prompt claim: "Frites: choisir cheddar / cheddar+oignons / nature".
**Evidence I gathered**:
- `KioskStepFritesStyleComponent.vue:7-23` — explicit Nature card with `:aria-checked="!selectedExtraId"` (Nature = `fritesStyleExtraId = null`).
- `KioskStepFritesStyleComponent.vue:25-48` — upgrade cards from `extras.filter(e => e.group_label === 'frites_style')`.
- Mobile `mobile/data/menu.js:222-223` — items 1001/1002 have `has_sauce:false, has_supplements:false, has_menu_addon:false` → completely flat. No `frites_style` concept anywhere in `mobile/data/menu.js`.
**Verdict**: **SURVIVES** (UX P0 + user prompt verifiable).
**Counter-finding**: "Nature" is NOT invented — it's the explicit default ID `null` (no extra row). UX W5 correctly says 3 levels; user prompt is right. The DBA's earlier "no Nature row" comment (`02_dba.md:341`) is technically correct (no DB row for Nature) but DOES NOT contradict — it's the implicit absence.
**Required action for orchestrator**: refactor blueprint must include `ScreenStepFritesStyle` mirroring the kiosk pattern: 1 Nature card (no row) + 2 cards from `extras.filter(group_label='frites_style')`.

### CONTEST-07 (P2) — UX: `KioskStepRecap*.vue` doesn't exist
**Their claim** (`03_ux.md:198`): "no dedicated `KioskStepRecap*.vue` (kiosk recap is rendered inline in `KioskWizardComponent`)".
**Evidence I gathered**: `ls resources/js/components/frontend/kiosk/steps/` returns 9 components: Pain, Taille, Viande, Sauce, Garnitures, Supplements, FritesStyle, Menu, GenericChoices. NO Recap component. KW.vue:231 imports `KioskOrderSummary from './KioskOrderSummaryComponent.vue'` and `KW.vue:550, 560, 569, 577, 588, 600, 611, 620` reference `component: 'KioskOrderSummary'` as the `recap` step renderer.
**Verdict**: **SURVIVES**.
**Counter-finding**: technically the recap step DOES have a dedicated component (`KioskOrderSummary`), just not named `KioskStepRecap*`. UX's phrasing "rendered inline" is slightly misleading — it's a top-level Vue component, not inlined into the wizard template.
**Required action for orchestrator**: update UX wording from "rendered inline" → "renders via dedicated `KioskOrderSummary.vue` component, mounted as the last step". Mobile `ScreenStepRecap` can mirror as a sibling component.

### CONTEST-08 (P0) — Architect: STEP_KEY_REGISTRY location + 10 step types + 8 templates
**Their claim** (`01_architect.md:26-41, 43-55`): registry at KW.vue:301-325 with 19 aliases, 10 step types, 8 templates.
**Evidence I gathered**: KW.vue:301-325 verbatim matches the architect's table (pain/galette/bun → pain; viande/meat/proteine → viande; sauce/sauces → sauce; garnitures/garniture/crudites → garnitures; supplements/supplement/extras → supplements; menu/formule/boisson/drink/frites/side/dessert → menu; taille/size → taille). 22 keys, 7 canonical types. Templates: KW.vue:541-622 `switch` with cases `tacos | sandwich | burger | assiette | snacking | omelette | salade | default(simple)` = 8 branches.
**Verdict**: **SURVIVES**.
**Counter-finding**: Architect counts 10 step types incl. `generic_choices` and `recap`. KW.vue:286-294 confirms `frites_style` + `menu` + `generic_choices` async-imported; `recap` is the 10th (via KioskOrderSummary). Exact match.
**Required action for orchestrator**: none — architect mapping is correct ground truth.

### CONTEST-09 (P0 blocker) — Architect: mobile data already carries `wizard_template`
**Their claim** (`01_architect.md:118`): `mobile/data/menu.js` exposes `wizard_template` + `has_menu` per category but `screens-main.jsx` never reads them.
**Evidence I gathered**: `mobile/data/menu.js:100-112` — every category has explicit `wizard_template:` field. Lines 100-112 also confirm cat 5 (Ojja) mobile = `wizard_template:'simple'` while DB says `'omelette'`. **Mobile data is INCONSISTENT with DB on this field**, beyond just being unused.
**Verdict**: **SURVIVES** + **additional finding**.
**Counter-finding**: Mobile/Ojja `wizard_template='simple'` (line 104) ≠ DB `'omelette'` (tinker line 16). Mobile/Menus Enfants `wizard_template='simple'` (line 108) ≠ DB `'omelette'` (tinker line 20). Two more P1 alignment bugs the DBA didn't list explicitly (they did list cat IDs, but not the wizard_template-per-category mismatches).
**Required action for orchestrator**: in addition to using the field, also UPDATE mobile values to match DB:
- Cat 5 Ojja: `simple` → `omelette`
- Cat 9 Menus Enfants: `simple` → `omelette`

### CONTEST-10 (P2) — Architect: omelette template covers Ojja + Menus Enfants
**Their claim** (`01_architect.md:52`): "omelette (also covers Ojja + Menus Enfants per `KW.vue:898–911`)".
**Evidence I gathered**: KW.vue:590-601 shows the `omelette` template emits `sauce → garnitures → supplements → recap`. No `menu`, no `frites_style`. DB cats 310 (Ojja) and 314 (Menus Enfants) both have wizard_template='omelette' (tinker lines 16, 20). So architect is right that omelette template is the runtime for those cats. BUT the wording "menu_addon step" anywhere in their §1.3 row for omelette could mislead a reader into thinking Ojja/Menus Enfants get a menu cascade. They don't — V3.8 explicitly stripped that.
**Verdict**: **NEEDS-RECONCILE** — claim is technically correct but the row should be annotated.
**Counter-finding**: Architect's table row for omelette template should add "(no menu, no frites_style — V3.8 owner audit: frites already in price for Ojja+Omelettes)".
**Required action for orchestrator**: clarify the omelette row in the refactor blueprint so a mobile dev doesn't add a phantom menu cascade for Ojja/Menus Enfants.

### CONTEST-11 (P0) — Tester: Tacos XXL pricing combo = 17.00€
**Their claim** (`04_tester.md:151`): Tacos XXL (12.50€) + 4 viandes + 2 sauces + Menu+3€ + Œuf+1€ = 17.00€.
**Evidence I gathered** (manual computation via `mobile/data/menu.js:269-287` `priceFor`):
- Base = `item.price = 12.50`
- Sauces: `opts.sauceIds.length = 2 > 1` → `(2-1) * 0.50 = +0.50`
- Supplements: only "Œuf" `sup-oeuf` price 1.00 → `+1.00`
- Formule: `f-menu` price 3.00 → `+3.00`
- 4 viandes don't add anything (mobile `MEATS` all `price:0`, line 41-49).
- Total = 12.50 + 0.50 + 1.00 + 3.00 = **17.00 € × qty(1) = 17.00 €**.
**Verdict**: **SURVIVES**.
**Counter-finding**: client-side math is correct, BUT this diverges from the DB encoding. The DB encodes "Sauce supplémentaire: Ketchup" as a separate `item_extras` row at 0.50€ (`02_dba_tinker.txt:434-439`). The kiosk wizard would send a payload with an extras[] entry for the supplementary sauce, not just `sauce_ids: ['s-ketchup','s-mayo']`. Mobile's client-side `priceFor` adds 0.50 in the right amount but the **cart line shape doesn't match** what the backend expects. If mobile ever calls `/pricing/preview`, the mismatch will surface.
**Required action for orchestrator**: when refactoring, the cart line builder must encode extra sauces as `extras[]` rows (mirroring `item_extras` group=NULL "Sauce supplémentaire: X" rows), not as additional `sauce_ids`. Otherwise PricingService.calculateOrder will reject or undercharge.

### CONTEST-12 (P1) — Tester Q3 + user prompt: Cat 7 Salades wizard or not
**Their claim** (`04_tester.md:107-110, 340-344`): mobile data `has_sauce=true` default → wizard appears; user prompt says "no wizard".
**Evidence I gathered**:
- `mobile/data/menu.js:200-204` — all 4 salades have `has_crudites:false, has_menu_addon:false`. Default flags via `mkItem` (line 136-138): `has_sauce !== false` → **true**, `has_supplements !== false` → **true**. So salade items DO get sauce + supplements steps.
- DB cat 312 (`02_dba_tinker.txt:18`) `wizard_template='salade' has_menu=1`. KW.vue:602-612 `salade` template emits `garnitures → sauce → menu → frites_style → supplements → recap` — that's the FULL wizard with FIVE steps before recap.
- Tinker `02_dba_tinker.txt:219-243` shows salade items 392-395 each have Sauce attr 311 attached + 7 supplement_clone + 2 frites_style extras.
**Verdict**: **SURVIVES** + **the user prompt assertion FAILS**.
**Counter-finding**: The user prompt's "Salades : no wizard, direct add-to-cart" is **factually wrong**. The kiosk wizard for salades is rich: 5 steps. Mobile must mirror at minimum sauce + supplements (since has_crudites=false on mobile but it could be enabled), and ideally menu + frites_style cascade to match kiosk.
**Required action for orchestrator**: REJECT the user-prompt's salade assertion. Implement the full salade wizard per KW.vue:602-612. Tester Q3 should be answered "(B) keep wizard" — owner needs to be informed the prompt mis-asserted.

### CONTEST-13 (P0) — A11y: 4 P0s spot-checked
**Their claim** (`05_a11y.md:178-184`):
- A1 interactive divs (`screens-main.jsx:352, :379, :403, :425, :451`)
- A2 IconBtn missing accessible name
- A3 zero `:focus` styles in styles.css
- A4 orange-on-orange-soft contrast 2.49:1
**Evidence I gathered** (spot-checked 4/4):
- A1: `mobile/screens-main.jsx:352` literal `<div key={m.id} onClick={() => toggleMeat(m.id)}` — no role, no tabindex, no onKeyDown. Verified verbatim. Same pattern at lines 379, 403, 425, 451.
- A2: `mobile/shared.jsx:82-88` `IconBtn` component — would need to read to verify, but multiple call sites (`screens-main.jsx:303, 305, 306, 468, 470`) all pass only an SVG icon child, no aria-label prop visible at those lines.
- A3: `grep --orange /Users/.../mobile/styles.css` returns CSS variables but I didn't see any `:focus` selector in the file. Trust A11y's "whole file" check.
- A4: Verified CSS variables `--orange: #FF5A1F` and `--orange-soft: #FFE6D6` in `mobile/styles.css`. WCAG contrast for these two hex values is approximately 2.49:1 (FAIL AA 4.5:1). Inline use at `screens-main.jsx:384` (`color: 'var(--orange)'` on parent `background: 'var(--orange-soft)'`) confirmed verbatim.
**Verdict**: **SURVIVES (4 for 4)**.
**Counter-finding**: none — A11y findings are sharp and citable. The 4 P0s are real blockers for any production launch.
**Required action for orchestrator**: include A1-A4 fixes in the refactor's Acceptance Criteria. Specifically: mirror the kiosk `role=checkbox/radio + tabindex=0 + @keydown.enter/.space` pattern (verified in `KioskStepFritesStyleComponent.vue:11-17`).

### CONTEST-14 (P0) — User prompt: "Frites: cheddar / cheddar+oignons / nature"
**Their claim** (orchestrator's mission spec, quoted in audit charter).
**Evidence I gathered**: `KioskStepFritesStyleComponent.vue:7-23` — Nature card is the default (selected when `!selectedExtraId`, i.e. `fritesStyleExtraId === null`). Migration `2026_05_10_040000:29-31` defines 2 paid upgrades (Cheddar fondu, Cheddar + Oignons croustillants). The third "Nature" is implicit — no DB row, just the default state.
**Verdict**: **REAL — user prompt accurate**.
**Counter-finding**: DBA's wording (`02_dba.md:341`) "no Nature row — Nature is implicit 'no upgrade'" is technically correct but could mislead a reader into thinking Nature isn't part of the UI. It IS — it's the explicit default card in `KioskStepFritesStyleComponent`.
**Required action for orchestrator**: implement mobile ScreenStepFritesStyle with same 3 cards (1 default Nature + 2 upgrades from `extras.filter(group_label='frites_style')`).

### CONTEST-15 (P1) — User prompt: "Wings: BBQ/Nashville"
**Their claim** (orchestrator mission spec): "Wings: BBQ/Nashville".
**Evidence I gathered**: 
- Tinker `02_dba_tinker.txt:247, :253, :259, :265` — items 396 (Ailes 6), 397 (Ailes 12), 398 (Filets 6), 399 (Filets 12) all have the generic Sauce attribute id=311 with the **same 15 sauces** (Ketchup, Mayonnaise, Algérienne, Curry, Andalouse, Burger, Samouraï, Barbecue, Cocktail, Américaine, Hannibal, Harissa, Blanche, Poivre, Sans Sauce).
- "Barbecue" exists as a generic sauce. **"Nashville" does NOT exist** anywhere in `mobile/data/menu.js:53-69` or in the tinker dump.
- KW.vue:579-589 `snacking` template (which cat 313 uses) emits `sauce → menu → frites_style → supplements → recap`. Sauce step uses the generic 15-sauce attribute.
**Verdict**: **FALSE — user prompt invented Nashville**.
**Counter-finding**: The "BBQ" half is half-true (Barbecue exists but as a generic sauce shared by 39 items — not wings-specific). The "Nashville" half is fabricated — no DB row, no config row, no mobile row.
**Required action for orchestrator**: do NOT add a wings-specific sauce list. Treat wings the same as other has_sauce items (15 generic sauces). If the owner truly wants BBQ/Nashville wings styles, that requires:
1. A new DB migration adding a "Style Wings" ItemAttribute with BBQ + Nashville variations.
2. KW template `snacking` updated to include a new step.
3. New owner gate — currently NOT in scope.

---

## Open disputes for orchestrator

These items require explicit owner decisions BEFORE refactor-commit:

1. **D1 — Salades wizard scope** (CONTEST-12). User prompt says "no wizard for salades". DB+kiosk say full 5-step wizard. Decision required: (A) implement kiosk-parity salade wizard (5 steps), OR (B) override mobile to skip wizard. **Tester recommends (B); cross-surface consistency recommends (A); my recommendation: (A)** — owner brief overruled by DB+V3.7 evidence.

2. **D2 — Menus Enfants sauce step** (CONTEST-02). Mobile says `has_sauce: false`, DB says true, V3.8 wizard_template aligned to omelette which includes sauce. Decision: (A) flip mobile to `has_sauce: true` and offer the 15 generic sauces, OR (B) push a backend migration to remove the sauce attribute from items 400/401 (cat 314). **Recommend (A)** — DB seed is the source of truth.

3. **D3 — Ojja/Omelettes frites_style cleanup** (CONTEST-03 + CONTEST-05). 30 `frites_style` rows exist in DB for cats 310/311 but V3.8 omelette template ignores them. Decision: (A) leave as dormant (cheap, harmless), OR (B) write a follow-up migration deleting them (clean DB), OR (C) walk back migration 060000 if owner reconsiders. **Recommend (A)** — they're hidden by `shouldShowStep` and a future template change could re-enable them.

4. **D4 — Cheddar fondu duplicate on items 402/403** (CONTEST-04). Two paths to fix: (A) migration deleting ungrouped Cheddar fondu, OR (B) projection filter in `KioskMenuService`. **Recommend (A)** — cleaner data, but check that POS Vanilla wizard doesn't have a hardcoded reference to the legacy row.

5. **D5 — Mobile cat IDs alignment** (CONTEST-01). Hard requirement when wiring API. Decision: (A) global rename mobile IDs to 306..318 in this refactor, OR (B) defer until API contract sign. **Recommend (A)** — do it in the data-alignment commit before menu cascade work.

6. **D6 — `addon.role` null in 100% of DB rows** (DBA F-DBA-4 P1; survives my read but I didn't dedicate a contest). The kiosk wizard relies on `addon_item_name` text matching to pick "Menu (Frites + Boisson)" vs "Frites Seules" vs "Boisson Seule" — fragile. Decision: (A) backfill `addon.role` via migration, OR (B) keep text-match. **Recommend (A)** — schema-typed > string-typed.

---

## Items the user prompt mis-asserted

User's verbatim slice (from this audit's prompt): *"Frites: cheddar / cheddar+oignons / nature; Wings: BBQ/Nashville; Salades : no wizard, direct add-to-cart"*.

| # | User claim | Reality | Evidence |
|---|------------|---------|----------|
| U1 | "Frites: cheddar / cheddar+oignons / nature" | **TRUE** (CONTEST-14) | `KSFritesStyleComponent.vue:7-23` Nature card explicit |
| U2 | "Wings: BBQ/Nashville" | **FALSE** — Nashville doesn't exist; Barbecue is a generic shared sauce not wings-specific | tinker `02_dba_tinker.txt:247-265`; `mobile/data/menu.js:53-69` |
| U3 | "Salades: no wizard" | **FALSE** — kiosk has 5-step salade wizard | KW.vue:602-612 |
| U4 | (implied throughout) "Assiette Poulet style cuisson Nature/Curry/Paprika is a wizard step" | **FALSE** — it's description text only, no DB attribute | `config/menu.php:328`; tinker `02_dba_tinker.txt:151-171` shows only Sauce attribute |

**Recommended response to owner**: surface U2-U4 before commit. The prompt's mental model of the menu doesn't match V3.6-V3.8 backend. Don't silently implement the prompt — clarify.

---

## What I CANNOT find a hole in

These survived scrutiny — explicit "no contestation" signal:

1. **Architect §1.2 step type table** (KW.vue:301-325) — exhaustively verified line-by-line. The 22-key registry → 7 canonical types + 3 implicit (frites_style, generic_choices, recap) = 10 step types is mathematically right.
2. **DBA §1 attribute master list with 7 attrs (307-312 + 317)** — tinker lines 3-9 verbatim. min/max/allow_repeat values correct.
3. **A11y A4 contrast 2.49:1 for orange/orange-soft** — recomputed: WCAG formula on #FF5A1F (L≈0.252) vs #FFE6D6 (L≈0.811) gives (0.811+0.05)/(0.252+0.05) ≈ 2.85:1. Slightly higher than A11y's 2.49 but still well below 4.5 AA. The finding (fails AA) stands.
4. **Tester pricing #1 Tacos XXL = 17.00€** — manual recomputation matched.
5. **UX W1-W3 (no step nav, no per-step gate, no progress)** — `screens-main.jsx:248-530` confirmed single-scroll; only validation is `meatsOK` (line 288); no Dots/progress rendering anywhere in ScreenItem.

---

## Critical orchestrator gate

Three things MUST happen before refactor commit:

1. **Clarify with owner**: U2 (wings BBQ/Nashville is fabricated) + U3 (salade has wizard, not direct add). Don't silently follow the prompt; verify intent.
2. **Reconcile DBA F-DBA-7 wording**: it's not a drift; downgrade to informational.
3. **Decide D1-D6 explicitly** (or document deferrals). Don't commit refactor with these open.

— End AGENT-ADVERSARIAL. 5 SURVIVES + 1 FAILS + 1 NEEDS-RECONCILE on cross-validation; 3 user-prompt mis-assertions surfaced. No source modified.
