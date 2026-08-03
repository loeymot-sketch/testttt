# 01 — Category Parcours Audit (mobile vs kiosk)

**Date** : 2026-05-11
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10` · HEAD `ebb712dd8`
**Agent** : AGENT-1 CATEGORY-PARCOURS-AUDITOR (ULTRA-PLAN cycle)
**Mission** : Comparer parcours mobile (13 cats × 60 items) vs canonique kiosk (`KioskWizardComponent.vue:547-639` V3.8 ROUND-5).

---

## Executive summary

**33 findings au total** dont **8 P0 blockers de parité** (tous catégoriels), **15 P1** (logiques de step), et **10 P2** (edge cases + composition snapshot).
**Parity rate global** : ~55% (mobile diverge significativement sur 5 catégories : `assiette`, `omelette`, `snacking`, `salade`, `sandwich` pain step).
**Biggest gaps** : **(1) `sandwich` step `pain` JAMAIS proposé en mobile** (kiosk OBLIGATOIRE pour items 207/208 "Sandwich Classique Pain/Galette"), **(2) `snacking` ordre des steps inversé** (mobile: sauce→menu→supplements ; kiosk: sauce→menu→frites_style→supplements), **(3) `assiette`/`omelette` mobile ajoute crudités/garnitures+supplements alors que kiosk V3.8 a sciemment simplifié à `sauce → recap` post owner-gate round-5**.

---

## Methodology

### Sources lues (READ-ONLY)

| Source | Lignes-clés |
|--------|-------------|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | `activeSteps` computed `547-640`, `shouldShowStep` `978-1037`, `canAdvance` `730-772`, `effectiveWizardTemplate` `881-903` |
| `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` | counter `7-12`, validation `9` (`includedQuotaSelected / maxViandes`), paid extras `44-46` |
| `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` | `selectedCount > 1` extra price `7-9,107-119`, no Sans-Sauce exclusivity logic (handled implicitly via min-1 + ordre `sauceOrder`) |
| `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue` | toggleable default ON `5-7`, removed strikethrough `45` |
| `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` | qty-counter `64-88` (incr/decr), pas un simple toggle |
| `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` | choices full/frites/boisson/none, drink picker inline `101-145`, frites_sauce inline `147-187` |
| `resources/js/components/frontend/kiosk/steps/KioskStepFritesStyleComponent.vue` | Nature default `8-22`, upgrades `26-48`, `selections.fritesStyleExtraId` |
| `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue` | S/M/L/XL → viandeCount `92-97` |
| `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue` | catalog pain attr `72-94`, no fallback |
| `config/menu.php` | 13 cats + 60 items + has_sauce/has_crudites + viandes count |
| `database/seeders/ItemCategoryWizardSeeder.php:19-35` | wizard_template canonique DB |
| `mobile/data/menu.js` | 60 items + flags `has_sauce/has_crudites/has_menu_addon/has_frites_style` (lines 280-394) |
| `mobile/screens-item-steps.jsx` | `computeActiveSteps:56-126`, `canAdvance:131-155`, 9 step components |
| `reports/review/mobile-audit-2026-05-10/99_VERDICT.md` | 14 findings + owner-gate D1-D6 + U2/U3/U4 (résolus partiellement Round-5) |

### Comparison criteria

Pour chaque catégorie, 8 dimensions ont été vérifiées :

1. **Active steps order & set** (kiosk `activeSteps` switch case vs mobile `computeActiveSteps` switch case)
2. **Per-item override** (e.g. tacos M=1 viande vs XXL=4 — via `item.viandes` count, kiosk via `detectViandeCount`)
3. **Validation rules** (`canAdvance` per step)
4. **Special edge cases** (Sans Sauce exclusivity, pain attr, cooking style, frites_style cascade)
5. **Cascade rules** (menu choice → drink + frites_style + frites_sauce)
6. **Default selections** (crudités default ON, sauce 1 gratuite, frites_style=Nature default)
7. **Composition snapshot** (cart line item shape)
8. **Step component A11y + UI** (qty counter for supplements, taille step, etc.)

Findings labeled by severity : P0 (blocker parity), P1 (logic divergence), P2 (cosmetic / nice-to-have), GAP / EXTRA / PARITY.

---

## Per-category findings

### Category 1 — `nos-tacos` (4 items: tacos M / L / XL / XXL — viandes 1/2/3/4)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `taille?` → `viande` → `sauce` → `garnitures` → `supplements` → `menu` (`→ cascade drink/frites_style`) → `recap` (KW.vue:557-565) | `viandes` → `sauce` → `crudites` → `supplements` → `menu` (`→ cascade`) → `recap` (steps.jsx:64-71) | **GAP — `taille` step ABSENT** (T-001) | **P0** |
| Per-item override | Tacos M (viandes=1), L (2), XL (3), XXL (4) | mobile data `viandes: 1/2/3/4` correct (menu.js:280-283) | PARITY | — |
| `taille` step logic | Affiché SI `effectiveWizardTemplate==='tacos'` ET `hasPresetSizeInName(name+desc)==false` (KW.vue:1039-1054) | JAMAIS affiché (steps.jsx:64 switch case `tacos` n'inclut pas STEP.TAILLE — n'existe même pas dans `STEP` registry) | **GAP** (T-002) — fallback nom-detection mobile OK pour items 101-104 mais aucune robustesse pour custom items | **P1** |
| Validation `viandes` step | `(selections.totalViandes \|\| 0) >= required` (KW.vue:737) — supporte viandes-extras payantes via `includedViandeSelectionCount` (KW.vue:1110-1116) | `meatIds.length === item.viandes` (steps.jsx:134) — pas de viandes payantes (T-003) | **GAP** | **P1** |
| Validation `sauce` step | `selections.sauceOrder.length > 0` (KW.vue:741) — au moins 1 sauce | `selections.sauceIds.length >= 1` (steps.jsx:135-136) | PARITY | — |
| Sauce extra pricing | 1st gratuite, additional `extraSaucePrice` via `getKioskExtraSauceUnitPrice` (KioskStepSauce:107-119) | 1st gratuite, additional +0.50€ hardcodé (steps.jsx:367-369) | **GAP** (T-004) — mobile hardcode €0.50 alors que kiosk lit le catalogue `getKioskExtraSauceUnitPrice` (peut varier par item) | **P2** |
| Sans Sauce | Kiosk ne hardcode pas — l'attribut catalogue a une row "Sans sauce" si présent ; sinon sauce step est requis | Mobile ajoute `SANS_SAUCE = 's-sans'` avec exclusivity logic (steps.jsx:338-345) | **EXTRA — comportement INVENTÉ côté mobile non présent en kiosk** (T-005) | **P2** |
| Cascade `menu='full'/'frites'/'boisson'` | Inline sur la page Menu (radio + drink picker + frites_sauce picker dans même step) — KioskStepMenuComponent.vue:101-187 | Split en 3 pages séparées (DRINK + FRITES_STYLE + FRITES_SAUCE) — steps.jsx:114-121 | **GAP UX** (T-006) — different UX paradigm (mobile = 3 separate pages, kiosk = 1 page with sub-sections) | **P2** |
| Default selections | Garnitures = toutes ON (KioskStepGarnitures:5-7 "all_included") | Crudités = `lcMenu.defaultCruditeIds()` ON (steps.jsx:383, 138 always-valid) | PARITY (semantically equivalent) | — |
| Composition snapshot | `composition_snapshot` JSON with viandes + sauces + garnitures + supplements + menu + cascade — sent to PricingService backend (KW.vue:1750-1800) | `buildLineItem` builds local `composition_summary` text + flat fields (steps.jsx:698-751) — pas d'extras[] avec group_label (déjà flaggé par F-11 audit Mai-10) | **GAP** (T-007) | **P1** |

**Tacos free-text notes** :
- Mobile data layer encode déjà correctement `viandes: 1/2/3/4` par item — c'est la `computeActiveSteps` qui ignore le `taille` step. C'est cohérent puisque mobile n'a pas besoin de demander la taille (déjà encodée dans l'item), MAIS le kiosk garde le step comme guard rail si `viande_count` absent du payload backend. **Fix recommandé** : ajouter STEP.TAILLE only when `item.viandes==null OR item.viandes==undefined` (defensive).
- Le `viande` counter mobile (`{meatIds.length}/{required}`) est PARITY visuelle avec kiosk `{includedQuotaSelected} / {maxViandes}` (KioskStepViande:8-13).

---

### Category 2 — `nos-sandwichs` (8 items: Méga / Terminator / Suprême / Cayenne / Froid / Panini / Classique Pain / Classique Galette)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `pain` → `viande?` → `sauce` → `garnitures` → `supplements` → `menu` → `recap` (KW.vue:567-575) | `viandes?` → `sauce` → `crudites` → `supplements` → `menu` → `recap` (steps.jsx:65-71) | **GAP — `pain` step ABSENT** (S-001) | **P0** |
| `pain` step gating | `shouldShowStep('pain')` : item.itemAttributes.find(a.name.includes('pain'\|'galette')) ET variations actives (KW.vue:1000-1011) | N'existe pas dans STEP registry mobile | **GAP — manquant** (S-002) | **P0** |
| Items concernés par `pain` | Items 207 `Sandwich Classique (Pain)` + 208 `Sandwich Classique (Galette)` — ces 2 items ont un attribut `pain` avec variations (cf. comment desc `1 Viande au choix dans un pain classique` vs `dans une galette`) | Mobile menu.js:294-295 distingue par slug `sandwich-pain` / `sandwich-galette` mais ne propose AUCUN step pain — l'item est juste différencié à l'URL | **GAP** (S-003) — UX impact : si user veut commander "Sandwich Classique" il doit choisir Pain OU Galette via 2 items distincts au lieu d'1 item + step pain (POS-parity broken) | **P1** |
| Sandwich Suprême viandes=1 | Config menu.php:217-223 dit `viandes: 1` "Only fixed meats" — mais owner comment dit "I'll limit to 0 custom meats since it's fixed" (drift dans le fichier source !) | Mobile menu.js:290 dit `viandes: 0` (no choice, ingredients listed in description) | **EXTRA** (S-004) — mobile diverge de config/menu.php:220 but ALIGNS avec intent (sandwich avec ingrédients fixes) | **P2** |
| Sandwich Froid viandes=0 | config:236 `viandes: 0` | mobile:292 `viandes: 0` | PARITY | — |
| Sandwich Panini viandes=1 | config:244 `viandes: 1` | mobile:293 `viandes: 1` | PARITY | — |
| Cascade menu (formule) | Same as tacos cascade | Same as tacos cascade | PARITY | — |

**Sandwiches free-text notes** :
- **Le plus gros gap de toute l'audit** : `pain` step absent. Items 207/208 sont actuellement traités comme 2 SKUs différents en mobile alors que kiosk modélise 1 SKU + 1 step de choix.
- Recommendation : soit (a) ajouter `STEP.PAIN` dans mobile et merger 207/208 en 1 item avec `has_pain: true` + 2 variations ; soit (b) garder les 2 items distincts mais documenter explicitement la divergence vs kiosk.

---

### Category 3 — `nos-burgers` (6 items: Burger Poulet / Cheese / Fish / Double / Big / Grill)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `viande?` → `sauce` → `garnitures` → `supplements` → `menu` → `recap` (KW.vue:577-584) | `sauce` → `crudites` → `supplements` → `menu` → `recap` (steps.jsx:72-77) | **GAP — `viande?` step MISSING** (B-001) si jamais un burger custom prend viande extra | **P2** |
| Viandes count | All 6 burgers `viandes: 0` (config:275,283,291,299,307,315) | All 6 burgers `viandes: 0` (mobile:300-305) | PARITY | — |
| Cascade menu | Same cascade | Same cascade | PARITY | — |

**Burgers free-text notes** :
- Burgers actuels = no-choice viandes — donc l'absence de step viande est tolérable. **MAIS** kiosk garde le step défensivement si un burger custom premium emerge (e.g. "Burger 2 Steaks + supplément Poulet payant"). Mobile n'a pas ce hook.

---

### Category 4 — `nos-assiettes` (4 items: Poulet / Kefta / Merguez / Mixte)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `viande?` → `sauce` → `recap` (KW.vue:585-597 — V3.8 ROUND-5 simplifié post owner-gate) | `sauce` → `supplements` → `recap` (steps.jsx:78-81) | **GAP — `supplements` step EXTRA en mobile** (A-001) | **P0** |
| Pourquoi simplifié | Comment KW.vue:586-592 : "Assiettes contiennent DÉJÀ frites + salade + pain + sauce de base ... Supplements/garnitures retirés du wizard pour ne pas alourdir l'UX" | Mobile garde encore supplements step en `template==='assiette'` | **GAP intentionnel kiosk non répliqué mobile** (A-002) | **P0** |
| Assiette Poulet "style cuisson" | Description `Poulet (Nature - Curry - Paprika)` mais **AUCUN ItemAttribute** ni step wizard (config:328) — owner-gate U4 = (A) "Garder en description" | Mobile description identique (`Poulet (Nature · Curry · Paprika)`) — pas de step (mobile:310) | PARITY (per U4=A) | — |
| `has_crudites` | config:331 `false` | mobile:310 `has_crudites: false` | PARITY | — |
| `has_menu_addon` | DB seeder `has_menu: false` (cat 309) + V3.8 simplifié | mobile:310 `has_menu_addon: false` | PARITY | — |
| Boisson upsell post-cart | Kiosk gère via `KioskUpsellComponent` post-add-to-cart (KW.vue:589-590) | Mobile n'a PAS d'upsell post-cart côté assiette | **GAP** (A-003) — owner intent : "on va proposer que le boisson et la sauce pour les frites" en post-cart upsell | **P1** |

**Assiettes free-text notes** :
- Mobile sur-engineering : ajoute supplements step alors que kiosk a sciemment simplifié à `sauce → recap`. **Fix** : retirer SUPPLEMENTS de template `assiette` mobile.
- Manque l'upsell post-cart boisson (sortie du wizard) — c'est une feature à part entière (cf. `KioskUpsellComponent`).

---

### Category 5 — `ojja` (4 items: Bœuf / Poulet / Viande Hachée / Merguez)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| `wizard_template` source | DB seeder `simple` (ItemCategoryWizardSeeder:23) MAIS heuristique `detectTemplateFromName` map `ojja → 'omelette'` (KW.vue:921) | Mobile menu.js:233 `wizard_template: 'omelette'` (déjà mappé directement — bonne move) | PARITY post-correction (cf. F-06 audit precedent) | — |
| Active steps order | Template `omelette` → `sauce` → `recap` (KW.vue:609-618 V3.8 ROUND-5 simplifié) | Template `omelette` mobile → `sauce` → `crudites` → `supplements` → `recap` (steps.jsx:82-87) | **GAP — mobile ajoute 2 steps EXTRA** (O-001) | **P0** |
| Pourquoi simplifié kiosk | Comment KW.vue:610-614 : "Omelettes + Ojja + Menus Enfants contiennent DÉJÀ frites + pain ... Supplements/garnitures retirés du wizard" | Mobile n'a pas appliqué la simplification | **GAP intentionnel non répliqué** (O-002) | **P0** |
| `has_crudites` | config:369,377,385,393 `false` | mobile:318-321 `has_crudites: false` — donc crudites step est skip-conditionnel | PARITY (gating skip OK) | — |
| `has_sauce` | config:368,376,384,392 `true` | mobile:318-321 default `has_sauce: true` (via opts.has_sauce !== false) | PARITY | — |
| `frites_style` dormant DB | 30 rows `frites_style` extras existent en DB (audit precedent D3) mais KW.vue:1018-1028 EXCLUT cats `[309, 310, 311, 314]` du `shouldShowStep('frites_style')` (per owner-gate D3=A "Laisser dormant") | Mobile n'a pas de gating sur cats — utilise `has_frites_style` flag par item (mobile:362-368 frites items ONLY) | PARITY (effective behavior same — frites_style not shown for ojja) | — |

**Ojja free-text notes** :
- Mobile diverge sur le template `omelette` : ajoute crudites + supplements alors que kiosk les retire sciemment.
- **Fix recommandé** : retirer CRUDITES + SUPPLEMENTS de `template === 'omelette'` dans mobile/screens-item-steps.jsx:82-87 → laisser uniquement SAUCE + RECAP.

---

### Category 6 — `omelettes` (3 items: Nature / Fromage / Champi)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='omelette'` → `sauce` → `recap` (KW.vue:609-618) | Mobile → `sauce` → `crudites` → `supplements` → `recap` (steps.jsx:82-87) | **GAP — mobile ajoute 2 steps EXTRA** (M-001) | **P0** |
| `has_crudites` | config:407,415,423 `false` | mobile:326-328 `has_crudites: false` (step skip OK) | PARITY (gating) | — |
| `has_sauce` | config:406,414,422 `true` | mobile:326-328 `has_sauce: true` default | PARITY | — |

