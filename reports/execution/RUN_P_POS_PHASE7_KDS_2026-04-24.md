# RUN — P_POS Phase 7 — KDS cuisine (T13, T14) — 2026-04-24

| Lot | Outil | Cible / suite | Résultat | Notes |
| --- | --- | --- | --- | --- |
| A | `after-execute-memory` | (cycle) | OK | |
| A | `check-invariants.sh` | 6/6 invariants | OK | |
| B | Vitest | `tests/js/kdsTimerEscalation.spec.js` | 3 tests, OK | `getKdsEscalationClass` |
| B | Vitest | `tests/js/kdsStationFilter.spec.js` | 4 tests, OK | `filterOrdersByStation` |
| B | Vitest | `tests/js/kdsBumpRecall.spec.js` | 4 tests, OK | bump / rappel |
| C | PHPUnit | 8 fichiers KDS (un par exécution) | 12 tests, OK | Voir détail ci-dessous |

**Détail PHPUnit (PHPUnit 9 : un seul path par lancement, donc 8 exécutions) :**  
`KdsSnapshotImmutableTest` 2, `KdsChangeStatusConcurrencyTest` 1, `KdsBranchFilterExactTest` 1, `KDSScopeRestrictionTest` 1, `KDSFlowTest` 3, `KDSOrderItemsTest` 2, `KDSAllergenVisibilityTest` 1, `KitchenDisplaySystemOrderSortTest` 1.

**EXECUTE_DELEGATION:** routine — alimentation mémoire, tests, audit, marqueur de traçabilité côté vue KDS (sans toucher `OrderService`).

**GATE (plan)** : pas de changement lourd côté domaine **OrderService** — ici seulement traçabilité + vérification tests / audit.

**Livrable code** : commentaire `Phase-7 / T13–T14` au-dessus de `export default` dans `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`.

**Audit terminal (Claude) — 2026-04-24** : **PASS avec réserves** — KDS ne couple pas `OrderService` côté gate ; *réserves* notées (bump `localStorage`, auto-PREPARED, allergènes surtout kiosk) — dettes / pas de `heal` automatique dans ce lot (aligné rôle routage, pas d’implem imposée ici).

**Suite (plan 10 phases)** : **Phase 8** — plan de salle (T19), `FloorplanComponent`, `branch_id` / transfert table, non-régression locks.
