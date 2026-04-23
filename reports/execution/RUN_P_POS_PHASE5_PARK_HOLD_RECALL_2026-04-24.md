# RUN — P_POS Phase 5 — park / hold / recall (T08) — 2026-04-24

| Lot | Outil | Cible / suite | Résultat | Notes |
| --- | --- | --- | --- | --- |
| A | `after-execute-memory` | (cycle) | OK | |
| A | `check-invariants.sh` | 6/6 invariants | OK | |
| B | PHPUnit | `tests/Feature/PosParkedOrderTest.php` | 8 tests, OK | C-9 cross-branch recall+discard 404, idempotence park |
| C | Vitest | `tests/js/posParked.spec.js` | 6 tests, OK | C-1 prune 86, multi-park tri, park/recall/discard store |

**EXECUTE_DELEGATION:** routine — alimentation mémoire, tests, audit terminal, marqueur de traçabilité dans le bundle POS (pas de logique frozen).

**GATE (plan)** : isolation `branch_id` — sentinels cross-branche **obligatoires** (Feature ci-dessus).

**Livrable code** : commentaire `Phase-5 / T08` au-dessus de `export default` dans `resources/js/components/admin/pos/ParkedOrdersComponent.vue` — **aucun** changement d’API ni de service.

**Audit terminal (Claude) — 2026-04-24** : **PASS** — isolation, idempotence, recall + purge 86/vars ; *gap* mineur noté : `preview_total` aperçu côté payload, à ne pas confondre avec le prix autorisant paiement (surveillance future).

**Cartographie plan** : Phase 5 = **T08** (`plans/PLAN_POS_10_PHASES_ORCHESTRATION_DESIGN_2026-04-24.md`, tableau phases ↔ tâches).

**Suite (plan 10 phases)** : Phase 6 — recherche / raccourcis / perçu perfo (T10, T12).
