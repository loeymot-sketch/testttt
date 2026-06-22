# RUN — P_POS Phase 6 — recherche / raccourcis / perçu perfo (T10, T12) — 2026-04-24

| Lot | Outil | Cible / suite | Résultat | Notes |
| --- | --- | --- | --- | --- |
| A | `after-execute-memory` | (cycle) | OK | |
| A | `check-invariants.sh` | 6/6 invariants | OK | |
| B | Vitest | `tests/js/posSkeletonGrid.spec.js` | 3 tests, OK | T12 `SkeletonGrid` (tuiles) |
| B | Vitest | `tests/js/posBarcode.spec.js` | 8 tests, OK | F-keys, détecteur HID, `shouldIntercept` |
| B | Vitest | `tests/js/PosComponent.spec.js` | 1 test, OK | `stderr` Vue (router-link/vue-select non résolus en shallow) — bruit connu |
| B | Vitest | `tests/js/posComponentA11y.spec.js` | 3 tests, OK | raccourci a11y POS (lien skip, `role=region`) — chevauche T18, régression utile |

**Total Vitest (lot ciblé)** : **15** tests, tous verts.

**EXECUTE_DELEGATION:** routine — alimentation mémoire, tests front, audit terminal, marqueur `Phase-6` dans le bundle.

**GATE (plan)** : aucun (front safe).

**Livrable code** : commentaire `Phase-6 / T10–T12` au-dessus de `export default` dans `resources/js/components/admin/pos/PosComponent.vue` — **aucune** logique métier / prix modifiée.

**Audit terminal (Claude) — 2026-04-24** : **PASS** — debounce 150 ms, `SkeletonGrid`, barcode + F-keys + parked drawer ; *gap* mineur : codes avec `.` / `+` non couverts par la regex scanner (edge-case documenté).

**Suite (plan 10 phases)** : **Phase 7** — KDS (T13, T14) stabilisation flux cuisine.
