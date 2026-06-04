# Admin Sidebar Inventory — V1 Le Cayenne

**Generated**: 2026-05-27
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Scope**: V1 LOCAL Le Cayenne — single-restaurant fast-food

## Sources of truth

| File | Role |
|------|------|
| `resources/js/router/index.js` | Vue Router root, aggregates 35 route modules + base routes |
| `resources/js/router/modules/*.js` | 35 module files (one per domain) |
| `resources/js/components/layouts/backend/BackendMenuComponent.vue` | **Sidebar renderer** — composes DB menus + V1 primary list + virtual children |
| `resources/js/config/v1-hidden-modules.js` | V1_HIDDEN_MENU_MODULES list + V1_HIDDEN_BACKEND_MENU_URLS |
| `database/seeders/MenuTableSeeder.php` | DB seeder for Spatie menu records (URL + language key + icon) |

## Sidebar composition algorithm (BackendMenuComponent.buildMergedSidebarMenus)

1. **Pinned top**: `dashboard` then `pos` (always pushed first).
2. **V1_PRIMARY_SIDEBAR_MENUS** (frozen array): stock/rupture, items, ingredients, pos-orders, cash-overview, delivery-boy-cash-sessions.
3. **DB-driven enrichedVisibleMenus** (from Spatie + V1 hidden filter): the seeder groups "Pos & Orders" (POS, POS Orders, Online, Table, KDS, OSS), "Promo" (coupons, offers), "Communications" (push, messages, subscribers), "Users" (admin, delivery boys, customers, employees, waiters, chefs), "Accounts" (transactions), "Reports" (sales, items, credit balance), "Setup" (settings).
4. **Virtual children** injected: `items` parent gets `[items/studio, settings/item-attributes/list]` regardless of DB children.
5. **Permission gate** per row: `permissionUrlForSidebarPath(menu.url)` → resolved against `$store.getters.authPermission`.
6. **Auto-hidden sidebar** when path matches `kitchen-display-system` or `order-status-screen` (CSS `.db-sidebar.hidden`).

---

## CORE V1 (must work — daily Le Cayenne operations)

