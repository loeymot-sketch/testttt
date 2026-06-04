# Mobile (standalone V1 Le Cayenne) — Board-Photo Alignment Visual Audit — Round 3

- **Date:** 2026-05-30
- **App:** standalone mobile, `http://127.0.0.1:8081/index.html` (php -S serving `mobile/`)
- **Context:** the app was just repointed to the **board's real photos** (board filenames preserved). This audit verifies every product card + every wizard option image now renders the correct real-subject photo.
- **Method (read-only):**
  1. Re-ran the abuse spec `tests/e2e/test-real-e2e-pagebypage-abuse-mobile-2026-05-30.spec.js` (18/18 passed) to refresh all 76 PNGs against the new board photos.
  2. **Mechanical DOM sweep** (Playwright, auth-seeded boot) of every rendered card's `<img src>` and every wizard-option `<img src>` — proves what the app actually paints, not just what the data says.
  3. **Multimodal Read** of the rendered screenshots AND the underlying board asset PNGs for every high-risk discriminator (riz vs frites, the 4 viandes, the 5 supplements, nuggets, drinks).
  4. Data-layer integrity: all 68 distinct PNGs referenced by `mobile/data/menu.js` confirmed to exist on disk (0 broken refs).
- **Board map:** `reports/test-e2e/frontends-abuse-2026-05-30/board-image-map.json`
- **Board assets:** `mobile/assets/menu/` (all board files mtime 2026-05-30 11:53 — consistent with "just repointed").

## VERDICT: ✅ BOARD-PHOTO ALIGNMENT IS VISUALLY CLEAN — with ONE non-blocking exception (BOL-1, P2)

- **41/41 product cards** render a board photo. **0** render `item-default.svg`, a `generated_*` blob, NO_IMG, or a wrong subject. (`BAD_CARDS:[]` from the live DOM sweep.)
- **All standard wizard option grids** (viande / sauce / crudité / **standard** supplément / frites-style / bol-drink) render correct real-subject board photos. 0 placeholders, 0 wrong subjects.
- **EXCEPTION — BOL-1 (P2):** the **bol-specific supplément step** ("Suppléments du bol") renders **emoji placeholders, not the real board photos** for its 4 options, even though all 4 board photos exist on disk. See finding below. Correct-identity (the right item is named + an emoji hints at it), but it is NOT board-aligned — the only surface where the repoint did not reach.
- Palette = **BLACK / ORANGE / YELLOW / WHITE** throughout (orange accent `#F4501E`-family, no Cayenne-red chrome). Red seen is product photography only (Coca cans, tandoori chicken) — not chrome.
- Prices correct, incl. **Tacos M 6,90 € / Tacos L 8,90 €**.

### Findings count
| Severity | Count | Notes |
|----------|-------|-------|
| P1 (wrong-subject / broken / raw-label / red chrome) | **0** | — |
| P2 (placeholder where real board photo exists / reuse not in board) | **1** | **BOL-1**: bol-supplément step renders emoji, not the 4 available board photos |
| P3 (cosmetic) | **0** | — |

---

## ⚠️ BOL-1 (P2) — Bol-specific supplément wizard step renders EMOJI, not board photos

- **Surface:** Bols Gourmands wizard → step "Suppléments du bol" (2/4). Screenshot: `08-wiz-bol-step1.png`.
- **Observed:** the 4 options render hardcoded emoji thumbnails instead of real board photos:

| Option | Board photo that EXISTS (unused) | Rendered | Verdict |
|--------|----------------------------------|----------|---------|
| Oignon frais (+0,90 €) | oignons-frits.png ✓ exists | 🧅 emoji | ⚠️ placeholder |
| Jambon (+0,90 €) | jambon-dinde.png ✓ exists | 🥓 bacon emoji | ⚠️ placeholder |
| Champignons (+0,90 €) | champignons.png ✓ exists | 🍄 emoji | ⚠️ placeholder |
| Boule gratinée (+2,00 €, POPULAIRE) | bol-frites-gratine.png ✓ exists (Read: LE CAYENNE bowl w/ gratinated cheese on fries) | 🧀 emoji | ⚠️ placeholder |

