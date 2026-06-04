# Data-Parity Audit — Standalone Frontends vs DB Canonical SSOT (V1 Le Cayenne)

**Mode:** READ-ONLY. No file modified. Report only.
**Date:** 2026-05-30
**Scope:** mobile/data/menu.js + /Users/1millnonstop/Downloads/web/data/menu.js vs DB canonical 45 items + config/menu.php.

## SSOT sources used
1. **`reports/test-e2e/supervisor-full-campaign-2026-05-30/DB_CANONICAL_ITEMS.txt`** — 45 items (`id | name | price | image`). **Authoritative SSOT** per CLAUDE.md §3bis ("DB items table = source officielle"). Verified live against the running DB (see Provenance below).
2. **`config/menu.php`** — flagged STALE pre-reset by both menu.js files (`mobile/data/menu.js:13`). Contains an entirely different, archived catalogue (Le Méga, Ojja, Assiettes, etc.). NOT a parity baseline. Used only for the addons/formules cross-check.
3. **`mobile/data/menu.js`** (627 LOC) — mobile standalone.
4. **`/Users/1millnonstop/Downloads/web/data/menu.js`** (493 LOC) — web standalone.

## Provenance check (decisive — resolves which side is "stale")
The 4 disputed items carry the prices/names written by `MenuHealLightV2Command.php` (2026-05-14):
- `app/Console/Commands/MenuHealLightV2Command.php:340` → Sandwich Cayenne 7.50
- `:341` → Sandwich Classique 7.00
- `:342` → Tacos M (rename from "Tacos") 6.90
- `:343` → Tacos L (rename from "Big Tacos") 7.90
- `mobile/data/menu.js:182` comment → "menu addon 3.00 → 2.50€"

**But `MenuHealLightV2Command` was NEVER applied to the live DB.** Live `php artisan tinker` query (run 2026-05-30):

| name | live DB id | live DB price |
|---|---|---|
| Sandwich Cayenne | 22 | **7.00** |
| Sandwich Classique | 25 | **6.50** |
| Tacos *(not "Tacos M")* | 26 | **8.50** |
| Big Tacos *(not "Tacos L")* | 27 | **11.50** |

This matches `DB_CANONICAL_ITEMS.txt` exactly → the DB export is **accurate and authoritative**; the menu.js files carry an unapplied price/name migration. **menu.js is the divergent artifact.** (Whoever is commercially "right" is an owner decision; the divergence itself is the finding.)

---

## (1) PARITY MATRIX — 45 canonical DB items

Legend: ✓ = match · ✗ = mismatch · `opt` = present in menu.js only as an option/formule, not a standalone catalogue item. Prices normalized to 2 decimals. mobile and web menu.js are byte-identical on every item def (id/name/price/image/category), so a single "menu.js" column covers both unless noted.

