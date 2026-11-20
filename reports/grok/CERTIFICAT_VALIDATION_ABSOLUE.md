# CERTIFICAT Grok — 2026-09-01 (ciblé, pas FoodKing entier)

**Validateur :** Grok (parent a lu les PNG).  
**GOAL :** `plans/GOAL_VALIDATION_ABSOLUE_GROK_2026-09-01.md`  
**Pas** `CONVERGENCE_FINAL` produit. **Pas** 2e round identique preview (1 vert W1).

## W0 smoke (rejeu ce jour)

```
PHPUnit  OK (83 tests, 263 assertions)
  (81 HANDOFF + CashierCannotToggleInterrupteurTest 2)
Vitest   39/39 HANDOFF + productComposerEditor 7/7
Frozen   git diff --stat kiosk/pos-wizard/Pricing/FiscalSequence = vide
Mix      admin-shell.57f379b6.js (stamp-day)
```

## P1-A Preview — FERMÉ (1 round vert, parent-lu)

Cause : wizard **catégorie** passait `this.item = catégorie` → menu-projection cherchait `item.id === cat.id`.

Correctif : `loadCategory` `item=null` ; `available-sources.item_id` = 1er item ACTIVE ; `GET admin/item/show/{id}`.

PNG :

| Rayon | Preview | Caisse/Borne |
|---|---|---|
| Burgers | Chicken Burger 4.9 | **Disponible** `round-5/wave-D-burgers/02-wizard.png` |
| Sandwichs | Cayenne 7.4 | **Disponible** `round-5/wave-D-sandwichs/02-wizard.png` |
| Galette | Galette Normale 6.5 | **Disponible** `round-5/wave-D-galette/02-wizard.png` |

Filiale **Le Cayenne (principal)**. Round-4 était **Indisponible**.

**Manque :** 2e round identique Disponible (GOAL W1 ×2).

## P1-C Interrupteur — FERMÉ

`CashierCannotToggleInterrupteurTest` PUT+GET **403**.  
E2E `pos@lecayenne.fr` `/admin/observability/system` → **dashboard**. PUT `wheel` **403**.  
PNG : `round-5/wave-E/02-observability.png`

## P1-B Wizards round-5 = round-4 (clés)

| Rayon | Pages | viande_2 |
|---|---|---|
| Sandwichs | 6 | **oui** |
| Galette | 5 | non |
| Burgers | 5 | non |
| Frites | 0 | — |

## BLOCK owner (dans le tampon, pas oublié)

**Viande 2 Sandwichs** : Méga/Terminator 7 choix, Cayenne 0. Ne pas dépublish.

## P2 restants

No Image Found thumbs wizard. Pain Inactive encore dans le parcours preview. Debugbar. 64 junk SQL. 1490 jobs.

## Claude

HANDOFF + ce certificat. Tranche viande_2, BRAIN, commit fichier par fichier, deploy. Grok ne push pas.