- **Root cause (file:line confirmed):**
  - `mobile/data/menu.js:175-180` — `SUPPLEMENTS_BOLS` entries have **NO `image:` field** (unlike `SUPPLEMENTS` at 163-171, which all carry `image:`).
  - `mobile/screens-item-steps.jsx:682-684` — `ScreenStepBolSupplements` is **hardcoded to emoji**: `<span>{isGratine ? '🧀' : s.id === 'sb-oignon-frais' ? '🧅' : s.id === 'sb-jambon' ? '🥓' : '🍄'}</span>`. It never reads `s.image` (contrast the standard renderer at line 484 `{s.image && <img src={s.image}>}`).
- **Evidence the board has the photos:** the SAME items (Champignons, Oignon frais, Jambon) render with REAL board photos in the standard supplément step (`07-wiz-tacos-step1.png`, verified). And `bol-frites-gratine.png` is a real board asset (Read directly). So this is a board-alignment *miss on one step*, not a missing asset.
- **Severity rationale:** graded **P2**, not P1, because (a) the option identity is still clear (name + indicative emoji), (b) it is a deliberate emoji code path, not a broken `item-default.svg` / 404 / `generated_*` blob, and (c) it is the bol composer's own pre-existing data shape (heal-light v2 2026-05-14), not something the board repoint regressed. It is, however, the one place the "use the board's real photos" repoint did NOT reach — worth a quick follow-up: add `image:` to the 4 `SUPPLEMENTS_BOLS` entries + switch line 682-684 to the `s.image && <img>` pattern. (Source-change — out of scope for this read-only audit.)
- **Not affected:** bol sauce step + bol drink step (`08-wiz-bol-step0` / `step2`) render correctly — sauces use `sauce-*.png`, drinks use real cans (`coca.png` etc.).

---

## Per-category product-card table (live DOM `<img src>` + asset subject verified)

Subjects in **bold** were Read directly from the asset PNG (high-risk or ambiguous); others confirmed via rendered category screenshot + board map.

### 1. Sandwich Cayenne (`05-cat-01`)
| Card | Expected board file | Rendered src | Subject | Verdict |
|------|--------------------|--------------|---------|---------|
| Sandwich Cayenne — 7,50 € | sandwich-cayenne.png | sandwich-cayenne.png | baguette sandwich, chicken+sauce | ✅ |
| Big Cayenne — 9,50 € | sandwich-cayenne-maxi.png | sandwich-cayenne-maxi.png | larger baguette sandwich | ✅ |

### 2. Galette (`05-cat-02`)
| Galette Normale — 6,50 € | galette.png | galette.png | folded galette/wrap w/ chicken | ✅ |
| Galette Cayenne — 7,00 € | galette.png | galette.png | folded galette/wrap (board shares photo) | ✅ |

### 3. Sandwich Classique (`05-cat-03`)
| Sandwich Classique — 7,00 € | sandwich-classique.png | sandwich-classique.png | faluche-bread sandwich | ✅ |
| Big Classique — 9,00 € | sandwich-classique-maxi.png | sandwich-classique-maxi.png | larger faluche sandwich | ✅ |

### 4. Burgers (`05-cat-04`)
| Chicken Burger — 6,90 € | burger-cheese.png | burger-cheese.png | single-patty brioche chicken burger | ✅ |
| Big Chicken — 8,90 € | burger-big.png | burger-big.png | tall double-stack burger | ✅ |

### 5. Tacos (`05-cat-05`)
| Tacos M — 6,90 € | tacos.png | tacos.png | real foil-wrapped tacos | ✅ |
| Tacos L — 8,90 € | tacos.png | tacos.png | real foil-wrapped tacos (board shares) | ✅ |