| # | DB id | DB name | DB € | in mobile? | in web? | name ✓ | price ✓ | category ✓ | image ✓ |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 1 | Menu (Frites + Boisson) | 3.00 | opt (f-menu) | opt (f-menu) | ✓ | **✗ 2.50** | formule | n/a |
| 2 | 2 | Frites Seules | 2.00 | opt (f-frites) | opt (f-frites) | ~ "Ajouter Frites" | ✓ 2.00 | formule | n/a |
| 3 | 3 | Boisson Seule | 2.00 | opt (f-boisson) | opt (f-boisson) | ~ "Ajouter Boisson" | ✓ 2.00 | formule | n/a |
| 4 | 12 | Cheddar | 0.90 | ✓ (801) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 5 | 13 | Raclette | 0.90 | ✓ (802) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 6 | 14 | Emmental | 0.90 | ✓ (803) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 7 | 15 | Œuf | 0.90 | ✓ (804) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 8 | 17 | Légumes sautés | 0.90 | ✓ (806) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 9 | 18 | Jambon | 0.90 | ✓ (807) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 10 | 19 | Oignon frais | 0.90 | ✓ (808) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 11 | 20 | Champignons | 0.90 | ✓ (809) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 12 | 21 | Boule gratinée | 2.00 | opt (sb-boule-gratinee) | opt | ✓ | ✓ 2.00 | bol-supp | n/a |
| 13 | 22 | Sandwich Cayenne | 7.00 | ✓ (101) | ✓ | ✓ | **✗ 7.50** | Sandwich Cayenne | ✓ |
| 14 | 23 | Galette Normale | 6.50 | ✓ (201) | ✓ | ✓ | ✓ | Galette | ✓ |
| 15 | 24 | Galette Cayenne | 7.00 | ✓ (202) | ✓ | ✓ | ✓ | Galette | ✓ |
| 16 | 25 | Sandwich Classique | 6.50 | ✓ (301) | ✓ | ✓ | **✗ 7.00** | Sandwich Classique | ✓ |
| 17 | 26 | Tacos | 8.50 | ✓ (501) | ✓ | **✗ "Tacos M"** | **✗ 6.90** | Tacos | ✓ |
| 18 | 27 | Big Tacos | 11.50 | ✓ (502) | ✓ | **✗ "Tacos L"** | **✗ 7.90** | Tacos | ✓ |
| 19 | 33 | Petite Frites | 2.50 | ✓ (701) | ✓ | ✓ | ✓ | Frites | ✓ |
| 20 | 34 | Grande Frites | 4.00 | ✓ (702) | ✓ | ✓ | ✓ | Frites | ✓ |
| 21 | 35 | Boursin | 0.90 | ✓ (805) | ✓ | ✓ | ✓ | Suppléments | ✓ |
| 22 | 36 | Big Cayenne | 9.50 | ✓ (102) | ✓ | ✓ | ✓ | Sandwich Cayenne | ✓ |
| 23 | 37 | Big Classique | 9.00 | ✓ (302) | ✓ | ✓ | ✓ | Sandwich Classique | ✓ |
| 24 | 38 | Chicken Burger | 6.90 | ✓ (401) | ✓ | ✓ | ✓ | Burgers | ✓ |
| 25 | 39 | Big Chicken | 8.90 | ✓ (402) | ✓ | ✓ | ✓ | Burgers | ✓ |
| 26 | 40 | Menu Nuggets | 6.00 | ✓ (1101) | ✓ | ✓ | ✓ | Menu enfant | ✓ |
| 27 | 41 | Bowl Frites Poulet mariné | 8.90 | ✓ (601) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 28 | 42 | Bowl Frites Poulet curry | 8.90 | ✓ (602) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 29 | 43 | Bowl Frites Poulet tandoori | 8.90 | ✓ (603) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 30 | 44 | Bowl Frites Poulet crispy | 8.90 | ✓ (604) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 31 | 45 | Bowl Riz Poulet mariné | 8.90 | ✓ (605) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 32 | 46 | Bowl Riz Poulet curry | 8.90 | ✓ (606) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 33 | 47 | Bowl Riz Poulet tandoori | 8.90 | ✓ (607) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 34 | 48 | Bowl Riz Poulet crispy | 8.90 | ✓ (608) | ✓ | ✓ | ✓ | Bols Gourmands | ✓ |
| 35 | 49 | Glace | 3.80 | ✓ (901) | ✓ | ✓ | ✓ | Desserts | ✓ |
| 36 | 50 | Tarte Daim | 3.80 | ✓ (902) | ✓ | ✓ | ✓ | Desserts | ✓ |
| 37 | 51 | Tiramisu | 3.80 | ✓ (903) | ✓ | ✓ | ✓ | Desserts | ✓ |
| 38 | 52 | Coca-Cola 33cl | 1.50 | ✓ (1001) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 39 | 53 | Coca-Cola Zero 33cl | 1.50 | ✓ (1002) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 40 | 54 | Fanta Orange 33cl | 1.50 | ✓ (1003) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 41 | 55 | Sprite 33cl | 1.50 | ✓ (1004) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 42 | 56 | Oasis Tropical 33cl | 1.50 | ✓ (1005) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 43 | 57 | Orangina 33cl | 1.50 | ✓ (1006) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 44 | 58 | Eau Plate 50cl | 1.00 | ✓ (1007) | ✓ | ✓ | ✓ | Boissons | ✓ |
| 45 | 59 | Capri-Sun | 1.50 | ✓ (1008) | ✓ | ✓ | ✓ | Boissons | ✓ |

**Coverage:** All 45 canonical items are accounted for — 41 as standalone menu.js items, 4 (ids 1/2/3/21) as options/formules rather than standalone catalogue cards. No canonical item is absent. **No invented products** — every menu.js item maps to a DB row; no "Box Familiale / Nashville / Solo" type inventions. (Note: `config/menu.php` still contains the archived old catalogue — Le Méga, Ojja, Assiettes, etc. — but that file is explicitly STALE and is NOT mirrored by either menu.js, so it produces no invented-product leakage.)

---

## (2) DIVERGENCE / FINDINGS LIST

### P0 — price mismatch vs DB SSOT (5 findings)
Each is a real customer-visible gap: the standalone web/mobile show a price the POS/kiosk (DB-driven) would ring differently.

