# Menu V3 Heal — Adversarial Convergence Report

**Date** : 2026-05-14
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Heal commit** : `7f06224af` ("feat(menu): heal-light v3 — images + bowl 3-step + sauces Barbecue+Ail")
**Spec** : `tests/e2e/menu-v3-kiosk.spec.js` (9 surfaces : 4 grids + 5 wizard states)
**Captures** : `tests/e2e/__screenshots__/menu-v3/` (15 PNG + DOM + console + network quartets)
**Raw payload** : `reports/audit/menu-v3-2026-05-14/raw-capture-payload.json`
**Test run** : Playwright 1 test, 1 worker, 31s, **PASSED**.

---

## 0. Final verdict

**GO-CONDITIONAL**

- 5 of 6 mission criteria fully verified visually + at DB layer.
- 1 mission claim (Sandwich Cayenne 474 displaying Barbecue+Ail) is structurally impossible by design and was verified instead on the correctly-scoped item (Sandwich Classique 477) — see §3 SCOPE NOTE.
- 1 **P1 wizard rendering drift** surfaced (bowl step 3 = "Quel menu ?" instead of drink picker). DB layer is correct ; wizard component code (frozen) maps `addon_role=drink → type=menu`. Documented for owner-gated followup ; **NOT auto-healed** (would require frozen-zone touch or substantive DB rework outside heal-light scope).

Frozen-zone diff this session : **0 lines touched**. Files written : `tests/e2e/menu-v3-kiosk.spec.js`, capture artifacts, this report.

---

## 1. DB verification (pre-flight, raw queries)

| Check | Expected | Observed | Verdict |
|---|---|---|---|
| Bowl composer profiles step count | 8 bowls × 3 steps each | 8 bowls × 3 steps (profile ids 74-81) | **PASS** |
| Bowl 493 step keys | `sauce / supplements / drink` | `sauce / supplements / drink` (step ids 266-268) | **PASS** |
| Bowl 493 sauce step (attr 330) active variations | 2 (Spicy + Sauce fromagère maison) | 2 active (status=5); 9 inactivated (status=10) | **PASS** |
| Item 490 rename | "Chicken Burger Special" | "Chicken Burger Special" (price 8.90) | **PASS** |
| Item 477 attr 311 variations | Barbecue + Ail present (status=5) | 13 active variations including Barbecue (id 1431) + Ail (id 1432) | **PASS** |
| Total Barbecue+Ail rows on attr 311 | 86 (43 items × 2 names) | 86 rows | **PASS** |
| Media rows for 6 target items | 6 rows on collection `item` | 6 rows (model_ids 78, 79, 80, 83, 84, 88) | **PASS** |
| Media files on disk | 6 files + 18 conversion derivatives | All 6 originals + 18 derivatives present | **PASS** |
| Storage HTTP serving | 200 OK for `/storage/<id>/conversions/<name>-thumb.png` | thumb=200/70KB, cover=200/263KB, preview=200/464KB | **PASS** |
| `SimpleItemResource` exposes thumb | `thumb`/`cover`/`preview` accessor keys | Confirmed in `SimpleItemResource.php:45-47` | **PASS** |

---

## 2. Visual capture summary (9 surfaces)

| # | Surface | PNG | Verdict | Findings |
|---|---------|-----|---------|----------|
| S1 | Bols Gourmands grid (cat 347) | `S1-bols-gourmands-grid.png` | **PASS** | 8 bowl cards, all `img.kiosk-product-image` rendered with `complete=true, naturalWidth=168`. Bowl Frites curry (493) → `/storage/88/conversions/bol-frite-curie-thumb.png`. No fallback divs. 0 i18n leaks. |
| S2 | Bowl 493 wizard step 1 — Sauce | `S2-bowl-493-step1-sauce.png` | **PASS** | Step indicator : 4 dots (`QUELLE SAUCE / QUEL SUPPLÉMENT / QUEL MENU / RÉCAP`) = 3 functional + auto-recap. Sauce step displays exactly 2 choices : `SAUCE FROMAGÈRE MAISON` + `SPICY`. Heading "Quelle sauce ?". 0 errors. |
| S3 | Bowl 493 wizard step 2 — Suppléments | `S3-bowl-493-step2-supplements.png` | **PASS** (with P2 obs) | Heading "Quel supplément ?". 4 choices visible : Oignon Frais €0,90 / Jambon €0,90 / Champignons €0,90 / **Boule Gratinée €2,00**. Gratiné consolidated into supplements list as heal intended. P2 observation : only 4 supps (vs 9 in mockup `menu_bowls_v3.png`) — out-of-scope per PLAN.md §6. |
| S4 | Bowl 493 wizard step 3 — Boisson | `S4-bowl-493-step3-boisson.png` | **P1 drift** | Heading "Quel menu ?" — wizard renders `KioskStepMenu` with 3 options (MENU COMPLET / + FRITES / SANS MENU) instead of a drink picker. See §4 P1-001. |
| S5 | Sandwich Classique 477 sauce step | `S5-classique-477-sauce-step-1.png` | **PASS** | Sauce grid renders 13 sauces with proper images. **Barbecue + Ail visually present** (bottom row, both with sauce thumbnails). `has_barbecue=true`, `has_ail=true` confirmed via body-text scan. |
| S6 | Burgers grid (cat 349) | `S6-burgers-grid.png` | **PASS** | 2 cards : CHICKEN BURGER €6,90 + **CHICKEN BURGER SPECIAL €8,90**, both with images. `has_big_chicken=false`, `has_chicken_burger_special=true`. Rename clean. |
| S7 | Sandwich Cayenne grid (cat 344) | `S7-cayenne-grid.png` | **PASS** | 2 cards : SANDWICH CAYENNE €7,50 (item 474, image 78) + BIG CAYENNE €9,50 (item 488, image 79). Both with images. |
| S8 | Galette grid (cat 345) | `S8-galette-grid.png` | **PASS** | 2 cards : GALETTE NORMALE €6,50 (item 475, image 80) + GALETTE CAYENNE €7,00 (item 476, image present). Both with images. |
| S9 | Sandwich Classique grid (cat 346) | `S9-classique-grid.png` | **PASS** | 2 cards : SANDWICH CLASSIQUE €7,00 (item 477) + BIG CLASSIQUE €9,00 (item 489, image 83). Both with images. |