### 6. Bols Gourmands (`05-cat-06`) — **8 cards, all verified in live DOM**
| Bowl Frites Poulet mariné — 8,90 € | bol-frites.png | bol-frites.png | **LE CAYENNE bowl, fries+chicken+sauce** | ✅ |
| Bowl Frites Poulet curry — 8,90 € | bol-frites.png | bol-frites.png | (board shares) | ✅ |
| Bowl Frites Poulet tandoori — 8,90 € | bol-frites.png | bol-frites.png | (board shares) | ✅ |
| Bowl Frites Poulet crispy — 8,90 € | bol-frites.png | bol-frites.png | (board shares) | ✅ |
| Bowl Riz Poulet mariné — 8,90 € | bol-riz.png | bol-riz.png | **LE CAYENNE bowl, white RICE base + chicken** | ✅ |
| Bowl Riz Poulet curry — 8,90 € | bol-riz.png | bol-riz.png | (board shares) | ✅ |
| Bowl Riz Poulet tandoori — 8,90 € | bol-riz.png | bol-riz.png | (board shares) | ✅ |
| Bowl Riz Poulet crispy — 8,90 € | bol-riz.png | bol-riz.png | (board shares) | ✅ |

> **Riz↔Frites discriminator (highest swap risk) — PASS.** Live DOM proves all 4 riz cards → `bol-riz.png` and all 4 frites cards → `bol-frites.png`. Asset Read confirms `bol-riz.png` shows rice and `bol-frites.png` shows fries — distinct, correct. (The `05-cat-06` screenshot is a top-of-list clip showing only the 4 frites bowls; the riz bowls render below — confirmed via DOM sweep + auth-seeded capture, not a defect.)

### 7. Frites (`05-cat-07`)
| Petite Frites — 2,50 € | frites.png | frites.png | LE CAYENNE fries cup | ✅ |
| Grande Frites — 4,00 € | frites.png | frites.png | LE CAYENNE fries cup | ✅ |

### 8. Suppléments (`05-cat-08`) — 9 cards
| Cheddar — 0,90 € | cheddar.png | cheddar.png | **orange cheddar slices** (NOT cheeseburger) | ✅ |
| Raclette — 0,90 € | raclette.png | raclette.png | **raclette cheese slices w/ rind** | ✅ |
| Emmental — 0,90 € | fromage.png | fromage.png | grated cheese (board maps emmental→fromage) | ✅ |
| Œuf — 0,90 € | oeuf.png | oeuf.png | **fried egg, sunny-side up** | ✅ |
| Boursin — 0,90 € | boursin.png | boursin.png | **white herbed Boursin cheese** | ✅ |
| Légumes sautés — 0,90 € | legumes-sautes.png | legumes-sautes.png | **sautéed peppers/onions** | ✅ |
| Jambon — 0,90 € | jambon-dinde.png | jambon-dinde.png | **folded turkey ham slices** | ✅ |
| Oignon frais — 0,90 € | oignons-frits.png | oignons-frits.png | **crispy fried onions** | ✅ |
| Champignons — 0,90 € | champignons.png | champignons.png | **sliced mushrooms** | ✅ |

### 9. Desserts (`05-cat-09`)
| Glace — 3,80 € | ben-jerrys.png | ben-jerrys.png | Ben & Jerry's tub | ✅ |
| Tarte Daim — 3,80 € | tarte.png | tarte.png | chocolate tart slice | ✅ |
| Tiramisu — 3,80 € | tiramisu.png | tiramisu.png | tiramisu cup | ✅ |

### 10. Boissons (`05-cat-10`) — 8 cards (real branded cans/pouches/bottle)
| Coca-Cola 33cl — 1,50 € | coca.png | coca.png | red Coca-Cola can | ✅ |
| Coca-Cola Zero 33cl — 1,50 € | coca-zero.png | coca-zero.png | Coca-Cola Zero can | ✅ |
| Fanta Orange 33cl — 1,50 € | fanta-orange.png | fanta-orange.png | orange Fanta can | ✅ |
| Sprite 33cl — 1,50 € | sprite.png | sprite.png | green Sprite can | ✅ |
| Oasis Tropical 33cl — 1,50 € | oasis.png | oasis.png | **Oasis Tropical can** | ✅ |
| Orangina 33cl — 1,50 € | tropico.png | tropico.png | **Tropico can** (board-sanctioned; label says Orangina) | ✅ (see observation) |
| Eau Plate 50cl — 1,00 € | eau.png | eau.png | **water bottle** | ✅ |
| Capri-Sun — 1,50 € | capri-sun.png | capri-sun.png | **Capri-Sun multivitamin pouch** | ✅ |