| Path | Component (resources/js/components/admin/) | Controller | Permission | Notes |
|------|-------|------------|------------|-------|
| `/admin/dashboard` | `dashboard/DashboardComponent` | `Admin/DashboardController` | `dashboard` | Landing pinned. |
| `/admin/pos` | `pos/PosComponent` | `Admin/PosController` + `PosOrderController` | `pos` | POS Caisse pinned. Frozen vanilla wizard. |
| `/admin/stock/rupture` | `stock/StockRuptureDashboardComponent` | `Admin/StockRuptureDashboardController` | `items` (mapped) | V1 primary #1. Wave Z S9. |
| `/admin/items` → `/admin/items/studio` | `items/CatalogStudioComponent` | `Admin/ItemController` + `AvailabilityController` + `ComposerProfileController` | `items` | Catalogue. Parent row hidden, Studio visible via virtual children. |
| `/admin/items/show/:id` | `items/ItemShowComponent` | `Admin/ItemController` | `items` | Item detail page. |
| `/admin/items/:id/composer` | `items/composer/ProductComposerEditorComponent` | `ComposerProfileController` | `catalog.compose` | Wizard composer editor. |
| `/admin/categories/:id/composer` | `items/composer/ProductComposerEditorComponent` | `ComposerProfileController` | `catalog.compose` | Category-level composer. |
| `/admin/ingredients` | `ingredients/IngredientList` | `Admin/IngredientController` | `ingredients_manage` | V1 primary #2. 85 images + 13 sauces Wave Y. |
| `/admin/ingredients/:type(attribute\|extra\|addon)` | `ingredients/IngredientList` | `Admin/IngredientController` | `ingredients_manage` | Type filter. |
| `/admin/pos-orders` | `pos-orders/PosOrderComponent` | `Admin/PosOrderController` | `pos-orders` | V1 primary #3. |
| `/admin/pos-orders/show/:id` | `pos-orders/PosOrderShowComponent` | `Admin/PosOrderController` | `pos-orders` | Order detail. |
| `/admin/pos-orders-tracker` | `pos-orders/PosOrdersTrackerComponent` | `Admin/PosOrderController` | `pos-orders` (mapped) | Live tracker, no sidebar entry. |
| `/admin/cash-overview` | `cash-overview/CashOverviewComponent` | `Admin/CashOverviewController` | `cash-sessions-report` | V1 primary #4. Wave X X4. |
| `/admin/cash-sessions-report` | `cash-sessions-report/CashSessionReportListComponent` | `Admin/CashSessionReportController` | `cash-sessions-report` | Linked from Cash Overview. |
| `/admin/kitchen-display-system` (`/kds`) | `kitchen-display-system/KitchenDisplaySystemComponent` | `Admin/KitchenDisplaySystemController` | `kitchen-display-system` | KDS chef view. Sidebar auto-hidden. |
| `/admin/order-status-screen` (`/order-status`) | `order-status-screen/OrderStatusScreenComponent` | `Admin/OssOrderController` | `order-status-screen` | OSS client view. Public-friendly auth. Sidebar auto-hidden. |
| `/admin/settings` → `/admin/settings/company` | `settings/SettingsComponent` + `settings/CompanyComponent` | `Admin/CompanyController` | `settings` | DB seeder Setup group. Has own sub-sidebar. |
| `/admin/settings/site` | `settings/SiteComponent` | `Admin/SiteController` | `settings` | |
| `/admin/settings/branches/list` | `settings/BranchListComponent` | `Admin/BranchController` | `settings` | |
| `/admin/settings/branches/show/:id` | `settings/BranchShowComponent` | `Admin/BranchController` | `settings` | |
| `/admin/settings/order-setup` | `settings/OrderSetupComponent` | `Admin/OrderSetupController` | `settings` | |
| `/admin/settings/kiosk-setup` | `settings/KioskSetupComponent` | `Admin/KioskSetupController` | `settings` | |
| `/admin/settings/currencies/list` | `settings/CurrencyListComponent` | `Admin/CurrencyController` | `settings` | |
| `/admin/settings/payment-terminals` | `settings/PaymentTerminalsComponent` | — | `settings` | NF525 hardware mapping. |
| `/admin/settings/kiosk-machines` | `settings/KioskMachineListComponent` | `Admin/KioskMachineController` | `settings` | |
| `/admin/profile/edit-profile` | `profile/ProfileEditProfileComponent` | `Admin/ProfileController` | (always allowed) | Header dropdown. |
| `/admin/profile/change-password` | `profile/ProfileChangePasswordComponent` | `Admin/ProfileController` | (always allowed) | |

**Count: 31 routes (~14 distinct sidebar entries after dedup).**

---

## OPTIONAL V1 (Le Cayenne ops may use)

| Path | Component | Controller | Permission | Notes |
|------|-------|------------|------------|-------|
| `/admin/observability/outbox` | `observability/OutboxOverviewComponent` | `Admin/OutboxController` | `dashboard` | V1.0.1 hardening. No sidebar entry. |
| `/admin/administrators` | `administrators/AdministratorComponent` | `Admin/AdministratorController` | `administrators` | Admin user mgmt (1-2 staff). |
| `/admin/administrators/show/:id` | `administrators/AdministratorShowComponent` | `Admin/AdministratorController` | `administrators` | |
| `/admin/employees` | `employees/EmployeeComponent` | `Admin/EmployeeController` | `employees` | Cashiers / POS operators. |
| `/admin/employees/show/:id` | `employees/EmployeeShowComponent` | `Admin/EmployeeController` | `employees` | |
| `/admin/chefs` | `chefs/ChefComponent` | `Admin/ChefController` | `chefs` | Kitchen staff for KDS login. |
| `/admin/chefs/show/:id` | `chefs/ChefShowComponent` | `Admin/ChefController` | `chefs` | |
| `/admin/push-notifications` | `push-notifications/PushNotificationComponent` | `Admin/PushNotificationController` | `push-notifications` | KDS chef alerts, OSS broadcasts. |
| `/admin/push-notifications/show/:id` | `push-notifications/PushNotificationShowComponent` | `Admin/PushNotificationController` | `push-notifications` | |
| `/admin/sales-report` | `sales-report/SalesReportComponent` | `Admin/SalesReportController` | `sales-report` | Daily revenue / NF525 context. |
| `/admin/items-report` | `items-report/ItemsReportComponent` | `Admin/ItemsReportController` | `items-report` | |
| `/admin/messages` | `messages/MessageComponent` | `Admin/MessageController` | `messages` | Internal staff messaging. |
| `/admin/transactions` | `transactions/TransactionListComponent` | `Admin/TransactionController` | `transactions` | Payment-tx ledger. |
| `/admin/demo/wizard-launcher` | `items/composer/WizardAdvancedLauncherComponent` | — | `catalog.compose` | Sandbox demo. |
| `/admin/delivery-boy-cash-sessions` | `delivery-boy-cash-sessions/DeliveryBoyCashSessionListComponent` | `Admin/DeliveryBoyCashSessionController` | `delivery-boys` | V1 primary #5. |
| `/admin/delivery-boy-cash-sessions/:id` | `delivery-boy-cash-sessions/DeliveryBoyCashSessionShowComponent` | `Admin/DeliveryBoyCashSessionController` | `delivery-boys` | |
| `/admin/delivery-boys` (`/delivery`) | `delivery-boys/DeliveryBoyComponent` | `Admin/DeliveryBoyController` | `delivery-boys` | Hidden by V1_HIDDEN_MENU_MODULES but route accessible. |
| `/admin/delivery-boys/show/:id` | `delivery-boys/DeliveryBoyShowComponent` | `Admin/DeliveryBoyController` | `delivery-boys` | |