**P0-1 — Sandwich Cayenne: menu.js 7.50 vs DB 7.00 (+0.50)**
- DB SSOT: `DB_CANONICAL_ITEMS.txt:13` → `22 | Sandwich Cayenne | 7.000000`; live DB id 22 = 7.00.
- mobile: `mobile/data/menu.js:403` → `mkItem(101, 'sandwich-cayenne-classique', 1, 'Sandwich Cayenne', 7.50,`
- web: `/Users/1millnonstop/Downloads/web/data/menu.js:301` → `mkItem(101, 'sandwich-cayenne-classique', 1, 'Sandwich Cayenne', 7.50,`

**P0-2 — Sandwich Classique: menu.js 7.00 vs DB 6.50 (+0.50)**
- DB SSOT: `DB_CANONICAL_ITEMS.txt:16` → `25 | Sandwich Classique | 6.500000`; live DB id 25 = 6.50.
- mobile: `mobile/data/menu.js:426` → `mkItem(301, 'sandwich-classique-faluche', 3, 'Sandwich Classique', 7.00,`
- web: `/Users/1millnonstop/Downloads/web/data/menu.js:322` → same, 7.00.

**P0-3 — Tacos (M): menu.js 6.90 vs DB 8.50 (−1.60)** *(largest gap)*
- DB SSOT: `DB_CANONICAL_ITEMS.txt:17` → `26 | Tacos | 8.500000`; live DB id 26 = 8.50, name "Tacos".
- mobile: `mobile/data/menu.js:446` → `mkItem(501, 'tacos-1-viande', 5, 'Tacos M', 6.90,`
- web: `/Users/1millnonstop/Downloads/web/data/menu.js:343` → same, 6.90.

**P0-4 — Big Tacos (L): menu.js 7.90 vs DB 11.50 (−3.60)** *(largest absolute gap of the whole audit)*
- DB SSOT: `DB_CANONICAL_ITEMS.txt:18` → `27 | Big Tacos | 11.500000`; live DB id 27 = 11.50, name "Big Tacos".
- mobile: `mobile/data/menu.js:450` → `mkItem(502, 'big-tacos-2-viandes', 5, 'Tacos L', 7.90,`
- web: `/Users/1millnonstop/Downloads/web/data/menu.js:347` → same, 7.90.

**P0-5 — Menu (Frites + Boisson) formule: menu.js 2.50 vs DB 3.00 (−0.50)**
- DB SSOT: `DB_CANONICAL_ITEMS.txt:1` → `1 | Menu (Frites + Boisson) | 3.000000`. (config/menu.php:733 addon also = 3.00.)
- mobile: `mobile/data/menu.js:184` → `{ id: 'f-menu', name: 'Menu (Frites + Boisson)', price: 2.50, ... }`
- web: `/Users/1millnonstop/Downloads/web/data/menu.js:144` → same, 2.50.
- Note: this is a formule/addon, not a standalone item card, but it is a priced canonical DB row and the menu addon affects every sandwich/galette/burger/tacos "+ menu" upsell total. Counted P0 per the task's "price mismatch vs DB" rule.

### P1 — broken/missing/divergent image references (0 findings)
- **All 30 distinct item images** referenced by `ITEM_IMG` resolve on disk in BOTH `mobile/assets/menu/` and `/Users/1millnonstop/Downloads/web/assets/menu/` (191 files each). Zero broken/missing.
- **All shared-pool images** (11 sauce SVGs, 9 supplement PNGs, 4 frites-style, 8 drink, 4 meat, 4 crudité, 2 hero `signature/*.png`, `item-default.svg`, `frites.png`, `generated_galette-pommes-de-terre.png`) resolve on disk in both dirs. Zero broken/missing.
- **mobile and web reference IDENTICAL image filenames** for every product (`ITEM_IMG` and `HERO_IMG` maps are byte-identical: mobile lines 53–107 / web lines 36–78). No cross-frontend image divergence.
- No raw/placeholder leakage: every slug has an explicit map entry, so the `item-default.svg` fallback (mobile:119) is never reached for any canonical item.
- **P1 = 0.**

### P2 — cosmetic naming drift, same product (2 findings)
**P2-1 — Tacos renamed "Tacos M" / "Tacos L"** (resolves to DB ids 26/27 by slug `tacos-1-viande`/`big-tacos-2-viandes`). Same product, display label drift. Cited at `mobile/data/menu.js:446,450` / web `343,347`. *(The accompanying price gap is the separate P0-3/P0-4 — not double-counted here.)*
**P2-2 — Formule labels** "Frites Seules"→"Ajouter Frites", "Boisson Seule"→"Ajouter Boisson" (mobile:185-186 / web:145-146). Same priced option, label drift only (prices match — see matrix rows 2,3).

---

## (3) IMAGE FRESHNESS TABLE

All referenced files present. mtimes confirm the "standalone assets frozen ~2026-05-17, kiosk canonical updated 2026-05-30" hypothesis: **the standalone image set is one generation behind the kiosk**, but every reference is intact, so this is a freshness note, not a broken-image P1.

