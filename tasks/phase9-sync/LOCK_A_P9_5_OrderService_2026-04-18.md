# LOCK_A — `app/Services/OrderService.php` (noop par défaut)

**Date.** 2026-04-18
**Track.** A (Kiosk)
**Vague.** P9.5

## Fichier couvert

- `app/Services/OrderService.php`

## Périmètre prévu

- **Aucune modification programmée** dans P9.5 selon le plan et le handoff : les items 9.5.1 (FrontendOrderService), 9.5.3 (Job dédié), 9.5.4 (migration pure), 9.5.6 (Requests) et 9.5.7 (Vue POS) sont conçus pour **ne pas toucher `OrderService.php`**.
- Ce lock est **posé à titre préventif / inter-track** pour signaler à Track B que, pendant P9.5, aucune PR Track B ne doit toucher `OrderService.php` (3 BLOCKERs POS déjà dépendants de ce lock : `BLOCKER_POS_9_4_2b`, `BLOCKER_POS_9_4_5`, `BLOCKER_POS_9_4_10`).

## Raison

- Verrou strict sur zone partagée selon SYNC_PROTOCOL §4.
- Si, au cours de P9.5, un item nécessite **impérativement** d'éditer `OrderService.php`, arrêter, mettre à jour ce lock avec : lignes touchées + raison + test de non-régression, et escalader via `tasks/phase9/P9_5_BLOCKER_OrderService_<id>.md`.

## Coordination Track B

- Lock informé par CROSS_TRACK_STATUS §"Locks shared actifs".
- À la fin de P9.5 + merge sur main + `BROADCAST_P9_5_MERGED_<DATE>.md`, ce lock devient `RELEASED` et Track B peut reprendre les 3 items POS-9.4.2b / 9.4.5 / 9.4.10.

## Tests obligatoires

- Si `OrderService.php` n'est pas touché : aucun test supplémentaire requis spécifique à ce lock. La suite PHPUnit complète doit rester verte.
- Si touché (après escalation) : test PHPUnit ciblé de non-régression + test Feature de la nouvelle logique + revérification `FrontendSurfaceFilteringTest`.

## ETA libération

- Lock levé à la fin de P9.5 (merge sur main + broadcast).

## Status

- **ACTIVE — PREVENTIVE (file unchanged)** depuis 2026-04-18.
- **RELEASED (preventive, no OrderService edit landed, this commit)** le 2026-04-18 — aucun diff n'a touché `app/Services/OrderService.php` pendant P9.5 ; lock clos au closeout de vague.
