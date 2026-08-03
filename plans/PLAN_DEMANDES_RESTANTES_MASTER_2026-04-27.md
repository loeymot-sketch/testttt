# Plan master - demandes restantes FoodKing - 2026-04-27

TASK_ID: REMAINING-DEMANDS-MASTER-2026-04-27  
Mode: orchestration multi-trains  
Objectif: completer les demandes utilisateur restantes sans casser la V1 deja validee.

## 0. Regles de base

1. Pas de mega-patch.
2. Un train = un objectif business mesurable.
3. Pas de suppression destructive sans gate.
4. Pas de modification `OrderService` tant que `HG-FROZEN-ORDERSERVICE-UNLOCK` n'est pas documente.
5. Prix backend SSOT: Dashboard edite la source, mais ne calcule jamais le prix final.
6. Kiosk et POS partagent les donnees, pas le design.
7. Kiosk client reste verrouille: aucun admin/PIN/retour caisse.
8. Toute mutation catalogue/stock doit etre branch-scoped ou globalement explicite.
9. Chaque train finit par tests cibles + rapport audit + decision GO/REWORK.

## 1. Train 0 - Deblocage gouvernance et baseline

TASK_ID: `FK-REM-T0-GOVERNANCE-UNLOCK-2026-04-27`

But:
- Rendre le depot executable sans ambiguite.
- Debloquer ou isoler la zone frozen `OrderService`.
- Produire un baseline clair avant les trains produit.

Allowlist:

```text
reports/audit/FK_REM_T0_GOVERNANCE_UNLOCK_2026-04-27.md
docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_2026-04-27.md
reports/post_execute_latest.log
```

Actions:

1. Inventorier changements staged/unstaged qui touchent `OrderService`.
2. Classer les changements: deja valides Train 1, hors scope, besoin gate, a ne pas toucher.
3. Produire gate brief `HG-FROZEN-ORDERSERVICE-UNLOCK`.
4. Rejouer:
   - `bash .cursor/hooks/safety-check.sh`
   - `php artisan test`
   - `npx vitest run`
   - `npm run production`
5. Si safety reste bloque: ne pas lancer trains touchant `OrderService`.

Exit:
- PASS: gate ou isolation propre documentee.
- BLOCKED: humain doit trancher.

## 2. Train 1 - Finir Train 2 centralisation catalogue

TASK_ID: `FK-REM-T1-CATALOG-PROJECTION-2026-04-27`

But:
- Finir PH2-04.
- Brancher POS/kiosk sur projection canonique sans changer pricing.
- Clarifier categories par branche et authz dashboard.

Plan detaille:
- Voir `plans/PLAN_DEMANDES_RESTANTES_TRAIN1_CATALOG_DASHBOARD_V1_2026-04-27.md`.

Exit:
- Menu projection consommee par kiosk et POS.
- Tests parity + headers/version + branch visibility verts.

## 3. Train 2 - Dashboard control plane V1

TASK_ID: `FK-REM-T2-DASHBOARD-CONTROL-PLANE-2026-04-27`

But:
- Livrer une interface exploitable: produits, categories, prix, images, offres, disponibilite.
- V1 utilise availability existante, pas encore stock quantitatif.

Plan detaille:
- Voir `plans/PLAN_DEMANDES_RESTANTES_TRAIN1_CATALOG_DASHBOARD_V1_2026-04-27.md`.

Exit:
- Un manager peut modifier un produit/categorie/prix/image/offre.
- POS/kiosk voient la mutation via refresh/live contract.
- Audit log existe sur mutations sensibles.

## 4. Train 3 - Stock V2 quantitatif

TASK_ID: `FK-REM-T3-STOCK-V2-2026-04-27`

But:
- Remplacer la simple disponibilite par stock quantitatif atomique, sans perdre la compat V1.

Plan detaille:
- Voir `plans/PLAN_DEMANDES_RESTANTES_TRAIN2_STOCK_REALTIME_ORDER_OPS_2026-04-27.md`.

Gate:
- `HG-STOCK-V2-SOURCE-OF-TRUTH`.

Exit:
- Stock concurrent: stock=1, deux commandes simultanees, une seule passe.
- Annulation/remboursement release idempotent.
- Rupture visible POS/kiosk.

## 5. Train 4 - Order ops: queue, live board, handover

TASK_ID: `FK-REM-T4-ORDER-OPS-2026-04-27`

But:
- Centraliser queue allocator.
- POS live board cross-origin.
- Handover/remise client explicite.
- KDS/OSS fanout stable.

Plan detaille:
- Voir `plans/PLAN_DEMANDES_RESTANTES_TRAIN2_STOCK_REALTIME_ORDER_OPS_2026-04-27.md`.

Gate:
- `HG-FROZEN-ORDERSERVICE-UNLOCK`.
- `HG-HANDOVER-SEMANTICS`.

Exit:
- POS voit les commandes kiosk/POS en live.
- KDS bump -> OSS update.
- Handover ferme la commande proprement.

## 6. Train 5 - Nettoyage FR/demo/gateways

TASK_ID: `FK-REM-T5-FR-CLEANUP-2026-04-27`

But:
- Nettoyer residus Bangladesh/demo visibles et seeders, sans suppression destructive.

Plan detaille:
- Voir `plans/PLAN_DEMANDES_RESTANTES_TRAIN3_CLEANUP_E2E_RELEASE_2026-04-27.md`.

Exit:
- Seeds V1 ne recreent plus `Dhaka Bangladesh`, `+880`, `BDT`, fausses branches visibles.
- Route list ne casse plus sur gateways manquantes.
- Runtime FR-first coherent.

## 7. Train 6 - Validation globale / E2E / hardware / release

TASK_ID: `FK-REM-T6-E2E-HARDWARE-RELEASE-2026-04-27`

But:
- Prouver le systeme complet sur flows reels.

Plan detaille:
- Voir `plans/PLAN_DEMANDES_RESTANTES_TRAIN3_CLEANUP_E2E_RELEASE_2026-04-27.md`.

Exit:
- Playwright flows PASS.
- Hardware lab PASS.
- Rapport release GO/NO-GO signe.

## 8. Ordre court d'execution

```text
T0 -> T1 -> T2 -> T3 -> T4 -> T5 -> T6
```

Exception autorisee:
- T5 Phase A runtime/no-delete peut etre faite avant T3 si elle ne touche ni `OrderService`, ni migrations stock, ni suppression DB.

## 9. Prochaine mission recommandee

`FK-REM-T0-GOVERNANCE-UNLOCK-2026-04-27`

Raison:
- Sans ce train, toutes les missions qui touchent queue/order/handover restent bloquees par safety hook.
- C'est aussi la meilleure protection contre la perte d'historique dans un worktree deja tres sale.
