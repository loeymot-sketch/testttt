# LOCK_A — migration `idempotency_key` UNIQUE composite `(branch_id, idempotency_key)`

**Date.** 2026-04-18
**Track.** A (Kiosk)
**Vague.** P9.5
**Item.** 9.5.4

## Fichiers verrouillés

- `database/migrations/<TS>_scope_idempotency_key_to_branch.php` (nouveau)
- Modèle / service qui consomme `idempotency_key` à la création de commande (lecture seule, pas de modif code Service sauf si l'usage actuel repose sur l'unique standalone — à auditer).

## Lignes prévues

- Migration DROP UNIQUE index existant sur `idempotency_key` (si présent), puis ajout UNIQUE composite `(branch_id, idempotency_key)`. Rollback : inverser. Skip si index déjà composite.
- Tester sur MySQL (CI) pour s'assurer que `ALGORITHM=INPLACE` convient ou que la migration passe en ALGORITHM par défaut (dataset petit en test).
- Zéro modification de `OrderService::posOrderStore` ou `FrontendOrderService::myOrderStore` dans ce lock : on se contente de changer la contrainte DB. Les call-sites doivent continuer de passer `(branch_id, idempotency_key)` comme aujourd'hui.

## Raison

- Invariant audit §6 / P0-15 : deux branches différentes peuvent soumettre la même idempotency_key sans collision ; même branche + même key = renvoi commande existante.
- Test attendu : `IdempotencyBranchScopedTest::test_same_key_different_branches_ok` + `test_same_key_same_branch_returns_existing_order`.

## Coordination Track B

- Aucun LOCK_B_* sur `orders` au 2026-04-18. Track B n'a pas de migration concurrente sur cette table (POS-9.4 a déjà livré `orders.fiscal_sequence_no` en migration dédiée mergée dans feat/pos-phase-9-4 mais pas encore sur main). Si conflit de migration au rebase main, arbitrage via CONFLICT_RESOLUTION.
- Broadcast P9.5 merged alertera Track B que l'INDEX composite est en place : tout insert `orders` doit désormais fournir `branch_id` non-null (déjà le cas).

## Tests obligatoires

- `tests/Feature/Orders/IdempotencyBranchScopedTest::test_same_key_different_branches_ok`
- `tests/Feature/Orders/IdempotencyBranchScopedTest::test_same_key_same_branch_returns_existing_order`
- Rollback vérifié : `migrate:rollback` remet l'unique index précédent.

## ETA libération

- Lock levé après commit 9.5.4 et confirmation verte des 2 tests.

## Status

- **RELEASED (`this commit`)** le 2026-04-18.
- Tests verts confirmés sur `IdempotencyBranchScopedTest`.
