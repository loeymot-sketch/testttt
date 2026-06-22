# RUN — P_POS Phase 8 — plan de salle (T19) — 2026-04-24

| Lot | Outil | Cible / suite | Résultat | Notes |
| --- | --- | --- | --- | --- |
| A | `after-execute-memory` | (cycle) | OK | |
| A | `check-invariants.sh` | 6/6 invariants | OK | |
| B | Vitest | `tests/js/posFloorplan.spec.js` | 4 tests, OK | store `posFloorplan` (fetch, assign, transfer, getter) |
| C | PHPUnit | `tests/Feature/Pos/FloorplanControllerTest.php` | 11 tests, OK | branch scope, 409, idempotence, **transfer cross-branch 404**, cross-branch order 422, audit log assign |

**Non-régression GATE (plan)** : transfert table / `branch_id` / locks (C-β) — **vert** sur le lot ci-dessus (incl. `test_transfer_cross_branch_returns_404`, conflit assign 409, idempotence même commande).

**EXECUTE_DELEGATION:** routine — alimentation mémoire, tests, audit, marqueur de traçabilité sur `FloorplanComponent`.

**GATE (plan)** : vérifier locks / `branch_id` — explicite dans ce RUN (tests Feature).

**Livrable code** : commentaire `Phase-8 / T19` au-dessus de `export default` dans `resources/js/components/admin/pos/FloorplanComponent.vue` — **aucun** changement d’API.

**Audit terminal (Claude) — 2026-04-24** : **PASS** — isolation `branch_id`, verrous transfer ; *gap* mineur suggéré : `FloorplanTransferRequest::authorize()` trop permissif (défense en profondeur) — suivi manuel, pas de correctif appliqué dans ce lot.

**Suite (plan 10 phases)** : **Phase 9** — A11y, contraste, harmonisation (T18) — surface dite *safe* dans le plan.
