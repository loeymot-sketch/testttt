# FoodKing — Project Onboarding Index

> Generated from repo scan 2026-04-12. Evidence grades: **code-confirmed**, **doc-stated**, **inferred**.

---

## 1. Surfaces (user-facing products)

| Surface | Primary actor | Key controller namespace | Auth mechanism | Evidence |
|---------|---------------|--------------------------|----------------|----------|
| **Admin SPA** | Admin / Manager | `App\Http\Controllers\Admin` (70 controllers) | Sanctum + Spatie roles | code-confirmed |
| **POS** (point-of-sale) | Cashier / Manager | `Admin\PosController`, `PosOrderController` | Sanctum (branch-scoped) | code-confirmed |
| **Kiosk** | Customer (self-service) | `Frontend\OrderController`, `Auth\KioskMachineLoginController` | Sanctum `kioskToken` (ability `kiosk:order`) | code-confirmed |
| **KDS** (kitchen display) | Chef | `Admin\KitchenDisplaySystemController` | Sanctum (branch-scoped) | code-confirmed |
| **OSS** (order status screen) | Public / passive | `Admin\OrderStatusScreenController` | `ApiKeyMiddleware` (read-only) | code-confirmed |
| **Table ordering** | Dine-in customer | `Table\OrderController`, `Table\DiningTableController` | Sanctum | code-confirmed |
| **Frontend / Web** | Customer | `Frontend\*` (24 controllers) | Sanctum / guest | code-confirmed |

---

## 2. Core services (business logic layer)

| Service | Role | Frozen? | Evidence |
|---------|------|---------|----------|
| `OrderService` | Backend SSOT for price recalc, order mutations, POS | **Active** — critical | code-confirmed |
| `FrontendOrderService` | Kiosk / web order creation (shared `orders` table) | **Active** — critical | code-confirmed |
| `KitchenDisplaySystemOrderService` | KDS status transitions (ACCEPT→PREPARING→PREPARED) | **Active** | code-confirmed |
| `OrderStatusScreenOrderService` | OSS read-only queries | **Active** | code-confirmed |
| `CouponService` | Backend coupon validation (date/fixed/percentage, cap) | **Active** | code-confirmed |
| `TransactionService` | Payment recording | **Active** | code-confirmed |
| `PaymentService` / `PaymentManagerService` | Payment gateway orchestration | **Frozen** (gateways) | doc-stated + code-confirmed |
| `PushNotificationService` / `FcmNotificationService` | FCM push | **Frozen** (internal logic) | doc-stated + code-confirmed |
| `FirebaseService` | Firebase integration | **Active** (integration) | code-confirmed |
| `LoyaltySetupService` | Loyalty points config | **Active** | code-confirmed |

**86 total service files** under `app/Services/` (CRUD, notifications, SMS, analytics, settings).

---

## 3. Models (data layer)

| Model cluster | Key models | Relationships (confirmed) |
|---------------|-----------|---------------------------|
| **Order domain** | `Order`, `FrontendOrder`, `OrderItem`, `OrderAddress`, `OrderCoupon`, `Transaction` | `Order` hasMany items, belongsTo user/branch/DiningTable, hasOne address/coupon/transaction |
| **Catalog** | `Item`, `ItemCategory`, `ItemVariation`, `ItemExtra`, `ItemAddon`, `ItemAttribute` | `Item` belongsTo category/tax, hasMany variations/extras/addons, belongsToMany offers |
| **Offers** | `Offer`, `OfferItem` | belongsToMany items via `offer_items` |
| **Users & auth** | `User`, `KioskMachine`, `Address` | `User` hasOne role, hasMany addresses/orders; `KioskMachine` belongsTo user/branch |
| **Branch** | `Branch`, `BranchScope` (global scope) | Implicit branch isolation via `BranchScope` on most queries |
| **Payments / gateways** | `PaymentGateway`, `SmsGateway`, `GatewayOption` | morphMany / morphTo |
| **Messaging** | `Message`, `MessageHistory` | belongsTo user |
| **CMS / Analytics** | `Page`, `MenuSection`, `Analytic`, `AnalyticSection`, `Slider` | hasMany / belongsTo |
| **Loyalty** | `LoyaltyTransaction` | belongsTo user/order |
| **Notifications** | `PushNotification`, `Notification`, `NotificationAlert` | belongsTo role/user |

**~49 distinct model classes** + `BranchScope` (global scope).

---

## 4. Events / Listeners / Jobs pipeline

