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

- **RELEASED (`this commit`)** le 2026-04-18.
- Modif additive confirmée sur `FrontendOrderService::myOrderStore`; tests verts sur `OrderAllergenSnapshotTest`.