| asset group | files | mobile mtime range | web mtime range | status |
|---|---|---|---|---|
| Item PNGs (30 distinct) | generated_*.png + supplement_boursin / crudite_oignon | 2026-05-11 (28) · 2026-05-17 (2: chicken-burger, big-burger, nuggets-x6) | 2026-05-16 (28) · 2026-05-17 (3) | all present |
| Sauce SVGs (9) | sauce_*.svg | 2026-05-11 | 2026-05-16 | all present |
| Supplement pool PNGs (6) | supplement_*.png | 2026-05-11 | 2026-05-16 | all present |
| Meat/crudité/drink/frites PNGs | viande_*, crudite_*, coca_cola… , frites.png | 2026-05-11 | 2026-05-16 | all present |
| Hero (2) | signature/cayenne-hero.png, signature/tacos-hero.png | cayenne 2026-05-17, tacos 2026-05-11 | cayenne 2026-05-17, tacos 2026-05-16 | all present |
| Fallback | item-default.svg | 2026-05-11 | 2026-05-16 | present (never reached) |

**Freshness verdict:** the standalone image SET is **stale relative to the 2026-05-30 kiosk refresh** (newest standalone asset = 2026-05-17). No standalone reference is broken or missing, so per the task rule "wrong image" is NOT asserted here — that judgment is deferred to the browser/visual pass. The empirical, defensible statement: *standalone assets are frozen at 2026-05-16/17; if the kiosk photos changed materially on 2026-05-30, the standalone frontends are displaying a prior generation of the same-named files.*

---

## (4) SYNC-READINESS NOTE (doc only — no wiring proposed)

**Composer-profile SHAPE: ALIGNED.** Both menu.js build `item.composer_profile = { template, version, is_published, steps: [{ step_key, label, source_type, position, min_select, max_select, allow_repeat, addon_role, default_choice_id, choices[] }] }` — mirroring the DB `item_wizard_profiles` + `item_wizard_steps` shape (mobile:298-388 / web:244-287). mobile and web are byte-identical on this. Bol wizard (3 steps: sauce / bol_supplements / bol_drink) and frites wizard (1 step: frites_style) match between the two frontends.

**item_id KEYS: MISALIGNED.** menu.js item ids are synthetic (101/201/301/501/601/801/901/1001/1101…), NOT the DB ids (22/25/26/27/36/12/49/52…). Choice ids inside composer steps are likewise synthetic string slugs (`s-mayo`, `sb-boule-gratinee`, `d-coca`), not DB row ids. For a future mechanical wireup the render layer can swap the data source (shape is identical), but a key-mapping/translation table (menu.js synthetic id → DB id) would be required — it is NOT a drop-in id match.

**Net sync-readiness:** *aligned on profile shape, misaligned on id keys.* mobile ↔ web are fully aligned with each other (identical id/name/price/image/composer for all 41 items; deltas are render scaffolding only — web adds `badge` field at web:231, `PEPPER_CLUB` at web:434, and `W_*`/`window.LC.menu.brand` globals; mobile uses `window.ITEMS`/`window.CATS`/`branch`). Both diverge from the live DB only on the 5 P0 prices + 2 P2 names above.

---

## SUMMARY

- **P0 = 5** (all price mismatches vs DB SSOT): Sandwich Cayenne 7.50/7.00, Sandwich Classique 7.00/6.50, Tacos 6.90/8.50, Big Tacos 7.90/11.50, Menu-formule 2.50/3.00.
- **P1 = 0** — every image reference resolves on disk in both standalone dirs; mobile and web reference identical images; no raw/placeholder leakage.
- **P2 = 2** — Tacos→"Tacos M/L" rename; formule "Frites Seules/Boisson Seule"→"Ajouter…" rename (same products, prices unaffected by the rename itself).
- **Invented products: 0. Missing canonical items: 0.** All 45 accounted for (41 standalone + 4 as formules/options).
- **mobile ↔ web parity: clean** — identical on every item field; only render-scaffolding deltas.

**Single most important divergence:** **P0-4 — Big Tacos is 7.90€ in the standalone web/mobile but 11.50€ in the live DB (the POS/kiosk source of truth): a −3.60€ gap, the largest in the audit.** Root cause for all 5 P0s is the same: `MenuHealLightV2Command.php` (2026-05-14) re-priced these items and the standalone menu.js files adopted those new prices, but **the seed was never applied to the live DB** (verified via live `tinker` query — DB still shows the pre-heal-light prices). Either the DB needs the heal-light-v2 migration applied, or the standalone menu.js needs to revert to DB prices — an **owner decision**; the divergence is real and customer-visible either way.