**Count: 18 routes.**

---

## OUT-OF-SCOPE V1 (hidden, V2 / not Le Cayenne)

| Path | Component | Permission | Hidden by | Notes |
|------|-----------|------------|-----------|-------|
| `/admin/customers` (+show) | `customers/CustomerComponent` | `customers` | `V1_HIDDEN_MENU_MODULES.customers` | Re-enable V2 if loyalty grows. |
| `/admin/coupons` (+show) | `coupons/CouponComponent` | `coupons` | `V1_HIDDEN_MENU_MODULES.coupons` | Promo coupon catalog. |
| `/admin/offers` (+show) | `offers/OfferComponent` | `offers` | `V1_HIDDEN_MENU_MODULES.offers` | Promo offers. |
| `/admin/online-orders` (+show) | `online-orders/OnlineOrderComponent` | `online-orders` | `V1_HIDDEN_MENU_MODULES.onlineOrders` | No online channel V1 (counter+kiosk+POS only). |
| `/admin/table-orders` (+show) | `table-orders/TableOrderComponent` | `table-orders` | `V1_HIDDEN_MENU_MODULES.tableOrders` | Dine-in disabled. |
| `/admin/dining-tables` (+list+show) | `dining-tables/DiningTableComponent` | `dining-tables` | `V1_HIDDEN_MENU_MODULES.diningTables` | Dine-in disabled. |
| `/admin/waiters` (+show) | `waiters/WaiterComponent` | `waiters` | `V1_HIDDEN_MENU_MODULES.waiters` | No waiters fast-food. |
| `/admin/subscribers` (+list) | `subscribers/SubscriberComponent` | `subscribers` | (DB-only — Communications group; appears if seeded) | Email marketing V2. |
| `/admin/credit-balance-report` | `credit-balance-report/CreditBalanceReportComponent` | `credit-balance-report` | `V1_HIDDEN_MENU_MODULES.creditBalanceReport` | Customer credit out-of-scope. |
| `/admin/settings/mail` | `settings/MailComponent` | `settings` | `settings.mail` | |
| `/admin/settings/loyalty-setup` | `settings/LoyaltySetupComponent` | `settings` | `settings.loyalty-setup` | Kiosk loyalty is client-side only V1. |
| `/admin/settings/notification` | `settings/NotificationComponent` | `settings` | `settings.notification` | |
| `/admin/settings/theme` | `settings/ThemeComponent` | `settings` | `settings.theme` | |
| `/admin/settings/item-categories/list` | `settings/ItemCategoryListComponent` | `settings` | `settings.item-categories` | Replaced by Studio. |
| `/admin/settings/role/list` (+show) | `settings/RoleListComponent` | `settings` | `settings.role` | Single-resto seeded once. |
| `/admin/settings/tax/list` | `settings/TaxListComponent` | `settings` | `settings.tax` | TVA seeded fixed. |
| `/admin/settings/languages/list` (+show) | `settings/LanguageListComponent` | `settings` | `settings.languages` | |
| `/admin/settings/otp` | `settings/OtpComponent` | `settings` | `settings.otp` | |
| `/admin/settings/notification-alert` | `settings/NotificationAlertComponent` | `settings` | `settings.notification-alert` | |
| `/admin/settings/social-media` | `settings/SocialMediaComponent` | `settings` | `settings.social-media` | |
| `/admin/settings/cookies` | `settings/CookiesComponent` | `settings` | `settings.cookies` | |
| `/admin/settings/analytics` (+show) | `settings/AnalyticListComponent` | `settings` | `settings.analytics` | |
| `/admin/settings/time-slots` | `settings/TimeSlotListComponent` | `settings` | `settings.time-slots` | |
| `/admin/settings/sliders/list` (+show) | `settings/SliderListComponent` | `settings` | `settings.sliders` | |
| `/admin/settings/pages/list` (+show) | `settings/PageListComponent` | `settings` | `settings.pages` | Marketing static pages. |
| `/admin/settings/sms-gateway` | `settings/SmsGatewayComponent` | `settings` | `settings.sms-gateway` | |
| `/admin/settings/payment-gateway` | `settings/PaymentGatewayComponent` | `settings` | `settings.payment-gateway` | V1 LOCAL uses POS terminal hardware, not gateway. |
| `/admin/settings/license` | `settings/LicenseComponent` | `settings` | `settings.license` | Multi-tenant licensing V2. |
| `/admin/pos/floorplan` | `pos/FloorplanComponent` | `pos` | (feature flag) | Dine-in disabled `pos.dine_in_enabled=false`. |
| `/admin/settings/item-attributes/list` | `settings/ItemAttributeListComponent` | `settings` | (conflict — hidden in settings but injected via virtual children under Items) | Reachable. |