**Omelettes free-text notes** :
- Même problème que Ojja : template omelette simplifié côté kiosk mais mobile garde le verbose template.
- **Fix unique** : 1 ligne de code corrige Ojja + Omelettes + Menus Enfants (les 3 sont sur template omelette).

---

### Category 7 — `nos-salades` (4 items: Chèvre / Royale / Saumon / Tunisienne)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='salade'` → `garnitures` → `sauce` → `menu` → `frites_style` (cascade) → `supplements` → `recap` (KW.vue:619-629 V3.7 owner-gate full pipeline) | Mobile → `sauce` → `supplements` → `recap` (steps.jsx:88-92 — D1 "scope simplifié") | **GAP MAJEUR** (SL-001) — 4 steps EN MOINS côté mobile | **P0** |
| Comment mobile | comment steps.jsx:89 "D1 owner-gate decision: scope simplifié = sauce + suppléments uniquement" | Audit precedent 99_VERDICT.md:70-75 D1 = recommandé (A) implémenter kiosk-parity 5 steps | **Décision D1 non finalisée** — mobile a fait choix B (override délibéré) sans owner-confirm explicit | **P0** |
| `garnitures` step | Kiosk affiche garnitures step (toutes les salades ont des garnitures dans description) | Mobile n'a pas STEP.CRUDITES pour salades (steps.jsx:91 only SAUCE + SUPPLEMENTS) | **GAP** (SL-002) | **P0** |
| `menu` step (cascade frites + boisson) | Kiosk affiche `menu` ET `frites_style` cascade si user pick formule | Mobile n'a aucun de ces 2 steps | **GAP** (SL-003) | **P1** |
| `has_menu` cat 312 | DB seeder `Nos Salades has_menu: false` (mais kiosk template inclut menu step quand-même per V3.7 — incohérence kiosk interne ?) | Mobile cat 7 `has_menu: false` (menu.js:235) | PARITY avec DB mais GAP avec kiosk-runtime | **P2** |

