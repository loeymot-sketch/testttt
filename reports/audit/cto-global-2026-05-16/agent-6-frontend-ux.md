# Agent 6 — Frontend / UX / Design / A11y — CTO Global Audit 2026-05-16

**Auditor** : Claude Opus 4.7 (1M ctx), read-only, no Agent dispatch
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10` @ HEAD `c3ba89863`
**Scope** : POS Vanilla wizard + Kiosk Vue wizard + KDS Vue + OSS Vue + Admin Vue + Mobile React
**Severity legend** : P0 = legal / safety / blocker shipping ; P1 = brand/UX defect a paying customer will hit ; P2 = polish / inconsistency

---

## §1 Top-level scores

| Axis | Score / 100 | One-line verdict |
|---|---|---|
| **Visual coherence** | **62** | Four parallel token systems (cv1, pos-v5, kiosk, mobile) ; POS frozen CSS still ships legacy red `#E93C3C` while pos-v5 tokens migrated to Cayenne `#F4501E` ; mobile palette `#FF5A1F` is **owner-validated divergent**, not a defect. |
| **A11y (WCAG 2.1 AA)** | **48** | Kiosk surfaces a11y-conscious (ARIA, focus management, 44px targets, RTL hooks). POS Vanilla wizard is structurally non-accessible : 0 ARIA, 0 roles, 34 click handlers on `<div>` elements, touch targets 32 px (below SC 2.5.5 44 px). |
| **i18n** | **66** | 1909 FR / 2032 EN / 1866 AR JSON keys ; AR ≈ −2.3 % vs FR ; `kiosk.confirmation.*` migration successfully landed across all 3 locales ; ≥ 11 raw-FR fallback strings in production Vue + 100 % hardcoded FR in `public/js/pos-wizard.js`. RTL hooks present in 14 files (kiosk + POS-v5 + KDS) — quality unverified visually. |

---

## §2 Per-surface micro-scores

| Surface | Score | Headlines |
|---|---|---|
| **Kiosk** (Vue 3, frozen wizard) | **74** | Mature DS (`ds/Ks*`), tokens warm-then-bold layered correctly, A11y composable, RTL CSS hooks. Visual evidence (`tests/e2e/__screenshots__/menu-v3/00-kiosk-categories-overview.png`) confirms clean light-mode Cayenne brand. Negatives : raw-FR fallbacks (`'Article'`, `'Supprimer cet article'`, `'Halal'`, etc.) sprinkled in 8+ kiosk components. |
| **POS Caisse** (Vanilla JS, frozen) | **31** | Production-validated by owner but objectively the weakest surface on a11y + i18n + brand-token discipline. `public/js/pos-wizard.js` ships 5 964 lines with 0 ARIA hooks, hardcoded FR strings ('Récap', 'Vérifiez votre commande', '✕ Sans ' + name, '▼ Voir tous'), and `public/css/pos-wizard.css` `.viande-btn` = 32×32 px + `border:#E93C3C` (legacy red). |
| **KDS** (Vue 3) | **58** | UX 3.2/10 baseline from 2026-05-11 cluster-7 audit. `KdsOrderCard.vue` exposes `hasAllergen` orange pill (good), `elapsedFormatted` timer (good), but `KitchenDisplaySystemComponent.vue` has **3 raw-FR fallbacks** in production paths (lines 1899, 2004, 2010 : `'Addon'`, `'Erreur réseau'`, `'Erreur réseau'`) + `aria-label="…"\|\|'Afficher les articles'` repeated × 4 — i18n key `label.kds_toggle_items` exists but operator gets FR if any locale change races a render. Lives under flat `label.kds_*` namespace — no dedicated `kds.*` root. |
| **OSS** (Vue 3) | **60 (limited evidence)** | `OrderStatusScreenComponent.vue` is a 61-line layout shell + 2 child components (PopularItem, PreparingAndReady). `role="main"` + `aria-label="$t('label.oss_main_aria')"` present — good basis. **Insufficient depth in this audit** ; defer to Wave-Z agent Z4 for substantive scoring. |
| **Admin** (Vue 3) | **55 (limited evidence)** | Directory inventory shows 30+ feature folders (administrators, cash, chefs, coupons, customers, dashboard, deliveryBoys, items, observability, etc.). Heavy admin surface area, only 4 top-level `admin.*` keys in fr.json (vs 111 `kiosk.*` keys) — strong suggestion of pervasive hardcoded labels on admin pages. **Not deeply sampled** ; full audit needs a dedicated pass. |
| **Mobile App** (React, separate codebase) | **52** | Strong cycle B work landed (2026-05-11 mobile-design-perfect) : viewport meta fix, TabBar role="tablist", IconBtn 44 px, ModalShell dialog pattern, axe-core clean. **But two cluster-7 P0 still open** : (a) fabricated allergens on 60/60 items, (b) promo code applied but total unchanged (deceptive UX). Palette divergence (`#FF5A1F` vs kiosk `#F4501E`) is owner-validated, not scored as defect. |