| Event | Listeners | Evidence |
|-------|-----------|----------|
| `OrderCreated` | `SendFcmOnOrderCreated` + mail/sms/push notification listeners | code-confirmed |
| `OrderStatusChanged` | `SendFcmOnOrderStatusChange`, `AwardLoyaltyPointsOnDelivery` + mail/sms/push | code-confirmed |
| `ItemAvailabilityChanged` | *(not scanned — exists as event)* | code-confirmed (file exists) |
| `Send*` events (13) | Corresponding `Send*Notification` listeners | code-confirmed |

**1 job**: `SendFcmNotificationJob` (queued FCM).

---

## 5. Middleware stack

| Middleware | Purpose | Evidence |
|------------|---------|----------|
| `ApiKeyMiddleware` | Public routes (OSS, frontend) require `apiKey` from `config` | code-confirmed |
| `Installed` | Checks `storage/installed` flag | code-confirmed |
| `Authenticate` (Sanctum) | Token-based auth for admin/kiosk/frontend | code-confirmed |
| `JsonMiddleware` | Force JSON responses on API | code-confirmed |
| `SetLocale` / `localization` | i18n | code-confirmed |
| Standard Laravel | CSRF, cookies, CORS, signatures, etc. | code-confirmed |

---

## 6. Route architecture

| Prefix | Surface | Auth | Evidence |
|--------|---------|------|----------|
| `/api/auth/*` | Login / signup / refresh | `installed`, `apiKey` | code-confirmed |
| `/api/admin/*` | Admin, POS, KDS, OSS, reports, settings | `auth:sanctum` | code-confirmed |
| `/api/frontend/*` | Customer, kiosk, web ordering | `apiKey` + optional Sanctum | code-confirmed |
| `/api/table/*` | Table ordering | Sanctum | code-confirmed |
| `/install` | Installer wizard (web) | none (setup) | code-confirmed |
| `/payment/*` | Gateway callbacks (web) | varies | code-confirmed |

---

## 7. Frontend (Vue 3 SPA)

- **Framework**: Vue 3 + Vuex + vue-router + axios + i18n
- **Entry**: `resources/js/app.js` → `createApp`
- **Components**: `resources/js/components/` → `admin/`, `frontend/`, `table/`, `layouts/`
- **Router modules**: `kioskRoutes.js`, `subscriberRoutes.js`, etc.
- **Blade** used only for: installer, email templates, PDF reports, payment gateway snippets
- **Evidence**: code-confirmed

---

## 8. Database

- **80 migrations** (Laravel standard `YYYY_MM_DD_HHMMSS_*`)
- **Most recent**: `2026_03_27` — kiosk upsell flags on `item_categories`
- **Key tables** (from models): `orders`, `order_items`, `order_addresses`, `order_coupons`, `transactions`, `items`, `item_categories`, `item_variations`, `item_extras`, `item_addons`, `users`, `kiosk_machines`, `branches`, `coupons`, `offers`, `loyalty_transactions`, `dining_tables`, etc.
- **Evidence**: code-confirmed (migration files + model names)

---

## 9. Tests

- **~41 test classes** under `tests/`
- `tests/Feature/` (~36 test files)
- `tests/Unit/` (3): `ValidJsonOrderTest`, `FrontendOrderServiceTest`, `OrderServiceSecurityTest`
- **Evidence**: code-confirmed

---

## 10. Frozen zones (no changes without explicit plan)

| Zone | Reason | Evidence |
|------|--------|----------|
| Payment gateways (Stripe, PayPal, Credit, etc.) | External integration; changing internals risks payment flow | doc-stated (`ARCHITECTURE.md`) |
| `PushNotificationService` internal logic | FCM integration; changing risks notification delivery | doc-stated (`ARCHITECTURE.md`) |
| Admin analytics | Low priority; not in active scope | doc-stated (`ARCHITECTURE.md`) |
| Delivery boy module | Low priority; not in active scope | doc-stated (`ARCHITECTURE.md`) |

---

## 11. Highest-priority modules (from vision + memory)

| Priority | Module / area | Status |
|----------|---------------|--------|
| P0 | Queue workers + realtime (Pusher/WebSockets) reliability | doc-stated (open risk) |
| P0 | `OrderService::changeStatus` correctness | doc-stated (inspection queue) |
| P1 | FCM push notification reliability | doc-stated |
| P1 | Order amendment on web POS | doc-stated (gap) |
| P1 | `BroadcastableOrder` / `ShouldBroadcastNow` audit | doc-stated (open risk) |
| P2 | Kiosk Electron wrapper | doc-stated (vision) |
| P2 | ESC/POS + TPE + cash drawer | doc-stated (vision) |

---

## 12. Config files (22)

`app`, `auth`, `broadcasting`, `cache`, `cors`, `database`, `easypaisa`, `filesystems`, `hashing`, `installer`, `kiosk`, `logging`, `mail`, `media-library`, `menu`, `menu_images`, `product`, `queue`, `sanctum`, `services`, `session`, `view`.
