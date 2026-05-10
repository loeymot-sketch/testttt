# 99 — VERDICT — Synthèse cross-agents + plan d'action

Date : 2026-05-10
Branche : `feature/mobile-app-le-cayenne-2026-05-10` · HEAD `1ba7aaf59`
Charter : Audit ultra-rigoureux mobile app vs kiosk Le Cayenne, 6 sub-agents YC GStack en parallèle, RED TEAM cross-validation.

---

## Executive summary

**Mode** : 6 sub-agents read-only (Architect / DBA / UX / Tester / A11y / Adversarial) + orchestrateur synthèse.
**Sources lues** : 13 525 lignes au total (kiosk Vue frozen, mobile JSX, config SSOT, models, seeders, migrations 2026_05_10_040000-070000, tinker live DB).
**Verdict adversarial** : 15 contestations cross-validées → 13 SURVIVES, 1 FAILS, 1 NEEDS-RECONCILE.
**User-prompt mis-assertions** : 3 sur 4 claims du prompt orchestrateur invalidées par l'évidence DB+kiosk.

**Recommandation finale orchestrateur** :
- ✅ **Audit livré 100%** — 6 rapports + 02_dba_tinker.txt + 99_VERDICT + 00_INDEX (8 fichiers).
- 🚫 **Refactor wizard mobile BLOQUÉ jusqu'à owner-gate** sur 9 décisions (D1-D6 + U2/U3/U4).
- Per CLAUDE.md §10 escalation rule + le prompt user explicite : "Si AGENT-ADVERSARIAL trouve un blocker que tu ne peux pas résoudre seul, ESCALATE à l'owner avec analyse claire de la cause + 2 options de remédiation."

---

## 1. Findings consolidés (post-reconciliation adversarial)

### 1.1 P0 — blockers (refactor commit interdit sans résolution)

| ID | Source | Finding | Statut | Action |
|----|--------|---------|--------|--------|
| F-01 | DBA F-DBA-1 / Adv C-01 | Mobile cat IDs `1..13` ≠ DB `306..318` | **Confirmé** | Phase 6 data alignment : remap IDs |
| F-02 | DBA F-DBA-2 / Adv C-02 | Cat 9 Menus Enfants `has_sauce: false` (mobile) ≠ `true` (DB+config+V3.8 omelette template) | **Confirmé** | Décision owner D2 ⬇️ |
| F-03 | DBA F-DBA-3 / UX W5 / Adv C-03 / C-06 | `frites_style` step manquant côté mobile ; existe en DB sur 19 items via migrations 040000+050000 | **Confirmé avec nuance V3.8** | Wire frites_style sur cats 312/313/315 SEULEMENT (PAS 310/311 — dormant V3.8) |
| F-04 | UX P0 W1-W4 | Pas de state machine multi-page : single-scroll, pas de prev/next, pas de validation par-step, pas de Recap step | **Confirmé** | Refactor multi-page (deliverable B) |
| F-05 | A11y A1-A4 / Adv C-13 | Interactive divs sans keyboard support · IconBtn sans accessible name · 0 `:focus` styles · contrast orange/orange-soft 2.49:1 (FAIL AA) | **Confirmé** (4/4 spot-checked) | A11y baseline obligatoire dans refactor |
| F-06 | Architect Gap #12 / Adv C-09 | `wizard_template` per category exposé en data layer mobile mais jamais lu par ScreenItem | **Confirmé** + **2 mismatches additionnels** | Cat 5 Ojja `simple`→`omelette` ; Cat 9 Menus Enfants `simple`→`omelette` |
| F-07 | Adv C-12 / Tester Q3 | **User prompt invalide** : "Salades = no wizard" — le kiosk a un wizard 5-steps salade | **User mis-assertion U3** | Décision owner D1 ⬇️ |

### 1.2 P1 — important, fixable post-V0

| ID | Source | Finding | Action |
|----|--------|---------|--------|
| F-08 | DBA F-DBA-4 / Adv C-04 | Cheddar fondu dupliqué sur items 402/403 — overcharge possible jusqu'à +2,50€ | Décision owner D4 ⬇️ |
| F-09 | DBA F-DBA-4 (autre) | `addon.role` NULL sur 180 rows — kiosk discrimine via name-match texte (fragile) | Décision owner D6 ⬇️ |
| F-10 | A11y A5-A11 | aria-disabled CTA + aria-live counter + Sans-Sauce exclusivity + TabBar + touch <44px + gray-3 contrast + HALAL pill | Refactor A11y spec |
| F-11 | Tester pricing | Mobile `priceFor()` correct mais cart line shape ≠ DB `item_extras` shape | Phase 6 wireup : encoder extras[] avec group_label |

### 1.3 P2 — nice-to-have

