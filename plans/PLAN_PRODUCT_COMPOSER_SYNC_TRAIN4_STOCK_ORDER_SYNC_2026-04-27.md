# TRAIN 4 - Stock V2 + Order Sync

TASK_ID: PRODUCT-COMPOSER-SYNC-04-STOCK-ORDER-SYNC  
MODE: gated execute  
GOAL: stock atomique partage produit + choix, rupture POS/kiosk, queue/order sync.

## 1. Gates

- `HG-STOCK-STOCKABLE-SCOPE`
- `HG-FROZEN-ORDERSERVICE-UNLOCK`
- `HG-HANDOVER-SEMANTICS`

## 2. Stock schema recommande

`stock_levels`:

- `id`
- `branch_id`
- `stockable_type` enum `item|variation|extra|addon_item`
- `stockable_id`
- `available_qty`
- `low_threshold`
- `status` enum `in_stock|low|out|disabled|infinite`
- `track_stock`
- `version`
- timestamps
- unique `(branch_id, stockable_type, stockable_id)`

`stock_movements`:

- `id`
- `branch_id`
- `stockable_type`
- `stockable_id`
- `delta`
- `reason` enum `order|cancel|refund|adjust|recount|loss|return|reset`
- `reference_type`
- `reference_id`
- `actor_id`
- `correlation_id`
- `notes`
- `created_at`

## 3. Order integration

Apres quote seal et avant commit final:

1. PricingService resout les lignes item/variation/extra/addon.
2. StockService construit la liste des stockables consommes.
3. Decrement atomique DB avec `available_qty >= qty`.
4. Si echec: 409 avec message item/choix en rupture.
5. Events apres commit: `StockLevelChanged`.

Release:

- Cancel/reject/refund augmente uniquement les quantites deja consommees.
- Idempotence par `correlation_id` et ledger ligne.

## 4. UI rupture

Kiosk:

- tuile visible,
- badge rouge `RUPTURE`,
- tap desactive,
- produit non masque.

POS:

- badge rupture,
- modal override staff si autorise,
- audit log obligatoire.

Dashboard:

- stock manager par categorie,
- filtre par stockable type,
- ajustement + raison,
- import/export.

## 5. Order ops

Apres stock:

- queue allocator central,
- POS live board,
- handover/remise client,
- KDS/OSS sync.

## 6. Tests

- `StockLevelStockableMigrationTest`
- `StockAtomicDecrementChoiceSentinelTest`
- `StockReleaseIdempotentSentinelTest`
- `StockBranchIsolationSentinelTest`
- `KioskRuptureBadgeSpec`
- `PosRuptureOverrideSpec`
- `PosLiveListContainsKioskOrdersSentinelTest`
- `OrderHandoverSentinelTest`

## 7. Exit

- Deux commandes concurrentes sur stock=1: une seule passe.
- Rupture produit/choix visible POS+kiosk.
- Annulation remet le stock une seule fois.
- POS live board voit commandes kiosk.
