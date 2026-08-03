# 02 — Data Drift Audit (mobile vs SSOT + DB)

> AGENT-2 DATA-DRIFT-AUDITOR · 2026-05-11
> Branch `feature/mobile-app-le-cayenne-2026-05-10` · HEAD `ebb712dd8`
> SSOT: `config/menu.php` + live DB (Items/ItemAttribute/ItemAddon/ItemExtra)
> Mobile target: `mobile/data/menu.js` (60 items, 13 categories)
> Prior audit reference: `reports/review/mobile-audit-2026-05-10/02_dba.md`

---

## Executive summary

`mobile/data/menu.js` (491 lines, 60 items) is still meaningfully drifted from
the live DB. Out of 60 mobile items, **47 map cleanly to a DB item by slug+
price**, **5 mobile items are display-only items with no DB counterpart**
(matched indirectly via cat 13 supplements items), and **8 substantive
structural drifts** persist — including **two P0 category-level `has_menu`
flips** (`nos-salades` and `chicken-tenders` went `has_menu=true` in DB but
mobile still says `false`), the `frites_style` cascade step missing from cat
10 frites items, and the `Le Suprême` viandes count still wrong (mobile=0,
DB=1 attribute attached).

The previous audit's P0 about fake mobile cat IDs (1..13 vs 306..318) remains
unresolved by design (mobile is V0 standalone). The `frites_style` step on
omelette-template cats (310/311/312/313) is wired in DB but **not exposed in
mobile** for any of the 15 items in those cats.

---

## Sources & methodology

**Files read (READ-ONLY):**
- `config/menu.php` (757 lines, full read)
- `database/seeders/MenuSeeder.php` (804 lines, full read)
- `mobile/data/menu.js` (491 lines, full read)
- `reports/review/mobile-audit-2026-05-10/02_dba.md` (prior audit)

**Tinker queries executed (results in `/tmp/02_data_drift_tinker.txt` lines 1-63):**
- `ItemCategory::orderBy('sort')->get()` → category metadata
- `ItemAttribute::orderBy('id')->get()` → 7 attributes (307-312, 317 test)
- `Item::with(['variations','extras','addons'])->get()` → 63 items full composition
- Per-item extras detail for items 363, 367, 381, 385, 400, 402, 403, 415
- `ItemVariation::where('item_id', X)->whereIn('item_attribute_id', [307..310])->pluck('item_attribute_id')->unique()` for viandes counts

**Key counts:**
- DB: 63 items (8 cat-13 supplement items + 8 boissons + 3 desserts + 5 frites/accompagnements
  including 3 hidden formules + 39 wizard items)
- Mobile: 60 items (no hidden formules — formules are encoded as `FORMULES` array
  separately, see `mobile/data/menu.js:195-199`)

---

## Drift findings — Items

Mobile item IDs are local (101..1308); DB item IDs are 360, 361, 362, 363..422.
Match is by **slug + name + price**.

### P0 / structural drifts

