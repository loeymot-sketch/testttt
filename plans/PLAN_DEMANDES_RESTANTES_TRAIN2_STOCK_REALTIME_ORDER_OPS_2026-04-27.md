# Plan Train 3/4 - Stock V2 + realtime + order ops - 2026-04-27

Scope:
- Ajouter stock quantitatif robuste.
- Centraliser queue.
- Livrer POS live board et handover.
- Renforcer KDS/OSS.

## 1. Gate source of truth stock

Gate obligatoire: `HG-STOCK-V2-SOURCE-OF-TRUTH`

Decision recommandee:
- `stock_levels` devient SSOT quantitatif.
- `item_branch_availability` reste compatibilite V1 pour disponibilite manuelle et projection legacy.
- Une tache de reconciliation garde les deux coherents pendant migration.

Interdiction:
- Ne pas avoir deux sources independantes qui peuvent diverger sans reconciliation.

## 2. Mission S0 - Stock ADR and backfill preflight

TASK_ID: `FK-REM-S0-STOCK-V2-ADR-BACKFILL`

Allowlist:

```text
docs/decisions/D-STOCK-V2-SOURCE-OF-TRUTH-2026-04-27.md
app/Console/Commands/StockV2PreflightCommand.php
tests/Feature/Stock/StockV2PreflightSentinelTest.php
reports/audit/FK_REM_S0_STOCK_V2_PREFLIGHT.md
```

Preflight doit lister:
- items actifs sans availability;
- availability out avec daily qty incoherent;
- commandes recentes non releasables;
- branches actives;
- choix `track_stock` initial.

Exit:
- Pas de migration tant que preflight n'est pas vert ou accepte.

## 3. Mission S1 - Migrations stock_levels / stock_movements

TASK_ID: `FK-REM-S1-STOCK-MODEL-MIGRATIONS`

Allowlist:

```text
database/migrations/2026_04_27_120000_create_stock_levels_table.php
database/migrations/2026_04_27_120100_create_stock_movements_table.php
app/Models/StockLevel.php
app/Models/StockMovement.php
tests/Feature/Stock/StockModelMigrationSentinelTest.php
```

Schema stock_levels:
- `branch_id`
- `item_id`
- `available_qty`
- `low_threshold`
- `status` in `in_stock|low|out|disabled`
- `track_stock`
- `version`
- unique `(branch_id,item_id)`

Schema stock_movements:
- `branch_id`
- `item_id`
- `delta`
- `reason`
- `reference_type`
- `reference_id`
- `actor_id`
- `correlation_id`
- `notes`

Validation:

```bash
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/Stock/StockModelMigrationSentinelTest.php
```

## 4. Mission S2 - StockService atomic decrement

TASK_ID: `FK-REM-S2-STOCK-ATOMIC-DECREMENT`

Gate:
- `HG-FROZEN-ORDERSERVICE-UNLOCK` si integration order services.

Allowlist:

```text
app/Services/Stock/StockService.php
app/Events/StockLevelChanged.php
app/Listeners/PersistStockLevelChangedToOutbox.php
app/Services/OrderService.php
app/Services/FrontendOrderService.php
tests/Feature/Stock/StockAtomicDecrementSentinelTest.php
tests/Feature/Stock/StockBranchIsolationSentinelTest.php
tests/Feature/Stock/StockDecrementInsideOrderTransactionSentinelTest.php
```

Invariant:
- Decrement doit etre dans la meme transaction que la creation commande.
- Condition DB atomique `available_qty >= qty`.
- Si rollback quote/order, rollback stock.

Validation:
- stock=1, deux commandes concurrentes -> une seule reussit.
- branche A n'affecte pas branche B.
- stale UI recoit 409.

## 5. Mission S3 - Release cancel/refund + reconciliation

TASK_ID: `FK-REM-S3-STOCK-RELEASE-RECONCILE`

Allowlist:

```text
app/Listeners/ReleaseStockOnOrderCancellation.php
app/Services/Stock/StockService.php
app/Console/Commands/StockReconcileCommand.php
app/Console/Kernel.php
tests/Feature/Stock/StockReleaseOnCancelSentinelTest.php
tests/Feature/Stock/StockReconciliationSentinelTest.php
```

Definition:
- Cancel/reject/refund release idempotent.
- `correlation_id` empeche double release.
- Reconciliation horaire detecte drift.

## 6. Mission S4 - Stock realtime + UI rupture

TASK_ID: `FK-REM-S4-STOCK-REALTIME-UI-RUPTURE`

Allowlist:

```text
routes/channels.php
resources/js/components/shared/RuptureBadge.vue
resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue
resources/js/components/admin/pos/ItemComponent.vue
resources/js/store/modules/kioskMenu.js
resources/js/store/modules/posOrder.js
tests/js/shared/__tests__/RuptureBadge.spec.js
tests/js/kioskRuptureUiContractSpec.js
tests/js/posRuptureOverrideSpec.js
tests/Feature/Stock/StockRealtimeFanoutSentinelTest.php
```

UX contract:
- `out`: rouge, texte `RUPTURE`, item visible, tap desactive kiosk.
- `low`: orange, "Plus que N".
- `disabled`: gris, indisponible.
- POS: override staff seulement avec audit.

## 7. Mission O1 - QueueNumberAllocator central

TASK_ID: `FK-REM-O1-QUEUE-ALLOCATOR-CENTRAL`

Gate:
- `HG-FROZEN-ORDERSERVICE-UNLOCK`.

Allowlist:

```text
app/Services/Order/QueueNumberAllocator.php
app/Services/OrderService.php
app/Services/FrontendOrderService.php
tests/Feature/Order/QueueNumberAllocatorContractTest.php
tests/Feature/Sentinels/QueueNumberMicrotimeFallbackRemovedSentinelTest.php
```

Validation:
- DB uniqueness garde la verite.
- Retry duplicate key limite.
- Aucun fallback microtime.
- Business_date scope conserve.

## 8. Mission O2 - POS live board

TASK_ID: `FK-REM-O2-POS-LIVE-BOARD`

Objectif:
- POS voit commandes POS/kiosk/web en cours.
- Colonnes: Nouveau, Accepte, Preparation, Pret, Remis.

Allowlist:

```text
app/Http/Controllers/Admin/OrderLiveController.php
routes/api.php
resources/js/components/admin/orders/OrderLiveBoardComponent.vue
resources/js/store/modules/orderLive.js
tests/Feature/Sentinels/PosLiveListContainsKioskOrdersSentinelTest.php
tests/Feature/Sentinels/OrderLiveAuthzAndBranchScopeSentinelTest.php
tests/js/orderLiveBoardComponentSpec.js
```

Authz:
- POS/KDS/supervisor.
- Branch-scoped strict.

Validation:
- Kiosk order creee -> POS endpoint la retourne.
- Changement status -> board update.

## 9. Mission O3 - Handover/remise client

TASK_ID: `FK-REM-O3-ORDER-HANDOVER`

Gate:
- `HG-HANDOVER-SEMANTICS`.
- Recommandation: garder enum `DELIVERED`, ajouter colonnes `handed_over_at`, `handed_over_by_user_id` si necessaire.

Allowlist:

```text
database/migrations/2026_04_27_130000_add_handover_fields_to_orders.php
app/Http/Controllers/Admin/OrderHandoverController.php
app/Services/OrderService.php
app/Events/OrderHandedOver.php
app/Listeners/PersistOrderHandedOverToOutbox.php
routes/api.php
resources/js/components/admin/orders/HandoverButton.vue
tests/Feature/Order/OrderHandoverSentinelTest.php
tests/Feature/Order/OrderRealtimeFanoutSentinelTest.php
```

Validation:
- READY/PREPARED -> handover -> DELIVERED.
- KDS ne peut pas faire action hors permission.
- OSS retire la commande.
- Audit fiscal coherent.

## 10. Mission O4 - KDS/OSS fanout and SLO

TASK_ID: `FK-REM-O4-KDS-OSS-FANOUT-SLO`

Allowlist:

```text
app/Listeners/PersistOrderStatusChangedToOutbox.php
app/Services/Observability/SyncMetricsRecorder.php
resources/js/store/modules/kds.js
tests/Feature/Observability/RealtimeSloRespectedSentinelTest.php
tests/js/kdsReactsToReconnectStorm.spec.js
tests/Playwright/sync-flow-kds-bump-oss-update.spec.js
```

SLO:
- Order created -> POS live < 500 ms p95.
- KDS bump -> OSS < 500 ms p95.
- Stock change -> POS/kiosk < 500 ms p95.

## 11. Closeout Train

Rapport attendu:

```text
reports/audit/FK_REM_TRAIN2_STOCK_REALTIME_ORDER_OPS_CLOSEOUT_2026-04-27.md
```

Doit contenir:
- tests concurrence stock;
- branch isolation;
- queue duplicate proof;
- live board proof;
- KDS/OSS proof;
- risques residuels hardware.
