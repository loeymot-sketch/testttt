# SYSTEM_MAP — Disjoint ownership of the 5 systems (cold-start SSOT)

**Rule:** each system = one agent lane. Path sets below are **DISJOINT**. Any file used by 2+ systems is listed under **§6 SHARED (lock + gate, never parallel)** — never claimed by a single system lane. All paths grep-verified at HEAD `d6487f716`; items not directly confirmed are tagged `(à vérifier)`. **Re-vérifié 2026-08-15 à HEAD `e8923b10a` (T-1.3, `GOAL_CONFORT_MAX_ET_BASE_PROUVEE_2026-08-15.md`)** : 4 dérives mineures corrigées ci-dessous (services déplacés, comptage controllers, dirs admin non listés). Rien de structurel — les voies restent disjointes.

Bundle ownership (from `webpack.mix.js` + `webpackChunkName` counts): `kiosk-shell/kiosk-wizard/kiosk-wizard-step/kiosk-errors`→BORNE · `pos-shell`→CAISSE · `admin-kds`/`admin-oss`→KDS+OSS · `admin-shell`(117 chunks)/`admin-reports`→CENTRAL · `app.js`=shared admin/customer SPA entry · `pos-app.js`=POS SPA entry · `vendor.js`=shared.

> ⚠️ **2 OWNER-CONFIRM judgment calls (Claude's decomposition, NOT owner-verbatim — flag for sign-off):** (1) **OSS** folded into the **KDS** lane — owner's 5 systems named «ÉCRAN CUISINE (KDS)» without listing OSS separately; bundled here as the paired display surface fed by the same sync contract. (2) **Backend customer storefront** (`components/frontend/{home,menu,account,...}`) placed under **WEB+APP** — owner said «site web + app, standalone»; this also assigns the backend-served storefront to that lane. Both defensible; confirm before relying on them for parallel routing.

---

## 1. BORNE (kiosk) — self-service customer ordering
**Frontend (OWNED):**
- `resources/js/components/frontend/kiosk/**` (all kiosk Vue)
- `resources/js/router/modules/kioskRoutes.js`
- kiosk cart/offline JS: `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`
- Bundles: `public/js/kiosk-shell.js`, `kiosk-wizard.js`, `kiosk-wizard-step.js`, `kiosk-errors.js`

**Backend (OWNED):**
- `app/Services/Kiosk/**` (PricingPreviewService, KioskPromoService — grep `app/Services/Kiosk/`)
- `app/Http/Controllers/Auth/KioskMachineLoginController.php`
- Routes: `routes/api.php:167` `/auth/kiosk-login`, `:205` kiosk-logout, kiosk-event under `frontend` prefix
- Model: `KioskMachine`

**FROZEN inside:** `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` (§7 — **auditable**, tests OK, modif sous soin).
**SHARED it touches:** PricingService (frozen), composition_snapshot, sync bus (publishes `OrderCreated`→KDS), order create path `FrontendOrderService.php` (shared §6).

---

## 2. CAISSE (POS) — main terminal: payment, cash, fiscal
**Frontend (OWNED):**
- `resources/js/components/admin/pos/**` · `.../posOrders/**` · `.../cash/**` · `.../cashOverview/**` · `.../cashSessionReport/**` · `.../encaissement/**`
- `resources/js/pos-app.js` (POS SPA entry) · Bundle `public/js/pos-shell.js` (`resources/js/components/admin/pos/ReceiptComponent.vue` compiles here)
- POS router modules: `resources/js/router/modules/{posRoutes,posOrderRoutes,cashOverviewRoutes,cashSessionReportRoutes,encaissementRoutes}.js`

**Backend (OWNED):**
- `app/Http/Controllers/Admin/Pos/**` (PosReceiptPrintController) + `PosController`, `PosOrderController`
- ⚠️ **POS controllers sitting DIRECTLY in `Admin/` (no `Pos/` subdir) — CAISSE, NOT CENTRAL:** `AdminPosV4Controller.php`, `PosCategoryController.php`, `PosLoyaltyController.php`, `CashOverviewController.php`, `CashSessionReportController.php` (verified `ls Admin/*.php`).
- `app/Services/PaymentService.php`, `app/Services/Payments/SplitPaymentService.php` (déplacé, `à vérifier` 2026-05→`Payments/` confirmé 2026-08-15), `app/Services/Cash/CashDrawerService.php` (déplacé, confirmé 2026-08-15), `app/Services/Pos/**`
- Routes: `routes/api.php:792` `pos.`, `:971` `pos-order.`, `:1156` `pos-category.`
- Config: `config/pos.php` (`simulation_hardware:37`)

**Canal AGRÉGATEUR (Uber) — rattaché à la CAISSE (même porte `pos-orders|pos`, même personnel) :**
- Écran tablette : `resources/js/components/admin/uber/UberPhotoCaptureComponent.vue` · router `modules/uberPhotoRoutes.js` (`/admin/uber-photo`)
- Backend : `app/Http/Controllers/Admin/UberPhotoCaptureController.php` · `app/Services/Uber/**` · `app/Models/UberTicketCapture.php` · `app/Providers/UberVisionServiceProvider.php`
- Routes : `routes/api.php` `uber.photo.*` · Config : `config/uber.php` (`vision_enabled`, `photo_max_*`)
- ⚠️ **`app/Services/Uber/UberOrderIngestor.php` = chemin de création UNIQUE** partagé avec le webhook `Webhook/UberWebhookController.php`. **NE JAMAIS le dupliquer** : il porte l'anti-doublon au niveau commande, la boucle anti-collision de numéro d'appel, l'ancre utilisateur technique et la pierre tombale d'annulation-avant-création.

**FROZEN inside (STRICT no-touch):** `public/js/pos-wizard.js` · `public/css/pos-wizard.css` · `resources/views/admin-pos-v4.blade.php`. **FROZEN (auditable-w/-gate):** `PaymentComponent.vue`, `v5/PosV5TrancheRow.vue`.
**SHARED it touches:** PricingService, Fiscal chain (alloc at counter-PAID), OrderStateMachine, sync bus (publishes→KDS/OSS) — all §6.

---

## 3. KDS + OSS — kitchen display + customer status screen
**Frontend (OWNED):**
- `resources/js/components/admin/kitchenDisplaySystem/**` (KdsOrderCard.vue, KdsOrderLine.vue, KdsV2Grid.vue, KitchenDisplaySystemComponent.vue)
- `resources/js/components/admin/orderStatusScreen/**` (PreparingAndReadyComponent.vue)
- `resources/js/helpers/kdsCustomization.js`
- `resources/js/services/OssSyncService.js` (OSS poll cadence — **OSS-specific, owned HERE, NOT in the §6 shared bus**)
- Bundles: `public/js/admin-kds.js`, `public/js/admin-oss.js` · router module `resources/js/router/modules/orderStatusScreenRoutes.js` (+ KDS module `(à vérifier)`)

**Backend (OWNED):**
- `app/Services/KitchenDisplaySystemOrderService.php`, `app/Http/Controllers/Admin/OrderStatusScreenController.php`
- ⚠️ **KDS controllers DIRECTLY in `Admin/` (no `Kds/` subdir) — KDS, NOT CENTRAL:** `KitchenDisplaySystemController.php` (changeStatus/recall), `KdsSyncController.php` (verified `ls Admin/*.php`).
- `app/Http/Resources/{KDSOrderItemsResource,KDSOrderDetailsResource,CDSOrderDetailsResource}.php`
- `app/Events/KdsOrderRecalled.php` + `app/Listeners/PersistKdsOrderRecalledToOutbox.php`
- `app/Http/Requests/Kds/**`
- Routes: `routes/api.php:1168` `kds-order.`, `:1216` `oss-order.` ; public OSS feed `GET /api/frontend/oss-order` (`routes/api.php:~1244` frontend prefix)

**FROZEN inside:** none (KDS/OSS auditable).
**SHARED it touches:** subscribes sync bus (channel `branch.{id}`), OrderStateMachine (status transitions) — §6.
**Supervisor note:** OSS (order-status-screen) is bundled into the KDS lane because both are display surfaces fed by the same order/sync contract; its controllers live under `Admin/` but are owned by THIS lane, not CENTRAL.

---

## 4. WEB + APP — customer storefront (standalone) + backend customer SPA
**Standalone repos (OWNED, NO API wireup V1):**
- `/Users/1millnonstop/Downloads/web/**` (web standalone; `data/menu.js` canonical mirror)
- `mobile/**` (mobile app; `mobile/data/menu.js` canonical mirror)

**Backend customer storefront (OWNED):**
- `resources/js/components/frontend/**` **EXCEPT `frontend/kiosk/**`** (which is the BORNE lane). Exclusion rule (like CENTRAL): every customer storefront dir (account, auth, checkout, home, menu, offers, search, page, otherPage, `frontend/components`) AND any NEW `frontend/<dir>` ≠ `kiosk` defaults to THIS lane — no dir is left unassigned.
- `resources/js/components/layouts/frontend/**` (FrontendNavBarComponent.vue)
- Customer route modules: `resources/js/router/modules/{frontendRoutes,customerRoutes}.js`. Backend customer API routes under `frontend` prefix (order tracker, account) — distinguished from kiosk by **customer Sanctum token**, not route prefix.

**FROZEN inside:** none.
**SHARED it touches:** PricingService, sync bus (customer order tracker subscribes `branch.{id}`), `FrontendOrderService.php` (§6). **Palette mandate:** mobile = NOIR/ORANGE/JAUNE/BLANC (NOT Cayenne red `#F4501E`).

---

## 5. CENTRAL — management (catalogue, dashboard, history, settings, users, reports)
**Frontend (OWNED):** `resources/js/components/admin/**` **EXCEPT** the POS-lane dirs (`pos`,`posOrders`,`cash`,`cashOverview`,`cashSessionReport`,`encaissement`) and the KDS-lane dirs (`kitchenDisplaySystem`,`orderStatusScreen`). I.e. OWNED: `dashboard, items, settings, administrators, employees, chefs, waiters, customers, coupons, offers, ingredients, stock, salesReport, itemsReport, transactions, messages, pushNotification, subscribers, orderHistory, onlineOrders, tableOrders, deliveryBoys, deliveryBoyCashSession, creditBalanceReport, diningTable, observability, profile`. ⚠️ **`admin/components/**` is NOT CENTRAL-owned → §6 SHARED** (its widgets LoadingComponent/BreadcrumbComponent/MapComponent are imported across CAISSE+KDS, incl. the FROZEN `PaymentComponent.vue:334`). Any new `admin/<dir>` ≠ POS/KDS-lane and ≠ `components` defaults here.
- `resources/js/components/layouts/backend/BackendMenuComponent.vue` (sidebar/RBAC). ⚠️ `resources/js/app.js` (shared admin SPA entry) is **NOT a CENTRAL-owned file → §6 SHARED (coordinate-only, never parallel)**.
- Bundles: `public/js/admin-shell.js` (117 lazy chunks), `public/js/admin-reports.js`

**Backend (OWNED):** `app/Http/Controllers/Admin/**` (**97 controllers** mesuré 2026-08-15 à HEAD `e8923b10a`, était noté ~100 ; incl. subdirs `Fiscal/`,`Observability/`,`Pos/`) EXCEPT the POS + KDS/OSS controllers **explicitly named in §2/§3** — including the 7 that sit DIRECTLY in `Admin/` (AdminPosV4/PosCategory/PosLoyalty/CashOverview/CashSessionReport → CAISSE ; KitchenDisplaySystemController/KdsSync → KDS). ⚠️ **The exclusion is by the EXPLICIT named list, NOT by subdir** — a POS/KDS controller sitting in bare `Admin/` belongs to its functional lane, never CENTRAL. Includes `DashboardController`/`DashboardService`, `OrderHistoryController`, Settings cluster (~26 controllers), catalogue (`ItemController`, CatalogStudio), `SalesReport`/`ItemsReport`/`AnalyticController`, users (Administrator/Employee/Chef/Waiter/Customer/DeliveryBoy), Coupon/Offer.

**FROZEN inside:** none specific.
**SHARED it touches:** PricingService (reads), Fiscal (reads Z, `LastZReportWidget`), BranchScope/auth, sync (dashboard polls) — §6.

---

## 6. SHARED ZONES — lock + gate, NEVER parallel, never a single system lane alone
| Zone | Files (file:line) | Frozen? |
|---|---|---|
| Pricing SSOT | `app/Services/Pricing/PricingService.php` (+ `DiscountCalculator`, `PricingRequest`) | **FROZEN** PricingService |
| NF525 chain | `app/Services/Fiscal/{FiscalSequenceService,ZReportService,AuditLogService}.php` + `audit_logs`/`z_reports` triggers | **FROZEN** |
| Sync bus | `app/Events/{OrderCreated,OrderStatusChanged,KdsOrderRecalled}.php` · `routes/channels.php:41` (`branch.{branchId}`) · soketi (`soketi.json`) · `resources/js/services/WebSocketService.js` (shared Echo client) · queue/outbox (`MonitorOutboxStaleness`). **NB:** `OssSyncService.js` is OSS-specific → owned by the **KDS+OSS lane (§3)**, NOT shared. | — |
| Auth / isolation | `app/Models/Scopes/BranchScope.php` (FROZEN) · Sanctum (`config/sanctum.php`) · `app/Http/Middleware/IdempotencyKeyMiddleware.php` (FROZEN) | partial FROZEN |
| Order core | `app/Services/OrderService.php` · `app/Services/FrontendOrderService.php` · `app/Domain/Order/OrderStateMachine.php` (FROZEN) | partial FROZEN |
| i18n | `resources/js/languages/{fr,en,ar}.json` · `lang/fr/**` | — (FR canonical) |
| Build/bundles | `webpack.mix.js` · `public/mix-manifest.json` · **`resources/js/app.js`** (shared admin/customer SPA entry — coordinate, never parallel) · `public/js/app.js`(gitignored)/`vendor.js`. NB: `resources/js/pos-app.js` is CAISSE-owned (§2). | — |
| **Registry / aggregators (append-coordination)** | `routes/api.php` (toutes les voies y ajoutent leurs routes) · `resources/js/router/index.js` (import manuel des modules) · `resources/js/store/index.js` (import manuel des modules vuex) | — |
| **Shared UI widgets** | `resources/js/components/admin/components/**` (LoadingComponent/BreadcrumbComponent/MapComponent/buttons — importés par CAISSE+KDS+CENTRAL, incl. le FROZEN `PaymentComponent.vue:334`) · `resources/js/components/common/**` (ConnectionStatusBanner — 4 voies) · `resources/js/components/DefaultComponent.vue` (enregistré dans app.js + pos-app.js) | — (éditer un widget partagé = coordination, jamais en parallèle) |

**Disjointness check (acceptance):** the OWNED sets of systems 1–5 share no file. All cross-system files are in §6. Two agents on different lanes cannot collide unless one writes §6 (which requires a LOCK doc + gate per PARALLEL_PROTOCOL.md). Touching `OrderService.php`/`FrontendOrderService.php`/`resources/js/app.js` (multi-lane) = coordination required even though not all are frozen.

**Append-coordination registries (CRITICAL for parallel safety):** `routes/api.php`, `resources/js/router/index.js`, `resources/js/store/index.js` (+ `webpack.mix.js`) are single aggregator files where EVERY lane registers its routes/modules. A lane OWNS its route/store-module *content*, but the **registration line** lands in these shared files. Rule: **declare any registry edit in your mission report; if two parallel missions both add routes/modules in the same wave, SERIALIZE the registry append** (second mission rebases). Not a free parallel edit — this is the one place "disjoint lanes" needs explicit coordination rather than structural separation.

**Coverage — dormant orphan:** `resources/js/components/table/**` + `resources/js/components/layouts/table/**` = dine-in surface, **DORMANT in V1** (`pos.dine_in_enabled=false`). Assigned to **CENTRAL** on activation (it owns `tableOrders`); untouched in V1 — no lane edits it.

**Default-assignment rule (catch-all — guarantees no path is ever ambiguous):** any path NOT in a lane's OWNED set (§1–§5) AND NOT in §6 → treat as **SHARED-until-assigned**: declare it in your mission report and coordinate (no parallel edit) until the owner assigns it. Covers any new `layouts/<dir>`, `router/modules/<x>`, `store/modules/<x>`, `services/<x>`, `helpers/<x>` not yet listed. No path is silently claimable by two lanes or by none.

**6 dirs `admin/<x>` non listés, résolus 2026-08-15 (T-1.3, ne pas re-signaler) :**
- `admin/kitchen/KitchenTicketPrintListener.vue` + `admin/promo/PromoFlyerPrintListener.vue` → **§6 SHARED** (les deux sont montés globalement dans `DefaultComponent.vue`, imprimant pour la destination `kitchen` COMME `counter` — cross-lane structurel, pas un oubli).
- `admin/promo/{PromoFlyerComponent,PromoFlyerQuickModal,PromoFlyerSettingsComponent}.vue` → **CAISSE** (flux comptoir : impression d'un ticket promo nominatif au point de vente).
- `admin/uber/UberPhotoCaptureComponent.vue` → **CAISSE**, déjà documenté §2 (canal agrégateur rattaché à la caisse) — absent seulement de CETTE liste d'exclusion CENTRAL, pas un trou d'ownership réel.
- `admin/shared/AvailabilityTogglePanel.vue` → **§6 SHARED** (le nom l'indique ; bascule de rupture consommée par plusieurs surfaces).
- `admin/purchasing/PurchaseScanComponent.vue`, `admin/demo/WizardAdvancedLauncherComponent.vue` → **CENTRAL** par la règle catch-all ci-dessus (aucun signal cross-lane trouvé).

**Produce-side events (boundary clarification):** a system lane EMITS the existing events (`OrderCreated`/`OrderStatusChanged`) and CONSUMES them — that stays in-lane. But the DISPATCH logic + the order-create path live in shared `OrderService.php`/`FrontendOrderService.php` and the event/payload classes live in §6. So changing **WHEN/HOW** an event fires, or the **KdsOrder payload shape**, is a SHARED-zone change = LOCK + gate, NEVER in a single lane in parallel (it would break KDS+OSS+tracker at once). A lane is in-lane only while it produces/consumes the existing contract UNCHANGED.
