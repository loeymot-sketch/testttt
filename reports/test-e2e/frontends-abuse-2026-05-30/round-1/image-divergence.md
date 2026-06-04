# Image Divergence Audit — Kiosk vs Standalone (Mobile + Web)

**Date:** 2026-05-30
**Mode:** READ-ONLY. No source or asset was modified. Visual comparison done by Reading both PNGs.
**Scope:** Determine, per product, whether the standalone (mobile + web) image is STALE/different vs the
kiosk's current product photo, and propose a bounded heal.

---

## 0. TL;DR

- **The kiosk's "fresh" photos are NOT new on 2026-05-30.** They are the **Le Cayenne V2 photo set dated
  2026-05-21** (`public/menu/le-cayenne-v2/*.png`, also mirrored to `public/images/menu/*.png`). The
  2026-05-30 file timestamps under `storage/app/public/{id}/` are a **Spatie media re-copy / duplication
  event** — the image *bytes* (md5) are byte-identical to the 2026-05-21 source. No NEW imagery was shot on
  2026-05-30; the owner's "I updated the borne photos" refers to the V2 set already in the repo.
- **10 real product photos exist** (frites, coca, sauce-supp, fromage, jambon-dinde, boursin, raclette, oeuf,
  galette[=potato], salade) + `item-default.svg`.
- **The two standalone frontends are STALE.** Their supplement images are **byte-identical (md5 match) to the
  OLD 2026-05-09 placeholder set** that the kiosk catalog has since replaced. Several of those placeholders
  are also **wrong-subject** (a triple-cheeseburger photo is used for both *raclette* and *fromage*).
- **8 P2 stale-image products** confirmed by direct visual Read of both PNGs.
- **Recommendation: bounded heal-now is justified and cheap** for the supplement/add-on images that have a
  correct real photo in-repo. The wholesale 41-item `generated_*` render→photo swap remains an **owner
  aesthetic decision** (flagged separately, NOT proposed here).

---

## 1. Kiosk fresh-photo inventory

### 1a. DB media mapping (source of truth)
Query: `DB::table('media')->where(model_type like Item)` joined to `items`. Only **11 Items** carry real media.
The Spatie file lives at `storage/app/public/{media.id}/{file_name}`.

| media.id (folder) | item_id | item name (DB)              | file_name                  |
|-------------------|---------|-----------------------------|----------------------------|
| 6                 | 1       | Menu (Frites + Boisson)     | item-default.svg           |
| 7                 | 2       | Frites Seules               | frites.png                 |
| 8                 | 3       | Boisson Seule               | coca.png                   |
| 9                 | 4       | Sauce supplémentaire        | sauce-supplementaire.png   |
| 10                | 5       | Fromage supplémentaire      | fromage.png                |
| 11                | 6       | Jambon de dinde             | jambon-dinde.png           |
| 12                | 7       | Boursin                     | boursin.png                |
| 13                | 8       | Fromage à raclette          | raclette.png               |
| 14                | 9       | Œuf                         | oeuf.png                   |
| 15                | 10      | Galette pommes de terre     | galette.png                |
| 16                | 11      | Salade verte                | salade.png                 |

> Note: all 11 are **supplement / add-on / drink catalog Items** in the kiosk POS. In the standalone menus
> they are NOT top-level product tiles — they render as wizard sub-options (supplements, drink choices) or,
> for frites/galette, as separate small renders. This narrows the divergence surface.

### 1b. Canonical real-photo bytes (md5) and true source
Each real photo is the **2026-05-21 Le Cayenne V2** asset. `storage/app/public/.../` copies are byte-identical
to `public/menu/le-cayenne-v2/` and `public/images/menu/` (the seeder source used by
`database/seeders/RestoreLeCayenneItemImagesSeeder.php`).

