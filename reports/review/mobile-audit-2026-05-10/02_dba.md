# AGENT-DBA — Mobile audit 2026-05-10

Authoritative composition rules (DB ground truth) vs `mobile/data/menu.js`.
All facts cited as `file:line` or `02_dba_tinker.txt:LINE`. Tinker extraction
succeeded — see `02_dba_tinker.txt` (449 lines) for raw evidence.

> Important runtime fact (`02_dba_tinker.txt:9, :376`):
> `ItemWizardProfile` and `ItemWizardStep` rows = 0. The wizard is currently
> driven by **`item_categories.wizard_template`** + **`items.variations` (Sauce,
> Viande, Pain attributes)** + **`item_addons` (3 menu addons)** + **`item_extras`
> (crudités atomiques + supplements + frites_style + supplement_clone)**.
> `KioskMenuService::projectItems` (KioskMenuService.php:280–402) does NOT use
> `composer_profile` because no published profile exists.

---

## 1. ItemAttribute master list (live DB)

Source: `02_dba_tinker.txt:3-9` and `app/Models/ItemAttribute.php`.

| ID  | Name                       | min_select | max_select | allow_repeat | applies_to (derived from variations linked) |
| --- | -------------------------- | ---------- | ---------- | ------------ | ------------------------------------------- |
| 307 | Viande 1                   | 0          | 1          | false        | 99 variations across tacos/sandwichs/panini |
| 308 | Viande 2                   | 0          | 1          | false        | 45 variations (tacos L/XL/XXL, Méga, Terminator) |
| 309 | Viande 3                   | 0          | 1          | false        | 18 variations (tacos XL + tacos XXL only)   |
| 310 | Viande 4                   | 0          | 1          | false        | 9 variations (tacos XXL only)               |
| 311 | Sauce (1ère Gratuite)      | 0          | 1          | false        | 585 variations (39 items × 15 sauces)       |
| 312 | Type de Pain               | 0          | 1          | false        | 16 variations (8 sandwichs × 2 pains)       |
| 317 | E2E_PLAYWRIGHT_ATTRIBUTE_TOGGLE | 0     | 1          | false        | test-only, no production variations         |

> The schema column `applies_to` does NOT exist on `item_attributes`
> (`02_dba_tinker.txt:11-25`). Attribute–item linkage is through
> `item_variations.item_attribute_id` (`item_variations.item_attribute_id`,
> `02_dba_tinker.txt:90-92`). There is **no item_item_attribute pivot table**
> (`02_dba_tinker.txt:56` — empty).

### Critical schema observation

- `min_select` = 0 means **no DB-level enforcement of "1 sauce required"**.
  The "1 sauce gratuite" rule lives in `MenuSeeder.php` (label) and presumably
  in the wizard / pricing layer — never as a DB constraint.