---

## §3 Findings (P0 / P1 / P2)

### P0 — Legal / Safety / Blocker

#### P0-FE-01 Mobile fabricated allergens — 100 % of catalog (60/60 items)
- **File** : `mobile/data/menu.js:274` — `allergens: opts.allergens || ['gluten', 'lactose'],`
- **Behavior** : No item passes `allergens` opt → every item (incl. Eau Plate 50 cl, Coca, Sprite, Fanta, Orangina, Oasis, Capri-Sun) is tagged `gluten + lactose`. Tiramisu missing real allergen `œuf`. Œuf supplément tagged `gluten + lactose` instead of `œuf`.
- **Exposure** : EU Regulation 1169/2011 — mandatory accurate disclosure of 14 major allergens. False positives mislead coeliacs / lactose-intolerant ; false negatives expose anaphylactic risk (Tiramisu without `œuf`).
- **Cross-ref** : cluster-7 adversarial verdict 2026-05-11 D4 — P0 still **OPEN** (not in heal commits).
- **Severity** : P0 (legal — French DGCCRF inspection trigger).

#### P0-FE-02 Mobile promo code applied without discount
- **Files** : `mobile/screens-main.jsx:595` total formula `cart.reduce((s,i)=>s+i.price*i.qty, 0)` ; PromoCodeRow internal `appliedCode` never propagates.
- **Behavior** : User types "WELCOME10", green banner "✓ Code WELCOME10 appliqué", proceeds to pay **full price unchanged**. Documented as "V0 mock, backend wireup Phase 6.C" in source.
- **Severity** : P0 — deceptive UX even at V0 ; either remove input or stub a fake discount. Cross-ref cluster-7 verdict D6 — **OPEN**.

#### P0-FE-03 POS wizard structural a11y failure (frozen — LOCK required)
- **Files** : `public/js/pos-wizard.js` ; `public/css/pos-wizard.css`
- **Evidence** :
  - `grep -c 'aria-' public/js/pos-wizard.js` → **0**
  - `grep -c "role=" public/js/pos-wizard.js` → **0**
  - 34 `addEventListener('click', …)` on `<div class="wizard-option">` (lines 1243, 1340, 1356, 1372, etc.) — div-as-button anti-pattern, **non-focusable, not keyboard-operable, not screen-readable**.
  - `.viande-btn` 32 × 32 px (`public/css/pos-wizard.css:308-310`) — **fails WCAG 2.5.5 SC Target Size 44 × 44 px**.
  - Legacy brand red `#E93C3C` hardcoded (line 312) — owner-gated Cayenne migration `#F4501E` complete on pos-v5-tokens.css line 30 but NOT applied to frozen wizard CSS.
  - 100 % hardcoded FR : `'Récap', 'Vérifiez votre commande', '▼ Voir tous (+' + n + ')', '✕ Sans ' + name, 'Choisissez ' + viandeCount + ' viande' + …`. No i18n hook at all.
- **Severity** : P0 — would fail any AccessiWay / DGE audit ; LF restaurants must be EU EAA-aligned by 2025-06-28. Restaurant employees needing assistive tech CANNOT operate the wizard.
- **Path** : requires `LOCK_POS_WIZARD_A11Y_2026-05-XX.md` doc + owner gate (file is in frozen-zone per CLAUDE.md §7).

---

### P1 — Brand / UX / customer-facing defects

#### P1-FE-04 Multi-surface raw-FR fallback proliferation
- **Files** (sample, non-exhaustive) :
  - `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1899` — `|| 'Addon'`
  - `…:2004, :2010` — `|| 'Erreur réseau'` (used in 2 catch blocks)
  - `…:321, :505, :650, :792` — `:aria-label="$t('label.kds_toggle_items') || 'Afficher les articles'"`
  - `resources/js/components/frontend/kiosk/KioskCartComponent.vue:216,544` — `|| 'Supprimer cet article'`, `|| 'Article'`
  - `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:377,461,718,731,734,737,740` — `|| 'Menu'`, `|| 'Épuisé'`, `|| 'Nouveau'`, `|| 'Halal'`, `|| 'Végétarien'`, `|| 'Piquant'`
  - `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue:28,38,46` — `|| 'Votre commande sera effacée dans'`, `|| 'Je suis là'`, `|| 'Abandonner'`
  - `resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue:6,21,31` — `|| 'Offres en cours'`, `|| 'Offre'`, `|| 'Code'`
  - `resources/js/components/frontend/kiosk/ds/KsChip.vue:29` — `|| 'Retirer'`
  - `resources/js/components/frontend/kiosk/ds/KsCartBottomSheet.vue:143,146,155` — `|| 'Aperçu de votre panier'`, `|| 'Votre commande'`, `|| 'Quantité'`
