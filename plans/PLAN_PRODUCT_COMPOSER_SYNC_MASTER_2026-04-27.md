# PLAN PRODUCT COMPOSER + CATALOG STOCK SYNC MASTER - 2026-04-27

TASK_ID: PRODUCT-COMPOSER-SYNC-MASTER-2026-04-27  
MODE: multi-train plan  
PRIMARY_GOAL: livrer un systeme central de composition produit, catalogue, photos, prix, stock et synchronisation POS/kiosk/KDS/dashboard.

## 0. Vision cible

FoodKing doit avoir un control plane unique dans le dashboard/POS admin:

- Gestion categories.
- Gestion produits.
- Gestion photos produit borne/POS.
- Gestion prix source DB.
- Gestion variations, extras, addons.
- Gestion wizard/composition par produit.
- Gestion disponibilite et stock.
- Gestion offres comme produits/compositions commercialisables.

Les consommateurs sont separes:

- POS: interface caisse rapide, pas de refonte du wizard POS tant que logique fonctionne.
- Kiosk: design client borne, pas d'admin, pas de caisse, pas de PIN.
- KDS/OSS: consomment commandes et statuts.

## 1. Invariants

1. Le frontend ne calcule jamais le prix final.
2. `PricingService` et quote backend restent l'autorite.
3. Toute lecture/ecriture stock est branche-scoped.
4. Les events de sync partent apres commit.
5. POS et kiosk partagent la projection catalogue, pas le design.
6. Le composer reference les choix existants; il ne duplique pas les prix.
7. Pas de migration stock/order sans gate humain.
8. Pas de patch `OrderService` / `FrontendOrderService` sans `HG-FROZEN-ORDERSERVICE-UNLOCK`.

## 2. Trains

| Train | Nom | Objectif | Gate |
| --- | --- | --- | --- |
| 00 | Demand registry + baseline | Archiver toutes les demandes et verifier etat courant | aucun |
| 01 | Schema ADR + data contract | Choisir schema composer + stockable, ecrire ADR/tests fail-first | `HG-COMPOSER-SCHEMA-ADR`, `HG-STOCK-STOCKABLE-SCOPE` |
| 02 | Dashboard composer | UI dashboard de creation/modification produit compose | `HG-DASHBOARD-AUTHZ-CATALOG-OPS` |
| 03 | Projection runtime | POS/kiosk consomment payload composer commun | aucun si pas migration DB |
| 04 | Stock/order sync | Stock atomique produit/choix + decrement/release + rupture POS/kiosk | `HG-FROZEN-ORDERSERVICE-UNLOCK` |
| 05 | E2E release + Claude | Parcours complet + audit global + prompt Claude | `HG-E2E-HARDWARE-COMPOSER-SIGNOFF` |

## 3. Architecture cible

```text
Dashboard Product Composer
  -> item_wizard_profiles / item_wizard_steps
  -> items / categories / attributes / variations / extras / addons
  -> stock_levels / stock_movements
  -> CatalogChanged / StockLevelChanged outbox
  -> MenuProjectionService V2
  -> POS adapter + Kiosk adapter
  -> PricingService quote seal
  -> OrderService / FrontendOrderService
  -> POS live / KDS / OSS
```

## 4. Data contract composer

Payload cible minimal:

```json
{
  "item_id": 123,
  "profile": {
    "template": "assiette",
    "version": 7,
    "published": true
  },
  "steps": [
    {
      "step_key": "crudites",
      "label": "Crudites",
      "source_type": "extra_group",
      "source_ref": "garniture",
      "min_select": 0,
      "max_select": 6,
      "allow_repeat": false,
      "visible_on": ["pos", "kiosk"],
      "choices": []
    }
  ]
}
```

Les `choices` sont projetes depuis les tables existantes. Le prix final n'est pas dans le profile.

## 5. Tests globaux requis

- PHP: schema, services, projection, pricing parity, stock concurrency.
- JS: composer UI, wizard POS adapter, wizard kiosk adapter, rupture UI.
- E2E: admin cree produit compose -> POS le commande -> kiosk le commande -> stock rupture -> KDS/OSS.
- Build: `npm run production`.
- Audit: rapport Codex + audit Claude.

## 6. Definition de fermeture

`PASS` uniquement si:

- Tous les trains 00..05 ont un rapport PASS.
- Les gates humains sont signes dans `docs/gates/`.
- Les tests cibles et E2E passent.
- Claude peut reproduire l'audit sans trouver P0/P1 bloquant.

Sinon: `REWORK`.