### 11. Menu enfant (`05-cat-11`)
| Menu Nuggets — 6,00 € | nuggets.png | nuggets.png | **golden chicken nuggets pile** | ✅ |

---

## Per-wizard-step option table (live DOM `<img src>` + asset subject verified)

### Viande step (sandwich/tacos/bol) — 4 options, **4 distinct real chicken photos**
| Option | Expected | Rendered src | Subject | Verdict |
|--------|----------|--------------|---------|---------|
| Poulet mariné | viande-marine.png | viande-marine.png | **golden grilled chicken chunks** | ✅ |
| Poulet curry | viande-curry.png | viande-curry.png | **yellow/turmeric curry chicken** | ✅ |
| Poulet tandoori | viande-tandoori.png | viande-tandoori.png | **deep-red tandoori chicken** | ✅ |
| Poulet crispy | viande-crispy.png | viande-crispy.png | **breaded fried crispy chicken** | ✅ |

### Crudité step — 4 options
| Salade | salade.png | salade.png | lettuce | ✅ |
| Tomate | tomate.png | tomate.png | tomato | ✅ |
| Oignon | oignon.png | oignon.png | onion | ✅ |
| Cornichon | cornichon.png | cornichon.png | pickle | ✅ |

### Supplément step — 9 options (rendered via `lc-toggle-row`, all with thumbs)
| Cheddar | cheddar.png | cheddar.png | cheddar slices | ✅ |
| Raclette | raclette.png | raclette.png | raclette slices | ✅ |
| Emmental | fromage.png | fromage.png | grated cheese | ✅ |
| Œuf | oeuf.png | oeuf.png | fried egg | ✅ |
| Boursin | boursin.png | boursin.png | herbed Boursin | ✅ |
| Légumes sautés | legumes-sautes.png | legumes-sautes.png | sautéed peppers | ✅ |
| Jambon | jambon-dinde.png | jambon-dinde.png | turkey ham | ✅ |
| Oignon frais | oignons-frits.png | oignons-frits.png | fried onions | ✅ |
| Champignons | champignons.png | champignons.png | mushrooms | ✅ |

### Sauce step — 11 options
| Mayonnaise | sauce-mayonnaise.png | sauce-mayonnaise.png | ✅ |
| Ketchup | sauce-ketchup.png | sauce-ketchup.png | ✅ |
| Algérienne | sauce-algerienne.png | sauce-algerienne.png | ✅ |
| Samouraï | sauce-samurai.png | sauce-samurai.png | ✅ |
| Curry | sauce-curry.png | sauce-curry.png | ✅ |
| Andalouse | sauce-andalouse.png | sauce-andalouse.png | ✅ |
| Harissa | sauce-harissa.png | sauce-harissa.png | ✅ |
| Hannibal | sauce-hannibal.png | sauce-hannibal.png | ✅ |
| Blanche | sauce-blanche.png | sauce-blanche.png | ✅ |
| Sauce fromagère maison | sauce-fromagere-maison.png | sauce-fromagere-maison.png | ✅ |
| Spicy | sauce-spicy-maison.png | sauce-spicy-maison.png | ✅ |

### Frites-style step — 3 options
| Nature | frites.png | frites.png | LE CAYENNE fries cup | ✅ |
| Cheddar fondu (+1,00€) | frites-cheddar.png | frites-cheddar.png | cheese-topped fries | ✅ |
| Cheddar + Oignons frits (+2,00€) | frites-cheddar-oignons.png | frites-cheddar-oignons.png | cheese + fried onions fries | ✅ |

### Bol wizard — sauce step (`08-wiz-bol-step0`) — uses standard SAUCES
| 11 sauces | sauce-*.png | sauce-*.png | same correct sauce photos as sandwich | ✅ |

