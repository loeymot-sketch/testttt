# RUN — P_POS Phase 9 — A11y & harmonisation (T18) — 2026-04-24

| Lot | Outil | Cible / suite | Résultat | Notes |
| --- | --- | --- | --- | --- |
| A | `after-execute-memory` | (cycle) | OK | |
| A | `check-invariants.sh` | 6/6 invariants | OK | |
| B | Vitest | `tests/js/posA11y.spec.js` | 5 tests, OK | `trapFocus`, `announce` |
| B | Vitest | `tests/js/posComponentA11y.spec.js` | 3 tests, OK | skip link, `role=region` panier, tuile `ItemComponent` — `stderr` shallow attendu (Phase 6) |

**Total** : **8** tests Vitest, tous verts.

**EXECUTE_DELEGATION:** routine — alimentation mémoire, tests, audit, marqueurs `Phase-9 / T18` (commentaire `PosComponent.vue`, JSDoc `posA11y.js`).

**GATE (plan)** : aucun (surface dite sûre).

**Livrables code (traçabilité seulement)** : pas de refonte visuelle ; lignes de commentaire liées au plan 10 phases, Phase 9.

**Audit terminal (Claude) — 2026-04-24** : **GAPS** — `announce()` peu ou pas utilisé depuis les flux clés, points à cadrer sur ancres / `aria-live` (voir audit) ; **aucun** correctif produit dans ce lot (dette documentée — traitement possible phase dédiée ou T18 follow-up).

**Suite (plan 10 phases)** : **Phase 10** — E2E « tacos 4 viandes » (T22) + audit UI global ciblé ; **GATE** E2E opt-in, `workflows/qa-loop.md`.