**Count: 41 routes (some grouped).**

---

## Top 5 likely broken / risk pages

| # | Path | Severity | Reason |
|---|------|----------|--------|
| 1 | `/admin/table-orders` & `/admin/dining-tables` | **P1** | Dine-in disabled (`pos.dine_in_enabled=false`). Components may fetch empty dataset or error on undefined dine-in config. BRAIN §10. |
| 2 | `/admin/observability/outbox` | P2 | Permission cheat (`dashboard` workaround). No sidebar entry — only reachable via direct URL. High chance of blank-state or empty placeholder. |
| 3 | `/admin/online-orders` | P2 | Hidden but route resolves. Empty list since no online orders ever flow V1 LOCAL (`STAFF_ONLY_MODE` blocks `/checkout`). Empty state may show raw `Label.X` keys. |
| 4 | `/admin/settings/payment-gateway` | P2 | Hidden but route exists. Direct URL access with no gateway API keys may throw on missing Stripe/SenangPay config. |
| 5 | `/admin/customers, /admin/coupons, /admin/offers, /admin/waiters, /admin/credit-balance-report, /admin/delivery-boys` | P3 | Hidden but routes still resolve. Empty data, untested in V1 cycle. Possible stale schema or broken empty-states (raw label keys, undefined refs). |

---

## Summary

- **Total inventoried routes**: 90 (top-level + children + redirects).
- **Sidebar visible after V1 filter**: 14 distinct top-level entries.
- **CORE V1**: 31 routes. **OPTIONAL V1**: 18 routes. **OUT-OF-SCOPE V1**: 41 routes.
- **Settings has its own internal `MenuComponent.vue`** with nested sub-sidebar (CompanyComponent landing). V1-hidden settings entries are filtered there too.
- **Pinned at sidebar top**: Dashboard, POS, Stock Rupture, Items (Studio + Item Attributes virtual children), Ingredients, POS Orders, Cash Overview, Delivery Boy Cash Sessions.
- **DB-seeded sidebar groups**: Pos & Orders (POS + POS Orders + KDS + OSS visible; Online + Table hidden), Communications (Push Notifications + Messages + Subscribers), Users (Administrators + Employees + Chefs visible; Delivery Boys + Customers + Waiters hidden), Accounts (Transactions), Reports (Sales + Items; Credit Balance hidden), Setup (Settings).
- **Auto-hidden sidebar**: KDS and OSS routes hide the entire `.db-sidebar`.