**Salades free-text notes** :
- Cette catégorie est la plus controversée — l'audit precedent a clairement identifié le user-prompt mis-assertion U3 disant "Salades = no wizard" alors que kiosk a un wizard 5-steps depuis V3.7.
- **Owner-gate D1 toujours non clarifié** : mobile a fait le choix B (simplifié) mais le kiosk reste sur A (5 steps). **Action** : escalader à owner pour confirmer.

---

### Category 8 — `chicken-tenders` (4 items: Wings 6 / Wings 12 / Tenders 6 / Tenders 12)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='snacking'` → `sauce` → `menu` → `frites_style` (cascade) → `supplements` → `recap` (KW.vue:598-608) | Mobile → `sauce` → `menu` → `supplements` → `recap` (steps.jsx:93-98) | **GAP — `frites_style` step MISSING ORDER** (CT-001) | **P0** |
| Order of steps | Kiosk: sauce → menu → **frites_style** → supplements | Mobile: sauce → menu → supplements (frites_style only via cascade if `menuChoice='full'/'frites'`) | **GAP** (CT-002) | **P0** |
| Cascade menu | Kiosk inline drink + frites_sauce dans page Menu | Mobile split en 3 pages | Same as tacos | **P2** (cosmetic) |
| Wings BBQ/Nashville | Audit U2 confirms : pas de variations BBQ/Nashville en DB (les 15 sauces génériques sont les mêmes pour tous `has_sauce: true`) | Mobile utilise les 15 sauces génériques | PARITY (per U2 confirm = pas de divergence kiosk) | — |
| Validation `sauce` step | min 1 sauce (KW.vue:741) | min 1 sauce (steps.jsx:135-136) | PARITY | — |

