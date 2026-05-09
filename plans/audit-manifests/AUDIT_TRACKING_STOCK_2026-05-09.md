# AUDIT MANIFEST — Tracking Commandes + Stock Management
**Date** : 2026-05-09
**Scope** : Order lifecycle tracking + Stock decrement/release/auto-rupture/quota + KDS status flow
**Branch** : `review/audit-tracking-stock`
**Cible** : `/ultrareview <this-PR>` doit auditer tout le périmètre listé ci-dessous

---

## §1 — Files in scope

### Order Tracking + State Machine
- `app/Domain/Order/OrderStateMachine.php` (9 states + transitions)
- `app/Services/OrderService.php` (focus `changeStatus` lockForUpdate iter13)
- `app/Services/KitchenDisplaySystemOrderService.php` (KDS list + changeStatus)
- `app/Services/Pos/PosCheckoutService.php` (status transitions POS)
- `app/Models/Order.php`
- `app/Models/OrderStatusTransition.php`
- `app/Http/Resources/OrderResource.php`
- `app/Http/Resources/OrderDetailsResource.php`
- `app/Http/Controllers/Backend/KitchenDisplaySystemOrderController.php`
- `app/Http/Controllers/Backend/OrderStatusScreenController.php` (OSS)

### Stock Management — Services
- `app/Services/Stock/StockService.php` (decrement + release + auto-rupture)
- `app/Services/Stock/ChoiceAvailabilityResolver.php`
- `app/Services/Menu/AvailabilityService.php` (toggle + quota + setMaxDailyQty)
- `app/Console/Commands/StockScanRupture.php` (preventive auto-86 cron, config-gated)
- `app/Console/Commands/ResetStaleDailyQuotaCommand.php` (iter13 stale reset cron)

### Stock Models + Migrations
- `app/Models/StockLevel.php` (BranchScope ✅)
- `app/Models/StockMovement.php` (BranchScope ✅ + immutable)
- `app/Models/ItemBranchAvailability.php`
- `database/migrations/2026_04_27_143120_create_stock_levels_table.php`
- `database/migrations/2026_04_27_143130_create_stock_movements_table.php`
- `database/migrations/2026_04_27_*_create_item_branch_availability_table.php`
- `database/migrations/2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php` (iter14 orphan retry)

### Order Lifecycle Helpers
- `app/Models/OrderItem.php` (BranchScope ✅ iter11)
- `app/Models/OrderRating.php` (post-iter11 unique fix)
- `app/Models/Item.php` (focus is_available + stock relation polymorphic)
- `app/Models/ItemVariation.php`, `ItemExtra.php`, `ItemAddon.php`

### Frontend KDS / OSS
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (i18n iter14)
- `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` (a11y iter14)
- `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`

### Tests existants
- `tests/Feature/OrderStateTransitionTest.php`
- `tests/Feature/KdsTransitionWhitelistTest.php`
- `tests/Feature/OrderStatusNoopSideEffectsTest.php`
- `tests/Feature/DeliveryBoyOrderStatusOrderingTest.php`
- `tests/Feature/KdsChangeStatusConcurrencyTest.php`
- `tests/Feature/KdsExpectedStatusConflictTest.php`
- `tests/Feature/Stock/WizardOptionStockSyncTest.php`
- `tests/Feature/Stock/StockReleaseOnCancelTest.php`
- `tests/Feature/Stock/StockReleaseOnRefundTest.php`
- `tests/Feature/Stock/StockConcurrentDecrementTest.php`
- `tests/Feature/Stock/StockMovementsAppendOnlyTest.php`
- `tests/Feature/Admin/StockRuptureDashboardEndpointsTest.php`
- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
- `tests/Feature/Fiscal/FiscalAllocOrphanRetryTest.php` (iter14 NEW)
- `tests/e2e/04-kds-status.spec.js`
- `tests/e2e/stock-rupture-sync.spec.js`

---

## §2 — Invariants à vérifier

### Order tracking
1. **State machine déterministe** — OrderStateMachine 9 states + ValidStatusTransition rule + IllegalTransitionException
2. **Race conditions** — `changeStatus(auth=true)` lockForUpdate iter13 (self-cancel race + payment race)
3. **OrderStatusTransition audit trail** — chaque transition recorded
4. **SealedOrderGuard** — empêche modif Z closed orders (NF525)
5. **KDS optimistic-lock 409** — concurrent KDS mutation rejected
6. **POS shortcut DELIVERED** — cashier permission gate

### Stock management
7. **DB::transaction + lockForUpdate** sur stock_levels.row (atomic decrement)
8. **Idempotency_key** sur stock_movements UNIQUE (replay-safe sha1 hash)
9. **Auto-rupture cascade** — on_hand=0 → is_available=false → ItemAvailabilityChanged broadcast
10. **Daily quota** — capped increment via SQL CASE, atomic flip is_available=false si consumed >= max_daily_qty
11. **Stale reset cron iter13** — `foodking:availability:reset-stale-quota` dailyAt 00:05 (branches sans traffic cross-day)
12. **Manual replenishment** — admin `AvailabilityService::toggle` cascade restore
13. **Compensating release** — OrderCanceled + RefundCreated → ReleaseStockOnOrderCanceled + ReleaseAvailabilityOnOrderCanceled (idempotent via released_qty ledger)
14. **StockMovement append-only** — booted() prevents update/delete
15. **BranchScope** — StockLevel + StockMovement + Order + OrderItem (post iter11+12)