| Mobile slug | DB id | Mobile fields | DB fields | Diff | Severity |
|------|--------------------|--------|------|----------|---|
| `tacos-m` | 363 (slug `tacos-m-1-viande`) | viandes=1, has_crudites=true | attrs=[307,311], 3 crudités@0€ + 6 sup@1€ + 14 sauce-sup@0.50€ | **slug mismatch** (mobile=`tacos-m`, DB=`tacos-m-1-viande`) | **P0** |
| `tacos-l` | 364 `tacos-l-2-viandes` | viandes=2 | attrs=[307,308,311] | slug mismatch | **P0** |
| `tacos-xl` | 365 `tacos-xl-3-viandes` | viandes=3 | attrs=[307,308,309,311] | slug mismatch | **P0** |
| `tacos-xxl` | 366 `tacos-xxl-4-viandes` | viandes=4 | attrs=[307,308,309,310,311] | slug mismatch | **P0** |
| `le-supreme` (item 203) | 369 | **viandes=0** (mobile/data/menu.js:290) | DB attaches Viande 1 (attr 307) with 9 meats | **mobile says 0, DB has 1** — wizard step missing on mobile | **P0** |
| `burger-poulet` | 375 (DB slug `chicken-burger`) | viandes=0 | attrs=[311] only | slug differs (mobile uses `burger-poulet`, DB uses `chicken-burger`) — same item | **P1** |
| `ojja-hachee` | 387 `ojja-viande-hachee` | viandes=0 | attrs=[311], 7 supplement_clone + 2 frites_style | slug shortened | **P1** |
| `omelette-champi` | 391 `omelette-champignons-fromage` | viandes=0 | attrs=[311], 7 supplement_clone + 2 frites_style | slug shortened | **P1** |
| `wings-6` | 396 `chicken-wings-6-pieces` | viandes=0 | attrs=[311] | slug shortened | **P1** |
| `wings-12` | 397 `chicken-wings-12-pieces` | | | slug shortened | **P1** |
| `tenders-6` | 398 `tenders-6-pieces` | | | slug match-prefix only | **P2** |
| `tenders-12` | 399 `tenders-12-pieces` | | | slug match-prefix only | **P2** |
| `menu-cheese-enfant` | 400 `menu-cheese-burger-enfant` | viandes=0, has_sauce=true (post-audit fix mobile/data/menu.js:351) | attrs=[311] confirms sauce | **MATCH (fixed in cbfea4fd7)** | OK |
| `menu-nuggets-enfant` | 401 `menu-nuggets-enfant` | | | OK | OK |
| `frites-moyenne` | 402 `frites-moyenne` | has_frites_style=true (post-audit fix mobile/data/menu.js:360) | extras: 15 "Sauce X"@0.50€ + 1 "Cheddar fondu"@1€ ungrouped + 2 frites_style group | mobile only exposes frites_style upgrade, ignores 15 ungrouped sauces and 1 ungrouped Cheddar (which is a DB duplicate vs frites_style row) | **P1** |
| `frites-grande` | 403 `frites-grande` | has_frites_style=true | same 18 extras, Cheddar ungrouped @1.50€ + Cheddar group @1€ | **PRICE INCONSISTENCY in DB** — ungrouped Cheddar = 1.50€ on Grande, frites_style row = 1.00€ flat. Mobile reflects the frites_style price only. | **P1** |
| `item-sauce-sup` (1301) | 415 `sauce-supplementaire` | price=0.50 has_sauce=true (mobile/data/menu.js:385) | price=0.50 attrs=[] (no Sauce attribute attached) — `has_sauce` is mobile-only | mobile injects has_sauce=true so user can pick sauce when ordering "Sauce supplémentaire" — DB has NO sauce attribute on this item | **P0 inconsistency** — mobile invents wizard step that DB cannot fulfill |
| `item-fromage` (1302) | 416 `fromage-supplementaire` | price=1.00 | price=1.00 | OK | OK |
| `item-raclette` (1305) | 419 `fromage-a-raclette` | name="Fromage à raclette" (with accent) | DB name="Fromage à raclette" | match | OK |

### Items with NO DB counterpart (mobile-only, intentional V0)

None. Mobile only references DB items; the 3 formule addons (item ids 360 "Menu Frites+Boisson", 361 "Frites Seules", 362 "Boisson Seule") are encoded in mobile as `FORMULES` array (mobile/data/menu.js:195-199), not as mobile ITEMS. This is correct behavior — kiosk uses them as `item_addons` rows, not as standalone listings.

### DB items missing from mobile

`mobile/data/menu.js` has 60 items total. DB has 63 items. The 3 DB items missing from mobile:
- 360 "Menu (Frites + Boisson)" — represented in mobile as `f-menu` (formule)
- 361 "Frites Seules" — represented as `f-frites` (formule)
- 362 "Boisson Seule" — represented as `f-boisson` (formule)

**Conclusion**: 60 mobile items = 60 mobile-visible items; 3 DB items are upsell-only addons that mobile correctly hides from main grid but should still be backable by `f-menu`/`f-frites`/`f-boisson` IDs.

---

## Drift findings — Attributes (sauce / crudités / viandes / suppléments)

DB has **7 active `ItemAttribute` rows** (live: 307, 308, 309, 310, 311, 312, 317).