| ID | Source | Finding | Action |
|----|--------|---------|--------|
| F-12 | UX P2 | Bottom-sheet composition preview, swipe gestures | Optionnel V1.1 |
| F-13 | A11y P2 | env() safe-area, prefers-reduced-motion, Dynamic Type | V1.1 |
| F-14 | DBA F-DBA-6 | Cat 318 Suppléments items ont eux-mêmes 6 supplement extras (self-supplementation) | Cleanup migration future |
| F-15 | Adv C-10 | Architect omelette-template wording prête à confusion | Documenter dans refactor blueprint |

### 1.4 P3 — informational / corrigés par adversarial

| ID | Source | Finding | Statut |
|----|--------|---------|--------|
| F-16 | DBA F-DBA-7 | "has_menu drift Ojja" — **FAILS** | Pas un drift, revert intentionnel migration 060000 V3.8 |
| F-17 | DBA aside | Attribute id 317 `E2E_PLAYWRIGHT_ATTRIBUTE_TOGGLE` pollue prod schema | Suggestion cleanup |
| F-18 | Adv | 404 `/.image-slots.state.json` sur home capture | Bruit debug, ignorer |

---

## 2. Owner-gated decisions (BLOQUANT pour refactor)

Per le prompt orchestrateur §F : "Si AGENT-ADVERSARIAL trouve un blocker que tu ne peux pas résoudre seul, ESCALATE à l'owner avec analyse claire de la cause + 2 options de remédiation."

### D1 — Salades wizard scope (CONTEST-12 + U3)
**Contexte** : User prompt dit "Salades : pas de wizard, ajout direct". DB+kiosk : wizard 5-steps `garnitures → sauce → menu → frites_style → supplements → recap`.
**Évidence** : `KW.vue:602-612`, tinker `02_dba_tinker.txt:219-243`.
**Options** :
- **(A)** Implémenter le wizard kiosk-parity 5-steps (cohérence cross-surface, conforme V3.8) — **recommandé**
- **(B)** Override mobile pour skip wizard (simplicité UX, divergence kiosk) — incompatible cross-surface
**Question owner** : confirme (A) ou override délibéré (B) ?

### D2 — Menus Enfants `has_sauce` (CONTEST-02)
**Contexte** : Mobile `has_sauce: false` ≠ DB `true` (sauce attr 311 attached) ≠ V3.8 omelette template (incluant sauce step).
**Évidence** : `mobile/data/menu.js:216-217` ; `config/menu.php:516,524` ; tinker line 20 ; KW.vue:590-601.
**Options** :
- **(A)** Flip mobile à `has_sauce: true`, offrir 15 sauces génériques — **recommandé** (DB seed = SSOT)
- **(B)** Migration backend retirant attribut sauce sur items 400/401 (cohérent avec mental model "menu enfant pré-fixe")
**Question owner** : (A) align mobile sur DB ou (B) "menus enfants pré-fixes" est l'intent vrai ?

### D3 — Ojja/Omelettes frites_style dormant (CONTEST-03 + CONTEST-05)
**Contexte** : 30 rows `frites_style` extras existent en DB sur cats 310 (Ojja) et 311 (Omelettes). Migration 060000 V3.8 walked back has_menu et exclut ces extras du wizard — frites déjà incluses dans le prix.
**Options** :
- **(A)** Laisser dormant (DB rows present mais non-rendus) — **recommandé** (cheap, réversible)
- **(B)** Migration cleanup deletant 30 rows — DB propre
- **(C)** Réactiver (walk-back V3.8) — owner reconsiderate intent
**Question owner** : (A), (B), ou (C) ?

### D4 — Cheddar fondu duplicate items 402/403 (CONTEST-04)
**Contexte** : Items 402 (Frites Moyenne) et 403 (Frites Grande) ont DEUX extras "Cheddar fondu" : (1) ungrouped legacy 1€/1.50€, (2) frites_style 1€. Si POS expose les deux, customer overcharge possible jusqu'à +2,50€.
**Évidence** : tinker `02_dba_tinker.txt:295,300`.
**Options** :
- **(A)** Migration deletant rows ungrouped legacy — **recommandé** (DB propre)
- **(B)** Filter projection dans `KioskMenuService::projectItems` (cosmétique)
**Question owner** : (A) ou (B) ? Vérifier que POS Vanilla pos-wizard.js n'a pas de référence hardcodée à la legacy row.

### D5 — Mobile cat IDs alignment timing (CONTEST-01 + F-01)
**Contexte** : Mobile uses 1..13, DB uses 306..318. Hard requirement quand wireup API.
**Options** :
- **(A)** Remap mobile IDs à 306..318 dans Phase 6 data alignment — **recommandé**
- **(B)** Keep 1..13 V0 standalone, remap dans Phase 6 wireup (Supabase ou backend FoodKing)
**Question owner** : faire (A) maintenant (data integrity early) ou (B) reporté à wireup ?