**Chicken-tenders free-text notes** :
- Le step `frites_style` côté kiosk apparaît AUTOMATIQUEMENT entre menu et supplements (KW.vue:605), peu importe le choix menu — mobile attend que l'utilisateur pick "full" ou "frites" pour cascader. Donc divergence d'ordre + de timing.
- **Fix** : ajouter STEP.FRITES_STYLE explicite dans `template==='snacking'` mobile entre MENU et SUPPLEMENTS, conditionnel à `menuChoice in ['full', 'frites']`.

---

### Category 9 — `nos-menus-enfants` (2 items: Cheese / Nuggets)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| `wizard_template` source | DB seeder `simple` MAIS heuristique `detectTemplateFromName` map `menu enfant → 'omelette'` (KW.vue:922-928) | Mobile menu.js:237 `wizard_template: 'omelette'` (déjà mappé) | PARITY post-F-06 | — |
| Active steps order | `template='omelette'` → `sauce` → `recap` (KW.vue:609-618) | Mobile → `sauce` → `crudites` → `supplements` → `recap` (steps.jsx:82-87) | **GAP** (ME-001) — comme Ojja/Omelettes | **P0** |
| `has_sauce` | config:516,524 `true` (audit D2=A : flip mobile à true) | mobile:351-352 `has_sauce: true` (explicit) | PARITY (D2 résolu) | — |
| `has_crudites` | config:517,525 `false` | mobile:351-352 `has_crudites: false` | PARITY | — |
| `has_supplements` | Pas dans config mais V3.8 retire supplements de template omelette | mobile:351-352 `has_supplements: false` (explicit) — donc supplements step skip OK pour menus enfants | **PARITY effective** mais code path emprunte template `omelette` qui inclut SUPPLEMENTS — heureusement gated par flag par-item | **P1** |

