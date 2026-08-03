# Gate Brief — `GATE_DROP_TABLE_DELIVERY_BOYS_V1` — 2026-05-02

**TASK_ID :** `CV1-V1-CLOSEOUT-001` — sub-action Lot B livraison
**Author :** Claude in-session orchestrator
**Plan :** `plans/PLAN_CV1-V1-CLOSEOUT-MASTER-2026-05-02.md` §3 (Lot B livraison)
**Hard gate type :** **Schema migration (DROP TABLE)** + suppression module métier — `human-gates.mdc` requires explicit approval.

---

## Trigger

User a demandé (2026-05-02) la suppression des fonctionnalités inutiles V1. L'hypothèse retenue par défaut au master plan §0 est : **FoodKing V1 ne fait pas de livraison interne** (toute la doctrine du dépôt parle de POS + Kiosk + KDS pour fast-food en service comptoir / à emporter ; aucune mention de logistique livraison). Si cette hypothèse est correcte, le module `deliveryBoys/` doit être supprimé pour clarifier le système.

**Inverser l'hypothèse :** si FoodKing fait réellement de la livraison V1 (à clarifier user), garder le module et fermer ce gate sans action.

## Affected Subsystems

| Subsystem | Impact |
|---|---|
| Frontend admin | Module `resources/js/components/admin/deliveryBoys/**` supprimé |
| Routes Vue | `resources/js/router/modules/deliveryBoyRoutes.js` supprimé |
| Backend Laravel | Controllers, services, models, ressources liées (à inventorier précisément via Axe 5 audit) |
| DB | Tables `delivery_boys` (et toutes les tables liées de livraison : `order_delivery_*`, etc.) — DROP TABLE ou rename pour archive |
| Tests | Tests unitaires + features liés |
| i18n | Clés `admin.deliveryBoy.*` |

## Invariants à challenger

1. **Données historiques** — y a-t-il des `orders` historiques qui référencent un `delivery_boy_id` ? Si oui, DROP TABLE casse l'intégrité référentielle. **Solution recommandée** : rename `delivery_boys` → `delivery_boys_archived_v1` (préserve historique + référentiel intact), retire le module frontend, garde la FK pour audit historique.
2. **NF525 / fiscal** — les commandes archivées doivent rester lisibles indéfiniment. Le rename préserve cette lisibilité.
3. **branch_id isolation (#3)** — non impacté.

## Décision requise

| Option | Effet | Réversibilité |
|---|---|---|
| **A — Cache du menu admin uniquement** | Routes + composants Vue retirés ; DB intacte ; controllers Laravel restent (orphelins) | Trivial : git revert |
| **B — Suppression frontend complète + RENAME table archive** (recommandé) | Frontend supprimé ; controllers Laravel supprimés ; table `delivery_boys` → `delivery_boys_archived_v1` ; FKs préservées | Migration `down()` : rename arrière |
| **C — DROP TABLE complet** | Frontend + backend + table supprimés | DESTRUCTIF : nécessite restore backup pour annuler |
| **D — Annuler ce gate (livraison réellement utilisée V1)** | Aucune action ; module conservé tel quel | — |

**Recommandation Claude orchestrator :** **Option B**. Préserve historique fiscal, retire la friction UI, controller Laravel supprimé pour réduire la surface, table renommée pour audit.

## Test Strategy (post-clearance)

- Vitest : aucun spec lié `deliveryBoy*` ne doit casser après suppression frontend.
- PHPUnit : tests features `tests/Feature/DeliveryBoy*` skipped puis supprimés au commit suivant.
- Migration test : up + down (rename + rename arrière) sur SQLite.

## Approval

```
[ ] Option A — Cache du menu admin uniquement
[ ] Option B — Suppression frontend + RENAME table archive (recommandé)
[ ] Option C — DROP TABLE complet
[ ] Option D — Annuler (livraison utilisée V1)

Approved by: __________________________________
Date: 2026-__-__
```

Après approbation : entrée dans `docs/gates/GATE_LOG.md`, exécution via `EXECUTE_DELEGATION: foodking-routine-implementer` (Composer) ou `foodking-complex-implementer` selon ampleur (M attendu).