### D6 — `addon.role` NULL backfill (DBA F-DBA-4)
**Contexte** : 180 rows `item_addons` ont `role` NULL en DB. Kiosk discrimine `'menu_component' / 'drink' / 'side'` via name-match texte sur `addon_item_name` ("Menu (Frites + Boisson)" / "Frites Seules" / "Boisson Seule"). Fragile : tout rename casse le wizard.
**Options** :
- **(A)** Migration backfill `addon.role` selon name pattern — **recommandé**
- **(B)** Keep text-match (status quo)
**Question owner** : (A) durcir ou (B) accepter le tech-debt ? (Backend hors scope mobile, mais à signaler équipe backend.)

### U2 — Wings: BBQ/Nashville sauce variations (CONTEST-15)
**Contexte** : User prompt asserte des sauces wings-specific BBQ/Nashville. **Aucun row Nashville en DB ni dans config/menu.php ni dans mobile/data/menu.js.** Barbecue existe mais comme sauce générique partagée (39 items).
**Question owner** : confirmer que le prompt s'est trompé (wings = 15 sauces génériques comme tous les `has_sauce` items) ? Ou owner veut implémenter BBQ/Nashville-specific (nouvelle migration backend nécessaire — hors scope refactor mobile) ?

### U4 — Assiette Poulet "style cuisson" (Nature/Curry/Paprika)
**Contexte** : Description text uniquement (`config/menu.php:328`). Aucun `ItemAttribute` ni `ItemVariation`.
**Options** :
- **(A)** Garder en description (status quo) — **recommandé** sauf si owner veut le promouvoir en step
- **(B)** Promouvoir en step wizard : nouvelle migration backend `cooking_style` ItemAttribute avec 3 variations — hors scope refactor mobile
**Question owner** : (A) ou (B) (nouvelle feature, cycle séparé) ?

### U3 — Salades wizard
Voir D1.

---

## 3. Plan d'action priorisé (post-owner-gate)

### Phase A — Refactor wizard multi-page (deliverable B)
Pré-requis : owner-gate D1, D2, U2, U4 résolus.
**Livrables** :
- `mobile/screens-item-steps.jsx` avec 8 ScreenStep* (Viandes / Sauce / Crudités / Suppléments / Menu / FritesStyle / Drink / Recap)
- `ScreenItem` rewrite : machine d'états, `activeSteps` dynamique selon `wizard_template + has_*` flags, prev/next, dots, sticky CTA, composition live preview
- Per-step validation gating CTA Suivant
- A11y baseline : role/tabindex/onKeyDown sur tous les interactifs, focus management transitions, aria-live counter, aria-disabled CTA
**Effort** : ~6-8h
**Tests** : Phase D test-e2e (deliverable E)

### Phase B — Alignement data 1:1 backend (deliverable C + D)
**Livrables** :
- Cat IDs 1..13 → 306..318 (D5 décision)
- `wizard_template` mobile alignée DB (Cat 5 Ojja `simple`→`omelette` ; Cat 9 Menus Enfants `simple`→`omelette`)
- `has_sauce` Menus Enfants flip si D2=A
- Salades wizard data si D1=A
- Frites items 1001/1002 : ajout `has_frites_style: true` + extras `frites_style` cataloged
- Wings items 801-804 : pas de changement (15 sauces génériques) si U2=confirme false
- Assiette Poulet : pas de changement (description text) si U4=A
- Formule menu cascade : `formuleId='f-menu'` déclenche steps Drink + FritesStyle + sauce frites
**Effort** : ~3-4h
**Tests** : Phase D

### Phase C — Tests E2E mobile vs kiosk (deliverable E)
**Livrables** :
- Spec Playwright `tests/e2e/mobile/wizard.spec.js` couvrant les 13 catégories
- Captures `reports/test-e2e/mobile-vs-kiosk-2026-05-10/captures/<cat>/<step>.png`
- Diff vs kiosk reference pour cats 4/5/6/8/12 (existing screenshots `tests/e2e/__screenshots__/test-e2e-borne-B/309|310|311|312|313-*.png`)
- Audit white-on-white (alpha-blending) sur tous PNGs
- Audit raw labels (Label.X / kiosk.X / 0undefined / NaN€) sur DOM bodies
- Verdict GO/NO-GO par catégorie dans `99_TEST_VERDICT.md`
**Effort** : ~3h

### Phase D — BRAIN.md + Graphiti (deliverable G)
- Update PROJECT_BRAIN.md §3 (livraison wizard multi-page mobile)
- Push épisode Graphiti `foodking` group : "Mobile wizard multi-page kiosk-aligned 2026-05-XX"