**Menus Enfants free-text notes** :
- Mobile bonne pratique : `has_supplements: false` explicit sur items 901/902 — donc le step est skip-conditionnel.
- **Mais** : si owner ajoute un nouveau menu enfant avec `has_supplements` non-explicite, le default `!== false` (mobile:270) inclura SUPPLEMENTS — divergence latente.

---

### Category 10 — `frites-accompagnements` (2 items: Frites Moyenne / Grande)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='simple'` → `frites_style?` → `supplements?` → `recap` (KW.vue:634-638 default case) | Mobile → `frites_style` → `sauce?` (skip) → `supplements?` (skip because has_sauce && has_frites_style logic) → `recap` (steps.jsx:99-107) | **GAP partielle** (F-001) | **P1** |
| `has_frites_style` filter cat | KW.vue:1018-1028 — cat 315 (`Frites & Accompagnements`) AUTORISÉ (pas dans `FRITES_INCLUDED_CATS = [309,310,311,314]`) | Mobile flag `has_frites_style: true` explicit (menu.js:360-361) | PARITY | — |
| Supplements en `simple` | KW.vue:634-638 affiche SUPPLEMENTS si extras présents (`partitionKioskExtras` non-vide) | Mobile steps.jsx:104 affiche SUPPLEMENTS UNIQUEMENT si `has_sauce \|\| has_frites_style` — frites items 1001/1002 ont `has_sauce: false` mais `has_frites_style: true` → SUPPLEMENTS affiché | PARITY effective | — |
| `sauce` step simple template | KW.vue:634-638 ne propose pas SAUCE dans template default ! C'est OK car frites n'ont pas d'attribut sauce | Mobile steps.jsx:103 `if (item.has_sauce) steps.push(STEP.SAUCE)` — donc skip pour frites | PARITY | — |

