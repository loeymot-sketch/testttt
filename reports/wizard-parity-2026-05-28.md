# EXEC-2 Phase 3 — Wizard Parity Audit (Kiosk × Mobile × Web)

**Date** : 2026-05-28
**Scope** : audit read-only de la parity du wizard cross-surface Le Cayenne
**Sources** :
- Kiosk : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (3104 LOC) + `steps/Kiosk*Component.vue` (8 fichiers)
- Mobile : `mobile/screens-item-steps.jsx` (1205 LOC) + `mobile/screens-main.jsx::ScreenItem` (delegation L304-307)
- Web : `/Users/1millnonstop/Downloads/web/wizard-v2.jsx` (528 LOC)
- Données : `mobile/data/menu.js` + `web/data/menu.js` (canonical, identiques côté pools)

---

## 1. Templates utilisés par Le Cayenne (categories canoniques)

`mobile/data/menu.js:218-228` ≡ `web/data/menu.js:175-185` :

| Cat | Slug | `wizard_template` | `has_menu` |
|---|---|---|---|
| 1 | sandwich-cayenne | `sandwich` | true |
| 2 | galette | `sandwich` | true |
| 3 | sandwich-classique | `sandwich` | true |
| 4 | burgers | `sandwich` | true |
| 5 | tacos | `tacos` | true |
| 6 | bols-gourmands | `custom` | false |
| 7 | frites | `custom` | false |
| 8/9/10/11 | suppléments/desserts/boissons/menu-enfant | `simple` | false |

Implication : seuls 4 templates effectifs Le Cayenne = `sandwich`, `tacos`, `custom`, `simple`. Les templates kiosk supplémentaires (`burger`, `assiette`, `omelette`, `salade`, `snacking`) ne sont pas appliqués par le data file Le Cayenne ; le mobile les supporte (pages 70-108 de `screens-item-steps.jsx`), le web ne les a pas (`wizard-v2.jsx:49-138`).

---

## 2. Parity Matrix par catégorie

### Tacos (`wizard_template:tacos`, ex. Tacos M id=501 viandes=1, Tacos L id=502 viandes=2)

| Step | Kiosk | Mobile | Web | Status |
|---|---|---|---|---|
| viandes (min=N max=N) | ✓ `KW.vue:559` | ✓ `screens-item-steps.jsx:72` | ✓ `wizard-v2.jsx:54-60` | ALIGNED |
| sauce (multi, min=1) | ✓ KW:560 (conditionnel: `has_sauce`) | ✓ L73 (conditionnel) | ✓ L62-69 (conditionnel) | ALIGNED |
| crudites (toggle, default ON) | ✓ KW:561 (`garnitures`) | ✓ L74 | ✓ L72-78 | ALIGNED |
| supplements (multi, optionnel) | ✓ KW:562 | ✓ L75 | ✓ L80-86 (max=6) | ALIGNED (max=6 web only — voir §3 P2) |
| menu (radio: full/frites/boisson/none) | ✓ KW:563 (single page combinée) | ✓ L76 | ✓ L88-100 | ALIGNED (UI: kiosk single page, mobile/web split) |
| cascade_drink | ✓ inline KioskStepMenu | ✓ L141 → STEP.DRINK | ✓ L159-165 (`getActiveSteps`) | ALIGNED |
| cascade_frites_style | ✓ KioskStepFritesStyle séparé | ✓ L143 → STEP.FRITES_STYLE | ✓ L167-173 (heal 2026-05-18) | ALIGNED |
| cascade_frites_sauce | ✓ inline KioskStepMenu (`fritesSauceOrder`) | ✓ L144 → STEP.FRITES_SAUCE | ✓ L177-183 (heal 2026-05-18) | ALIGNED |
| recap | ✓ KW:564 | ✓ L150 | ✓ L145 | ALIGNED |

### Sandwichs / Galettes / Burgers (`wizard_template:sandwich`)

Notez : burgers Le Cayenne sont catégorisés `sandwich` (cat 4 — `web/data/menu.js:178`). Donc le pipeline `burger` natif kiosk (`KW:576-584`) n'est jamais appelé sur le menu Le Cayenne actuel.

| Step | Kiosk | Mobile | Web | Status |
|---|---|---|---|---|
| pain | ✓ KW:568 (KioskStepPain) | ✗ (pain non exposé) | ✗ (sandwich = mêmes étapes que tacos sans pain) | DIVERGENT (P3 — kiosk a step pain extra, mobile/web aucun) |
| viandes (conditionnel) | ✓ KW:569 (hasViandes) | ✓ L72 (viande_count > 0) | ✓ L54 (viande_count > 0) | ALIGNED |
| sauce | ✓ KW:570 | ✓ L73 | ✓ L62-70 | ALIGNED |
| crudites | ✓ KW:571 | ✓ L74 | ✓ L72-78 | ALIGNED |
| supplements | ✓ KW:572 | ✓ L75 | ✓ L80-86 | ALIGNED |
| menu + cascade | ✓ idem tacos | ✓ idem tacos | ✓ idem tacos | ALIGNED |