| Element | DB ItemAttribute id | Source (config/seeder) | Mobile encoding | Diff | Severity |
|---|---|---|---|---|---|
| Viande 1 | 307 (`Viande 1`) | `MenuSeeder.php:428` | `MEATS` array (9 items, mobile/data/menu.js:145-155) | DB has min_select=0, max_select=1 → mobile encodes as 1 mandatory pick (V0 limitation) | **P2** divergent enforcement |
| Viande 2/3/4 | 308/309/310 | `MenuSeeder.php:429-431` | mobile reuses `MEATS` array per `viandes` count (mobile/data/menu.js:280-283) | DB attaches 9 meat variations per attribute; mobile shares one MEATS array — OK | OK |
| Sauce (1ère Gratuite) | 311 | `MenuSeeder.php:432` | `SAUCES` array (15 items, mobile/data/menu.js:159-174) | min_select=0 in DB → "1 free" rule is mobile-side only (priceFor at mobile/data/menu.js:411-413). DB encodes the upsell as 14 separate `ItemExtra "Sauce supplémentaire: X" @0.50€` rows (not visible in mobile). **Cross-surface cart format divergence.** | **P1** |
| Type de Pain | 312 | `MenuSeeder.php:433` | **NOT EXPOSED in mobile** | `MenuSeeder.php:537-540` attaches it to all 8 sandwichs incl. `sandwich-classique-galette` (DB confirmed: item 374 has attrs=[307,311,312]). Mobile sandwichs (items 201-208) have no `has_pain` flag; mobile cannot let user pick Pain vs Galette. | **P0** |
| Crudités | NULL (no attribute) | `MenuSeeder.php:640-650` writes 3 `ItemExtra` rows price=0 group_label=NULL | `CRUDITES` array (3 items, mobile/data/menu.js:177-181) | Encoding is name-based heuristic on both sides. Brittle but consistent. | **P2** |
| Suppléments | NULL (no attribute) | `MenuSeeder.php:673-694` writes 6 `ItemExtra` rows price=1€ group_label=NULL (or `supplement_clone` per migration `2026_05_10_050000`) | `SUPPLEMENTS` array (7 items mobile/data/menu.js:184-192) | Mobile includes `sup-sauce` (Sauce sup @0.50€) and groups by `group: 'Sauces'`, but DB has NO `sup-sauce`-like row separate from the 14 "Sauce supplémentaire: X" rows. **Mobile has 1 generic sauce upsell row, DB has 14 specific ones.** | **P1** |
| Frites style | `group_label='frites_style'` (extras) | migration `2026_05_10_040000` writes Cheddar fondu @1€ + Cheddar+Oignons @2€ on items 360/361/402/403; migration `2026_05_10_050000` extends to cats 310/311/312/313 items 385-399 | `FRITES_STYLES` array (3 items incl. Nature, mobile/data/menu.js:206-210) | Mobile exposes step only for `has_frites_style:true` (items 1001/1002 only). **The 15 cat 310-313 items (Ojja/Omelettes/Salades/Poulet) carry `frites_style` extras in DB but mobile sets `has_frites_style:false` on all of them.** | **P0** |

---

## Drift findings — Extras (item-specific supplements)