| product            | md5 (current kiosk photo)            | source file (2026-05-21)                        | size   |
|--------------------|--------------------------------------|-------------------------------------------------|--------|
| frites             | `3d425a3f3335c57fd52a042b54acc4aa`   | `public/menu/le-cayenne-v2/frites.png`          | 106 KB |
| coca               | `776f5aabebf7fd97e7c56d011e488429`   | `public/menu/le-cayenne-v2/coca.png`            | 264 KB |
| sauce-supplementaire | `780765c6077c88b9d02a85a13d313e02` | `public/menu/le-cayenne-v2/sauce-supplementaire.png` | 232 KB |
| fromage            | `6a6bab43901a03de51d63c6888169078`   | `public/menu/le-cayenne-v2/fromage.png`         | 369 KB |
| jambon-dinde       | `97096f874d472201366ed0530239e974`   | `public/menu/le-cayenne-v2/jambon-dinde.png`    | 220 KB |
| boursin            | `a2e0412d9911dbe2ee9a9ef357015003`   | `public/menu/le-cayenne-v2/boursin.png`         | 159 KB |
| raclette           | `0f99df01fecc71d9b90a245fe5384e13`   | `public/menu/le-cayenne-v2/raclette.png`        | 414 KB |
| oeuf               | `7632e934db885dfc929b4b12952f8bcc`   | `public/menu/le-cayenne-v2/oeuf.png`            | 189 KB |
| galette (potato)   | `ebcde01a4d21fd3732eee9eef3ac7477`   | `public/menu/le-cayenne-v2/galette.png`         | 140 KB |
| salade             | `f5ad1131f43fe654268b98c7548305e8`   | `public/menu/le-cayenne-v2/salade.png`          | 279 KB |

> The 2026-05-30 timestamps under `storage/app/public/{7,8,...,16}/` are duplicate copies (same md5). The
> served bytes themselves carry a 2026-05-21 lineage. This is a re-sync, not a new photoshoot.

---

## 2. Per-product visual compare table

**Standalone render path for supplements:** `mobile/screens-main.jsx:483` renders
`lcMenu.supplements.map(s => ... src={s.image})` — i.e. the **`image:` field of the `SUPPLEMENTS` array** in
`mobile/data/menu.js` (lines 162-171), NOT `ITEM_IMG`/`imgFor`. Web mirrors this in
`/Users/1millnonstop/Downloads/web/data/menu.js` (image mappings byte-identical; all asset bytes md5-MATCH
between the two standalone trees).

Each row below: **I Read both PNGs.** "Verdict" states what the kiosk shows vs what the standalone shows.

