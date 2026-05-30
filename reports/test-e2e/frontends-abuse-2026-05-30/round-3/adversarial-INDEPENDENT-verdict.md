# Adversarial INDEPENDENT Verdict — Round 3 board-photo cycle (mobile + web) — 2026-05-30

> Independent adversarial supervisor pass. READ-ONLY against app source (the only file written
> under tests/ is a throwaway driving spec, `tests/e2e/test-real-e2e-fullpage-web-indep-drive-2026-05-30.spec.js`,
> which captures + asserts — it does NOT modify the web/mobile apps). Goal: try to BREAK
> "board photos render everywhere on both surfaces incl wizard options, prices correct, 0 P0/P1."
> Every finding below carries file:line OR PNG + exact observed value.

## VERDICT: **CONFIRM convergence — 0 NEW P0/P1.**

The single assertion failure in my own driving spec was an over-strict test (the intentional
"Aucune boisson / 🚫" sentinel), NOT an app defect — explained in §6. The app is clean.

---

## 1. Did board photos render everywhere on BOTH surfaces incl wizard options?  — YES

### Static SSOT (grep-verified)
- `mobile/data/menu.js`: **0** `generated_*`/`supplement_*` image refs. `web/data/menu.js`: **0**. (grep -c = 0 both.)
- Pool image filename sets are **byte-identical** mobile↔web (`diff` empty → "IDENTICAL POOL IMAGE SETS"):
  41 distinct files (viande-*, sauce-* ×11, crudités, supplements, supplementsBols, frites styles, drinks).
- **All 41 web pool assets HTTP 200** on 8095; **all mobile pool assets HTTP 200** on 8087 (curl sweep, 0 non-200).

### Product CARD images (separate path from option pools — swept independently)
The ITEM_IMG card map references bare filenames (e.g. `web/data/menu.js:46` `'tacos-1-viande'→'tacos.png'`)
that are NOT in the option-pool set and use the SAME `onError`→emoji fallback as the wizard
(`screens.jsx:39-52`). A present-but-404 card image = emoji on a card = in-scope defect. So I swept
the 30 ITEM_IMG card filenames on **both** servers:
- Web cards (30 incl `tacos.png`, `ben-jerrys.png`, `burger-big/cheese.png`, `galette.png`, `nuggets.png`,
  `sandwich-classique(-maxi).png`, `tarte.png`, `tiramisu.png`, `bol-frites.png`): **0 non-200** on 8095.
- Mobile cards: **0 non-200** on 8087. Card sets effectively identical (3 sed-window diffs all benign:
  `bol-frites-gratine.png` & `galette.png` both resolve 200 on each surface; `signature/cayenne-hero.png`
  = owner-classified hero art). No card can fall to emoji fallback. ✓

### Web wizard renderer logic (wizard-v2.jsx)
- radio (L333-335) + multi (L362-364): `opt.image ? <img onError→opt.icon> : icon`. Photos render when present; emoji only on missing-image or a live 404.
- All option builders pass `image:` — pool builders L25/26/27/31-33/34-38/39/44-47; composer `sauce` choices carry `image: s.image` (`web/data/menu.js:265`); `bol_supplements` rebuilt via `suppBolsOptions()` pool (renderer L114-119).

### Web — INDEPENDENT LIVE DRIVE on products OTHER than the provided Tacos M captures
Drove the live web wizard (port 8095) myself and audited every option thumb via DOM
(`<img>` present + `naturalWidth>0` + `display!==none` = real PHOTO; otherwise EMOJI/BROKEN):

- **Bowl Frites Poulet mariné (`bowl-frites-marine`, `custom` template):**
  - Sauce step → 11/11 PHOTO (`sauce-mayonnaise..sauce-spicy-maison.png`, natW=800). PNG: `independent-drive/bowl-step-0-sauce.png`.
  - Suppléments du bol step → 4/4 PHOTO (oignons-frits / jambon-dinde / champignons / bol-frites-gratine, natW=800) — **this is the BOL-1 fix area**. PNG: `independent-drive/bowl-step-1-supplmentsdubo.png` (Read: real onions/ham/mushrooms/gratinated-bowl thumbnails + correct +0,90/+2,00 € + POPULAIRE).
  - Ajouter une boisson step → 8/8 real drinks PHOTO (incl Orangina→tropico.png, owner-classified mirror); only "Aucune boisson" = 🚫 icon sentinel (correct, §6).
- **Sandwich Cayenne classique (`sandwich-cayenne-classique`):**
  - viande 4/4 PHOTO · crudités 4/4 PHOTO · suppléments gourmands 9/9 PHOTO · cascade "Sauce pour les frites" 11/11 PHOTO. All natW=800.
