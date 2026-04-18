# LOCK_A — `OrderItem.php` + migration `order_items.allergens_snapshot`

**Date.** 2026-04-18
**Track.** A (Kiosk)
**Vague.** P9.5
**Item.** 9.5.1

## Fichiers verrouillés

- `app/Models/OrderItem.php` (ajout du cast `allergens_snapshot => 'array'`)
- `database/migrations/<TS>_add_allergens_snapshot_to_order_items.php` (nouveau)

## Lignes prévues

- Migration ALTER ajoutant une colonne `allergens_snapshot JSON NULL` sur `order_items`, indexable MySQL, rollback-safe (`up()` + `down()` symétriques).
- Model : ajout `protected $casts[] = 'allergens_snapshot' => 'array';` et `fillable[]` si le flow le requiert côté `FrontendOrderService::myOrderStore`.

## Raison

- Support de stockage pour le snapshot immuable des allergènes (item 9.5.1). La colonne n'est ni lue ni écrite par les flows existants (POS/Admin) : ajout purement additif.
- `OrderItem.php` étant un modèle Eloquent partagé (POS lit aussi `order_items` via `OrderItemResource`), on pose un lock pour signaler l'extension à Track B.

## Coordination Track B

- Aucun LOCK_B_* actif sur `OrderItem.php` ou `order_items` au 2026-04-18.
- Les 3 BLOCKERs POS-9.4 ne touchent pas `order_items` (ils concernent `orders.fiscal_sequence_no`, `audit_logs`, `Z reports`). Aucune collision attendue.
- Broadcast P9.5 merged inclura la nouvelle shape `OrderItemResource.allergens_snapshot: string[]|null` pour que Track B puisse consommer si besoin.

## Tests obligatoires

- `tests/Feature/Orders/OrderAllergenSnapshotTest::test_kiosk_order_stores_allergens` (assertion DB + cast array)
- Test rollback-safe migration : `php artisan migrate:fresh && php artisan migrate:rollback && php artisan migrate` reste vert.

## ETA libération

- Lock levé après le commit migration + model cast (probablement fusionné avec 9.5.1 ou posé juste avant).

## Status

- **RELEASED (`this commit`)** le 2026-04-18.
- Modèle + migration validés par `OrderAllergenSnapshotTest`.