| Product | Kiosk photo (Read) | Standalone file (Read) | Visual verdict |
|---------|--------------------|------------------------|----------------|
| **Frites** | `storage/app/public/13/frites.png` — branded **"LE CAYENNE" black fry box** with golden fries (clean white bg) | `mobile/assets/menu/frites.png` (`3284a010…`) — dark, generic plate of **loaded/dirty fries** on a black tray, blurry background | **DIFFERENT — clearly old/wrong.** Subject and styling unrelated to the new branded box. Standout. |
| **Coca (Boisson Seule)** | `storage/app/public/8/coca.png` — slim **red Coca-Cola can**, clean white bg | `mobile/assets/menu/coca_cola.png` (`11de75fa…`) — **"CLASSIC" Coca-Cola can** on a dark grey gradient, different can shape | **DIFFERENT photo.** Same brand, different shot + dark background (off-style). |
| **Raclette** | `storage/app/public/13/raclette.png` — **folded raclette cheese slices** | `mobile/assets/menu/supplement_raclette.png` (`d962373a…`) — a **triple cheeseburger** | **DIFFERENT + WRONG SUBJECT.** Standalone shows a burger, not cheese. |
| **Fromage (supp.)** | `storage/app/public/10/fromage.png` — **pile of grated cheese** | `mobile/assets/menu/supplement_fromage.png` (`d962373a…`) — **same triple cheeseburger** as raclette | **DIFFERENT + WRONG SUBJECT.** Identical placeholder reused for two cheeses. |
| **Boursin** | `storage/app/public/7/boursin.png` — **boursin cheese ball with chives** | `mobile/assets/menu/supplement_boursin.png` (`99a42b19…`) — **bowl of pale mayo/aioli sauce** | **DIFFERENT + WRONG SUBJECT.** Standalone reads as a sauce, not boursin. |
| **Œuf** | `storage/app/public/9/oeuf.png` — **fried egg**, hi-res, white bg | `mobile/assets/menu/supplement_oeuf.png` (`9019526d…`) — **fried egg**, lower-res, different crop/shape | **SAME SUBJECT, different/older render.** Acceptable-ish but visibly inferior + not matching. |
| **Jambon de dinde** | `storage/app/public/6/jambon-dinde.png` — **sliced turkey ham on a board** | `mobile/assets/menu/supplement_jambon_dinde.png` (`769aa076…`) — **sliced turkey ham on a board**, lower-res/cropped | **SAME SUBJECT, lower-quality crop.** Looks like the same source family, degraded. |
| **Salade verte** | `storage/app/public/11/salade.png` — **photo of shredded iceberg lettuce** | `mobile/assets/menu/generated_salade-verte.png` (`f55ba4bd…`) — **crude flat-vector cartoon** (green blobs on a plate, striped tablecloth) | **DIFFERENT — placeholder cartoon vs real photo.** |
| **Galette (potato)** | `storage/app/public/10/galette.png` — *(note: this kiosk file is actually a **chicken-wrap photo**, "galette" = wrap here)* | `mobile/assets/menu/generated_galette-pommes-de-terre.png` (`1386625…`) — **flat-vector cartoon plate** | **DIFFERENT subjects entirely** (naming collision: kiosk galette = wrap; standalone supp = potato galette cartoon). See §3 caveat. |
| **Sauce supplémentaire** | `storage/app/public/9/sauce-supplementaire.png` — real sauce-bowl photo | *no direct standalone sauce-supp tile* (sauces use `sauce_*.svg` vector icons in standalone) | **N/A for direct swap** — standalone sauces are SVG icon set by design. |

### Byte-proof that the standalone supplements are the OLD placeholders
The standalone supplement files are **md5-identical to the 2026-05-09 placeholder set** in
`public/images/menu/` that the kiosk catalog has since superseded with the 2026-05-21 real photos:

| file | standalone md5 | `public/images/menu/` (old) md5 | match |
|------|----------------|----------------------------------|-------|
| supplement_raclette.png | `d962373a…` | `d962373a…` (2026-05-09) | **SAME-OLD** |
| supplement_fromage.png | `d962373a…` | `d962373a…` (2026-05-09) | **SAME-OLD** |
| supplement_boursin.png | `99a42b19…` | `99a42b19…` (2026-05-09) | **SAME-OLD** |
| supplement_oeuf.png | `9019526d…` | `9019526d…` (2026-05-09) | **SAME-OLD** |
| supplement_jambon_dinde.png | `769aa076…` | `769aa076…` (2026-05-09) | **SAME-OLD** |

This is the concrete, byte-level proof of the owner's "ancienne image" concern.

---

## 3. Severity-tagged findings

### P1 — broken / missing / 0-byte (none)
No standalone image is absent or 0-byte. All files resolve. (Consistent with the prior parity agent's
"all 30 resolve".) **0 P1.**

### P2 — visibly DIFFERENT/older image than kiosk's current photo (the main finding)
Confirmed by Reading both PNGs:

