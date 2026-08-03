# Gate Brief — `GATE_DROP_TABLE_TABLE_SERVICE_V1` — 2026-05-02

**TASK_ID :** `CV1-V1-CLOSEOUT-001` — sub-action Lot B service à table
**Author :** Claude in-session orchestrator
**Plan :** `plans/PLAN_CV1-V1-CLOSEOUT-MASTER-2026-05-02.md` §3 (Lot B service à table)
**Hard gate type :** **Schema migration multi-table** + suppression module métier — `human-gates.mdc` requires explicit approval.
**Périmètre :** 4 modules métier liés (waiters, chefs, tableOrders, diningTable).

---

## Trigger

User a demandé (2026-05-02) la suppression des fonctionnalités inutiles V1. L'hypothèse retenue par défaut au master plan §0 est : **FoodKing V1 ne fait pas de service à table** (toute la doctrine du dépôt parle de fast-food en service comptoir + Kiosk + KDS pour préparation et OSS pour rappel commande ; aucune mention de QR-code-table / addition table / waiter assigné). Si cette hypothèse est correcte, les 4 modules `waiters/`, `chefs/`, `tableOrders/`, `diningTable/` doivent être supprimés.

**Inverser l'hypothèse :** si FoodKing fait du service à table V1, garder ces modules et fermer ce gate sans action.

## Affected Subsystems (4 modules)

| Module | Frontend | Backend | DB tables candidates |
|---|---|---|---|
| `waiters/` | `resources/js/components/admin/waiters/**` + `resources/js/router/modules/waiterRoutes.js` | `app/Http/Controllers/Admin/Waiter*Controller.php` | `waiters`, `waiter_*` |
| `chefs/` | `resources/js/components/admin/chefs/**` + `resources/js/router/modules/chefRoutes.js` | `app/Http/Controllers/Admin/Chef*Controller.php` | `chefs` (si distinct des employees) |
| `tableOrders/` | `resources/js/components/admin/tableOrders/**` + `resources/js/router/modules/tableOrderRoutes.js` + `adminTableOrderRoutes.js` | `app/Http/Controllers/*TableOrder*Controller.php` | `table_orders`, `table_order_items` |
| `diningTable/` | `resources/js/components/admin/diningTable/**` + `resources/js/router/modules/diningTableRoutes.js` | `app/Http/Controllers/Admin/DiningTable*Controller.php` | `dining_tables`, `dining_table_zones` |

(L'inventaire précis sera produit par l'audit Axe 5 sous-jacent à ce gate.)

## Invariants à challenger

1. **Données historiques** — y a-t-il des `orders` historiques ayant `dining_table_id` ou `waiter_id` non NULL ? Si oui, **DROP TABLE casse l'intégrité référentielle** ⇒ rename + archive obligatoire.
2. **NF525 / fiscal** — historique commandes lisible indéfiniment.
3. **POS routes** — vérifier que `posRoutes.js` (qui contient `/admin/pos/floorplan` ligne 25) ne dépend PAS de `diningTable/` pour le floorplan. Si oui, le cas est plus complexe (il faut retirer aussi le floorplan POS ou découpler).
4. **Permissions** — rôles RBAC `waiter`, `chef` à supprimer aussi (table `permissions`).
5. **branch_id isolation (#3)** — non impacté directement.

## Risque spécifique : POS Floorplan

**`posRoutes.js:25` expose `/admin/pos/floorplan` → `FloorplanComponent`.** Cette page sert probablement à dessiner le plan de salle pour assigner les commandes à des tables. Si on supprime `diningTable/`, **on doit aussi retirer ce floorplan POS** car il devient orphelin.

**Décision sous-jacente :** garder ou retirer le floorplan POS ? Recommandation = retirer (cohérence fast-food sans table).

## Décision requise

| Option | Effet | Réversibilité |
|---|---|---|
| **A — Cache du menu admin uniquement (4 modules)** | Routes + composants Vue retirés ; DB intacte ; controllers Laravel orphelins ; floorplan POS gardé | Trivial : git revert |
| **B — Suppression frontend 4 modules + RENAME 4 tables archive + retire floorplan POS** (recommandé) | Frontend des 4 modules supprimé ; controllers Laravel supprimés ; tables renommées `*_archived_v1` ; permissions `waiter`/`chef` retirées ; FKs préservées | Migration down : rename arrière |
| **C — DROP TABLE complet 4 tables** | Frontend + backend + tables supprimés | DESTRUCTIF |
| **D — Annuler ce gate (service à table utilisé V1)** | Aucune action | — |

**Recommandation Claude orchestrator :** **Option B** + retire floorplan POS (cohérence). Préserve historique fiscal NF525.

## Test Strategy

- Vitest : aucun spec `waiter*`, `chef*`, `tableOrder*`, `diningTable*`, `floorplan*` ne doit casser.
- PHPUnit : tests features liés skipped puis supprimés.
- Migration test : 4 tables rename up + down sur SQLite.
- E2E : `tests/e2e/*` qui mentionnent table service (à inventorier) skipped.

## Approval

```
[ ] Option A — Cache du menu admin uniquement
[ ] Option B — Suppression frontend 4 modules + RENAME 4 tables + retire floorplan POS (recommandé)
[ ] Option C — DROP TABLE complet 4 tables
[ ] Option D — Annuler (service à table utilisé V1)

Approved by: __________________________________
Date: 2026-__-__
```

Après approbation : entrée dans `docs/gates/GATE_LOG.md`, exécution via `EXECUTE_DELEGATION: foodking-complex-implementer` (effort L — 4 modules + permissions + floorplan).