### Bols (`wizard_template:custom` + `has_bol_wizard:true`)

| Step | Kiosk | Mobile | Web | Status |
|---|---|---|---|---|
| sauce (radio, default `bol_sauce_default`) | ✓ via `composer_profile.steps[].step_key='sauce'` (KW:807 registry) | ✓ `screens-item-steps.jsx:114` | ✓ `wizard-v2.jsx:108-113` | ALIGNED |
| bol_supplements (multi, max=`max_select` profil) | ✓ via composer profile generic_choices | ✓ L115 → STEP.BOL_SUPPLEMENTS | ✓ L114-119 | ALIGNED |
| bol_drink (radio optionnel, `__none` + 8 drinks) | ✓ via composer profile addon_role='drink' | ✓ L116 → STEP.BOL_DRINK | ✓ L120-126 (defaultValue=`__none`) | ALIGNED |
| recap | ✓ | ✓ | ✓ | ALIGNED |

### Frites (`wizard_template:custom` + `has_frites_style:true`)

| Step | Kiosk | Mobile | Web | Status |
|---|---|---|---|---|
| frites_style (radio Nature/Cheddar/Cheddar+Oignons) | ✓ KW:638 + KioskStepFritesStyle | ✓ L120 → STEP.FRITES_STYLE | ✓ L127-133 (defaultValue=`__nature`) | ALIGNED |
| recap | ✓ | ✓ | ✓ | ALIGNED |

### Menu Enfant / Boissons / Desserts / Suppléments (`wizard_template:simple`)

| Step | Kiosk | Mobile | Web | Status |
|---|---|---|---|---|
| Direct add (qty stepper, sans wizard) | ✓ KW:637-641 fallback | ✓ L125-133 (sauce/frites_style standalone OK) | ✓ `wizard-v2.jsx:243-246` (DirectAddView) | ALIGNED |

---

## 3. Écarts résiduels identifiés

### Écart #1 — `supplements.max` web=6, mobile=∞, kiosk=∞ (P3 cosmetique)
- Web `wizard-v2.jsx:83` : `max: 6` (block toggle si arr.length>=6)
- Mobile `screens-item-steps.jsx:457-461` : pas de plafond
- Kiosk `KioskStepSupplementsComponent.vue` : pas de plafond appliqué côté template
- **Impact** : marginal — Le Cayenne expose 9 suppléments, user pourrait théoriquement vouloir 7+ sur web et serait bloqué
- **Recommandation** : aligner web sur `max: undefined` OU codifier plafond business à 6 + ajouter même plafond mobile/kiosk
- **Priorité** : P3 (cosmetique, low-impact, no fiscal/composition risk)

### Écart #2 — Affichage prix formule mobile = 3.00€, calc = 2.50€ (P2 UX confusion)
- Mobile `screens-item-steps.jsx:525` UI hard-codé `price: 3.00` (badge sur card "Menu complet")
- Mobile pricing engine `mobile/data/menu.js:184` `FORMULES.f-menu.price = 2.50`
- Web `wizard-v2.jsx:93` UI = `price: 2.50` + `savings: 1.50` (aligné sur computation)
- Kiosk : `KioskStepMenuComponent.vue` lit `getKioskMenuAddonPrice` (helper SSOT)
- **Impact** : mobile user voit "+3.00€" sur le bouton "Menu complet" mais total réel +2.50€. Pas d'écart fiscal (calc utilise FORMULES bit-identique), juste expectation gap UX.
- **Recommandation** : corriger `mobile/screens-item-steps.jsx:525` `price: 2.50` + ajouter `savings: 1.50`
- **Priorité** : P2 (UX confusion, no money loss — sous-affichage du total à payer en faveur du client, donc bénin)