1. **Frites** — `frites.png` standalone = old dark loaded-fries shot vs kiosk branded "LE CAYENNE" box. *(strongest)*
2. **Raclette** — `supplement_raclette.png` = wrong-subject burger vs real cheese slices.
3. **Fromage supp.** — `supplement_fromage.png` = wrong-subject burger vs real grated cheese.
4. **Boursin** — `supplement_boursin.png` = wrong-subject sauce bowl vs real boursin.
5. **Coca** — `coca_cola.png` (and `generated_coca-cola-33cl.png`) = different/dark-bg can vs clean kiosk can.
6. **Salade verte** — `generated_salade-verte.png` = cartoon vs real lettuce photo.
7. **Œuf** — `supplement_oeuf.png` = lower-res different egg render vs kiosk egg. *(milder — same subject)*
8. **Jambon de dinde** — `supplement_jambon_dinde.png` = lower-quality crop vs kiosk turkey-ham photo. *(milder — same subject)*

**Count: 8 P2 products.** (Items 1-6 are clear divergences incl. 3 wrong-subject; items 7-8 are same-subject
quality/render downgrades.)

### P3 — image-reuse within app (cosmetic, known backlog)
- `supplement_raclette.png == supplement_fromage.png` (md5 `d962373a`) — one burger photo for two cheeses.
- `generated_*` placeholder collisions: boursin/galette/fromage-supplementaire all share `1386625…`;
  oeuf/sauce-supp/fromage-a-raclette share `68b9c47e`.
- All 8 bowls share `generated_assiette-poulet.png`; `sandwich-cayenne == big-cayenne`. *(already known
  backlog — not in scope to heal here.)*

---

## 4. Bounded heal proposal (P2 ONLY)