**Image verification summary** : 6/6 target items render `<img.kiosk-product-image>` with `complete=true, naturalWidth=168`. 0 fallback `<div.kiosk-product-image-fallback>` instances on the target IDs. 0 i18n leaks across all 9 surfaces.

**Network**: 0 4xx/5xx in any surface's `<state>.network.json`. **Console**: only 2 environmental warnings (WebSocket connection to `ws://127.0.0.1:6001` refused — Pusher/Echo not running locally, P3 non-blocking).

---

## 3. Adversarial findings

### P0 — None.

### P1-001 — Bowl step 3 renders menu picker instead of drink picker

**File** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:315-346` (STEP_KEY_REGISTRY + ADDON_ROLE_TO_TYPE)

**Description** :

- DB heal correctly set bowl composer step 3 to `step_key='drink'`, `source_type='addon'`, `source_ref='drink'`, `addon_role='drink'`, `min_select=0`, `max_select=1` (verified per-bowl, profiles 74-81).
- Frontend wizard's `composerStepType(step)` calls `resolveExplicitStepType(step)` which maps `addon_role='drink' → type='menu'` (line 341-346) and `step_key='drink' → type='menu'` (line 333), then renders `KioskStepMenu` component (line 374, `componentForStepType` line 821-835).
- `KioskStepMenu` displays the menu-formula picker : MENU COMPLET / + FRITES / SANS MENU. **No drink choices are shown.** Customer cannot pick a specific drink for their bowl.
- Step label also drifts : "QUEL MENU ?" appears in the stepper (line 64, `getStepLabel` line 1571-1590 resolves `kiosk.wizard.prompt.menu` because type='menu').

**Heal layer involvement** : The heal-light V3 wizard mapping is pre-existing wizard code, not introduced by commit `7f06224af`. The heal correctly wrote the DB ; the rendering issue lives upstream in the frozen frontend component.

**Evidence** : `S4-bowl-493-step3-boisson.png` + `raw-capture-payload.json` surface S4 :
```
heading: "Quel menu ?"
choices: []  (the KioskStepMenu options are rendered as kiosk-menu-card not kiosk-option-card)
body_text_preview: "...QUEL MENU ? Touchez une option pour continuer ... MENU COMPLET Frites + Boisson + FRITES Seulement les frites + SANS MENU Article seul..."
```

**Owner-gated remediation options** (NOT auto-applied) :

- **Option A** (frontend LOCK) : add a `drink` distinct type in `STEP_KEY_REGISTRY` + `ADDON_ROLE_TO_TYPE` + new `KioskStepBoisson.vue` step component. Requires LOCK on `KioskWizardComponent.vue` (frozen-zone).
- **Option B** (DB rework) : replace bowl step 3 with `source_type='item_attribute'` pointing to a new "Boisson" attribute populated with drink variations (Coca, Eau, etc.) per bowl. Composer-only change, no frozen-zone touch — but adds 4-8 sauce-style variations per bowl × 8 bowls = ~50 new rows.
- **Option C** (accept-as-is) : owner accepts the menu-formula picker as the bowl's "step 3" — customer picks add-on slot rather than specific drink. The drink itself is picked post-cart via `KioskUpsellComponent` (current architecture).

**Recommendation** : **Owner decision required**. Heal-light V3 should NOT modify wizard component (frozen) ; the DB is correctly aligned with heal intent. Owner picks A/B/C based on UX preference and timeline.

### P2 — Observations (deferred)

**P2-001** — Bowl supplements list shows 4 items (Oignon, Jambon, Champignons, Boule gratinée) vs 9 items in mockup `menu_bowls_v3.png` (Oignon, Champignons, Jambon, Cheddar, Raclette, Emmental, Boursin, Œuf, Légumes sautés). Already documented in PLAN.md §6 as deferred. Not blocking V1 — bowl heal scope was sauce step + Gratiné consolidation.

**P2-002** — Sauce thumbnails on bowl step 1 (Sauce fromagère maison + Spicy) show a `+` placeholder icon instead of actual sauce images. The 13 sauces on Sandwich Classique step (S5) DO render proper images. Cause : bowl sauces on attr 330 don't have image conversions wired (sauce images live in `image produit (ancien)/` and are not attached to attr-330 variations). Cosmetic, doesn't impact UX function.

**P2-003** — Step label "QUEL MENU ?" on bowl drink step (downstream symptom of P1-001).

### P3 — Environmental

**P3-001** — 2 WebSocket connection errors on every page (`ws://127.0.0.1:6001/app/app-key` ERR_CONNECTION_REFUSED). Pusher/Echo broadcast service not running locally — doesn't affect kiosk functionality (real-time updates degrade gracefully).