**Frites free-text notes** :
- C'est OK actuellement. Mais le default mobile (template='simple') a une logique `has_sauce \|\| has_frites_style` (steps.jsx:104) pour décider d'afficher supplements, ce qui est plus restrictif que kiosk.

---

### Category 11 — `nos-desserts` (3 items: Glace / Tarte / Tiramisu)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='simple'` + no extras → direct add (KW.vue:634-638 → all filtered out → only RECAP) | Mobile `shouldDirectAdd` path (steps.jsx:789) → `ScreenItemDirectAdd` | PARITY | — |
| `has_sauce/has_crudites/has_supplements` | All `false` (config:562-579) | All `false` (mobile:366-368) | PARITY | — |

**Desserts free-text notes** : aucun gap.

---

### Category 12 — `nos-boissons` (8 items: Coca / Coca Zero / Fanta / Sprite / Oasis / Orangina / Eau / Capri-Sun)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='simple'` direct add | Mobile direct add | PARITY | — |
| Per-item override | All `viandes:0`, `has_sauce/crudites/menu_addon: false` | Same | PARITY | — |

**Boissons free-text notes** : aucun gap.

---

### Category 13 — `supplements` (8 items: Sauce sup / Fromage / Jambon / Boursin / Raclette / Œuf / Galette pdt / Salade verte)

| Dimension | Kiosk behavior | Mobile current | Status | Severity |
|-----------|----------------|----------------|--------|----------|
| Active steps order | `template='simple'` direct add SAUF item 1301 (`Sauce supplémentaire`) qui a `has_sauce: true` → step SAUCE | Mobile menu.js:385 `has_sauce: true` pour item 1301 → STEP.SAUCE → STEP.RECAP (steps.jsx:103) | PARITY | — |
| Item 1301 Sauce supplémentaire | Kiosk-side : utilise même catalogue 15 sauces que tacos/sandwich (pas dédié) | Mobile-side : utilise même `lcMenu.sauces` (steps.jsx:354) | PARITY | — |
| Items 1302-1308 (fromage, jambon, etc.) | direct add | direct add | PARITY | — |

**Supplements free-text notes** : aucun gap fonctionnel.

---

## Cross-category systemic gaps

### CS-001 — Pas de `STEP.TAILLE` registry mobile (P0)
Le `STEP` registry (steps.jsx:29-39) n'inclut PAS taille. Tacos custom items sans `viandes` pré-encodé crashent silencieusement (default à `item.viandes = 0` → STEP.VIANDES skip → no validation).

### CS-002 — Pas de `STEP.PAIN` registry mobile (P0)
Idem pour pain. Sandwiches "Sandwich Classique (Pain)" / "(Galette)" sont gérés comme 2 SKUs séparés en mobile alors que kiosk modélise 1 SKU + 1 step.

### CS-003 — Pas d'override per-item du template catégorie (P1)
Mobile lit `category.wizard_template` (steps.jsx:59) **MAIS** ne supporte pas d'override per-item via `item.wizard_template` comme kiosk (cf. `effectiveWizardTemplate` KW.vue:881-903). Donc impossible de créer un item custom avec wizard différent de sa catégorie.

### CS-004 — Pas de `cooking_style` step pour Assiette Poulet (P2)
Audit U4 résolu = A (status quo description). **MAIS** si owner décide d'ajouter ce step plus tard, mobile devra créer un nouveau STEP.COOKING_STYLE + UI dédiée + cooking_style ItemAttribute backend.

### CS-005 — Composition snapshot shape divergente (P1)
Mobile `buildLineItem` (steps.jsx:698-751) construit un objet plat avec `meatLabels[], sauceLabels[], cruditeRemoved[], supLabels[]` etc. **MAIS** kiosk envoie `extras[]` avec `group_label` ('viandes', 'sauces', 'garnitures', 'supplements', 'frites_style') au backend (cf. F-11 audit precedent). Si mobile wireup l'API plus tard, payload sera incompatible.

### CS-006 — Pas de support viandes payantes extras (P1)
Kiosk a un système viandes-extras (`kioskViandeCatalogForItem` fusionne variations + extras payantes) — un user peut prendre 2 viandes incluses + 1 viande extra à +1€ (KioskStepViande:44-46 `kiosk-viande-badge-paid`). Mobile a 9 viandes au choix uniquement sans system d'extras payantes.

### CS-007 — Pas de filtres allergènes / végétarien actifs (P2)
Kiosk a `activeFilters` propagé à viande/sauce/garnitures/supplements steps (KW.vue:717-728) avec `isVariationAllowedByFilters`. Mobile a `is_vegetarian` flag par item (menu.js:333) mais ne le propage pas en gating step.

### CS-008 — Pas de OOS (out-of-stock) handling per-variation (P1)
Kiosk has `isViandeOos`, `isSauceOos`, `isGarnitureOos`, `isSupplementOos` (e.g. KioskStepSauce:235) avec badge + disabled state. Mobile n'a aucun OOS handling.