| Item | DB extras | Mobile `has_supplements` | Match? |
|---|---|---|---|
| Tacos M (363) | 23 ungrouped: 3 crudités + 6 sup + 14 sauce-sup | has_supplements=true (default), has_crudites=true | **Aligned** (mobile uses default 6 supplements + 3 crudités, ignores 14 sauce-sup discrete rows; mobile rolls them up via `priceFor`) |
| Sandwichs 367-374 | Same 23 ungrouped | has_supplements=true, has_crudites=true | Aligned (same caveat re: 14 sauce-sup not surfaced) |
| Burgers 375-380 | Same 23 ungrouped | has_supplements=true, has_crudites=true | Aligned |
| Assiette Poulet 381 | 6 ungrouped sup @1€ (no crudités, no sauce-sup) | has_supplements=true, has_crudites=false | Aligned (mobile correctly omits crudités) |
| Ojja 385-388 | 7 `supplement_clone` (6 sup + Salade verte @2€) + 2 `frites_style` | has_supplements=true, has_frites_style=**false** (mobile/data/menu.js:318-322) | **Drift P0** — mobile MISSES `frites_style` step AND mobile SUPPLEMENTS array omits Salade verte @2€ (mobile doesn't have a 7th supplement matching DB id 832) |
| Omelettes 389-391 | Same as Ojja | Same drift | **P0** |
| Salades 392-395 | Same as Ojja | Same drift | **P0** |
| Wings/Tenders 396-399 | Same as Ojja | Same drift | **P0** |
| Menus Enfants 400-401 | 6 ungrouped sup @1€ | has_supplements=**false** (mobile/data/menu.js:351-352) | **Drift P1** — mobile blocks the 6 supplements that DB exposes; cross-surface UX divergence |
| Frites Moyenne 402 | 15 "Sauce X" @0.50€ + 1 "Cheddar fondu" @1€ ungrouped + 2 `frites_style` | has_sauce=false, has_supplements=false, has_frites_style=true | **Drift P1** — mobile reflects only `frites_style` group; the 15 ungrouped "Sauce X" rows and the 1 ungrouped Cheddar are invisible to mobile but visible to kiosk |
| Frites Grande 403 | Same as 402, Cheddar ungrouped @1.50€ | same | **Drift P1 + DB duplicate P1** (Cheddar fondu appears twice for the same item — 1.50€ ungrouped vs 1.00€ frites_style) |
| Desserts 404-406 | none | has_supplements=false | Aligned |
| Boissons 407-414 | none | has_supplements=false | Aligned |
| Suppléments 415-422 | 6 ungrouped sup @1€ (recursive — `MenuSeeder::attachSupplements` doesn't filter cat 318) | has_supplements=false | **Drift P2** — mobile correctly hides the recursive supplement rows; DB has them but doesn't break anything (kiosk wizard also hides them in `simple` template) |

---

## Drift findings — Addons (formule menu, frites_style upgrades)

DB has **180 `item_addons` rows**, ALL with `role=NULL` (confirmed in prior audit `02_dba_tinker.txt:376`).

| Item | DB addons attached | Mobile `has_menu_addon` + category `has_menu` | Match? |
|---|---|---|---|
| Tacos (363-366) | 3 (Menu, Frites, Boisson) | true, cat has_menu=true | **Match** |
| Sandwichs (367-374) | 3 | true, cat has_menu=true | **Match** |
| Burgers (375-380) | 3 | true, cat has_menu=true | **Match** |
| Assiettes (381-384) | 3 | mobile has_menu_addon=**false** (mobile/data/menu.js:310-313), cat 4 has_menu=**false** mobile vs cat 309 has_menu=**false** DB | **Match** |
| Ojja (385-388) | 3 | mobile has_menu_addon=false, mobile cat 5 has_menu=false / DB cat 310 has_menu=**false** | **Match** |
| Omelettes (389-391) | 3 | false, cat 6 has_menu=false / DB cat 311 has_menu=**false** | **Match** |
| Salades (392-395) | 3 | mobile has_menu_addon=**false** (mobile/data/menu.js:333-336), mobile cat 7 has_menu=**false** / **DB cat 312 has_menu=TRUE** | **Drift P0** — DB flipped salades to `has_menu=true` (probably from migration 2026_05_10_050000) but mobile still encodes `has_menu=false` on cat 7 + `has_menu_addon=false` on items 701-704 |
| Wings/Tenders (396-399) | 3 | mobile has_menu_addon=**false** (mobile/data/menu.js:341-344), mobile cat 8 has_menu=**false** / **DB cat 313 has_menu=TRUE** | **Drift P0** — same as salades |
| Menus Enfants (400-401) | 3 | mobile has_menu_addon=false, cat 9 has_menu=false / DB cat 314 has_menu=false | **Match** |
| Frites items 360/361 | 0 (no addons) | not encoded in mobile (formule entries) | OK |
| 362 Boisson Seule | 0 | encoded as `f-boisson` formule (mobile/data/menu.js:198) | OK |
| Frites 402/403 | 3 | mobile has_menu_addon=false on items 1001/1002 | **Drift P2** — mobile blocks formule offering on frites items that DB has 3 addons attached; semantic question (does it make sense to upsell a Menu on top of an "extra frites" item?) |
| Desserts 404-406 | 3 | mobile has_menu_addon=false on items 1101-1103 | **Drift P2** — same as frites |
| Boissons 407-414 | 3 | mobile has_menu_addon=false on items 1201-1208 | **Drift P2** — same |
| Suppléments 415-422 | 3 | mobile has_menu_addon=false | **Drift P2** — same |

---

## Drift findings — Allergens

| Item | DB allergens | Mobile allergens | Diff |
|---|---|---|---|
| ALL 63 items | empty (`Item->allergens` returns []; `allergens` table has 0 rows; `item_allergens` pivot table does not exist) | `['gluten','lactose']` default for ALL items via `mkItem` (mobile/data/menu.js:274) | **Drift P1** — mobile invents allergens that DB does not have. The owner-facing allergens feature is unwired; mobile is the only surface displaying them. Risk: mobile users see "contains lactose" on a boisson (Coca-Cola) which is factually wrong. |

---

## Categories drift

| Cat slug | DB id | DB items count | Mobile items count | wizard_template DB | wizard_template mobile | has_menu DB | has_menu mobile | Match? |
|---|---|---|---|---|---|---|---|---|
| nos-tacos | 306 | 4 | 4 | `tacos` | `tacos` | true | true | **Match** |
| nos-sandwichs | 307 | 8 | 8 | `sandwich` | `sandwich` | true | true | **Match** |
| nos-burgers | 308 | 6 | 6 | `burger` | `burger` | true | true | **Match** |
| nos-assiettes | 309 | 4 | 4 | `assiette` | `assiette` | false | false | **Match** |
| ojja | 310 | 4 | 4 | `omelette` (since migration 2026_05_10_070000) | `omelette` | false | false | **Match** |
| omelettes | 311 | 3 | 3 | `omelette` | `omelette` | false | false | **Match** |
| **nos-salades** | **312** | **4** | **4** | `salade` | `salade` | **TRUE** | **false** | **Drift P0** — DB flipped has_menu to TRUE; mobile still says false |
| **chicken-tenders** | **313** | **4** | **4** | `snacking` | `snacking` | **TRUE** | **false** | **Drift P0** — DB flipped has_menu to TRUE; mobile still says false |
| nos-menus-enfants | 314 | 2 | 2 | `omelette` (since migration 2026_05_10_070000) | `omelette` | false | false | **Match** |
| frites-accompagnements | 315 | 5 (incl. 3 formules) | 2 | `simple` | `simple` | false | false | **Match** (mobile correctly hides 3 formules — they live in `FORMULES` array) |
| nos-desserts | 316 | 3 | 3 | `simple` | `simple` | false | false | **Match** |
| nos-boissons | 317 | 8 | 8 | `simple` | `simple` | false | false | **Match** |
| supplements | 318 | 8 | 8 | `simple` | `simple` | false | false | **Match** |

**Category-level fake IDs (P0 standing item):** mobile cat IDs 1..13 ≠ DB IDs 306..318. Already known from prior audit; if mobile ever talks to `/api/frontend/menu`, the joins break. Severity P0 standing.

---

## Per-category special rules check

### Tacos viandes count (M=1, L=2, XL=3, XXL=4)
**Mobile:** items 101/102/103/104 set `viandes: 1/2/3/4` correctly (mobile/data/menu.js:280-283).
**DB:** items 363/364/365/366 attach distinct `item_attribute_id` count [307]/[307,308]/[307,308,309]/[307,308,309,310] = 1/2/3/4. **Match.**

### Assiette Poulet "cooking style" (Nature/Curry/Paprika)
**Mobile:** lives in description text only ("Poulet (Nature · Curry · Paprika) + Frites + Salade + Pain + Sauce", mobile/data/menu.js:310).
**DB:** item 381 has only `attribute_id=311` (Sauce), no "Cuisson" attribute. **Cooking style is description-only on BOTH sides — feature missing.** Severity P1 (standing item from previous audit).

### Wings/Tenders sauce list (BBQ/Nashville etc.)
**Mobile:** uses generic 15 SAUCES (mobile/data/menu.js:159-174).
**DB:** items 396-399 attach `attribute_id=311` with generic sauces. No wings-specific BBQ/Nashville sauce in DB. **Aligned — neither side has wings-specific sauces.**

### Sandwich Type de Pain
**Mobile:** no `has_pain` flag anywhere; `mkItem` doesn't expose Pain selection.
**DB:** items 367-374 (all 8 sandwichs) attach `attribute_id=312` (Type de Pain) with 2 variations (Pain, Galette). **P0 drift — mobile cannot offer Pain vs Galette selection on sandwichs.**

### Le Suprême viandes
**Mobile:** item 203, `viandes: 0` (mobile/data/menu.js:290).
**DB:** item 369, attrs=[307,311,312] — has `Viande 1` (attribute 307) with 9 meats attached. **P0 drift — mobile says fixed recipe, DB exposes 1 meat selection step.** Same drift identified in prior audit (`02_dba.md:296-303`). Mobile fix NOT applied since 2026-05-10.

### Le Cayenne viandes
**Mobile:** item 204, `viandes: 1`.
**DB:** item 370, attrs=[307,311,312] — has `Viande 1`. **Match.**

### Sandwich Froid viandes
**Mobile:** item 205, `viandes: 0`.
**DB:** item 371, attrs=[311,312] — no viande attribute. **Match.**

### Frites Moyenne/Grande Cheddar fondu duplicate
**DB:** items 402 and 403 each have **two** Cheddar fondu extra rows:
- Ungrouped: 1.00€ (Moyenne) / 1.50€ (Grande) (from `MenuSeeder::attachFritesExtras` MenuSeeder.php:728-734)
- Grouped `frites_style`: 1.00€ flat (from migration `2026_05_10_040000`)

**Mobile:** items 1001/1002 expose only the `frites_style` group via `has_frites_style:true`. The 16 ungrouped extras (15 "Sauce X" + 1 "Cheddar fondu") are invisible to mobile. **Hidden DB redundancy P1 — kiosk wizard likely double-charges if both UIs render.**

### Ojja/Omelette/Salade/Poulet supplement_clone + frites_style
**DB:** items 385-399 (15 items) all carry 7 `supplement_clone` (6 sup + Salade verte @2€) + 2 `frites_style` group_label extras (migration `2026_05_10_050000`).

**Mobile:** all 15 items have `has_supplements:true` (default) but **no `has_frites_style` flag set** (mobile/data/menu.js:318-345). The `frites_style` upgrade is completely missing from these 15 items in mobile. **P0 drift — owner explicitly added this in migration 2026_05_10_050000 days ago.**

Also: mobile `SUPPLEMENTS` array (mobile/data/menu.js:184-192) has **7 rows including `sup-sauce` @0.50€** but DB has **7 `supplement_clone` rows including `Salade verte @2€`** — **mobile's 7th row is Sauce sup, DB's 7th row is Salade verte**. So mobile and DB disagree on **what the 7 supplements are** for these 15 items.

### Menus Enfants has_sauce
**Mobile:** items 901/902, `has_sauce: true` (mobile/data/menu.js:351-352). **Already fixed in post-audit commit cbfea4fd7.**
**DB:** items 400/401, attrs=[311] = Sauce attached. **Match.** ✓

---

## Priority list (P0/P1/P2)

### P0 — must fix before any cross-surface integration

1. **`has_menu` flip on cat 312 (nos-salades) — DB=true, mobile=false.** Mobile must update CATEGORIES[7].has_menu=true and items 701-704 `has_menu_addon=true`. Cause: DB migration not back-ported to mobile. File: `mobile/data/menu.js:235, 333-336`.

2. **`has_menu` flip on cat 313 (chicken-tenders) — DB=true, mobile=false.** Same fix as above. File: `mobile/data/menu.js:236, 341-344`.

3. **`frites_style` step missing on 15 omelette-template items (ojja/omelettes/salades/poulet).** Mobile should set `has_frites_style:true` and (re)map mobile `SUPPLEMENTS` array to match DB's 7 `supplement_clone` rows (drop `sup-sauce`, add Salade verte @2€). File: `mobile/data/menu.js:316-345`.

4. **Le Suprême viandes=0 (mobile) vs viandes=1 (DB).** Standing from prior audit. Mobile must set item 203 `viandes: 1` (mobile/data/menu.js:290).

5. **Type de Pain step missing on 8 sandwichs.** Mobile has no `has_pain` flag; DB attaches Type de Pain (attr 312) to all 8 sandwichs incl. Sandwich Classique (Galette) which is silly but is DB ground truth. Mobile should add a `PAIN` array + `has_pain:true` for items 201-208 (or accept this V0 gap explicitly).

6. **Tacos slug mismatch (mobile `tacos-m` vs DB `tacos-m-1-viande`).** 4 items. If mobile ever calls API, lookups by slug will 404. Cf. `mobile/data/menu.js:280-283`.

7. **`item-sauce-sup` (mobile id 1301) has `has_sauce:true` but DB id 415 has no sauce attribute attached.** Mobile invents a wizard step the backend cannot fulfill — order payload will be rejected by `PricingService::calculateOrder` because `composition_snapshot` references a sauce attribute not present. File: `mobile/data/menu.js:385`.

8. **Mobile fake cat IDs 1..13 vs DB 306..318.** Standing P0 from prior audit. Mobile must adopt DB IDs for any API-backed mode.

### P1 — should fix in next cycle

9. **DB duplicate Cheddar fondu on items 402/403** (ungrouped 1€/1.50€ + frites_style 1€). DB-side cleanup needed; mobile already deduplicates by only exposing the frites_style row.

10. **Sandwich slug mismatches.** mobile `burger-poulet` → DB `chicken-burger`, mobile `ojja-hachee` → DB `ojja-viande-hachee`, mobile `omelette-champi` → DB `omelette-champignons-fromage`, mobile `wings-6/12` → DB `chicken-wings-6/12-pieces`. 7 items. P1 because none are blocking V0 (mobile is standalone) but every one becomes a P0 if API-backed.

11. **Allergens fabricated on mobile.** All items get `['gluten','lactose']` defaults that don't exist in DB. Coca-Cola says "contains lactose". File: `mobile/data/menu.js:274`.

12. **Sauce supplémentaire encoding divergence.** Mobile rolls up "1 free + 0.50€/extra" via `priceFor` (mobile/data/menu.js:411-413); DB encodes 14 discrete "Sauce supplémentaire: X" @0.50€ extras rows. Cart line item totals will match but composition_snapshot JSON will differ.

13. **Menus Enfants supplements blocked (mobile) vs exposed (DB).** Mobile sets `has_supplements:false` on items 901/902; DB attaches 6 supplements@1€ to items 400/401. File: `mobile/data/menu.js:351-352`.

14. **Frites Moyenne/Grande 15 ungrouped "Sauce X" extras invisible to mobile.** DB exposes 15 sauce extras + 1 Cheddar ungrouped on items 402/403; mobile shows only `frites_style` group (2 upgrades). User cannot pick a sauce on extra frites via mobile.

15. **`has_menu` race hazard between MenuSeeder + ItemCategoryWizardSeeder + migrations.** Live DB state of `has_menu` flags depends on seed/migration ordering. Standing from prior audit (`02_dba.md:516-524`).

### P2 — cleanup, not blocking

16. Cat 318 Suppléments self-supplement (6 supplements attached to themselves).
17. Mobile blocks formule offering on cats 10/11/12/13 items (frites/desserts/boissons/suppléments) but DB attaches 3 addons each — mobile suppresses for UX, accept the divergence.
18. Mobile's `SUPPLEMENTS` array has the legacy "Fromage" row (mobile/data/menu.js:188) but DB extras name it `Fromage à raclette` (with accent). Cosmetic naming drift.
19. `is_featured=5` on ALL 63 DB items (config `featured_default=true` overrides per-item flags). Mobile correctly only marks 5 items as `is_featured` (heroes). DB metadata is over-broad.
20. `is_spicy` / `is_new` / `is_chef_pick` flags not set in DB but used by mobile for tags. Mobile encodes these in JS — acceptable but not DB-backed.

---

## Recommendations

### Immediate (P0, this cycle)

1. Apply a single mobile patch fixing items #1, #2, #3, #4, #7 (and ideally #5, #6) — all are 1-3 line edits in `mobile/data/menu.js`. Estimated: 1 commit, ~30 lines diff. **No backend change needed; mobile is V0 standalone.**

2. For #8 (fake cat IDs), defer until mobile becomes API-backed. Add a `// TODO API-backend: replace cat IDs 1..13 with DB IDs 306..318` comment at top of `CATEGORIES` array.

### Mid-term (P1, next cycle)

3. **Build a `scripts/check-mobile-sync.php` script** that loads `config/menu.php`, queries DB, parses `mobile/data/menu.js` via regex or AST, and reports drift. Run in CI on every PR that touches `mobile/data/menu.js` or `config/menu.php` or `database/migrations/202?_05_*_phase_d*`.

4. **Adopt DB slugs in mobile.** Eliminates the slug mismatch class entirely. Cost: 1 commit renaming 11 mobile slugs + 11 `ITEM_IMG` keys + screen-spec test updates.

5. **Allergens table needs seeding.** Either populate `allergens` + `item_allergens` from a static map, OR remove the `allergens` field from mobile entirely. As-is mobile lies to users.

6. **Decide canonical encoding of "1 sauce free + 0.50€/extra":** either keep DB's 14 discrete extras (then mobile should expose them as 14 toggleable extras priced 0.50€ each), OR refactor DB to use `attribute.min_select=1, max_select=1+N` with extras for excess (then mobile's current priceFor is right). Pick one before backend integration.

### Long-term (P2, backlog)

7. Clean up the Cheddar fondu duplicate (delete the legacy ungrouped row in a follow-up migration; legacy `attachFritesExtras` should be retired now that `frites_style` exists).

8. Add `group_label='crudite'` to the 3 crudités extras to eliminate the name-based heuristic (`MenuSeeder::attachCruditeExtras` MenuSeeder.php:640-650).

9. Remove the recursive supplement attachment from cat 318 (`MenuSeeder::attachSupplements` needs to skip categorySlug='supplements').

---

## Drift summary statistics

- **Total mobile items audited:** 60
- **Total DB items:** 63
- **P0 drifts:** 8 (cat 312/313 has_menu flip + frites_style cascade + Le Suprême viandes + Type de Pain missing + 4 Tacos slug mismatch + sauce-sup invented attribute + fake cat IDs)
- **P1 drifts:** 7 (DB Cheddar duplicate, 7 slug mismatches sandwichs/ojja/omelettes/wings, allergens, sauce encoding, menus enfants supplements, frites sauces hidden, has_menu race)
- **P2 drifts:** 5 (supplements self-supp, frites/desserts/boissons/sup formule blocked, naming "Fromage à raclette" vs "Fromage a raclette", is_featured over-broad, is_spicy mobile-only)
- **Drifts introduced since 2026-05-10 audit:** **2 P0** (cat 312/313 has_menu flip from DB-side migration not yet mirrored to mobile)
- **Drifts that were claimed fixed by `cbfea4fd7` and are still fixed:** Menus Enfants `has_sauce: true` ✓
- **Standing drifts from 2026-05-10 still open:** Le Suprême viandes, fake cat IDs, Assiette Poulet cooking style missing, frites_style cat 10 items, allergens fabricated.

---

## Evidence file pointers

- `config/menu.php:47-62` — categories with wizard_template + has_menu (note: config has cat 7/8 has_menu=**false**, but live DB has both as **true** — migration drift)
- `database/seeders/MenuSeeder.php:399-419` — createCategories (writes config has_menu)
- `database/seeders/MenuSeeder.php:506-556` — createItem (writes Item with addons + variations + extras + pain)
- `database/seeders/MenuSeeder.php:673-694` — attachSupplements (skip list incl. ojja/omelettes/salades/desserts/boissons/frites/chicken-tenders)
- `database/seeders/MenuSeeder.php:715-735` — attachFritesExtras (legacy 15 Sauce X + Cheddar fondu — DB redundancy with migration 2026_05_10_040000)
- `database/migrations/2026_05_10_040000_add_frites_style_upgrade_extras.php` — frites_style group on items 360/361/402/403
- `database/migrations/2026_05_10_050000_phase_d_omelette_ojja_salade_poulet_menu_supplements.php` — supplement_clone + frites_style for cats 310-313 + flips `has_menu` for some cats (presumably 312/313)
- `database/migrations/2026_05_10_070000_phase_d_v381_wizard_template_align.php` — Ojja + Menus Enfants → wizard_template='omelette'
- `mobile/data/menu.js:235-236` — mobile cat 7/8 still `has_menu: false`
- `mobile/data/menu.js:280-283` — Tacos slug mismatch
- `mobile/data/menu.js:290` — Le Suprême viandes=0 (mobile bug)
- `mobile/data/menu.js:316-345` — Ojja/Omelettes/Salades/Wings without `has_frites_style:true`
- `mobile/data/menu.js:351-352` — Menus Enfants `has_supplements: false`
- `mobile/data/menu.js:385` — `item-sauce-sup` invented `has_sauce: true`
- `/tmp/02_data_drift_tinker.txt` — full live DB items+composition snapshot (63 rows)
- `reports/review/mobile-audit-2026-05-10/02_dba.md` — prior audit (still authoritative on F-DBA-1..7 with the new exceptions noted above)