### Bol wizard — bol_supplément step (`08-wiz-bol-step1`) — ⚠️ EMOJI, see BOL-1 (P2)
| Oignon frais | oignons-frits.png (exists, unused) | 🧅 emoji | placeholder | ⚠️ P2 |
| Jambon | jambon-dinde.png (exists, unused) | 🥓 emoji | placeholder | ⚠️ P2 |
| Champignons | champignons.png (exists, unused) | 🍄 emoji | placeholder | ⚠️ P2 |
| Boule gratinée | bol-frites-gratine.png (exists, unused) | 🧀 emoji | placeholder | ⚠️ P2 |

### Bol wizard — drink step (`08-wiz-bol-step2`) — real cans
| Aucune boisson / Coca / Coca Zero / Fanta / Sprite / Oasis / Orangina / Eau | coca.png, coca-zero.png, fanta-orange.png, sprite.png, oasis.png, tropico.png, eau.png | (same) | real branded cans/bottle | ✅ |

### Menu/formule cascade step ("Faire un menu") — **emoji by design, NOT a board-photo grid**
| Menu complet / Ajouter Frites / Ajouter Boisson / Sans formule | (emoji 🍟🥤🚫) | NO_IMG | by-design emoji selector | ✅ not a finding |

> The board map's `addons → menu-frites-boisson.png` is for other surfaces; mobile's formule step is intentionally emoji-driven. Confirmed by-design (`mobile/screens-item-steps.jsx`), not a placeholder defect.

---

## Observations (non-blocking, NOT P1)

- **OBS-1 (board-sanctioned, P3-level):** "Orangina 33cl" card + drink-addon renders `tropico.png` (a Tropico-branded can). This is exactly what `board-image-map.json` prescribes (`"orangina": "tropico.png"`), so it is **not a repoint defect** — it is the board's deliberate substitution. The label/brand mismatch (Orangina text vs Tropico can) is a board-data decision, not a rendering bug. Flagging only for owner awareness if a true Orangina photo is later desired.
- **Bowl base step (`bb-riz → generated_assiette-poulet.png` in `mobile/data/menu.js:199`):** this is in the BOL_BASE data block but the bols use a 3-step composer (sauce / bol_supplements / drink) with the meat/base **fixed per item** (`bol_meat_fixed`), so the base-select grid does not render. The `generated_*` ref is vestigial/dead for the rendered flow — confirmed no bowl card or wizard step paints it. Not surfaced to the user. Noted for cleanup backlog only.

## Coverage summary
- **Products checked:** 41 cards (all 11 categories) — 100% via live DOM sweep + screenshots.
- **Wizard option images checked:** 4 viande + 11 sauce + 4 crudité + 9 standard-supplément + 3 frites-style + 4 bol-supplément + 8 bol-drink = **43 option images** across **6 wizard step types** (sandwich + tacos + bol sauce/supp/drink + frites) — 100% via live DOM sweep + screenshots + asset Reads.
- **Assets Read directly (multimodal):** bol-frites, bol-riz, bol-frites-gratine, viande-{marine,curry,tandoori,crispy}, nuggets, cheddar, raclette, boursin, oeuf, jambon-dinde, legumes-sautes, champignons, oignons-frits, tropico, oasis, capri-sun, eau (18+ board PNGs).
- **Broken refs:** 0 / 68 referenced PNGs (all exist on disk).
- **P1 wrong-subject / broken / placeholder still present:** **0**.
- **P2:** **1** — BOL-1 (bol-supplément step renders emoji where 4 real board photos exist).

**Bottom line:** the board-photo repoint on mobile is visually clean for all 41 product cards and 5 of 6 wizard step types. The single gap is **BOL-1 (P2)**: the bol-specific supplément step still shows emoji instead of the available board photos — correct identity, not board-aligned, pre-existing emoji code path the repoint did not touch. No wrong-subject, no broken/404 image, no `generated_*`/`item-default.svg` leak, no red chrome anywhere.