- `allow_repeat = false` everywhere → no DB row in this dataset uses
  `allow_repeat=true` (would be needed e.g. if a user could pick "Merguez +
  Merguez" on a Tacos XL).

---

## 2. Per-category composition rules — DB ground truth

For each of the 13 active categories (real DB IDs 306..318, **NOT 1..13** as
the mobile data assumes — this is itself a P1 alignment bug, see §4).

### CAT 306 — Nos Tacos (`wizard_template='tacos'`, `has_menu=1`, `default_menu_kiosk=0`, `sauce_included_menu=0`)

`02_dba_tinker.txt:12, :29-58`.

| Item | Name              | Price | Variations groups (attributes)            | Addons (3, role=NULL) | Extras                                                         |
| ---- | ----------------- | ----- | ----------------------------------------- | --------------------- | -------------------------------------------------------------- |
| 363  | Tacos M (1V)      | 6.50  | Viande 1 (9 meats), Sauce (15)            | 360, 361, 362         | 24 ungrouped: 3 crudités free + 6 cheese/meat sup @1€ + 14 "Sauce supplémentaire: …" @0.50€ |
| 364  | Tacos L (2V)      | 8.50  | Viande 1, Viande 2, Sauce                 | 360, 361, 362         | same 24 ungrouped                                              |
| 365  | Tacos XL (3V)     | 10.50 | Viande 1..3, Sauce                        | 360, 361, 362         | same 24 ungrouped                                              |
| 366  | Tacos XXL (4V)    | 12.50 | Viande 1..4, Sauce                        | 360, 361, 362         | same 24 ungrouped                                              |

Notes:
- "Crudités" is **not** an `ItemAttribute`. It is encoded as 3 free
  (`price=0.00`) `item_extras` rows with `group_label=NULL`: Salade, Tomate,
  Oignon (`02_dba_tinker.txt:425-427`). Mobile correctly puts them in a
  dedicated `CRUDITES` array — but the API does not expose a separate group
  label, so the projection layer cannot distinguish them from supplements
  without a name-based filter. **Confirmed gap**: see §4.
- "Sauce supplémentaire" is one extra row PER sauce (14 rows × 0.50€), produced
  by `MenuSeeder::attachSupplements` at `MenuSeeder.php:697-708`.

### CAT 307 — Nos Sandwichs (`wizard_template='sandwich'`, `has_menu=1`)

`02_dba_tinker.txt:60-117`.

| Item | Name                       | Price | Attributes                                   | Addons | Notable extras |
| ---- | -------------------------- | ----- | -------------------------------------------- | ------ | -------------- |
| 367  | Le Méga                    | 8.00  | Viande 1, Viande 2, Sauce, **Type de Pain**  | 3      | 24 ungrouped (3 crudités + 6 sup + 14 sauce sup +0.50€) |
| 368  | Le Terminator              | 9.00  | Viande 1, Viande 2, Sauce, Type de Pain      | 3      | same 24        |
| 369  | Le Suprême                 | 7.00  | Viande 1, Sauce, Type de Pain                | 3      | same 24        |
| 370  | Le Cayenne                 | 7.00  | Viande 1, Sauce, Type de Pain                | 3      | same 24        |
| 371  | Sandwich Froid             | 4.50  | Sauce, Type de Pain (no Viande)              | 3      | same 24        |
| 372  | Panini                     | 5.00  | Viande 1, Sauce, Type de Pain                | 3      | same 24        |
| 373  | Sandwich Classique (Pain)  | 6.50  | Viande 1, Sauce, Type de Pain                | 3      | same 24        |
| 374  | Sandwich Classique (Galette) | 6.50 | Viande 1, Sauce, Type de Pain                | 3      | same 24        |

**Important**: `Type de Pain` is attached to **every** sandwich (including
`Sandwich Classique (Galette)` which is ALREADY a galette by name —
`MenuSeeder.php:537-540` attaches it unconditionally per-category). This is a
seeder-level redundancy, but it's the DB ground truth.

### CAT 308 — Nos Burgers (`wizard_template='burger'`, `has_menu=1`)

`02_dba_tinker.txt:119-149`.

All 6 burgers (375..380) have:
- ATTR: Sauce only (no Viande variations; `viandes=0` in config — fixed recipes)
- Addons: 3 (menu + frites + boisson)
- Extras: same 24 ungrouped (3 crudités + 6 sup + 14 sauce sup)

**Surprise**: `MenuSeeder.php:537-540` only attaches `Type de Pain` for
`nos-sandwichs`, NOT for `nos-burgers`. Burgers have no Pain attribute in DB.
This is consistent.

### CAT 309 — Nos Assiettes (`wizard_template='assiette'`, `has_menu=0`, `sauce_included_menu=0`)

`02_dba_tinker.txt:151-171`.

| Item | Name              | Price | Attributes  | Addons | Extras                                  |
| ---- | ----------------- | ----- | ----------- | ------ | --------------------------------------- |
| 381  | Assiette Poulet   | 12.50 | Sauce only  | 3      | 6 ungrouped sup @1€: Jambon, Boursin, Raclette, Œuf, Fromage, Galette |
| 382  | Assiette Kefta    | 12.50 | Sauce only  | 3      | same                                    |
| 383  | Assiette Merguez  | 12.50 | Sauce only  | 3      | same                                    |
| 384  | Assiette Mixte    | 14.50 | Sauce only  | 3      | same                                    |

**Critical finding for AGENT-ADVERSARIAL** (also in §6):
`Assiette Poulet` description in `config/menu.php:328` says
`"Poulet (Nature - Curry - Paprika) + Frites + Salade + Pain + Sauce"`.
**The cooking style "Nature/Curry/Paprika" exists ONLY in the description
text** — there is NO `ItemAttribute`, no `ItemVariation`, no `ItemExtra`
exposing those 3 choices in DB. The kiosk wizard cannot let the user pick
a cooking style. Mobile correctly only puts it in description text — but the
owner brief almost certainly expected a real choice. Severity P1.

### CAT 310 — Ojja (`wizard_template='omelette'` since 2026_05_10_070000 migration)

`02_dba_tinker.txt:173-197`. 4 items (385–388).

All 4 have:
- ATTR: Sauce only (no Viande — fixed recipes per name)
- Addons: 3
- Extras: 7 `supplement_clone` (Fromage, Jambon, Boursin, Raclette, Œuf, Galette, Salade verte @2€) + 2 `frites_style` (Cheddar @1€, Cheddar+Oignons @2€)

The `supplement_clone` + `frites_style` rows came from migration
`2026_05_10_050000_phase_d_omelette_ojja_salade_poulet_menu_supplements.php:33-49`.
The `MenuSeeder.attachSupplements` actively SKIPS `ojja` (`MenuSeeder.php:676-679`).

`has_menu` flipped to `1` by the same migration (`MenuSeeder.php` originally
created with `has_menu=false`, but the migration overrides it post-seed —
**but tinker shows `has_menu=0`** for cat 310 (`02_dba_tinker.txt:16`). This is
a discrepancy between migration intent and live state — likely the migration
hasn't been re-run on the dev DB after a seed wipe, OR the
`ItemCategoryWizardSeeder` later overrides it back to `false`
(`ItemCategoryWizardSeeder.php:23` keeps Ojja `has_menu=>false`). **Race
hazard P1** — the `has_menu` value depends on seed/migration ordering.

### CAT 311 — Omelettes (`wizard_template='omelette'`)

`02_dba_tinker.txt:199-217`. 3 items (389–391).
- ATTR: Sauce only
- Addons: 3
- Extras: 7 supplement_clone + 2 frites_style (same as Ojja)

### CAT 312 — Nos Salades (`wizard_template='salade'`)

`02_dba_tinker.txt:219-243`. 4 items (392–395).
- ATTR: Sauce only
- Addons: 3
- Extras: 7 supplement_clone + 2 frites_style

### CAT 313 — Poulet croustillant / chicken-tenders (`wizard_template='snacking'`)

`02_dba_tinker.txt:245-269`. 4 items (396–399): Wings 6/12, Tenders 6/12.
- ATTR: Sauce only
- Addons: 3
- Extras: 7 supplement_clone + 2 frites_style

**Important spot-check finding** (also §6): the DB does NOT expose any
"sauce variation specific to wings/tenders". Wings and Tenders share the
**identical 15-sauce list** with all other items (id=311 attribute, generic
sauces). There is no `wings_special` sauce list in DB.

### CAT 314 — Nos Menus Enfants (`wizard_template='omelette'` since 2026_05_10_070000)

`02_dba_tinker.txt:271-281`. 2 items (400, 401).
- ATTR: Sauce only — **so DB ground truth is `has_sauce=true`, NOT `false`** (mobile bug, §4)
- Addons: 3
- Extras: 6 ungrouped sup @1€ (no `supplement_clone`/`frites_style` because the migration `2026_05_10_050000` only targets cat 310-313, not 314).

### CAT 315 — Frites & Accompagnements (`wizard_template='simple'`, `has_menu=0`)

`02_dba_tinker.txt:283-302`. 5 items.

| Item | Name              | Price | Attributes | Addons | Extras |
| ---- | ----------------- | ----- | ---------- | ------ | ------ |
| 360  | Menu (Frites + Boisson) | 3.00 | none | none | 1 ungrouped E2E test toggle + 2 frites_style |
| 361  | Frites Seules     | 2.00  | none       | none   | 2 frites_style only |
| 362  | Boisson Seule     | 2.00  | none       | none   | none |
| 402  | Frites Moyenne    | 2.50  | none       | 3      | 16 ungrouped (15 "Sauce X" @0.50€ + 1 "Cheddar fondu" @1€ from `attachFritesExtras`) + 2 frites_style |
| 403  | Frites Grande     | 4.00  | none       | 3      | same 16 ungrouped (Cheddar fondu = 1.50€ here, size-dependent) + 2 frites_style |

**Critical finding for AGENT-ADVERSARIAL** (§6):
- `Menu (Frites + Boisson)` (id 360, the formule addon itself when sold alone) has the 2 `frites_style` extras attached but NO sauce/no sides — yet it represents a "menu" itself.
- `Frites Moyenne` and `Frites Grande` carry **two parallel paths** for the same upgrade:
  - 15 `Sauce X` extras (ungrouped) at 0.50€ each — from `attachFritesExtras` at `MenuSeeder.php:715-735`
  - 1 `Cheddar fondu` extra (ungrouped) at 1€ or 1.50€ depending on size — same method
  - PLUS 2 `frites_style` group rows (Cheddar fondu @1€, Cheddar+Oignons @2€) — from migration `2026_05_10_040000_add_frites_style_upgrade_extras.php:26-32`.
  - **So "Cheddar fondu" appears TWICE for items 402 and 403** (once ungrouped at 1€/1.50€, once in `frites_style` at 1€). This is the "frites topping" duplication — see §6.

### CAT 316 — Nos Desserts (`wizard_template='simple'`)

`02_dba_tinker.txt:304-313`. 3 items: Glace, Tarte Daim, Tiramisu (3.80€).
- No ATTR, 3 addons, no extras. Pure simple item.

### CAT 317 — Nos Boissons (`wizard_template='simple'`)

`02_dba_tinker.txt:315-339`. 8 boissons.
- No ATTR, 3 addons, no extras.

### CAT 318 — Suppléments (`wizard_template='simple'`)

`02_dba_tinker.txt:341-373`. 8 items (415–422). **These are atomic
supplements sold standalone in POS**.
- No ATTR
- 3 addons attached (debatable — why is "Menu Frites+Boisson" addonable to
  a 0.50€ sauce supplement item? — see §6)
- 6 ungrouped extras (the 6 supplements again, attached recursively because
  `MenuSeeder::attachSupplements` doesn't filter cat 318 from receiving the
  supplement list itself). **Self-supplementation P2 oddity.**

---

## 3. KioskMenuService projection — what the kiosk receives per item

Source: `app/Services/Kiosk/KioskMenuService.php:280-402` (`projectItems`).

### Eager-loaded relations (KioskMenuService.php:78-89)

```
variations:id,item_id,item_attribute_id,name,price,visible_on,status
extras:id,item_id,name,price,visible_on,group_label,status
addons:id,item_id,addon_item_id,addon_item_variation,role
addons.addonItem:id,name,status,is_available,channels
allergens:id,code,name_key,icon,sort
```

### Per-item fields exposed (KioskMenuService.php:297-400)

- `id, category_id, item_category_id, slug, name, description`
- `price, convert_price, currency_price, flat_price, tax_id, item_type`
- `is_featured, is_upsell, is_chef_pick, chef_pick_order, is_new, is_spicy, is_vegetarian, is_pork_free, is_halal, is_gluten_free`
- `kiosk_emoji, thumb, cover, image, preview, is_available, unavailable_reason, channels, offer, allergens[]`
- **`variations[]`** with `{id, attribute_id, item_attribute_id, name, price, thumb, status, visible_on, is_available, unavailable_reason}` (KioskMenuService.php:337-356)
- **`itemAttributes[]`** — derived array of unique attributes, computed from `variations`'s linked `itemAttribute`, projected with `{id, name, status, min_select, max_select, allow_repeat}` (KioskMenuService.php:438-455). This is THE projection that the kiosk wizard consumes to render Sauce / Viande 1..4 / Type de Pain steps.
- **`composer_profile`** — currently always `null` because `ItemWizardProfile` rows = 0 (see KioskMenuService.php:408-428).
- **`extras[]`** with `{id, name, price, thumb, status, group_label, visible_on, is_available, unavailable_reason}` (KioskMenuService.php:359-377). `group_label` is the discriminator used to drive "Frites style" UI step (`group_label='frites_style'`) vs "Supplements" step (`group_label='supplement_clone'`) vs "Crudités atomiques + sauce sup" (`group_label=NULL`).
- **`addons[]`** with `{id, addon_item_id, addon_item_variation, role, addon_item_name, is_available, unavailable_reason}` (KioskMenuService.php:378-399). **Role is null for ALL 180 rows** in DB (`02_dba_tinker.txt:376`) — so the kiosk cannot use `role` to discriminate "menu_component" vs "drink" vs "side". The kiosk likely matches by `addon_item_name` text or `addon_item_id`.

### How `viandes`, `has_sauce`, `has_crudites`, `has_menu_addon` are derived

**They are NOT derived as computed flags by the projection.** Instead the
kiosk wizard infers them client-side:
- `viandes`: count distinct `item_attribute_id` in `itemAttributes[]` whose name matches `Viande N` (so 1..4 = Viande1..Viande4 attributes).
- `has_sauce`: presence of attribute id 311 (`Sauce (1ère Gratuite)`) in `itemAttributes[]`.
- `has_crudites`: presence of `extras[]` row with `name in ['Salade','Tomate','Oignon']` and `price=0` and `group_label=NULL` (no clean DB flag — name-based heuristic).
- `has_menu_addon`: presence of `addons[]` rows whose `addon_item_name` matches the 3 menu addons. Equivalent: `category.has_menu===true` AND addons attached.

### `wizard_template` and step gating

Source: `KioskMenuService::projectCategories` (KioskMenuService.php:239-273):
- Exposes `wizard_template` (string) on each category: `tacos|sandwich|burger|assiette|omelette|salade|snacking|simple` — DB column `item_categories.wizard_template` (`02_dba_tinker.txt:35`).
- Also exposes `has_menu`, `default_menu_kiosk`, `sauce_included_menu`.

The wizard front-end (KioskWizardComponent.vue) reads `wizard_template`
to decide which steps to render (e.g. `tacos` template → Viande step(s)
+ Sauce step + Crudités step + Supplements step + Menu addon step;
`simple` template → no wizard, direct add-to-cart). **The mapping
`wizard_template → step list` is hard-coded in the Vue component, not in
DB.**

---

## 4. Mobile data layer gaps — concrete deltas

Comparing `mobile/data/menu.js` (341 lines) against DB ground truth.

### A. Category IDs mismatched

| Aspect           | Mobile          | DB ground truth (`02_dba_tinker.txt:12-24`) | Severity |
| ---------------- | --------------- | ------------------------------------------- | -------- |
| Cat IDs          | 1..13           | 306..318                                    | **P0**   |
| Cat ordering     | Tacos, Sandwichs, Burgers, Assiettes, Ojja, Omelettes, Salades, Poulet, Menus enfants, Frites, Desserts, Boissons, Suppléments (mobile/data/menu.js:99-113) | Same human ordering, but real DB IDs preserve insertion order (Tacos=306, Suppléments=318) | P1 (semantic) |

If the mobile is meant to call the kiosk API (`/api/frontend/menu`), it
will receive 306..318 from the projection and the local CATEGORIES array
will fail any 1:1 join.

### B. `viandes` flags — mostly aligned but Le Suprême wrong

`mobile/data/menu.js:155-163` lists Sandwichs viandes flags:
- Le Méga: 2 ✅ (DB attrs Viande1+Viande2)
- Le Terminator: 2 ✅
- Le Suprême: **0** in mobile → **DB has Viande 1** (`02_dba_tinker.txt:78`). 

Wait, mobile sets `viandes: 0` for Le Suprême (line 157 of mobile menu.js)
but `config/menu.php:217-222` sets `'viandes' => 1` and DB seeded variations
for attribute 307 confirm 1 meat. **Mobile bug P1**.

| Item ID | Mobile viandes | DB viandes (live) | Source |
| ------- | -------------- | ----------------- | ------ |
| 203 (Le Suprême) | 0 | 1 | mobile/data/menu.js:157 vs 02_dba_tinker.txt:78 |

Same ambiguity for Le Cayenne — mobile says `viandes=1`, DB says 1 (Viande 1 attribute attached, `02_dba_tinker.txt:85`). OK.

### C. `has_sauce` mismatch on Menus Enfants

| Cat | Mobile             | DB ground truth                            | Verdict |
| --- | ------------------ | ------------------------------------------ | ------- |
| 9 (Menus Enfants) | `has_sauce: false` (lines 216-217) | DB attaches Sauce attribute (id 311) — `02_dba_tinker.txt:273, :278` | **Mobile wrong P0** |

`config/menu.php:516, :524` confirm `'has_sauce' => true`. Mobile is wrong
to set `false` on items 901-902 (`mobile/data/menu.js:216-217`). The
"Capri-Sun is the drink" reasoning likely led the mobile dev to omit sauce,
but the DB seeded sauce variations and would expect them.

### D. Cat 4 Assiette Poulet — cooking style not in DB

| Aspect | Mobile | DB | Verdict |
| ------ | ------ | -- | ------- |
| Cooking style "Nature/Curry/Paprika" for Assiette Poulet | description text only (mobile/data/menu.js:177) | description text only (`config/menu.php:328`) — NO attribute, NO variation | **Aligned but feature-missing P1** |

Both mobile and DB place this in description text. The wizard cannot offer
it as a step. To fix properly: a new `ItemAttribute` "Cuisson Poulet"
(min=1, max=1) with 3 variations attached only to item 381. **Mobile
should NOT add it as a step until DB exposes it.**

### E. Cat 8 Wings/Tenders — sauce list

| Aspect | Mobile | DB | Verdict |
| ------ | ------ | -- | ------- |
| Wings/Tenders specific sauces | mobile uses generic 15 SAUCES (lines 53-69) | DB attaches generic Sauce attribute id 311 with all 15 sauces — `02_dba_tinker.txt:247, :253, :259, :265` | **Aligned** |

No DB-level "wings sauce list", consistent with mobile.

### F. Cat 10 Frites — frites topping NOT a structured attribute

| Aspect | Mobile | DB | Verdict |
| ------ | ------ | -- | ------- |
| Frites topping (Cheddar / Cheddar+Oignons / Nature) | NOT exposed in mobile (frites items have `has_sauce: false, has_supplements: false`, lines 222-223) | DB exposes via 2 `frites_style` group_label extras on items 360, 361, 402, 403 (no Nature row — Nature is implicit "no upgrade") | **Mobile missing P0** |

Mobile completely ignores the `frites_style` upgrade step for frites items.
The kiosk wizard has it as the "Frites style" step.

Also: **`Frites Moyenne` and `Frites Grande` (items 402, 403) have a
`Cheddar fondu` ungrouped extra (price 1€ / 1.50€) AND a `Cheddar fondu`
in `frites_style` group (always 1€)** — duplicate. DB inconsistency P2.

### G. All cats — sauce 1-free + 0.50€-extra rule

| Where encoded | Source | Notes |
| ------------- | ------ | ----- |
| `attribute.min_select=0` `max_select=1` | `02_dba_tinker.txt:7` | DB does NOT enforce "1 free". |
| `ItemExtra "Sauce supplémentaire: X" @0.50€` | `MenuSeeder.php:697-708` | Extras pre-priced — kiosk shows them on top of base sauce. |
| Mobile `priceFor` adds `(sauceIds.length - 1) * 0.50` | `mobile/data/menu.js:273-275` | Mobile encodes the rule client-side, but DB has it as discrete extra rows. |

Severity: P1 — divergent encoding. The kiosk wizard adds `Sauce
supplémentaire: X` as **distinct extras**, mobile treats them as multi-
select on the `sauces` array. Result: mobile cart and kiosk cart will not
agree on the line items even if the total matches.

### H. Crudités encoding

| Where | Source | Notes |
| ----- | ------ | ----- |
| Mobile | `CRUDITES` array of 3 toggles (mobile/data/menu.js:72-76) | Default ON, no price |
| DB | 3 `item_extras` rows price=0, `group_label=NULL` (`02_dba_tinker.txt:425-427`) | Indistinguishable from supplements without name match |

This works as long as the kiosk/mobile interprets a price=0 extra named
in `['Salade','Tomate','Oignon']` as a crudité toggle. Brittle. **P1 — should
become a `group_label='crudite'`** to be explicit.

### I. has_menu_addon mismatch

Mobile has `has_menu_addon: true` for ALL Sandwichs INCLUDING `Le Suprême`
(mobile/data/menu.js:157). DB confirms 3 addons attached to item 369
(`02_dba_tinker.txt:81`). **Aligned**. (Note: AGENT-ADVERSARIAL hint asked
about this — it IS consistent.)

---

## 5. Recommended mobile data shape — kiosk-aligned schema

Mobile should mirror the kiosk projection exactly so the API can later become
the source. Proposed shape per item (drop-in replacement for the current
flag-based shape):

```js
{
  id: 363,                          // align with DB (no fake 1xx ids)
  category_id: 306,                 // align with DB (no fake 1..13)
  slug: 'tacos-m',
  name: 'Tacos M (1 Viande)',
  description: '1 Viande au choix',
  price: 6.50,
  is_featured: 5,                   // DB uses int (item_type-style flag)
  is_new: false,
  is_spicy: false,
  is_halal: true,
  is_vegetarian: false,
  kiosk_emoji: '🌮',
  thumb: '...',                     // produced by API
  // Wizard inputs — mirror KioskMenuService projection 1:1
  variations: [
    { id, attribute_id: 307, name: 'Merguez', price: 0 },
    /* ... 9 meats ... */
    { id, attribute_id: 311, name: 'Ketchup', price: 0 },
    /* ... 15 sauces ... */
  ],
  itemAttributes: [
    { id: 307, name: 'Viande 1', min_select: 0, max_select: 1, allow_repeat: false },
    { id: 311, name: 'Sauce (1ère Gratuite)', min_select: 0, max_select: 1, allow_repeat: false },
  ],
  extras: [
    { id, name: 'Salade', price: 0, group_label: null },          // crudité atomique
    { id, name: 'Tomate', price: 0, group_label: null },
    { id, name: 'Oignon', price: 0, group_label: null },
    { id, name: 'Jambon de dinde', price: 1, group_label: null }, // supplement
    /* ... */
    { id, name: 'Sauce supplémentaire: Ketchup', price: 0.5, group_label: null },
    /* ... */
  ],
  addons: [
    { id, addon_item_id: 360, role: null, addon_item_name: 'Menu (Frites + Boisson)' },
    { id, addon_item_id: 361, role: null, addon_item_name: 'Frites Seules' },
    { id, addon_item_id: 362, role: null, addon_item_name: 'Boisson Seule' },
  ],
  // Category-level — replicate from category.wizard_template
  wizard_template: 'tacos',
  // Optional derived flags — kept for back-compat but NOT load-bearing:
  viandes: 1,                       // = count(itemAttributes where name LIKE 'Viande %')
  has_sauce: true,                  // = exists(itemAttribute id=311)
  has_crudites: true,               // = exists(extras where price=0 AND name in [Salade,Tomate,Oignon])
  has_menu_addon: true,             // = addons.length > 0
}
```

For frites topping (cat 315 items 402, 403), add `extras` with
`group_label='frites_style'`:
```js
{ id, name: 'Cheddar fondu', price: 1.00, group_label: 'frites_style' },
{ id, name: 'Cheddar + Oignons croustillants', price: 2.00, group_label: 'frites_style' },
```

For Ojja/Omelettes/Salades/Poulet items, add the 7
`group_label='supplement_clone'` and 2 `group_label='frites_style'`
extras (cf. migration `2026_05_10_050000`).

---

## 6. Critical findings for AGENT-ADVERSARIAL

### F-DBA-1 [P0] — Mobile category IDs are fake

`mobile/data/menu.js` uses cat IDs 1..13. DB uses 306..318. Any future
attempt to back the mobile UI by `/api/frontend/menu` will break joins.
Worse: if the mobile builds an order payload using `category_id=1`, the
backend `OrderItemPolicy` may reject it or silently misroute. **Mobile
must adopt DB IDs before any API contract is signed.**

### F-DBA-2 [P0] — `has_sauce: false` on Menus Enfants is mobile wrong

Mobile (`mobile/data/menu.js:216-217`) sets `has_sauce: false` for items
901, 902 (Menu Cheese Burger Enfant, Menu Nuggets Enfant). DB attaches
Sauce attribute id 311 with all 15 sauces (`02_dba_tinker.txt:273, :278`)
and `config/menu.php:516, :524` says `has_sauce: true`. Mobile users will
not be offered a sauce step on these items, while kiosk users will.
**Cross-surface UX divergence — fix mobile.**

### F-DBA-3 [P0] — Mobile has no `frites_style` step

DB exposes `frites_style` group_label extras for cat 315 items (Frites
Moyenne 402, Frites Grande 403, Menu Frites+Boisson 360, Frites Seules 361)
AND for the 15 cat 310-313 items (via migration `2026_05_10_050000`).
Mobile completely lacks this concept — items 1001/1002 (Frites Moyenne /
Grande) have `has_sauce: false, has_supplements: false` (mobile/data/menu.js:222-223).
**The "Frites style: Nature / Cheddar / Cheddar+Oignons" UI step is not
implemented in mobile.** Severity P0 because owner explicitly added this
in last 24h via 2 migrations (`2026_05_10_040000` and `2026_05_10_050000`).

### F-DBA-4 [P1] — `addons[].role` is NULL in 100% of DB rows

`02_dba_tinker.txt:376` shows all 180 `item_addons` rows have `role=NULL`.
But `ItemAddon::ROLES` constant defines 5 roles
(`drink|side|dessert|menu_component|upsell`, `app/Models/ItemAddon.php:13`)
and `KioskMenuService` projects `role` directly to the wire
(`KioskMenuService.php:393`). So the kiosk has no DB-driven way to
discriminate addons. The wizard must rely on `addon_item_name` text match
("Menu (Frites + Boisson)", "Frites Seules", "Boisson Seule") to decide
whether to show a "Choose your menu" step. **This is a fragile coupling
between projection and UI — text-match break = wizard break.**

### F-DBA-5 [P1] — "Cheddar fondu" duplicated for Frites Moyenne/Grande

Items 402 and 403 carry **two `Cheddar fondu` extra rows**:
- Ungrouped (group_label=NULL), price `1.00€` for Moyenne / `1.50€` for Grande (from `MenuSeeder::attachFritesExtras` at `MenuSeeder.php:728-734`)
- Grouped (group_label='frites_style'), price always `1.00€` (from migration `2026_05_10_040000_add_frites_style_upgrade_extras.php`)

Wizard displays both → user can be charged twice for the same option.
Migration was added 2026-05-10 without removing the legacy
`attachFritesExtras` row. **Severity P1 — fix by either deleting the
ungrouped row in a follow-up migration, or by skipping it in the kiosk
wizard projection.**

### F-DBA-6 [P2] — Cat 318 Suppléments self-supplement

Items in cat 318 (Suppléments — Sauce supplémentaire @0.50€, Fromage @1€,
etc.) have themselves 6 supplement extras attached (`02_dba_tinker.txt:344, 348, 352, ...`).
So a user could order a "Fromage à raclette (1€)" as a base item AND add
"Boursin" / "Œuf" / etc. as a supplement extra to it. UX nonsense, not
a fiscal hazard. Likely from `MenuSeeder.php:686-694` not filtering cat
318 from receiving supplements. **P2 cleanup**.

### F-DBA-7 [P1] — `has_menu` flag drift between migration and seeder

`ItemCategoryWizardSeeder.php:23` sets Ojja `has_menu=>false`. Migration
`2026_05_10_050000_phase_d_omelette_ojja_salade_poulet_menu_supplements.php:73-75`
sets Ojja `has_menu=true`. Live DB shows Ojja `has_menu=0`
(`02_dba_tinker.txt:16`). So the **migration was reverted by a later seed**
or the migration hasn't run on this DB. The kiosk wizard logic depends on
`has_menu` to show the menu addon step — so this drift can change the
wizard flow silently between deploys.

---

## Tinker evidence pointer

Full extraction in `02_dba_tinker.txt` (449 lines). Key sections:
- `:3-9` — 7 attributes
- `:12-24` — 13 categories with wizard_template + has_menu
- `:29-373` — per-category items + variations + addons + extras
- `:376` — addon role distribution (100% NULL)
- `:396-398` — extra group_label distribution (NULL=531, frites_style=38, supplement_clone=105)
- `:441-447` — variation count per attribute

---

## File pointers (every fact above traceable)

- `app/Models/Item.php:131-144` — variations/extras/addons relations
- `app/Models/ItemAttribute.php:1-24` — schema
- `app/Models/ItemAddon.php:13` — ROLES constant (5 values, all unused in DB)
- `app/Models/ItemExtra.php:14-26` — group_label column
- `app/Models/ItemVariation.php:19-37` — item_attribute_id link
- `app/Services/Kiosk/KioskMenuService.php:78-89` — eager loads
- `app/Services/Kiosk/KioskMenuService.php:280-402` — projectItems
- `app/Services/Kiosk/KioskMenuService.php:438-455` — projectItemAttributes (derived from variations)
- `config/menu.php:47-62` — categories with `wizard_template`
- `config/menu.php:83-93, 100-116, 124-128, 135-142, 730-743` — meats/sauces/crudités/supplements/addons
- `database/seeders/MenuSeeder.php:424-438` — createAttributes (creates 6 attrs, not 7 — id 317 is from a separate test seeder)
- `database/seeders/MenuSeeder.php:506-556` — createItem flow
- `database/seeders/MenuSeeder.php:601-616` — attachMeatVariations
- `database/seeders/MenuSeeder.php:622-635` — attachSauceVariations
- `database/seeders/MenuSeeder.php:640-650` — attachCruditeExtras (NULL group_label)
- `database/seeders/MenuSeeder.php:656-668` — attachPainVariations (sandwichs only)
- `database/seeders/MenuSeeder.php:673-710` — attachSupplements (skip list)
- `database/seeders/MenuSeeder.php:715-735` — attachFritesExtras (legacy "Cheddar fondu")
- `database/seeders/ItemCategoryWizardSeeder.php:18-36` — has_menu defaults
- `database/migrations/2026_05_10_040000_add_frites_style_upgrade_extras.php` — frites_style for items 360/361/402/403
- `database/migrations/2026_05_10_050000_phase_d_omelette_ojja_salade_poulet_menu_supplements.php` — supplement_clone + frites_style for cats 310-313
- `database/migrations/2026_05_10_070000_phase_d_v381_wizard_template_align.php` — Ojja + Menus Enfants → wizard_template='omelette'
- `mobile/data/menu.js:99-113` — fake cat IDs 1..13
- `mobile/data/menu.js:155-217` — items with stale flags
- `mobile/data/menu.js:222-223` — frites without frites_style step
