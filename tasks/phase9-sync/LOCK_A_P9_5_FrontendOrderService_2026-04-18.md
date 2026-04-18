# LOCK_A — `app/Services/FrontendOrderService.php`

**Date.** 2026-04-18
**Track.** A (Kiosk)
**Vague.** P9.5 (order pipeline hardening)
**Item.** 9.5.1 (persistance `allergens_snapshot` à la création de commande kiosk)

## Fichier verrouillé

- `app/Services/FrontendOrderService.php` (méthode `myOrderStore` / pipeline de création `order_items`)

## Lignes prévues

- Bloc de construction de chaque `OrderItem` dans la transaction de `myOrderStore` : après résolution de l'`Item` cible, lecture de `item->allergens()->pluck('code')` (SSOT = pivot `item_allergen` synchronisé par `AllergenService::projectFlags` + `ItemObserver`) et persistance sur `order_item->allergens_snapshot`.
- Aucune modification du flux de pricing / idempotency / state machine dans ce lock : modif purement additive.

## Raison

- Item 9.5.1 exige que le payload KDS expose la liste d'allergènes **telle qu'elle était au moment de la commande**, y compris si l'admin modifie le pivot a posteriori. L'unique point où l'information est connue de façon authoritative est la transaction de création `FrontendOrderService::myOrderStore`.
- Le handoff `HANDOFF_P9_5_2026-04-18.md` §7.1 confirme que toute modification de `FrontendOrderService` nécessite **gate clearance explicite + LOCK_A** avant code. Message utilisateur 2026-04-18 donne cette clearance.

## Coordination Track B

- Aucune intersection Track B : SYNC_PROTOCOL §2 classe `FrontendOrderService.php` comme **zone Track A exclusive**. Ce LOCK_A est donc uniquement un marqueur de gate interne Kiosk (et non un conflit inter-track).
- Les 3 BLOCKERs POS-9.4 (`BLOCKER_POS_9_4_2b`, `_5`, `_10`) concernent `OrderService.php` (pas `FrontendOrderService.php`). Après merge P9.5, Track B pourra reprendre sur `OrderService` sans collision avec ce lock-ci (voir `LOCK_A_P9_5_OrderService_*` séparé si touché).

## Tests obligatoires

- `tests/Feature/Orders/OrderAllergenSnapshotTest::test_kiosk_order_stores_allergens`
- Le flow E2E `KioskFullFlowE2ETest` (9.5.5) doit également exercer ce chemin.

## ETA libération

- Lock levé **immédiatement après** le commit `feat(kiosk/phase-9.5.1)` qui implémente la persistance. Le fichier retourne en frozen zone par défaut.

## Status

- **RELEASED (9.5.1 allergens_snapshot)** au commit `e5be3763f` le 2026-04-18 — modif additive confirmée sur `FrontendOrderService::myOrderStore`, tests verts sur `OrderAllergenSnapshotTest`.
- **RE-OPENED 2026-04-18** pour 9.5.5 : le verrou runtime `Cache::lock('frontend_order_idempotency_' . sha1($idempotencyKey), …)` doit être scopé à `(branch_id, idempotency_key)` afin d'aligner le comportement runtime avec l'index DB composite posé en 9.5.4. Voir `tasks/phase9/P9_5_BLOCKER_9.5.5_frontend_order_idempotency_lock_scope.md`. Modif additive uniquement (pas de pricing, pas de state machine, pas d'autres flows). Sera `RELEASED (idempotency lock scope)` au commit `test(kiosk/phase-9.5.5)` qui porte à la fois le fix runtime et la preuve E2E.
- **RELEASED (idempotency lock scope)** au commit `1f145bdbe` le 2026-04-18 — lock d'idempotence kiosk désormais scopé à `(branch_id, idempotency_key)` avec preuve `KioskFullFlowE2ETest`.