- **0 image 404s, 0 console/page errors** on both products (`page._imgErrors=[]`, `page._errors=[]`).

### Web — provided captures (Read, corroborate)
- `web-fullpage/web-wizard-viande-step.png`: Poulet mariné/curry/tandoori/crispy = real meat photos (NOT emoji); live preview "6 90 €".
- `web-fullpage/web-wizard-supp-step.png`: Cheddar/Raclette/Emmental/Œuf/Boursin/Légumes/Jambon/Oignon/Champignons = real photos + "+0,90 €".

### Mobile — mandated abuse spec + capture Read
- `npx playwright … test-real-e2e-pagebypage-abuse-mobile … (8087)`: **18/18 passed**, gate "no P0/P1 findings" PASS.
- `mobile/08-wiz-bol-step1.png` (Read): Oignon frais / Jambon / Champignons / Boule gratinée = real board photos, no emoji; total 8,90 €.
- `mobile/08-wiz-bol-step2.png` (Read): bol_drink — all real drink cans render; "Aucune boisson"=🚫 sentinel (same design as web → parity).

---

## 2. NEW P0/P1 with evidence — **NONE.**

I specifically hunted the advisor-flagged divergent path (composer `sauce` `c.image` from
`composer_profile.choices`, a different source than the asset-checked `M.sauces` pool). Drove it
live on the Bowl: **all 11 composer sauce choices rendered real PHOTO** (`web/data/menu.js:265`
maps `image: s.image` from SAUCES, all 200). No P1. No emoji on any reachable composer step.

---

## 3. Tacos price + parity — CONFIRMED

- **Tacos M = 6,90** (`mkItem(501,…,'Tacos M', 6.90)`), **Tacos L = 8,90** (`mkItem(502,…,'Tacos L', 8.90)`)
  — identical in `web/data/menu.js:344/348` and `mobile/data/menu.js:446/450` (mobile has owner-decision
  comment 2026-05-30 at L444). Wizard viande live preview rendered "6 90 €" for Tacos M.
- Card image parity: both surfaces map `tacos-1-viande` & `big-tacos-2-viandes` → `tacos.png`
  (web L46/47, mobile L67/68) — board-shared per owner classification, not a defect.

---

## 4. Numeric integrity / raw-labels / console / 404 / overflow / palette / un-wired stop
- Numeric: bowl total 8,90 € == base (no supp selected); recap totals == line sums in prior captures; no NaN/undefined/0undefined observed.
- No raw-label leaks, **0 console/page errors**, **0 image 404s** across my live drives + the 18/18 mobile gate.
- Palette: mobile black/orange/yellow/white (no Cayenne red in chrome — bol step PNGs); web charter correct.
- Un-wired checkout stop: clean on both (mobile pay-choice modal; web 3-step payment) per prior captures + 18/18 gate.

---

## 5. Parity (mobile ↔ web)
- ITEM_IMG + every option pool image filename set **identical** (diff empty). Composer sauce/bol_supplements/bol_drink
  reference the same files. "No drink" sentinel design identical (🚫, no photo) on both.

---

## 6. The one "failure" (NOT a defect — disclosed for honesty)
My driving spec asserted *every* thumb is a PHOTO. The Bowl `bol_drink` step's **"Aucune boisson"**
option is `{ id: '__none', icon: '🚫' }` with NO `image` by design (`wizard-v2.jsx:41` `drinkAddonOptions`).
It correctly renders its 🚫 icon — a "no selection" sentinel, not a board product. Mobile shows the
identical pattern (`08-wiz-bol-step2.png`). This is correct UX, not a P1. Every actual food/drink
option rendered a real photo (natW=800).

## Owner-classified items (NOT re-litigated, unchanged)
Orangina→tropico.png (board mirror), web hero promo 9,00, board-shared photos, F-PRICE-01 standalone↔DB,
HERO_IMG signature art — all confirmed still as-classified; none surfaced as new defects.

---

### Evidence index
- Driving spec: `tests/e2e/test-real-e2e-fullpage-web-indep-drive-2026-05-30.spec.js` (1 of 2 tests "fails" only on the 🚫 sentinel over-assertion; sandwich test PASSED clean).
- Independent PNGs: `round-3/independent-drive/{bowl-step-0-sauce,bowl-step-1-supplmentsdubo,bowl-step-2-ajouterunebois,sandwich-step-0-choisisviande,sandwich-step-1-crudits,sandwich-step-2-supplmentsgour,sandwich-step-6-saucepourlesfr}.png`
- Mobile abuse: 18/18 (8087). Provided web captures: web-fullpage/web-wizard-{viande,supp}-step.png. Mobile: 08-wiz-bol-step1/2.png.