- **Impact** : EN/AR customers see FR labels if `$t()` returns falsy (locale boot race, missing leaf, MissingHandler swallowed).
- **Severity** : P1 — i18n quality regression risk, especially on AR which already has ≈ −2.3 % key coverage.

#### P1-FE-05 AR i18n coverage gap (2 032 EN vs 1 866 AR = −8.2 % vs EN, 1 909 FR vs 1 866 AR = −2.3 % vs FR)
- **Evidence** : `jq 'paths|length'` on each of `resources/js/languages/{fr,en,ar}.json`.
- **Interpretation** : EN has 123 keys that FR doesn't (likely Anglo-only payment / shipping copy), AR has 43 fewer than FR. EN > FR is anomalous on a French-primary product — surface admin/dev keys leaking into production bundle ; AR < FR means some FR labels have no AR translation and will render as the i18next fallback (likely FR string or the key itself).
- **Severity** : P1 — Le Cayenne first restaurant has Arabic-speaking clientele claim ; partial AR is worse than no AR (signals neglect).

#### P1-FE-06 Four parallel design-token systems with no convergence map
- **Files** :
  - `resources/css/foundations/cv1-tokens.css` — admin/kiosk CV1 (blue `#2563EB` focus, slate palette)
  - `resources/css/foundations/pos-v5-tokens.css` — POS V5 (Cayenne `#F4501E`, warm cream)
  - `resources/css/kiosk/tokens.css` + `tokens-bold.css` — Kiosk W3C DTCG (Cayenne, V2 white-pur surface)
  - `mobile/styles.css` + `mobile/redesigns-styles.css` — Mobile (Cayenne sibling `#FF5A1F`, ink black, yellow `#FFD93D`)
- **Observation** : tokens layered (good intent) but no shared root — focus ring is `#2563EB` blue on cv1, `#F4501E` orange on kiosk-bold, `var(--orange)` on mobile. Same product, three focus rings. POS frozen CSS still uses legacy red `#E93C3C` while neighboring pos-v5.css uses Cayenne — internal POS inconsistency.
- **Severity** : P1 — brand drift visible to anyone who logs into admin then operates kiosk + POS.

#### P1-FE-07 KDS UX 3.2/10 baseline still open (8 P0 from cluster-7 2026-05-11)
- **Cross-ref** : `memory/project_kds_audit_2026-05-11.md` — accordéon fermé, banners stack, bump 32px (matches our 32px finding pattern), bug allergenModal typo, contrast 3.2:1, 18 raw labels FR.
- **Status in this audit** : Confirmed `KitchenDisplaySystemComponent.vue` (2 545 lines, monolithic) still ships raw-FR fallbacks (P1-FE-04 above) ; KDS V2 grid (`KdsV2Grid.vue`) shipped per `5f48856f9` but the 8 P0 reading-from-3m findings not separately verified here.
- **Severity** : P1 — kitchen operator surface, station readability critical.

#### P1-FE-08 Kiosk empty-state under-utilization (visual evidence)
- **Screenshot** : `tests/e2e/__screenshots__/menu-v3/S5a-classique-477-step1.png` (read this audit)
- **Observation** : "QUELLE VIANDE ?" step on Sandwich Classique shows 4 meat cards in a 2-column grid, top-half-screen only, then **massive empty white space below** + a "Sélectionnez 1 viande pour continuer" pill. On a 1920px-tall kiosk this is ~ 60 % wasted real-estate. The wizard could show price tiles, allergens, recap preview, or upsell carousel.
- **Severity** : P1 — conversion / AOV opportunity loss.

---

### P2 — Polish / inconsistency

#### P2-FE-09 POS wizard inline `onclick="…"` strings (lines 1255, 1642, 3329, 4986, 4989)
- Mixed inline-JS + addEventListener — CSP nightmare, refactor target on next LOCK pass.

#### P2-FE-10 KDS namespace lives under flat `label.kds_*` instead of `kds.*`
- Works but undisciplined ; merging into a `kds.*` root would align with `kiosk.*`, `pos.*`, `a11y.*` already present.