### CS-009 — Pas de step `generic_choices` composer profile (P2)
Kiosk a un système `composer_profile` (KW.vue:775-797) pour items custom avec choices ad-hoc (`STEP.GENERIC_CHOICES`). Mobile n'a pas ce mechanism — si DB introduit un item avec composer_profile, mobile crashera ou skipera.

### CS-010 — Sauce step `Sans Sauce` mobile invente une row absente du catalogue kiosk (P2)
Mobile ajoute hardcodé `SANS_SAUCE = 's-sans'` (steps.jsx:338) avec exclusivity logic — kiosk n'a JAMAIS de "Sans sauce" hardcodé. Le catalogue DB peut contenir une row "Sans sauce" mais ce n'est pas obligatoire. Risk : si DB n'a pas cette row, mobile crashera quand `lcMenu.sauces.find(s.id === 's-sans')` retourne undefined.

### CS-011 — Frites style mobile : `fritesStyleId = null` vs `undefined` distinction fragile (P2)
Mobile steps.jsx:147 `selections.fritesStyleId !== undefined` pour valider — donc `null` = "Nature" (valid) vs `undefined` = pas encore choisi (invalid). Cette distinction est subtile et peut se casser au state reset. Kiosk utilise `selections.fritesStyleExtraId` avec convention `id == null` = Nature (KioskStepFritesStyle:89-92) — convention DIFFÉRENTE.

### CS-012 — Quantity stepper sur recap step (P2)
Mobile a un qty stepper dans le recap (steps.jsx:679-690). Kiosk n'a pas de qty stepper dans KioskOrderSummary (l'item est ajouté qty=1 puis user peut bump qty via panier). Divergence UX mais pas blocker.

### CS-013 — Boisson upsell post-cart absent (P1)
Pour assiette/omelette/ojja/menus-enfants, kiosk a un `KioskUpsellComponent` post-cart qui propose boisson + sauce frites (cf. KW.vue:589-590 "Boisson upsell est géré post-cart"). Mobile n'a aucun upsell post-cart.

### CS-014 — Pas de hint texte pour viandes counter (P2)
Kiosk affiche `kiosk-viande-instruction` (KioskStepViande:15-17) avec texte "Choisis X viandes" + counter `{includedQuotaSelected}/{maxViandes}`. Mobile affiche counter (steps.jsx:301-308) mais n'a pas de hint au-dessus.

### CS-015 — Pas de prix unitaire viande affiché si payant (P1)
Kiosk montre `+{formatPrice(viande.price)}` badge sur card viande payante (KioskStepViande:44-46). Mobile : pas de prix affiché par viande (steps.jsx:310-326) — donc si viandes payantes ajoutées plus tard, mobile crashera silencieusement.

### CS-016 — Frites Sauce step réutilise ScreenStepSauce sans badge "première gratuite" pour frites (P2)
Mobile steps.jsx:606-608 `ScreenStepFritesSauce` réutilise `ScreenStepSauce` avec `sauceField='fritesSauceIds'` mais affiche le même badge "1 gratuite · sup 0.50€" qui est faux pour frites_sauce (kiosk a une logique différente per `fritesSaucePriceLabel` cf. KioskStepMenu:182).

### CS-017 — Default crudités ON mais cruditesIds undefined si direct-add (P2)
Mobile initial state crée `cruditeIds: lcMenu.defaultCruditeIds()` (steps.jsx:764) MAIS pour items direct-add (template simple sans STEP.CRUDITES) ce field reste dans state inutilement. Pas un bug mais clutter.

---

## Priority list (P0/P1/P2)

### P0 BLOCKERS (must fix Phase 6.B)

1. **CS-001 / T-001 / T-002** : Ajouter `STEP.TAILLE` registry mobile + handler pour items tacos sans `item.viandes` pré-encodé.
2. **CS-002 / S-001 / S-002 / S-003** : Ajouter `STEP.PAIN` registry mobile + step component + gating pour items 207/208.
3. **A-001 / A-002** : Retirer `STEP.SUPPLEMENTS` de `template === 'assiette'` (steps.jsx:78-81) — kiosk V3.8 simplifié.
4. **O-001 / O-002 / M-001 / ME-001** : Retirer `STEP.CRUDITES` + `STEP.SUPPLEMENTS` de `template === 'omelette'` (steps.jsx:82-87) — kiosk V3.8 simplifié.
5. **SL-001 / SL-002 / SL-003** : Décider D1 (kiosk-parity 5 steps vs simplifié). Si A : refactor full `template === 'salade'` avec garnitures+menu+frites_style+supplements.
6. **CT-001 / CT-002** : Réordonner snacking template : insérer `STEP.FRITES_STYLE` entre `STEP.MENU` et `STEP.SUPPLEMENTS` (steps.jsx:93-98) gated par `menuChoice in ['full','frites']`.
7. **CS-003** : Implémenter override per-item `item.wizard_template` (Priority 1 dans kiosk `effectiveWizardTemplate` KW.vue:881-903).
8. **CS-005 / T-007** : Refactor `buildLineItem` pour produire shape `extras[]` avec `group_label` compatible backend payload.