All heal sources already exist in-repo (`public/menu/le-cayenne-v2/` = the kiosk's current real photos). The
heal is "copy real photo → standalone asset path (×2 trees) + point the `image:` field at it". **No new asset
generation, no DB change, no kiosk change.** Mobile and web are kept in lockstep (both standalone, owner-mandate
mirror).

Per product, copy the kiosk real photo into BOTH standalone asset dirs under a clear new filename, then update
the reference in BOTH `menu.js` files. (Filenames below are suggestions; keep the `_v2` suffix to avoid cache
collisions with the old bytes.)

| # | Product | Copy SOURCE (in-repo, real photo) | → DEST (mobile) | → DEST (web) | menu.js reference to update |
|---|---------|-----------------------------------|-----------------|--------------|-----------------------------|
| 1 | Frites | `public/menu/le-cayenne-v2/frites.png` | `mobile/assets/menu/frites_v2.png` | `/Users/1millnonstop/Downloads/web/assets/menu/frites_v2.png` | replace `'frites.png'` refs in FRITES_STYLES (`{id:null}`), BOL_BASE (`bb-frites`), FRITES_STANDALONE (`{id:null}`) |
| 2 | Coca | `public/menu/le-cayenne-v2/coca.png` | `mobile/assets/menu/coca_v2.png` | …`/coca_v2.png` | DRINKS `d-coca` `image` (`coca_cola.png`) + ITEM_IMG `'coca'` (currently `generated_coca-cola-33cl.png`) |
| 3 | Raclette | `public/menu/le-cayenne-v2/raclette.png` | `mobile/assets/menu/supplement_raclette_v2.png` | …`/supplement_raclette_v2.png` | SUPPLEMENTS `sup-raclette` `image` |
| 4 | Fromage | `public/menu/le-cayenne-v2/fromage.png` | `mobile/assets/menu/supplement_fromage_v2.png` | …`/supplement_fromage_v2.png` | SUPPLEMENTS `sup-emmental` `image` (`supplement_fromage.png`); consider `sup-cheddar` (uses `generated_fromage-supplementaire.png`) |
| 5 | Boursin | `public/menu/le-cayenne-v2/boursin.png` | `mobile/assets/menu/supplement_boursin_v2.png` | …`/supplement_boursin_v2.png` | SUPPLEMENTS `sup-boursin` `image` + ITEM_IMG `'supp-boursin'` |
| 6 | Salade | `public/menu/le-cayenne-v2/salade.png` | `mobile/assets/menu/salade_verte_v2.png` | …`/salade_verte_v2.png` | SUPPLEMENTS `sup-legumes-sautes` `image` (`generated_salade-verte.png`) + crudite `c-salade` if desired |
| 7 | Œuf | `public/menu/le-cayenne-v2/oeuf.png` | `mobile/assets/menu/supplement_oeuf_v2.png` | …`/supplement_oeuf_v2.png` | SUPPLEMENTS `sup-oeuf` `image` |
| 8 | Jambon | `public/menu/le-cayenne-v2/jambon-dinde.png` | `mobile/assets/menu/supplement_jambon_v2.png` | …`/supplement_jambon_v2.png` | SUPPLEMENTS `sup-jambon` `image` + ITEM_IMG `'supp-jambon'` |

**Heal size:** 8 product photos × 2 trees = **16 file copies + ~16-24 one-line `menu.js` edits**. Zero
backend, zero frozen-zone, zero DB.

**Caveats / do-not-do:**
- **Galette naming collision (do NOT auto-swap):** the kiosk `galette.png` (folder 10/15) is a **chicken-wrap**
  photo bound to the *potato* "Galette pommes de terre" supplement Item — the photo subject and the Item name
  already disagree in the kiosk itself. The standalone `generated_galette-pommes-de-terre.png` is a separate
  potato-galette cartoon. Propagating the kiosk file would put a wrap photo on a potato-galette chip. **Flag
  to owner — needs a decision on which subject is correct, not a mechanical copy.**
- **Sauces** in standalone are an intentional **SVG vector icon set** (`sauce_*.svg`); the kiosk
  `sauce-supplementaire.png` is a single generic sauce-bowl photo. Not a 1:1 swap — leave sauces as-is.

---

## 5. Recommendation

**This is a real, bounded heal-now — not a wholesale aesthetic swap.**

The owner's complaint is concretely true and provable: the standalone supplement images are **md5-identical to
the 2026-05-09 placeholders** that the kiosk catalog already replaced with the **2026-05-21 V2 real photos**,
and three of them (raclette, fromage, boursin) are **wrong-subject** (a burger / a sauce bowl). The correct
photos already live in the repo at `public/menu/le-cayenne-v2/`. So:

- **DO (heal-now, ~16 copies + ~20 one-line edits, mobile+web lockstep, 0 frozen-zone):** propagate the **8 P2**
  real photos per §4. High value (kills 3 wrong-subject images + 1 off-brand frites + cartoon salade), low
  risk, sources in-hand. The two same-subject downgrades (œuf, jambon) are optional polish but cheap to include.
- **HOLD for owner decision (separate from this heal):**
  1. **Galette** photo-subject collision (wrap vs potato) — needs an owner call, not a copy.
  2. The **wholesale 41-item `generated_*` render → real-photo swap** (all bowls share one render,
     cayenne==big-cayenne, category renders, etc.). That is an **owner aesthetic/asset-production decision**
     (most products have no real photo in-repo at all) and must NOT be bundled into this bounded heal.

**Bottom line: 8 P2 stale-image products; recommend the bounded photo-propagation heal-now for them, with
galette and the full render→photo redesign explicitly deferred to an owner decision.**

---

## Evidence appendix (commands run, read-only)
- `find storage/app/public -newermt 2026-05-30` → fresh-copy file list.
- `DB::table('media')` tinker dump → media.id↔item mapping (table §1a).
- `md5` across kiosk copies, `public/menu/le-cayenne-v2/`, `public/images/menu/`, and both standalone trees →
  byte-identity proofs (§1b, §2 byte-proof table).
- `Read` (multimodal) on both PNGs for all 8 product pairs in §2 — verdicts are from actually viewing the images.
- `mobile/screens-main.jsx:483` + `mobile/data/menu.js:162-171` → confirmed supplements render from the
  `SUPPLEMENTS[].image` field, not `ITEM_IMG`.
- `diff` of image mappings + `md5` of asset bytes → web standalone mirrors mobile exactly (findings apply to both).
