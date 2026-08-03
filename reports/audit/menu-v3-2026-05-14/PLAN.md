# Menu V3 — Plan owner-validé 2026-05-14

**Source mockups** : `/Users/1millnonstop/Downloads/Le cayenne - compressé/`
- `menu_sandwichs_v3.png` (4 produits sandwichs)
- `menu_tacos_burgers_v4.png` (Tacos M/L + Chicken Burger / Special)
- `menu_bowls_v3.png` (2 bols + 4 viandes + 8 sauces + 9 suppléments)
- `menu_enfant_desserts.png` (Menu enfant + 3 desserts + Boissons + Menu +2.50€)

**Owner-validated wizard logic (this session)** :
> Sandwiches alone (Cayenne, Classique, Galette = 3 catégories). Tacos cat. Burgers cat. Bowls cat = 2-page flow (base shape Frites/Riz → 4 bols par viande → wizard sauce + supp/gratiné + drink). Other 5 cats share same wizard.

---

## §1 Structure catégories (10 visibles kiosk + 1 hidden POS-only)

| Sort | Cat | Wizard | Items | Status DB |
|------|-----|--------|-------|-----------|
| 1 | Sandwich Cayenne (cat 344) | `sandwich` | Cayenne 7.50, Big Cayenne 9.50 | ✓ DB |
| 2 | Galette (cat 345) | `sandwich` | Galette Normale 6.50, Galette Cayenne 7.00 | ⚠️ prix à reviser ? |
| 3 | Sandwich Classique (cat 346) | `sandwich` | Classique 7.00, Big Classique 9.00 | ✓ DB |
| 4 | Burgers (cat 349) | `sandwich` | Chicken Burger 6.90, Chicken Burger Special 8.90 | ✓ DB (rename Big Chicken → Special) |
| 5 | Tacos (cat 306) | `tacos` | Tacos M 6.90, Tacos L 7.90 | ✓ DB |
| 6 | Bols Gourmands (cat 347) | `custom + 2-page` | Bowl Frites × 4 viandes + Bowl Riz × 4 viandes (8.90 chacun) | ✓ DB |
| 7 | Frites (cat 348) | `simple` | Petite Frites 2.50, Grande Frites 4.00 | ✓ DB |
| 8 | Suppléments (cat 318) | transverse | 10 items 0.90€ + Boule gratinée 2.00€ | ✓ DB |
| 9 | Desserts (cat 316) | simple | Glace, Tarte Daim, Tiramisu 3.80€ each | ✓ DB |
| 10 | Boissons (cat 317) | simple | 8 boissons 1.50€ + Eau 1.00 + Capri-Sun 1.50 | ✓ DB |
| 11 | Menu enfant (cat 350) | bundle | Menu Nuggets 6.00 | ✓ POS-visible kiosk-hidden |

---

## §2 Bowls 2-page flow (NEW UX)

**Page 1** : 2 cards de base
- Bowl Frites 8,90€
- Bowl Riz 8,90€

**Page 2** : 4 cards de viande (filtrées par base choisie)
- (Bowl Frites/Riz) Poulet mariné
- (Bowl Frites/Riz) Poulet curry
- (Bowl Frites/Riz) Poulet tandoori
- (Bowl Frites/Riz) Poulet crispy

**Wizard** (composer profile 4 steps) :
- Step 1 : **Sauce** (2 choix : Spicy, Sauce fromagère maison) — min=1 max=2 (user : "either spicy, or cheese, or both")
- Step 2 : **Suppléments** (10 items 0.90€ + Gratiné option +2.00€) — min=0 max=N
- Step 3 : **Boisson** (drink) — min=0 max=1
- Récap

**Implementation** : current DB has 8 bowls items (492-499) avec composer profile chacun. Need to update :
- Bowl composer step 1 : Sauce 2 options (Spicy + Fromagère maison)
- Bowl composer step 2 : Suppléments (existing) + Gratiné option (currently separate step)
- Bowl composer step 3 : Boisson (drink) — existing
- DELETE old separate "Option Gratiné" step (consolidate into Suppléments)

---