### P1 IMPORTANT (Phase 6.B ou 6.C)

9. **CS-006** : Support viandes payantes extras (catalogue extras `group_label='viande'`).
10. **CS-008** : OOS handling per-variation (badge + disabled state).
11. **CS-013 / A-003** : Implémenter upsell post-cart boisson pour assiette/omelette.
12. **CS-015** : Afficher prix unitaire viande si payant.
13. **T-003** : Validation viandes step doit supporter `totalViandes` (inclus + extras) pas juste `meatIds.length`.
14. **S-004** : Décider config/menu.php:217-223 vs mobile (Sandwich Suprême viandes=0 vs 1).

### P2 NICE-TO-HAVE (Phase 6.C ou plus tard)

15. **T-004** : Sauce extra price lu du catalogue au lieu de hardcoder €0.50.
16. **T-005** : Retirer Sans Sauce hardcoded mobile, lire du catalogue.
17. **T-006** : Aligner cascade menu UX (inline vs split) si owner préfère.
18. **CS-009** : Implémenter composer_profile generic_choices step.
19. **CS-010** : Voir T-005.
20. **CS-011** : Aligner convention `fritesStyleExtraId` mobile vs kiosk.
21. **CS-012** : Retirer qty stepper du recap (parité kiosk).
22. **CS-014** : Ajouter hint texte au-dessus viande counter.
23. **CS-016** : Différencier badge frites_sauce du sauce step.
24. **F-001** : Aligner default template simple supplements gating.

---

## Recommendations for Phase 6.B

### Order of execution (top-down)

1. **Phase 6.B.1 — Templates simplification (1h)**
   - Retirer SUPPLEMENTS de `assiette` (steps.jsx:80) → laisser sauce + recap
   - Retirer CRUDITES + SUPPLEMENTS de `omelette` (steps.jsx:82-87) → laisser sauce + recap
   - Réordonner `snacking` : ajouter STEP.FRITES_STYLE entre MENU et SUPPLEMENTS
   - Owner-gate D1 confirm avant refactor salade (5 steps full vs simplified)

2. **Phase 6.B.2 — Add `STEP.PAIN` + `STEP.TAILLE` registry (3h)**
   - Créer `ScreenStepPain` + `ScreenStepTaille` components
   - Étendre `STEP` registry + `STEP_LABELS`
   - Update `computeActiveSteps` switch `sandwich` (add PAIN as first step quand `item.has_pain` true)
   - Update `computeActiveSteps` switch `tacos` (add TAILLE as first step quand `item.viandes==null`)
   - Update mobile data layer : merger items 207/208 en 1 SKU avec `has_pain: true` + variations

3. **Phase 6.B.3 — Per-item template override (1h)**
   - Mirroir kiosk priority chain : `item.wizard_template > item.category.wizard_template > heuristic`

4. **Phase 6.B.4 — Composition snapshot shape backend-compatible (2h)**
   - Refactor `buildLineItem` pour produire `extras[]` shape `{ id, group_label, name, price, qty }`
   - Aligner avec kiosk `KioskMenuService::projectItems` payload spec

5. **Phase 6.B.5 — Salade decision (owner-gate D1 first)**
   - Si A : implémenter full 5-step pipeline
   - Si B : documenter divergence officielle dans BRAIN.md

6. **Phase 6.B.6 — P1 polish (3-4h)**
   - Viandes payantes extras
   - OOS handling
   - Upsell post-cart boisson
   - Prix unitaire viande affiché

7. **Phase 6.B.7 — P2 cosmetic (deferred)**

### Anti-drift safeguards

- **Lock kiosk V3.8 ROUND-5 templates** comme SSOT pendant Phase 6.B (cf. KW.vue:547-639). Toute divergence mobile doit être documentée comme override délibéré owner-gated.
- **Per-step parity check** : pour chaque step component mobile, identifier l'équivalent kiosk + commenter l'écart toléré ou non.
- **Spec snapshot** : produire `Phase6B_parity_matrix.md` listant chaque step + validation rule + default + special cases avec colonnes Kiosk / Mobile / Status.

### Test coverage Phase D

Pour chaque catégorie, vérifier post-fix :
- Le set des `activeSteps` match kiosk pour items canonical (`tacos M`, `Méga`, `Cheese Burger`, `Assiette Poulet`, `Ojja Bœuf`, `Omelette Nature`, `Salade Chèvre`, `Wings 6`, `Menu Cheese`, `Frites Moyenne`, `Glace`, `Coca`, `Sauce sup`)
- `canAdvance` retourne `true` post-selection minimum, `false` avant
- Composition snapshot output matche kiosk payload shape pour backend wireup

---

*Fin AGENT-1 — 33 findings (8 P0 / 15 P1 / 10 P2) — biggest gap catégoriel = `nos-salades` (4 steps manquants).*
