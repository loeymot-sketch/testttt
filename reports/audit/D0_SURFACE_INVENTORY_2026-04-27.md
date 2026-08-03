# D0 Surface Inventory — Production Live Validation — 2026-04-27

Verdict: `D0_INVENTORY_COMPLETE_FOR_D1_D13`

Scope: documentation-only inventory from current code. No product code was edited.

## Executive Inventory

| Surface | Primary files | Test anchor status | Notes |
| --- | --- | --- | --- |
| Kiosk | `resources/js/components/frontend/kiosk/*.vue` | Strong `data-testid` coverage on idle/categories/cart/payment/waiting/confirmation/errors; weaker on login and wizard step internals | D1/D4 must expand full visual + functional path coverage. |
| POS | `resources/js/components/admin/pos/*.vue` | `data-testid` mostly limited to receipt and counter-collect details | D2/D5 must add black-box locators or improve stable selectors if needed. |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Realtime bindings present, testids not visible in preflight grep | D3/D6 must decide locator strategy and visual baselines. |
| OSS | `resources/js/components/admin/orderStatusScreen/*.vue` | Realtime bindings present in `PreparingAndReadyComponent.vue`; testids sparse | D3 must cover big-screen readability. |
| Dashboard / catalogue management | `resources/js/components/admin/items/**`, `resources/js/components/admin/settings/ItemCategory/**`, `resources/js/components/admin/dashboard/**` | Composer UI specs exist; visual coverage not complete | D9/D11 must cover CRUD/authz/branch/photo paths. |

## Kiosk Vue Components

Inventory source: `find resources/js/components/frontend/kiosk -type f`.

Main screens:
- `KioskAppComponent.vue`: kiosk shell, theme toggle, offline conflict CTA.
- `KioskIdleScreenComponent.vue`: root `kiosk-idle-root`, language selector, accessibility drawer entry, order type chooser, dine-in/takeaway buttons.
- `KioskLoginComponent.vue`: machine login and auto-login path. No `data-testid` found in preflight grep.
- `KioskCategoriesComponent.vue`: root `kiosk-categories-root`, sidebar, products grid, quick strip, filters, product cards, bottom bar, pay button, abandon.
- `KioskWizardComponent.vue`: wizard header allergen marker and composer/heuristic runtime steps. Step children include `KioskStepPainComponent.vue`, `KioskStepViandeComponent.vue`, `KioskStepSauceComponent.vue`, `KioskStepGarnituresComponent.vue`, `KioskStepSupplementsComponent.vue`, `KioskStepTailleComponent.vue`, `KioskStepMenuComponent.vue`.
- `KioskOrderSummaryComponent.vue`: root `kiosk-order-summary-root`, item/price/total/quantity controls.
- `KioskCartComponent.vue`: root `kiosk-cart-root`, order type, item list, promo, loyalty, checkout, quote error.
- `KioskLoyaltyComponent.vue`: register name/phone/email testids.
- `KioskUpsellComponent.vue`: root/loading/grid/cards/add+continue/skip/autoskip bar.
- `KioskPaymentComponent.vue`: root `kiosk-payment-root`, back, total, card/cash/ticket restaurant methods, processing, TPE overlay, confirm, error.
- `KioskCashInstructionComponent.vue`: title, order number, amount, understood CTA.
- `KioskWaitingComponent.vue`: root `kiosk-waiting-root`, event subscription to `OrderCreated` and `OrderStatusChanged`.
- `KioskConfirmationComponent.vue`: root/title/card/order number/total/print/home/print receipt.
- Error screens: `KioskErrorNetworkComponent.vue`, `KioskErrorMenuUnavailableComponent.vue`, `KioskErrorProductRemovedComponent.vue`, `KioskErrorPaymentRefusedComponent.vue`, shared `KioskErrorLayoutComponent.vue`.
- Support: `KioskInactivityOverlayComponent.vue`, `KioskOfflineConflictModalComponent.vue`, `KioskPromoCarouselComponent.vue`, `KioskToastComponent.vue`, design-system atoms under `ds/`.

