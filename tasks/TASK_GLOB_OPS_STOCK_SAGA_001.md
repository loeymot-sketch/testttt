# TASK_GLOB_OPS_STOCK_SAGA_001 — Convergence stock physique et disponibilité

## Meta

- **Priority:** P0/P1 intégrité stock
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `chaos` + `data-reconciliation`
- **SOURCE:** audit global F-11 et contre-audit sync/stock
- **STATUS:** `PENDING_STOCK_SAGA_SCHEMA_GATE`

## Problème prouvé

Lors d'une annulation/remboursement, le listener de remise en stock physique peut échouer et avaler l'exception. Le listener disponibilité continue puis écrit le même `order_items.released_qty`. Le réconciliateur et `StockService` utilisent ensuite ce compteur partagé : si disponibilité a avancé jusqu'à `quantity`, l'échec physique devient invisible et ne sera jamais réparé.

Le test sentinelle actuel valide que le sibling continue après exception, mais ne vérifie pas la convergence finale. Il est donc vert sur le contre-exemple dangereux.

## Décision recommandée

Conserver une intention/saga commune et séparer ses preuves idempotentes :

- remise de stock/ingrédients physique ;
- libération de disponibilité/quota/menu ;
- état de saga/reconciliation qui compare l'attendu et le réalisé.

Un sibling ne peut jamais marquer la preuve de l'autre et les effets ne deviennent pas deux workflows sans orchestration commune. Une exception produit un retry durable ou une dead-letter actionnable, pas un simple log.

## Contrat minimal

Pour chaque compensation d'ordre/ligne/génération :

- identité `branch_id`, order type/id, order_item_id, reason/refund scope ;
- quantité attendue en unité canonique serveur ;
- `stock_state` et quantité réalisée ;
- `availability_state` et quantité réalisée ;
- attempts, last_error, next_attempt, completed/dead-letter timestamps ;
- clés d'idempotence distinctes par effet ;
- référence aux mouvements physiques réels.

États possibles par effet : `PENDING`, `APPLIED`, `FAILED_RETRYABLE`, `DEAD_LETTER`. L'état agrégé est dérivé, jamais utilisé pour masquer le détail.

## Règles d'implémentation

1. À la création, validation et réservation de stock sont atomiques avec la commande. La transaction d'annulation/remboursement crée l'intention de compensation commune de façon atomique ; les effets s'exécutent après commit si nécessaire.
2. Chaque effet acquiert son propre claim/lease et écrit sa preuve uniquement après succès.
3. Retry identique ne double ni mouvement stock ni quota.
4. Le réconciliateur recalcule attendu vs mouvements/preuves, y compris si un ancien `released_qty` est déjà égal à quantity.
5. Les anciens rows sont classés avant backfill ; aucun reset aveugle de `released_qty`.
6. Refund partiel et annulation complète produisent des générations/scopes non chevauchants. Un aliment déjà préparé/consommé n'est jamais remis automatiquement en `on_hand`; il devient perte/waste/override selon une décision métier auditée.
7. Une branche ne peut jamais consommer ou réparer les mouvements d'une autre.
8. Une dead-letter apparaît dans la santé/paging avec commande, branche, effet manquant et action.
9. Le modèle distingue ledger `on_hand`, réservation par ligne, consommation/production, override commercial et projection backend `sellable_quantity`.

## SUBSYSTEMS_OFF_LIMITS

- Aucun correctif consistant uniquement à ne plus avaler l'exception sans persistance/retry.
- Aucun compteur partagé servant de preuve aux deux effets.
- Aucun backfill destructif des historiques sans dry-run/review humain.
- Aucun calcul quantité/prix frontend.
- Aucun événement avant commit.
- Aucun merge de l'ingress Uber dirty tant que sa réservation stock atomique n'est pas résolue.
- Aucun remboursement après préparation interprété automatiquement comme retour de nourriture en stock.

## INVARIANTS_AT_RISK

- Stock et schema frozen.
- `branch_id`.
- Dispatch après commit.
- Parité OrderService/FrontendOrderService.
- Idempotence cancel/refund/replay.
- Unités, recettes/ingredients et disponibilité menu.

## Tests falsifiables

1. Injecter échec stock, succès disponibilité : saga reste incomplète, retry répare le physique malgré l'ancien compteur partagé.
2. Injecter succès stock, échec disponibilité : retry libère seulement disponibilité.
3. Crash après effet physique avant preuve : réconciliation par mouvement/idempotency empêche le double crédit.
4. Double cancel, double refund et replay event : une seule quantité par scope.
5. Refund partiel puis complet : somme exacte, jamais au-delà de quantity.
6. Deux branches avec mêmes product/ingredient IDs : aucune fuite de mouvements.
7. Minuit/business date et commande planifiée : compensation attribuée à la bonne branche/date comptable.
8. Données historiques `released_qty == quantity` mais mouvement physique absent : détectées par dry-run.
9. Dead-letter : health `DOWN/DEGRADED`, alerte humaine sur un seul incident, commande actionnable.
10. Test sentinelle existant inversé : il doit exiger convergence, pas seulement poursuite du sibling.
11. Annulation avant production libère la réservation ; annulation/refund après préparation produit waste/contre-écriture métier, pas crédit `on_hand` automatique.

## Acceptance Criteria

- [ ] La réussite disponibilité ne peut plus masquer un échec stock.
- [ ] Chaque effet est idempotent, observable et réconciliable.
- [ ] Le contre-exemple actuel converge automatiquement ou devient dead-letter visible.
- [ ] Refund partiel/complet et double événements ne surcréditent jamais.
- [ ] Un dry-run humain précède tout backfill historique.

## Gate

Requiert `HG-GLOBAL-OPS-RELIABILITY-2026-08-11` Décision 4, gate schema/frozen, plan de migration/backfill séparé et tests de non-régression OrderService/FrontendOrderService. Aucun edit avant approbation consignée.