## §3 Shared wizard for cats 1-5 (Sandwich Cayenne, Classique, Galette, Tacos, Burgers)

**Same wizard logic** per user request. Current implementation : `wizard_template='sandwich'` for Cayenne/Galette/Classique/Burgers + `wizard_template='tacos'` for Tacos. Plus composer profiles 82/83/84 for Big variants.

**Standard step sequence** :
1. Format pain/galette (sandwich+burgers only, tacos auto-tortilla)
2. Viande 1 (always)
3. Viande 2 (Big variants only — composer 82/83/84 OR conditional render via 'has 2 viandes')
4. Sauce (Cayenne locked / Classique libre / Tacos libre / Burgers libre)
5. Crudités (incluses : salade, tomate, oignon — optionnel skip)
6. Suppléments (10 items 0.90€)
7. Menu +2.50€ (Frites + Boisson)
8. Récap

---

## §4 Viandes — 4 canoniques confirmées

Photos disponibles dans le folder :
- Mariné (cayenne mariné.png, classic mariné.png, galette mariné.png, tacos mariné — pas de tacos mariné photo, fallback)
- Curry (cayenne curry.png, cayenne v1 curry!!.png, classic cury 1.png, galette cury.png, tacos cury.png)
- Tandoori (cayenne tondoré.png, cayenne 1v tondoré!!!.png, classic pondory 1.png, classic maxi tondory.png, maxi tandoory.png, galette tondory cayenne.png, tacos tondory.png)
- Crispy (cayenne crispy!!.png, cayenne cryspy validé.png, cayenne v1 cryspie.png, classic maxi cryspy.png, galette cryspie.png, galette cryspie 1.png, tacos crispy.png, Tacos cryspie.png, maxi cryspy.png)

**4 viandes DB current OK** : Poulet mariné (renamed from classic), Poulet curry, Poulet tandoori, Poulet crispy.

NOTE : sandwich menu mockup v3 montre "Poulet Thaï" et "Poulet Épicé" — interpretation : marketing visual showing sauce variants, NOT 2 nouveaux viandes. Les 4 viandes canoniques sont Mariné/Curry/Tandoori/Crispy (cohérent avec photos disponibles).

---

## §5 Sauces — alignment bowl menu v3 (8 sauces affichées)

Mockup bowl v3 montre :
1. Sauce blanche
2. Sauce fromagère maison
3. Sauce spicy
4. Sauce algérienne
5. Sauce samouraï
6. Sauce barbecue ← NEW (add)
7. Sauce curry
8. Sauce ail ← NEW (add)

DB current (post heal v2 round 2) :
- Mayo, Ketchup, Algérienne, Samouraï, Curry, Andalouse, Harissa, Hannibal, Blanche, Sauce fromagère maison, Spicy + Sauce Cayenne maison (item 488)

**P1 sauce drifts** :
- ADD : Barbecue + Ail
- REMOVE-or-keep : Mayo, Ketchup, Andalouse, Harissa, Hannibal (in DB, NOT in mockup) → keep but reorder visibility ?

**Owner decision needed** : strict-spec (8 sauces only) OR additive (keep extras for legacy compat) ?
**Heal recommendation** : ADD Barbecue + Ail, KEEP all others (additive — no destructive change).

---

## §6 Suppléments — alignment bowl menu v3 (9 suppléments)

Mockup montre 9 suppléments à 0.90€ :
1. Oignon frais ✓ DB (renamed Oignons frits → Oignon frais)
2. Champignons ✓
3. Jambon ✓
4. Cheddar ✓
5. Raclette ✓
6. Emmental ✓
7. Boursin ✓ DB (added round 1)
8. Œuf ✓
9. Légumes sautés ✓

**+ Bacon** est ABSENT du mockup but PRESENT dans current DB (BUT was supposedly removed at round 1 heal — verify).

Tacos+burgers mockup v4 montre Bacon 0.90€ as supplément → keep Bacon in DB.

**Action** : verify supp list current vs spec, no major change needed.

---

## §7 Tacos+Burgers menu v4 specifics

**Tacos** :
- Tacos M 6.90 (1 viande) ✓
- Tacos L 7.90 (2 viandes) ✓ — composer profile 84