Key kiosk `data-testid` groups observed:
- Idle: `kiosk-idle-root`, `kiosk-idle-lang-selector`, `kiosk-idle-a11y-btn`, `kiosk-idle-logo`, `kiosk-idle-brand`, `kiosk-idle-touch-btn`, `kiosk-order-type-chooser`, `kiosk-order-type-dine-in`, `kiosk-order-type-takeaway`.
- Catalogue: `kiosk-categories-root`, `kiosk-categories-breadcrumb`, `kiosk-categories-products`, `kiosk-product-card-*`, `kiosk-product-add-*`, `kiosk-product-price-*`, `kiosk-categories-pay`, `kiosk-categories-abandon`.
- Cart: `kiosk-cart-root`, `kiosk-cart-items`, `kiosk-cart-total`, `kiosk-cart-promo-input`, `kiosk-cart-checkout`, `kiosk-cart-quote-error`.
- Payment: `kiosk-payment-root`, `kiosk-payment-method-card`, `kiosk-payment-method-cash`, `kiosk-payment-method-tr`, `kiosk-payment-confirm`.
- Cash and confirmation: `kiosk-cash-order-number`, `kiosk-cash-amount`, `kiosk-confirmation-root`, `kiosk-confirmation-number`, `kiosk-confirmation-cta-home`.
- Offline/a11y: `kiosk-offline-conflict-modal`, `kiosk-a11y-drawer`, `kiosk-vkeyb`.

## POS Vue Components

Inventory source: `find resources/js/components/admin/pos -type f`.