**Effort total** : ~12-15h post-owner-gate.

---

## 4. GO / NO-GO matrix par catégorie (post-refactor)

(Matrice à remplir après refactor + tests E2E. Aujourd'hui = AUDIT seulement.)

| Cat ID | Slug | Kiosk template | Steps attendus mobile | Statut audit | Refactor blocker |
|--------|------|----------------|------------------------|--------------|------------------|
| 1 / 306 | nos-tacos | tacos | viandes → sauce → garnitures → suppléments → menu (→drink/frites_style) → recap | ✅ data ready, refactor pending | F-04 |
| 2 / 307 | nos-sandwichs | sandwich | viandes (variable) → sauce → garnitures → suppléments → menu → recap | ✅ data ready | F-04 |
| 3 / 308 | nos-burgers | burger | sauce → garnitures → suppléments → menu → recap | ✅ data ready | F-04 |
| 4 / 309 | nos-assiettes | assiette | sauce → suppléments → recap (style cuisson = description, U4) | ✅ data ready | F-04 + U4 |
| 5 / 310 | ojja | **omelette** | sauce → garnitures → suppléments → recap (PAS de menu/frites_style V3.8) | ⚠️ wizard_template mobile=`simple` à corriger (F-06) | F-04 + F-06 |
| 6 / 311 | omelettes | omelette | sauce → garnitures → suppléments → recap | ✅ data ready | F-04 |
| 7 / 312 | nos-salades | **salade** | garnitures → sauce → menu → frites_style → suppléments → recap | ⚠️ user prompt = "no wizard" est FAUX, D1 needed | **D1 BLOCKER** |
| 8 / 313 | chicken-tenders | snacking | sauce → menu (→frites_style) → suppléments → recap (PAS BBQ/Nashville, U2) | ⚠️ U2 to confirm | **U2 confirm** |
| 9 / 314 | nos-menus-enfants | **omelette** | sauce → garnitures → suppléments → recap (D2) | ⚠️ has_sauce mismatch (F-02) | **D2 BLOCKER** |
| 10 / 315 | frites-accompagnements | simple | frites_style → recap (F-03 wire) | ⚠️ frites_style step manquant mobile | F-04 + F-03 |
| 11 / 316 | nos-desserts | simple | (direct add, pas de wizard) | ✅ ready | none |
| 12 / 317 | nos-boissons | simple | (direct add) | ✅ ready | none |
| 13 / 318 | supplements | simple | (sauce sup → direct add pour item 1301) | ✅ data ready | F-04 mineur |

---

## 5. Anti-drift / risk register

| Risque | Mitigation |
|--------|------------|
| Refactor commit basé sur user-prompt mis-assertions (D1/U2/U3/U4) → produit divergent du DB | **Owner-gate obligatoire** avant refactor (cf. §2) |
| Hardcoder mobile IDs 1..13 puis casser à wireup | D5 Phase B early remap |
| Wizard template Ojja/Menus Enfants = `simple` côté mobile → manque sauce/garnitures | F-06 fix dans Phase B data alignment |
| Frites_style dormant Ojja/Omelettes → tentation de les afficher | Documenter F-15 dans refactor blueprint, RESPECTER `shouldShowStep` filter |
| Cheddar fondu duplicate (D4) → POS overcharge | Migration cleanup en cycle séparé backend (hors scope mobile) |
| Mobile A11y 4 P0 (F-05) shippé sans baseline AT | Refactor MUST inclure role/tabindex/onKeyDown, focus management, contrast fixes |

---

## 6. Conditions de retour orchestrateur

Per CLAUDE.md §10 + prompt user §F :

✅ **AUDIT 100% LIVRÉ**
- 6 sub-agents YC GStack ont produit leurs rapports (1 659 lignes total)
- AGENT-ADVERSARIAL a clôturé 15 contestations cross-validées
- 99_VERDICT (ce fichier) + 00_INDEX rédigés
- Commit-1 reports/review/mobile-audit-2026-05-10/

🚫 **REFACTOR BLOQUÉ — owner-gate requis**
- 6 décisions D1-D6
- 3 user-prompt mis-assertions à clarifier (U2, U3, U4)

📋 **Action owner**
Trancher D1 (salades wizard A/B), D2 (menus enfants sauce A/B), D3 (Ojja frites dormant A/B/C), D4 (Cheddar fondu A/B), D5 (cat IDs A/B), D6 (addon.role A/B), U2 (wings BBQ/Nashville confirm/reject), U3 (= D1), U4 (assiette cooking style A/B).

Une fois owner-gate cleared → orchestrateur reprend Phase A (refactor multi-page) → Phase B (data alignment) → Phase C (tests E2E) → Phase D (BRAIN + Graphiti).

— *Verdict orchestrateur 2026-05-10 · escalation gate.*
