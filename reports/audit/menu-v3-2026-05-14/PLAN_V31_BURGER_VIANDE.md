# Menu V3.1 — Burger composer + viande confirmation 2026-05-14

## Owner spec (this turn — verbatim parsed)

1. **4 viandes principales** (Mariné/Curry/Tandoori/Crispy) restent pour : Sandwiches (Cayenne, Classique, Galette), Tacos, Bols
2. **EXCEPTION Burger** : 1 viande locked **Crispy** (toujours). Supplément optionnel "2ᵉ viande **+2.50€**" disponible dans étape Suppléments.
3. **EXCEPTION Menu enfant** : Nuggets only, pas de viande.
4. **Bowl drink step** : owner OK pattern menu-step standard (Menu/Frites/Drink-only/Sans menu) → P1-001 du round précédent **résolu, accept-as-is**.

## Burger wizard flow (owner spec)

> "Burger c'est vraiment très facile à gérer sauce et supplément, s'il veut au menu ou son menu, ça du menu, sans menu, bien frites seul ou bien boisson seul, comme toujours notre WIZARD."

Translation = 3 steps :
1. **Sauce** (1 choice from libre sauce list, attr 311)
2. **Suppléments** (multi-select 0..N, includes new "Double viande +2.50€" option)
3. **Menu** (addon menu_component : Menu Combo / +Frites / Drink-only / Sans menu — same as Big variants)

**No viande step** — Crispy est implicite/locked. Customer who wants 2 viandes coche "Double viande +2.50€" en supplément.

## Current state inspection (audit done)

| Item | Cat | Current composer | Action |
|------|-----|------------------|--------|
| 474 Sandwich Cayenne | 344 | none (fallback) | OK fallback shows 4 viandes |
| 488 Big Cayenne | 344 | profile 82 (5 steps) | OK |
| 475 Galette Normale | 345 | none (fallback) | OK fallback shows 4 viandes |
| 476 Galette Cayenne | 345 | none (fallback) | OK fallback shows 4 viandes |
| 477 Sandwich Classique | 346 | none (fallback) | OK fallback shows 4 viandes |
| 489 Big Classique | 346 | profile 83 (5 steps) | OK |
| 478 Tacos M | 306 | none (fallback) | OK tacos-template 1 viande |
| 479 Tacos L | 306 | profile 84 (3 steps V1+V2+menu) | OK 2 viandes |
| 492-499 Bowls | 347 | profiles 74-81 (3 steps) | OK |
| **375 Chicken Burger** | 349 | **none (fallback)** | **NEW** composer 3-step Sauce+Supp(+Double viande)+Menu |
| **490 Chicken Burger Special** | 349 | **none (fallback)** | **NEW** composer same as 375 (price diff = premium ingredients) |
| 491 Menu Nuggets | 350 | none | OK simple item, no wizard |

## Heal V3.1 deliverables

1. **`MenuHealLightV31BurgerCommand`** :
   - Create extra "Double viande" in burger supplement group (price 2.50€)
   - Create composer profile for 375 : Sauce / Suppléments / Menu (3 steps)
   - Create composer profile for 490 : same structure (3 steps)
   - Idempotent transactional, fires CatalogChanged event branchId=1

2. **Tests** :
   - PHPUnit filter `Menu|ItemCategory|Wizard|Burger`
   - Vitest filter `kioskMenu|kioskWizard|kioskComposer`

3. **Visual capture + adversarial** :
   - /test-e2e walking burger wizard (375 + 490)
   - Verify 3 steps render : Sauce / Suppléments (Double viande visible) / Menu (4 options)
   - Verify 4 viandes still render for Sandwich/Galette/Tacos fallback (regression check)
   - Verify bowls 3-step still OK (regression check)

4. **Convergence** : 2 consecutive clean rounds P0+P1=0, identical findings.

## Frozen-zone respect
- 0 modification de `KioskWizardComponent.vue` (le menu-step pattern accepté résout P1-001)
- 0 modification de `pos-wizard.js`
- 0 modification fiscal/multi-tenant services

## Commit signature
`feat(menu): heal v3.1 — burger composer 3-step + Double viande supplément`
