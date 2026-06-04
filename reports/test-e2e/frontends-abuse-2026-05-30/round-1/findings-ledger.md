# Findings Ledger — Round 1 (verify-before-report gated)

## F-PRICE-01 — Standalone↔DB price divergence — **ESCALATE (owner decision, NOT silently healed)**
**Severity:** cross-system reconciliation (was reported P0 by parity agent vs DB; re-graded
after reading primary source). For the STANDALONE mission scope: NOT a defect — surfaces are
internally consistent + reflect documented owner intent.

**Evidence (verified file:line + DB query):**
| Item | menu.js (mobile+web) | DB (live kiosk) | config/menu.php |
|---|---|---|---|
| Menu (Frites+Boisson) | 2.50 (mobile:184/web:144) | 3.00 | 3.00 |
| Sandwich Cayenne | 7.50 (mobile:403/web:301) | 7.00 | — |
| Sandwich Classique | 7.00 (mobile:426/web:322) | 6.50 | 6.50 |
| Tacos M | 6.90 (mobile:446/web:343) | 8.50 ("Tacos") | 6.50 |
| Tacos L / Big Tacos | 7.90 (mobile:450/web:347) | 11.50 ("Big Tacos") | 8.50 |

**Root cause:** menu.js comments document these as DELIBERATE "heal-light v2" changes (2026-05-14):
- `mobile/data/menu.js:182` `// Formules menu (heal-light v2 2026-05-14 — menu addon 3.00 → 2.50€)`
- `:401` `// SANDWICH CAYENNE — heal-light v2 prix 7.00→7.50`
- `:424` `// SANDWICH CLASSIQUE — heal-light v2 prix 6.50→7.00`
- `:444` `// TACOS — heal-light v2 rename + prix down (8.50→6.90, 11.50→7.90)`
The heal-light seed was applied to the standalone data layer but never to the live DB.

**Why NOT healed autonomously:** aligning standalone→DB would REVERT documented owner intent;
applying standalone→DB is out of scope (backend = GO/frozen this mission). Genuine 3-way SSOT
contradiction → owner's call. Surfaces are mobile↔web consistent (no internal defect).

**Recommendation for owner (in final book):** confirm canonical direction —
(A) standalone heal-light prices are canonical → apply to DB at next backend window; OR
(B) DB is canonical → I revert the 5 standalone prices (exact 10 edits ready, 2 min).
Default lean: (A), because the standalone changes are documented + dated + intent-tagged.

---
(further findings appended below as waves complete)

## F-IMG-01 — Wrong-subject standalone images — **P1 (heal-now, verified)**
**Evidence (md5 + visual Read, both surfaces):**
- `supplement_raclette.png` (md5 d962373a) = **triple cheeseburger photo** (I Read it) — wrong for "Raclette" cheese. mobile/data/menu.js:164 + web:126 (SUPPLEMENTS sup-raclette.image).
- `supplement_fromage.png` (md5 d962373a, IDENTICAL) = same burger — wrong for "Emmental". mobile:165 + web:127 (sup-emmental.image).
- `supplement_boursin.png` (md5 99a42b19) = mayo/sauce bowl — wrong for "Boursin". mobile:167 + web:129 (sup-boursin.image).
**Correct replacements verified in-repo:** `public/menu/le-cayenne-v2/{raclette,fromage,boursin}.png` (raclette.png Read = real raclette slices ✓).
**Heal (batch):** overwrite the 3 standalone files (×2 trees = 6 copies) with le-cayenne-v2 content. Same filename → 0 menu.js edits. Zero backend/DB/frozen-zone.

## F-IMG-02 — Stale (same-subject, lower-quality) standalone images — **P2 (heal-now, bounded)**
- `frites.png` (old dark loaded-fries) vs kiosk branded "LE CAYENNE" box → overwrite with le-cayenne-v2/frites.png.
- `supplement_oeuf.png`, `supplement_jambon_dinde.png` (lower-res crops) → overwrite with le-cayenne-v2/{oeuf,jambon-dinde}.png.
**Heal (batch):** 3 files ×2 trees = 6 copies, same subject, clear quality upgrade.

## F-IMG-03 — DEFERRED to owner (NOT healed) — calibrated open items
- **coca** menu-card (`generated_coca-cola-33cl.png`) unverified; supplement `coca_cola.png` dark-bg — optional, low value, skip for now.
- **salade**: standalone `generated_salade-verte.png` is bound to "Légumes sautés" (≠ lettuce) — copying kiosk salade.png would INTRODUCE a wrong subject → SKIP.
- **galette**: kiosk galette.png is a CHICKEN-WRAP bound to potato-galette Item — subjects disagree in kiosk itself → owner decision.
- **Wholesale 41-item generated_* render → real-photo swap**: owner aesthetic/asset decision (most products have no real photo in-repo). NOT bundled.
- **Image-reuse P3** (cayenne==big-cayenne, 8 bowls share 1 render): known backlog B-ML-04.

## ROUND 3 — Board-photo alignment + full web sweep (2026-05-30 owner /goal continuation)
### Applied (board = base of truth, owner decisions)
- BOARD PHOTOS mirrored onto mobile+web (ITEM_IMG/categories/sauces/meats/crudités/supplements/drinks/frites-styles → public/images/menu board photos). Verified: real tacos/nuggets/cheddar render; 0 generated_* placeholders on live items.
- TACOS: M 6,90 · L 8,90 (owner 2026-05-30). Both surfaces.
- fs-cheddar cheesecake → frites-cheddar.png; fs-cheddar-oignon → frites-cheddar-oignons.png.
- Commits: testttt 56c1cf991, web 4588dab.

### Web full-page sweep (78 tests pass, P0=0, all pages incl hidden/direct → payment, ×3 viewports)
- All pages render, 0 crash/blank/overflow/raw-label/console/404. Board photos GREEN (14 subjects vision-verified).

### F-ORANGINA (board data-gap, NOT a standalone defect) — deferred owner
Orangina renders tropico.png — but config/menu_images.php:145 maps `orangina→tropico.png` (the BOARD itself shows Tropico for Orangina; no faithful Orangina asset exists in-repo). Standalone correctly MIRRORS the board per mandate. Owner: add a real orangina.png to public/images/menu/ → propagates to board + both frontends. No standalone heal (would diverge from board + no correct asset).

### F-HERO-PROMO (P2 disclosed) — web only
screens.jsx:173-176 hero special "Sandwich Cayenne + Menu à 9,00€" (lc-special-price) vs un-wired wizard 10,00€ (7,50+2,50). Intentional counter-promo (About page prices at :737 correctly match menu). Verify the 9,00 deal is current; un-wired V1 doesn't apply promos in-app. Not healed (marketing copy, owner intent).
