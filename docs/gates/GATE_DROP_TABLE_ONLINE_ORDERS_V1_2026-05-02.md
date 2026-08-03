# Gate Brief — `GATE_DROP_TABLE_ONLINE_ORDERS_V1` — 2026-05-02

**TASK_ID :** `CV1-V1-CLOSEOUT-001` — sub-action Lot B vente en ligne
**Author :** Claude in-session orchestrator
**Plan :** `plans/PLAN_CV1-V1-CLOSEOUT-MASTER-2026-05-02.md` §3 (Lot B online orders)
**Hard gate type :** **Schema migration (DROP TABLE)** + suppression module métier — `human-gates.mdc` requires explicit approval.

---

## Trigger

User a demandé (2026-05-02) la suppression des fonctionnalités inutiles V1. L'hypothèse retenue par défaut au master plan §0 est : **FoodKing V1 ne propose pas de commande en ligne via site web** (toute la doctrine du dépôt parle de POS + Kiosk + KDS pour ventes en boutique ; aucune mention de `frontend` site web client direct hors `kiosk/` qui est sur tablette physique en boutique). Si cette hypothèse est correcte, le module `onlineOrders/` doit être supprimé.

**Inverser l'hypothèse :** si FoodKing fait des commandes en ligne (site web ou app mobile), garder le module et fermer ce gate sans action.

## Affected Subsystems

| Subsystem | Impact |
|---|---|
| Frontend admin | Module `resources/js/components/admin/onlineOrders/**` |
| Routes Vue | `resources/js/router/modules/onlineOrderRoutes.js` |
| Frontend client | Si `resources/js/components/frontend/**` (hors kiosk) sert le site web → également à inventorier |
| Routes Vue frontend | `frontendRoutes.js`, `tableOrderRoutes.js` (à confirmer périmètre) |
| Backend Laravel | Controllers `*OnlineOrder*Controller`, services associés |
| DB | Tables `online_orders`, `online_order_items` (à inventorier) |
| OrderService / FrontendOrderService | **CRITIQUE — invariant #5** : `FrontendOrderService` est-il utilisé seulement par le kiosk (à garder) ou aussi par le frontend site web (à supprimer si online supprimé) ? Audit Axe 5 doit clarifier. |

## Invariants à challenger

1. **Invariant #5 OrderService / FrontendOrderService symétrie** — si `FrontendOrderService` n'est utilisé que par le frontend site web, on peut le supprimer ; si Kiosk l'utilise aussi, on doit le garder. **À clarifier avant signature** (audit Axe 5 + Axe 1 doivent répondre).
2. **Données historiques** — `online_orders` historiques doivent rester lisibles (NF525). Rename obligatoire si tables existent.
3. **Routes frontend** — il existe `resources/js/router/modules/frontendRoutes.js` et `tableOrderRoutes.js` qui peuvent gérer un mini-site web public. Si le user n'a PAS de site web public V1, ces routes peuvent aussi être candidates à suppression (à vérifier).
4. **Customer auth** — `customers/` est lié (souvent un customer commande en ligne). Si online supprimé, `customers/` perd sa raison d'être V1 → renforce la décision « cacher du menu ».
5. **branch_id isolation (#3)** — non impacté.

## Décision requise

| Option | Effet | Réversibilité |
|---|---|---|
| **A — Cache du menu admin uniquement** | Routes admin + composants Vue admin retirés ; DB intacte ; site web frontend client encore accessible | Trivial : git revert |
| **B — Suppression frontend admin + RENAME tables archive + retire frontend client public** (recommandé si pas de site V1) | Frontend admin + frontend public site web supprimé ; controllers Laravel supprimés ; tables renommées `*_archived_v1` ; FrontendOrderService **conservé** car utilisé par Kiosk | Migration down |
| **C — DROP TABLE complet** | Tout supprimé | DESTRUCTIF |
| **D — Annuler ce gate (vente en ligne utilisée V1)** | Aucune action | — |

**Recommandation Claude orchestrator :** **Option B avec verification critique** que `FrontendOrderService` reste utilisé par Kiosk avant suppression frontend client public. Audit Axe 5 + Axe 1 + Axe 3 doivent répondre AVANT signature.

## Test Strategy

- Vitest : aucun spec `*onlineOrder*`, `*Frontend*Order*` côté Kiosk ne doit casser.
- PHPUnit : test de symétrie `OrderServiceFrontendOrderServiceSymmetryTest` (M2 2.4) doit rester PASS — l'invariant #5 est protégé.
- E2E : `tests/e2e/*` qui mentionnent commande en ligne (à inventorier).
- Migration test : up + down rename.

## Approval

```
[ ] Option A — Cache du menu admin uniquement
[ ] Option B — Suppression frontend admin + frontend public + RENAME tables (recommandé après vérif Kiosk-FrontendOrderService)
[ ] Option C — DROP TABLE complet
[ ] Option D — Annuler (commande en ligne utilisée V1)

PRÉ-REQUIS pour B/C : confirmation que FrontendOrderService reste utilisé par Kiosk uniquement (audit Axe 5 + Axe 1 + Axe 3).

Approved by: __________________________________
Date: 2026-__-__
```

Après approbation : entrée dans `docs/gates/GATE_LOG.md`, exécution via `EXECUTE_DELEGATION: foodking-complex-implementer` (effort M-L selon périmètre frontend public).
