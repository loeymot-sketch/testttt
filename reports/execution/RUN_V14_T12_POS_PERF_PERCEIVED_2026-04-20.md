# RUN V14 T12 — POS perceived performance (2026-04-20)

## Statut

**PASSED** — livraison conforme au périmètre T12 (skeleton grille, add-to-cart optimiste, vérif debounce recherche). Aucune migration, aucun toucher `app/Services/**`, pas de KDS/Kiosk.

## Fichiers livrés

| Fichier | Action |
|---------|--------|
| `resources/js/components/admin/pos/SkeletonGrid.vue` | **NEW** — grille shimmer pure CSS + `aria-busy` / `role="status"` |
| `resources/js/components/admin/pos/PosComponent.vue` | Skeleton pendant `posItemsFetchPending` + liste vide ; `loadingItems` computed |
| `resources/js/components/admin/pos/ItemComponent.vue` | Chemin « nouvelle ligne » : `addOptimistic` → `lists` → `__optimisticConfirm` / rollback + `subtotal` |
| `resources/js/store/modules/posCart.js` | Mutations `__optimisticAdd` / `__optimisticConfirm` / `__optimisticRollback`, action `addOptimistic`, helper `samePosLineMergeSignature` (réconciliation fusion `lists`) |
| `resources/js/languages/en.json` | Clé `label.loading` (manquante) |
| `resources/js/languages/fr.json` | Clé `label.loading` (parité) |
| `tests/js/posSkeletonGrid.spec.js` | **NEW** — 3 tests |
| `tests/js/posCartOptimistic.spec.js` | **NEW** — 4 tests |

## Tests

- `npx vitest run tests/js/posSkeletonGrid.spec.js tests/js/posCartOptimistic.spec.js` → **7/7** verts.
- Régression : `npx vitest run tests/js/pos*.spec.js tests/js/PosComponent.spec.js` → **118/118** verts (21 fichiers).

## Virtual scroll (>100 items)

**TODO (non livré)** : `vue-virtual-scroller` est **absent** de `package.json`. Aucune installation de dépendance sans gate — pas de `ItemListing.vue` dans le repo ; la grille reste dans `ItemComponent.vue`. **Backlog** : ajouter la dépendance + `RecycleScroller` derrière un seuil `items.length > 100` si validé.

## Debounce recherche (T10)

**Vérifié** : `PosComponent` utilise `lodash/debounce` (150 ms) via `_debouncedListRefresh` et `onSearchInput` — aligné T10, pas de changement requis.

## Risques / suivis

- `__optimisticConfirm` gère la double fusion `lists` + ligne orpheline (même signature qu’une ligne plus ancienne) ; logique localisée dans `posCart.js`.
- Édition de ligne existante (`replaceCartLine`) : pas d’optimistic (flux inchangé).

## Exécution

`EXECUTE_DELEGATION: foodking-routine-implementer (Composer) — TASK_ID T12_POS_PERF_PERCEIVED`