Files:
- `PosComponent.vue`: main POS shell, item grid, cart, order creation, counter-collect panel, realtime `OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `ItemAvailabilityChanged`.
- `ItemComponent.vue`: POS item card.
- `PaymentComponent.vue`: POS payment modal.
- `ReceiptComponent.vue`: receipt print trigger and hidden print button.
- `FloorplanComponent.vue`: dining table state and actions.
- `ParkedOrdersComponent.vue`: parked order flow.
- `CreateCustomerAddressComponent.vue`: delivery/customer address support.
- `SkeletonGrid.vue`: loading layout.
- `ReceiptDuplicataMarker.vue`: receipt duplicate marker.

Observed POS `data-testid`:
- `receipt-print-trigger`, `receipt-hidden-print-button`.
- `kiosk-cash-expand-*`, `kiosk-cash-details-*` inside `PosComponent.vue`.

Gap: POS has far less stable testid coverage than kiosk. D2/D5 must either add scoped testids or use accessible locators cautiously.

## KDS / OSS / Dashboard Components

KDS:
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- Realtime listeners: `OrderStatusChanged`, `OrderCreated`, `OrderPaidAtCounter`, `ItemAvailabilityChanged`, `OrderTableChanged`.
- API: `/api/admin/kds-order`, `/change-status/{order}`, `/items`, `/sync`.

OSS:
- `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue`
- `PreparingAndReadyComponent.vue`: listens to `OrderStatusChanged` and `OrderCreated`.
- `PopularItemComponent.vue`.
- API: `/api/admin/oss-order`, `/popular-items`.

Dashboard/catalog:
- Dashboard: `resources/js/components/admin/dashboard/*.vue` including realtime report, SLA alerts, audit trail.
- Product/category CRUD: `resources/js/components/admin/items/**`, `resources/js/components/admin/settings/ItemCategory/**`.
- Composer: `ProductComposerSummaryComponent.vue`, `items/composer/ProductComposerEditorComponent.vue`, `StepEditorComponent.vue`, `StepPreviewComponent.vue`.

## API Routes Inventory

`php artisan route:list --path=api` was attempted and failed before route rendering due `ReflectionException: Class "App\\Http\\PaymentGateways\\Gateways\\Senangpay" does not exist`. Route inventory below is therefore source-based from `routes/api.php`.

Global middleware groups:
- Health: no auth, lines 134-136.
- Auth: `installed`, `apiKey`, `localization`, throttles by endpoint, lines 145-229.
- Profile: `installed`, `apiKey`, `auth:sanctum`, `localization`, lines 232-236.
- Admin: `installed`, `apiKey`, `auth:sanctum`, `localization`, `throttle:admin-mutation`, lines 239-940.
- Frontend: `installed`, `apiKey`, `localization`, lines 942-1134.
- Table: `installed`, `apiKey`, `localization`, lines 1138-1153.

Critical admin route groups:
- Menu projection: `GET /api/admin/menu-projection`, line 246.
- Availability toggle: `POST /api/admin/menu/availability/toggle`, line 248.
- Category CRUD: `/api/admin/setting/item-category/*`, lines 298-307.
- Item CRUD/photo/variations/extras/addons: `/api/admin/item/*`, lines 634-665.
- Composer profile/steps: `/api/admin/composer/*`, lines 668-680, permissions `catalog.compose` and `catalog.publish`.
- POS quote/store/counter-collect/floorplan/cash drawer/NFC: `/api/admin/pos/*`, lines 683-748.
- POS order management: `/api/admin/pos-order/*`, lines 752-763.
- Online/table order management: lines 766-784.
- Dashboard rollups: `/api/admin/dashboard/*`, lines 823-839.
- KDS: `/api/admin/kds-order/*`, lines 899-904.
- Observability: `/api/admin/observability/*`, lines 908-912.
- OSS: `/api/admin/oss-order/*`, lines 914-916.
- Fiscal Z/X: `/api/admin/fiscal/*`, lines 927-938.

Critical frontend route groups:
- Frontend order quote/store/show/payment-confirm: `/api/frontend/order/*`, lines 979-986, `auth:sanctum`, quote/store throttled by `kiosk-orders`.
- Frontend item/catalog: `/api/frontend/item/*`, lines 995-1002.
- Frontend item category: `/api/frontend/item-category/*`, lines 1005-1007.
- Coupon checking: line 1026, throttle.
- Device token kiosk: line 1047.
- Loyalty: lines 1060-1070.
- Kiosk telemetry/event/menu/pricing/promo/upsell: lines 1076-1122.
- CSP report: line 1133.

## Laravel Events, Listeners, Outbox

Authoritative listener map: `app/Providers/EventServiceProvider.php`.

Order lifecycle:
- `OrderCreated` -> `SendFcmOnOrderCreated`, `PersistOrderCreatedToOutbox`, `DecrementItemAvailabilityOnOrder`, `DecrementStockOnOrderCreated`.
- `OrderStatusChanged` -> `AwardLoyaltyPointsOnDelivery`, `SendFcmOnOrderStatusChange`, `PersistOrderStatusChangedToOutbox`.
- `OrderPaidAtCounter` -> `PersistOrderPaidAtCounterToOutbox`.
- `OrderCanceled` -> `ReleaseAvailabilityOnOrderCanceled`, `ReleaseStockOnOrderCanceled`.
- `RefundCreated` -> `ReleaseAvailabilityOnRefundCreated`, `ReleaseStockOnRefundCreated`.
- `OrderTableChanged` -> `PersistOrderTableChangedToOutbox`.

Catalog/stock:
- `ItemAvailabilityChanged` -> `BumpMenuSnapshotOnItemAvailabilityChanged`, `InvalidateKioskMenuCacheOnItemAvailabilityChanged`, `PersistCatalogChangedToOutbox`, `PersistItemAvailabilityChangedToOutbox`.
- `ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted` -> kiosk cache invalidation and catalog outbox.
- Event files also include `CatalogChanged.php`, `ComposerProfilePublished.php`, `StockLevelChanged.php`.

Outbox pattern:
- Table/model: `domain_events`, `App\Models\DomainEvent`.
- Dispatcher: `app/Jobs/DispatchDomainEventsJob.php`.
- Contract guard: `app/Domain/Events/EventContract.php`.
- Rescue/retry commands: `app/Console/Commands/OutboxRescueCommand.php`, `OutboxRetryFailedCommand.php`.
- Persist listeners create outbox rows and dispatch `DispatchDomainEventsJob` in `DB::afterCommit`.

Broadcast names observed:
- `OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `OrderTableChanged`, `CatalogChanged`, `ItemAvailabilityChanged`.

## Echo Channels

Auth route: `app/Providers/BroadcastServiceProvider.php` sets `/api/broadcasting/auth` with Sanctum.

Channels in `routes/channels.php`:
- `App.Models.User.{id}`: authorized only for same user id.
- `branch.{branchId}`: kiosk machine token constrained to its machine branch; branch `0` admin can subscribe to all; regular staff constrained to own branch.

Runtime JS:
- Echo setup: `resources/js/bootstrap.js`, Pusher-compatible config and Bearer token auth.
- Event contract client: `resources/js/services/eventContract.js`, `onEvent`/`onEvents` subscribe to branch channel and parse V1 envelope.
- Consumers:
  - Kiosk waiting: `OrderCreated`, `OrderStatusChanged`.
  - Kiosk menu store: `ItemAvailabilityChanged`.
  - POS: `OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `ItemAvailabilityChanged`.
  - KDS: `OrderStatusChanged`, `OrderCreated`, `OrderPaidAtCounter`, `ItemAvailabilityChanged`, `OrderTableChanged`.
  - OSS: `OrderStatusChanged`, `OrderCreated`.

## Critical Tables and Migrations

Orders and lifecycle:
- `orders`: `2022_11_17_110810_create_orders_table.php`.
- `order_items`: `2022_11_17_110832_create_order_items_table.php`.
- `order_addresses`: `2023_02_20_180253_create_order_addresses_table.php`.
- `order_status_transitions`: `2026_04_15_230000_create_order_status_transitions_table.php`.
- `order_quotes`: `2026_04_25_190000_create_order_quotes_table.php`.
- `queue_number`: `2026_03_06_170846_add_queue_number_to_orders_table.php`, unique branch queue migration `2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`.
- `fiscal_sequence_no`: `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php`.
- `source_surface`: `2026_03_26_075905_add_source_surface_to_orders_table.php`.
- `idempotency_key`: `2026_03_25_002938_add_idempotency_key_to_orders_table.php`, branch scope `2026_04_18_140003_scope_idempotency_key_to_branch.php`.

Catalog/composer/stock:
- `item_categories`: `2022_11_17_110428_create_item_categories_table.php`.
- `items`: `2022_11_17_110514_create_items_table.php`.
- `item_attributes`: `2022_11_17_110541_create_item_attributes_table.php`.
- `item_variations`: `2022_11_17_110621_create_item_variations_table.php`.
- `item_extras`: `2022_11_17_110650_create_item_extras_table.php`.
- `item_addons`: `2022_11_17_120627_create_item_addons_table.php`, role migration `2026_04_27_143140_add_role_to_item_addons_table.php`.
- `item_branch_availability`: `2026_04_15_230100_create_item_branch_availability_table.php`, FKs `2026_04_18_140001_add_fks_to_item_branch_availability.php`.
- `item_wizard_profiles`: `2026_04_27_143100_create_item_wizard_profiles_table.php`.
- `item_wizard_steps`: `2026_04_27_143110_create_item_wizard_steps_table.php`.
- `stock_levels`: `2026_04_27_143120_create_stock_levels_table.php`.
- `stock_movements`: `2026_04_27_143130_create_stock_movements_table.php`.
- `composition_snapshot`: `2026_04_22_000020_add_composition_snapshot_to_order_items.php`.

Fiscal/audit/sync:
- `audit_logs`: `2026_04_22_000002_create_audit_logs_table.php`, chain index `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php`.
- `z_reports`: `2026_04_22_000003_create_z_reports_table.php`.
- `domain_events`: `2026_04_15_200000_create_domain_events_table.php`.
- `sync_metrics`: `2026_04_23_220000_create_sync_metrics_table.php`.

Kiosk/POS support:
- `kiosk_machines`: `2025_02_21_110459_create_kiosk_machines_table.php`.
- `pos_parked_orders`: `2026_04_20_200000_create_pos_parked_orders_table.php`.
- `dining_tables`: `2023_09_05_133748_create_dining_tables_table.php`, occupancy extension `2026_04_20_210000_extend_dining_tables_occupancy.php`.
- `printers`: `2026_04_20_210000_create_printers_table.php`.

## Enums

`app/Enums/PaymentStatus.php`:
- `PAID = 5`
- `UNPAID = 10`
- `PENDING_COUNTER = 15`
- `REFUNDED = 20`

`app/Enums/OrderStatus.php`:
- `PENDING = 1`
- `ACCEPT = 4`
- `PREPARING = 7`
- `PREPARED = 8`
- `OUT_FOR_DELIVERY = 10`
- `DELIVERED = 13`
- `CANCELED = 16`
- `REJECTED = 19`
- `RETURNED = 22`

`app/Enums/OrderType.php`:
- `DELIVERY = 5`
- `TAKEAWAY = 10`
- `POS = 15`
- `DINING_TABLE = 20`
- `KIOSK = 25`

`app/Enums/PosPaymentMethod.php`:
- `CASH = 1`
- `CARD = 2`
- `MOBILE_BANKING = 3`
- `OTHER = 4`
- `TICKET_RESTAURANT = 5`
- `COUNTER_DEFERRED = 6`

`app/Enums/Source.php`:
- `WEB = 5`
- `APP = 10`
- `POS = 15`

## Preflight Tooling Findings

- `php artisan route:list --path=api` currently fails due missing `App\Http\PaymentGateways\Gateways\Senangpay`. This does not prove runtime kiosk/POS failure, but it blocks automated route introspection and must be in the D0 backlog.
- `axe-core` exists in `package.json`, so D1/D2/D3 can implement axe checks without adding a dependency.
- Current test inventory includes PHPUnit Feature/Unit, Vitest UI, and Playwright E2E. Full production-live D missions still need run-many expansion.