**Burgers** :
- Chicken Burger 6.90 ✓
- Chicken Burger **Special** 8.90 ← RENAME from "Big Chicken" current item 490

**Mockup viandes display** : Poulet mariné/Tenders/Nuggets/Poulet mariné — marketing visual showing sauce associations, not strict viande set. Keep current 4 viandes.

---

## §8 Image assets integration plan

### Mapping product → photo (best hero per item)
| Item DB | Photo hero recommended |
|---------|------------------------|
| Sandwich Cayenne 474 | `cayenne/cayene.png` ou `cayenne/cayenne mariné.png` |
| Big Cayenne 488 | `Big cayenne 2v/big_cayenne_2v_avec_oeuf_cheddar.png` |
| Galette Normale 475 | `galette/galette mariné.png` |
| Galette Cayenne 476 | `galette/galette tondory cayenne.png` |
| Sandwich Classique 477 | `sandwich classic/classico1.png` |
| Big Classique 489 | `sandwich classic/classic maxi cryspy.png` |
| Chicken Burger 375 | `burger/burger 1 cheese chicken.png` |
| Big Chicken / Special 490 | `burger/chicken big burger.png` |
| Tacos M 478 | `tacos/tacos cury.png` |
| Tacos L 479 | `tacos/Tacos cryspie.png` |
| 8 Bowls 492-499 | mapping selon `bols/bol frite/riz <viande>.png` |
| Menu Nuggets 491 | (à créer ou réutiliser nuggets icon) |

### Workflow
1. Copy 60+ PNGs to `storage/app/public/menu/items/` (or `public/images/items/`)
2. UPDATE items SET image='menu/items/<filename>' WHERE id=X
3. Run `php artisan storage:link` if not done
4. Verify image URLs render in ItemResource API

---

## §9 Action plan (heal v3 artisan command)

### `MenuHealLightV3Command`
1. **Bowl composer profile update** : 4 steps → 3 steps (merge Gratiné into Suppléments step)
   - Step 1 Sauce (Spicy + Fromagère maison) min=1 max=2
   - Step 2 Suppléments (existing 10 + Gratiné +2€ option entry) min=0 max=N
   - Step 3 Boisson min=0 max=1
2. **Sauces ADD** : Barbecue + Ail item_variations for all sauce attrs (311, 330)
3. **Burgers rename** : "Big Chicken" / "Big Chicken / Double Chicken" → "Chicken Burger Special"
4. **Image attachment** : map photos to items.image column (or items.featured_image_url)
5. **Bowls 2-page UI** : verify kiosk renders bowls cat with 2-base navigation (frontend implementation — needs verification if not already in KioskCategoriesComponent)
6. **i18n** : verify no copy drift on viande step ("Choisissez 1 viande" still in place)
7. **Fire CatalogChanged events** + verify NF525 chain unchanged

---

## §10 Out-of-scope (defer V1.0.1)
- Add Poulet Thaï / Poulet Épicé as new viandes (mockup interpretation as marketing visual, not strict viande)
- Remove Mayo/Ketchup/Andalouse/Harissa/Hannibal sauces from variants (additive heal preferred)
- 2-page bowl flow client-side rendering (existing 8-item architecture renders all 8 in cat — owner may want a real 2-page nav)

---

## §11 Risk register
- Frozen-zone respect : KioskWizardComponent.vue + KioskCategoriesComponent.vue + pos-wizard.js — toute logique UI 2-page flow doit passer par child components OR config OR composer_profile (data layer).
- Image storage path : verify `php artisan storage:link` is configured correctly. Test image URLs in ItemResource.
- composition_snapshot historical : 60 orders (1481-1490 etc.) have snapshots referencing items current state. Image changes are non-destructive (image field only, no items deleted/renamed).

---

## §12 Execution order
1. Build `MenuHealLightV3Command`
2. Copy images to storage
3. Run `--dry-run` → review plan
4. Run `--force` → apply transaction
5. PHPUnit + Vitest filter pass
6. Visual capture kiosk + POS + KDS for new sauces + new burger name + image rendering
7. Adversarial review
8. Convergence loop