#### P2-FE-11 Mobile vs Kiosk brand-red divergence (owner-validated)
- Kiosk `#F4501E` (Cayenne) ; Mobile `#FF5A1F` (lighter, more saturated). Per CLAUDE.md memory : owner-confirmed correction. **NOT scored as defect** but worth re-confirming with owner before next print/POS rollout.

#### P2-FE-12 `viande-btn` Vue components also 32 px (per kiosk visible touch target in screenshot)
- Need ground-truth measurement on kiosk hardware ; Vue wizard appears to comply with kiosk-touch-min token but spot-check on 27" portrait recommended.

---

## §4 Top-3 recommendations

1. **LOCK-doc POS wizard A11y + brand migration** (`P0-FE-03`)
   - Surgical patch : convert `<div class="wizard-option">` → `<button class="wizard-option">` + `aria-label` + `data-i18n` ; bump `.viande-btn` to 44×44 px ; replace `#E93C3C` with `var(--pos-v5-brand-red)` (Cayenne) ; introduce `pos.wizard.*` i18n keys ; keep logic + price math intact (the actual "design parfait" stays).
   - Sub-tasks scope ~ 200 lines diff, fully testable via Playwright keyboard-nav spec + axe-core run. Owner gate explicit per frozen-zone rule.

2. **Fix mobile allergens + promo wiring** (`P0-FE-01` + `P0-FE-02`)
   - Allergens : remove default `['gluten', 'lactose']` from `mkItem` (mobile/data/menu.js:274), make `allergens || []`, then audit every 60-item record and curate per real recipe ; ensure `AllergenBadge` returns `null` when empty (it already does). 1–2 days of recipe-owner-validated data entry + 1 line code change.
   - Promo : either remove the PromoCodeRow input entirely until Phase 6.C wires, or stub a 1-line fake `discount = appliedCode === 'WELCOME10' ? 10 : 0` to make UX truthful. 30 minutes.

3. **i18n sweep — kill raw-FR fallbacks + close AR gap** (`P1-FE-04` + `P1-FE-05`)
   - One-shot script : ripgrep `\|\|\s*['"][A-ZÉ][a-zé ]{3,40}['"]` across `resources/js/components/`, add the missing keys to all three locales, delete the `||` tail. Plan : 1 day work, regression-tested by `vue-i18n MissingHandler` enforcement (already wired per `resources/js/i18n.js`).
   - AR gap : diff `jq paths fr.json` vs `jq paths ar.json` → produce 43-key delta list → translate, commit. 1 day with native AR translator.

---

## §5 Evidence files cited

- `resources/js/languages/{fr,en,ar}.json` — i18n coverage (jq paths counts 1909 / 2032 / 1866)
- `public/js/pos-wizard.js` (5964 lines) + `public/css/pos-wizard.css` (lines 308-310 viande-btn 32px, 312 #E93C3C)
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1899,2004,2010,321,505,650,792`
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:32-55,221-244` (allergen pill OK)
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:377,461,718,731,734,737,740`
- `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue:28,38,46`
- `resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue:6,21,31`
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue:216,544`
- `resources/css/foundations/cv1-tokens.css` ; `resources/css/foundations/pos-v5-tokens.css` ; `resources/css/kiosk/tokens.css` ; `mobile/styles.css`
- `mobile/data/menu.js:274` (allergen default)
- `mobile/screens-main.jsx:595` (total formula)
- `reports/test-e2e/cluster-7-2026-05-11/ADVERSARIAL_VERDICT.md` (cross-ref D4 + D6)
- `reports/test-e2e/mobile-design-perfect-2026-05-11/FINAL_REPORT.md` + `contrast-investigation.json`
- `tests/e2e/__screenshots__/menu-v3/00-kiosk-categories-overview.png` + `S5a-classique-477-step1.png` (visually read by auditor)

---

## §6 Limits & gaps in this audit

- OSS and Admin sampled at directory-listing depth only ; per-component review deferred. Scores published with explicit "(limited evidence)" disclaimer.
- KDS visual rendering on a 3 m / 27" wall-screen not directly verified — relied on cluster-7 baseline.
- RTL hooks present in 14 files but no AR live-render screenshot loaded in this session ; recommend a 30-min dedicated AR pass.
- POS V5 Vue components (`resources/js/components/admin/pos/v5/*`) not depth-sampled — only the Vanilla frozen wizard was scored. V5 may already address the a11y gaps the Vanilla wizard exhibits ; the question is which surface end users actually see in production today.

---

*End report — Agent 6 / Frontend-UX / 2026-05-16.*