### Écart #3 — Kiosk step `pain` (P3 — absent mobile/web sandwich/galette)
- Kiosk `KW:568` : étape `pain` (KioskStepPain) pour `sandwich`
- Mobile/web : pain non exposé (data file Le Cayenne n'a pas de `pain_choice` dans les items sandwich, donc no-op de fait)
- **Impact** : nul pour le menu Le Cayenne actuel (items n'exposent pas `bread_variations`)
- **Recommandation** : aucune — divergence latente non activée pour V1 Le Cayenne
- **Priorité** : P3 (latent)

### Écart #4 — Mobile `SANS_SAUCE` exclusivity, absent web (P3 dead code)
- Mobile `screens-item-steps.jsx:371-378` : si user pick `s-sans`, tous les autres sauces cleared
- Web `wizard-v2.jsx` : pas de logique exclusivity sur "Sans sauce"
- Réalité : `s-sans` n'existe dans aucune des pools SAUCES (`mobile/data/menu.js:138-150` = 11 sauces, idem web `:102-114`). Dead code mobile défensif.
- **Impact** : nul (option jamais affichée)
- **Recommandation** : nettoyer le dead code mobile OU ajouter explicitement `s-sans` au pool + aligner web. Non bloquant.
- **Priorité** : P3 (dead code)

### Écart #5 — UI granularité : kiosk single-page menu vs mobile/web multi-page (P3 by-design)
- Kiosk `KioskStepMenuComponent.vue:101-187` : menu cards + drink + frites_sauce rendus sur la MÊME page (single-page deep card)
- Mobile/Web : split en 3-4 pages séparées (`STEP.MENU` puis `STEP.DRINK` puis `STEP.FRITES_STYLE` puis `STEP.FRITES_SAUCE`)
- Documented in `screens-item-steps.jsx:17-22` comme by-design (mobile viewport 390×844 = one full-screen step at a time)
- **Impact** : nul — both UX patterns valides selon viewport
- **Priorité** : P3 (by-design, no heal needed)

---

## 4. Validations min/max — alignement

| Step | Kiosk | Mobile | Web |
|---|---|---|---|
| viandes | `>= required` (KW:738-742) | `=== required` (L160, force-replace si arr.length≥required) | `min=n max=n` (L57) |
| sauce | `length>0` (KW:744) | `>=1` (L162) | `min=1` (L67) |
| menu | non-vide + boisson si full/boisson + frites_sauce si full/frites (KW:753-771) | `!!menuChoice` (L168) | `state.menu!==undefined` (L257-258) |
| frites_style | always OK (Nature default valide, KW:748) | `selections.fritesStyleId !== undefined` (L173) | required, defaultValue=`__nature` (L132) |
| frites_sauce | `length>0` si catalogue exposé (KW:768) | `>=1` (L175) | `min=1` (L179) |

Verdict validations : **alignées**. Tous les wizards exigent ≥1 sauce, ≥N viandes, choix explicite menu + cascade complète si formule sélectionnée.

---

## 5. Pricing logic (`priceFor`) cross-surface

Mobile `mobile/data/menu.js:528-564` ≡ Web `web/data/menu.js:416-427` :
- Sauce : 1 gratuite, +0.50€/sauce additionnelle
- Suppléments : prix unitaire de la pool
- Bol supplements + bol drink (priceForDrinkAddon)
- Formule (FORMULES.id) : +2.50 menu / +2.00 frites / +2.00 boisson
- Frites style : +0/+1/+2€
- Frites sauce cascade : 1 gratuite, +0.50€/additionnelle

**Bit-identical sur le calcul**. Backend pricing kiosk = SSOT (`/pricing/preview`) qui fait autorité — mobile/web restent client-side calc seul (non NF525-bound car pas de séquence fiscale émise côté app).

---

## 6. Verdict final

**GO** — parity wizard cross-surface ALIGNED post heal 2026-05-18 + heals précédents 2026-05-16/17.

Aucun P0 ou P1 ouvert sur la parity wizard. Les 5 écarts identifiés (Écart #1 à #5) sont :
- 1 × P2 (UX confusion mobile menu prix display)
- 4 × P3 (cosmétique / by-design / dead code / latent)

Le P2 (mobile menu prix 3.00€ vs 2.50€ calc) mérite un heal scope-minimal **1-line** (`screens-item-steps.jsx:525` `price: 3.00 → 2.50` + `savings: 1.50`) hors scope V1 ship. Aucun risque fiscal NF525 (calc engine bit-identical entre les 3 surfaces).

**Recommandation EXEC-2** : Phase 3 close — no heal required pour V1 ship. Backlog V1.0.2 : Écart #2 (mobile menu prix display).

---

## 7. Fichiers de référence (file:line)

- Kiosk activeSteps : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:547-643`
- Kiosk composer profile : `KioskWizardComponent.vue:779-844`
- Kiosk menu step (single-page combiné) : `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue:1-189`
- Mobile templates : `mobile/screens-item-steps.jsx:60-152`
- Mobile cascade : `mobile/screens-item-steps.jsx:137-147`
- Web buildSteps : `web/wizard-v2.jsx:13-147`
- Web getActiveSteps + cascade : `web/wizard-v2.jsx:152-188`
- Web computeWizardTotal : `web/wizard-v2.jsx:193-213`
- Heal cascade_frites_sauce 2026-05-18 : `web/wizard-v2.jsx:174-184` + `:206`
- Mobile heal allergens FIC 2026-05-17 : `mobile/screens-item-steps.jsx:790-819`

---

(EXEC-2 — wizard parity audit Phase 3 — read-only — 0 commits — 0 frozen-zone touch)