---

## §3 — Questions critiques

### Order lifecycle
- Path complet POS → KDS → DELIVERED tracé ? OrderStateMachine couvre tous les chemins ?
- KDS limit-50 overflow flag : exposé UI ou silently truncated ? (V1.0.1 backlog)
- Status concurrent transitions : 2 admins simultanés → seule la 1ère gagne via lockForUpdate ?
- Self-cancel race iter13 : double-tap mobile → cashback 1 fois only ?
- Payment race iter13 : double-click "Pay" → 1 seul webhook dispatch ?

### Stock concurrency
- Concurrent decrement on même stock_level : 2 orders simultanés sur dernière unité → 1 OK + 1 StockUnavailableException ?
- Race quota flip : 2 orders au max-1 → both incrementent à max → seul 1 trigger is_available=false (CAS)
- Listener escalation iter12+13 : DecrementStock + ReleaseStock try/catch + Log::error + re-throw → Sentry breadcrumb ?
- Stock leak : si listener throw silent → order créé mais on_hand pas decrement ? (iter12 fix)

### Daily quota
- Lazy reset on next order : si jour 1 quota hit puis jour 2 sans traffic → counter stays frozen ? Cron iter13 fix this ?
- Midnight boundary : order 23:59:59 + order 00:00:01 (different dates) → reset window correct ?
- max_daily_qty changed admin → instant flip is_available ?

### Auto-rupture
- Trigger `syncItemAvailabilityForStockLevel` : on_hand <= 0 → flip + ItemAvailabilityChanged broadcast → kiosk + POS reçoivent
- Restock : on_hand > 0 → si reason=stock_rupture flip back → ItemAvailabilityChanged broadcast
- stock_scan_rupture cron : disabled by default per config — pas de window oversell brève si listener fail ?

### Fiscal orphan tracking iter14
- finalizePaidKioskOrder catch fiscal alloc fail → `fiscal_alloc_error_at` timestamp set + return (no throw)
- Cron `foodking:fiscal:retry-alloc` everyMinute : retry orders WHERE payment_status=PAID AND fiscal_seq=NULL AND fiscal_alloc_error_at IS NOT NULL
- Z-close pre-check : warns si orphans dans window (opened_at, closedAt]

---

## §4 — Acceptance criteria

CLEAN si :
- ✅ OrderStateMachine 9 states + transitions exhaustive
- ✅ lockForUpdate iter13 sur changeStatus + changePaymentStatus
- ✅ Stock atomic (DB::tx + lockForUpdate + idempotency_key)
- ✅ Listener escalation iter12+13 (DecrementStock + ReleaseStock try/catch + Log::error + re-throw)
- ✅ BranchScope sur 11 models post iter11+12 (Order, OrderItem, OrderPayment, KioskMachine, StockLevel, StockMovement, etc.)
- ✅ Cron stale daily quota reset iter13 active
- ✅ Cron fiscal retry alloc iter14 active
- ✅ Tests OrderStateTransition + KdsTransitionWhitelist + StockConcurrentDecrement + StockReleaseOnCancel + StockReleaseOnRefund + ProdLikeConcurrency + FiscalAllocOrphanRetry verts

HEAL si :
- ⚠️ KDS limit-50 overflow flag UI manquante (V1.0.1)
- ⚠️ Stale daily quota visibility log WARN (P2)
- ⚠️ Quota flip latency metric SLO manquant (P2)
- ⚠️ Frontend dedup correlation_id absent (V1.0.1)

BLOCK si :
- ❌ Stock leak listener silent throw (iter12 regression)
- ❌ Race condition status transition unhandled
- ❌ Auto-rupture cascade broken (event not broadcast)
- ❌ Fiscal orphan paid+seq=NULL non recovery

---

## §5 — Out of scope

- POS Caisse spécifique — cf `AUDIT_CAISSE_POS_2026-05-09.md`
- Kiosk Borne spécifique — cf `AUDIT_BORNE_KIOSK_2026-05-09.md`
- Sync infra (outbox + Pusher) — cf `AUDIT_SYNC_EVENTS_2026-05-09.md`
- Multi-tenant général — cf `AUDIT_GLOBAL_CROSS_SYSTEM_2026-05-09.md`
- Admin reports / catalogue management

---

## §6 — Reference

CLAUDE.md §5 LOOP, §8 NF525, §9 Multi-Tenant
PROJECT_BRAIN.md §7 #6 Order state machine + lockForUpdate, #8 Stock concurrency + listener escalation, #9 Daily quota stale reset cron, #16 Fiscal orphan retry GATE-FZH-ALLOC
ULTRA-AUDIT-STOCK-FLOW + ULTRA-AUDIT-ORDER-PATH iter13 (cf `plans/MASTER_ITER13_HARDENING_AUDIT_2026-05-09.md`)

— *Manifest pour `/ultrareview review/audit-tracking-stock`. Audit tracking + stock système.*