---

## 4. SCOPE NOTE — Mission claim #2 vs DB reality (Sandwich Cayenne 474)

Mission spec stated : "Sandwich Cayenne wizard sauce step displays Barbecue + Ail".

**Structural impossibility** :
- Item 474 (Sandwich Cayenne) has **no variations on attr 311 ("Sauce libre")**. It has variations only on attr 307 (Viande 1, 4 choices) + attr 331 ("Sauce Cayenne (incluse)", locked single choice = "Sauce Cayenne maison" variation 1251).
- Heal V3 Layer 3 (00_HEAL_V3_REPORT.md) explicitly scoped Barbecue+Ail to **attr 311 only**, NOT attr 330 (bowl) or attr 331 (Cayenne locked). The scope decision matches mockup `menu_sandwichs_v3.png` and is correct.

**Verified instead via Sandwich Classique (item 477)** :
- 477 has 13 active variations on attr 311 including Barbecue (id 1431) and Ail (id 1432).
- S5 capture visually confirms both sauces render with images in the wizard.

**Verdict** : Mission spec error (not a heal defect). The heal correctly added Barbecue+Ail to the 43 sandwich/burger/tacos/galette family items that have attr 311 (sauce libre), and correctly excluded the locked-sauce items (Cayenne 474/488/etc).

---

## 5. Coverage summary

| Mission criterion | Verdict | Evidence |
|---|---|---|
| (1) Bowl 493 = 3 steps Sauce / Suppléments+Gratiné / Boisson | **PASS** (functional) / **P1** (boisson renders as menu picker) | S2, S3, S4 |
| (2) Sandwich Cayenne 474 sauce step shows Barbecue + Ail | **N/A by design** — verified on 477 (correct scope) | S5 + DB |
| (3) Burgers category renames "Chicken Burger Special" | **PASS** | S6 + DB |
| (4) Images attached to 474, 488, 475, 489, 493, 490 | **PASS** | S1, S6, S7, S8, S9 + media DB rows + HTTP 200 |

---

## 6. Frozen-zone diff

```bash
git diff -- public/js/pos-wizard.js public/css/pos-wizard.css \
  resources/views/admin-pos-v4.blade.php \
  resources/js/components/frontend/kiosk/{KioskWizardComponent.vue,KioskAppComponent.vue,KioskUpsellComponent.vue} \
  app/Services/Fiscal/{FiscalSequenceService.php,ZReportService.php,AuditLogService.php} \
  app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php
# → 0 lines changed this session.
```

Files written this session :
- `tests/e2e/menu-v3-kiosk.spec.js` (new spec — not in frozen list)
- `tests/e2e/__screenshots__/menu-v3/**` (capture artifacts)
- `reports/audit/menu-v3-2026-05-14/raw-capture-payload.json`
- `reports/audit/menu-v3-2026-05-14/CONVERGENCE.md` (this report)

No heals applied. No commits required for adversarial verification.

---

## 7. Recommendation to owner

1. **Accept GO-CONDITIONAL** for the V3 heal. DB-layer artifacts (images, bowl composer, sauces, rename) are clean and idempotent.
2. **Decide on P1-001** (bowl drink step UX) :
   - If acceptable as-is (menu-formula picker via KioskStepMenu) → close as won't-fix, mark V1.0.1+.
   - If Option B (DB rework attr 330-style drink attribute) preferred → schedule a follow-up heal `MenuHealLightV3aDrinkAttribute` (composer-only, no frozen touch).
   - If Option A (frontend split type='drink') preferred → schedule a LOCK plan on `KioskWizardComponent.vue` for the V1.0.1 cycle.
3. **Defer P2-001** (bowl supplements 4→9) and **P2-002** (sauce thumbnails on bowl step 1) to V1.0.1 menu polish wave.
