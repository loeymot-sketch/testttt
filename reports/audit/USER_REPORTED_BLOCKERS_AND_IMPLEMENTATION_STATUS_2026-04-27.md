# User-Reported Blockers and Implementation Status — 2026-04-27

Scope: kiosk client lock, POS customer requirement, delivery/address/Google Maps, dashboard catalog/category/product/stock, realtime connection banner, final status of prior implementation requests.

Author: Codex extension inspection pass.

Verdict: `PRODUCT_PATCH_BLOCKED_BY_SAFETY_HOOK`

Current local safety status:

```text
bash .cursor/hooks/safety-check.sh
[HALT] Frozen zone staged: app/Services/OrderService.php — gate clearance required. See docs/gates/
```

This means I did not patch product files in this pass. The causes and fixes are now concrete enough to decide the next correction cycle without involving Claude yet.

---

## 1. Executive Summary

The main problems reported by the user are confirmed:

1. `/kiosk/idle` is rendered inside the backend shell, so the kiosk shows the FoodKing backend header, branch selector, admin identity, and admin/POS icons. This is a layout routing bug.
2. The kiosk shows a connection-lost banner because the UI exposes WebSocket connectivity to the customer surface. Local `.env` points Pusher/Soketi to `127.0.0.1:6001`; if that realtime server is not running, the banner appears even if REST/polling still works.
3. POS order creation requires `customer_id`. This blocks walk-in/takeaway orders when the seeded "walking customer" is missing or not loaded.
4. Delivery address autocomplete depends on `window.google.maps.places`, but `.env` has an empty Google Maps key and POS does not load Maps by itself.
5. Delivery fee is not currently "5 EUR per 5 km". Current logic is: base charge + per-km charge after a free radius.
6. The unified Dashboard control plane requested for product/category/price/stock/offers is not implemented as a single new interface. Legacy admin modules exist, but the requested central dashboard is still a Train B/Phase 2 item and is blocked behind Train A release persistence/gates.

The system has many foundations and tests already present, but it is not in final PASS state. The prior final global audit file already says `HOLD_REWORK_REQUIRED`.

Reference report already present:

- `reports/audit/FINAL_GLOBAL_ULTRA_REVIEW_POS_KIOSK_KDS_DASHBOARD_SYNC_2026-04-27.md`

---

## 2. What Was Done / Present In The Repo

### 2.1 POS / Kiosk / KDS / Payment / Queue foundations

From the final global audit:

- Vitest full suite: `126 files passed`, `867 tests passed`.
- Critical modern PHP targeted suite: `73 passed`.
- Playwright global: `34 passed`, `1 failed`.
- `npm run production`: PASS.
- Queue number tests: `QueueNumberConcurrencyTest` and `QueueNumberUniquenessSentinelTest` pass locally.
- Payment simulation/restriction tests pass for the targeted paths:
  - payment method restriction,
  - cross-branch payment confirm,
  - concurrency/idempotency,
  - Stripe activation guard,
  - web payment disabled.
- KDS route/auth/list Playwright checks pass.

Important: these are foundations, not a full release signoff.

### 2.2 Catalog / product / category foundations

Existing admin modules are present:

- Product list route: `resources/js/router/modules/itemRoutes.js`, `/admin/items`.
- Product list UI: `resources/js/components/admin/items/ItemListComponent.vue`.
- Product availability toggle in product list: `resources/js/components/admin/items/ItemListComponent.vue:170`.
- Category admin route: `resources/js/router/modules/settingRoutes.js`, `/admin/settings/item-categories`.
- Category list/CRUD/sort/wizard fields: `resources/js/components/admin/settings/itemCategory/ItemCateogryListComponent.vue`.

Backend/catalog tests reported PASS in the final audit:

- `MenuProjectionServiceTest`.
- `MenuProjectionControllerTest`.
- `ItemImageCatalogRefreshTest`.
- `AdminItemBranchAvailabilityProjectionTest`.
- `CatalogStockCentralSyncEndToEndTest`.
- `AvailabilityServiceTest`.
- item/category/attribute request tests.
- item extra / attribute composer tests.

So there is a working legacy admin/product/category base, plus some sync/projection foundations.

### 2.3 What is not equivalent to the requested dashboard

The requested interface was a unified control plane:

- product list,
- categories,
- price management,
- stock management,
- offers/promos as product-like objects,
- live sync dashboard,
- kiosk/POS/KDS operational overview.

That is not implemented as one new dashboard. The repo currently has fragmented legacy modules and some newer foundations.

The active phase plan explicitly blocks Train B until Train A is closed:

- `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md:15`: Train B is dashboard centralisation/projection/catalog/roles/archive legacy and remains blocked while Train A is not closed.
- `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md:29-30`: Train A and Train B are sequential; Train B is `BLOCKED_UNTIL_TRAIN_A_CLOSED`.
- `.cursor/ACTIVE_CYCLE.md:44`: D-M13 remains blocked until final human signoff for unique `(branch_id, queue_number)`.

This is the real reason the dashboard decomposition is not visible as a finished feature.

---

## 3. User-Reported Issues — Root Cause and Fix

### 3.1 Kiosk shows backend/admin header

User symptom:

- On `/kiosk/idle`, the top bar shows:
  - FoodKing logo,
  - branch selector,
  - admin identity,
  - icons that look like admin/POS navigation.

Confirmed cause:

- Kiosk routes use `meta: { isKiosk: true }`.
- `resources/js/components/DefaultComponent.vue` only switches to frontend/table themes for:
  - `route.meta.isFrontend === true`,
  - `route.meta.isTable === true`.
- If neither is true, it falls back to `theme = "backend"`.
- Because kiosk routes are `isKiosk`, not `isFrontend`, the default layout renders backend navbar/menu.

Relevant files:

- `resources/js/components/DefaultComponent.vue:13-18`: backend layout renders `BackendNavbarComponent` and `BackendMenuComponent`.
- `resources/js/components/DefaultComponent.vue:112-120`: theme resolver falls back to backend.
- `resources/js/router/modules/kioskRoutes.js:135+`: kiosk routes carry `meta: { isKiosk: true }`.

Required fix:

- Add a kiosk-specific layout branch in `DefaultComponent.vue`.
- If `route.meta.isKiosk === true`, render only the kiosk router view, no backend navbar, no backend menu, no admin controls.
- Add a route/layout sentinel test proving `/kiosk/idle` never renders backend navbar/menu.

Recommended implementation:

```js
if (route?.meta?.isKiosk === true) {
  this.theme = "kiosk";
} else if (route?.meta?.isFrontend === true) {
  this.theme = "frontend";
} else if (route?.meta?.isTable === true) {
  this.theme = "table";
} else {
  this.theme = "backend";
}
```

Template should include:

```vue
<div v-else-if="theme === 'kiosk'">
  <router-view />
</div>
```

Risk:

- Low technical risk, high business impact.
- This is the first patch to apply after safety unlock.

### 3.2 Kiosk must be locked, no access back to caisse/admin

Confirmed current behavior:

- There is a hidden kiosk admin trigger in `resources/js/components/frontend/kiosk/KioskAppComponent.vue`.
- It opens after 5 taps on a secret zone.
- There is a `/kiosk/admin` route in `resources/js/router/modules/kioskRoutes.js:227-230`.

Relevant files:

- `resources/js/components/frontend/kiosk/KioskAppComponent.vue:135-146`: admin panel in the kiosk component.
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue:441-448`: 5-tap secret trigger.
- `resources/js/router/modules/kioskRoutes.js:227-230`: kiosk admin route.

Required policy decision:

- Customer kiosk mode must be hard locked.
- No visible navigation to caisse/admin.
- No route from kiosk UI to POS.
- A direct URL to POS can still work only if the authenticated staff session is allowed, but the kiosk surface itself must not expose it.

Recommended fix:

- Disable hidden admin trigger by default in customer mode.
- Add an explicit maintenance/admin mode flag if staff service access is needed.
- Guard `/kiosk/admin` behind staff auth/PIN/feature flag, or remove the route from the customer kiosk route tree.
- Add Playwright/static test:
  - `/kiosk/idle` contains no backend navbar,
  - no admin/caisse visible link,
  - no route button to POS.

### 3.3 Kiosk shows "Connexion perdue"

Confirmed current behavior:

- `KioskAppComponent.vue` renders `ConnectionStatusBanner`.
- The banner reads WebSocket state.
- `.env` points realtime to local Pusher/Soketi:
  - `BROADCAST_DRIVER=pusher`
  - `PUSHER_HOST=127.0.0.1`
  - `PUSHER_PORT=6001`
  - `MIX_PUSHER_APP_KEY=app-key`
  - `MIX_PUSHER_HOST=127.0.0.1`
  - `MIX_PUSHER_PORT=6001`
  - `MIX_PUSHER_SCHEME=http`

If no websocket server is running at `127.0.0.1:6001`, the banner is expected.

Relevant files:

- `resources/js/components/frontend/kiosk/KioskAppComponent.vue:11`: banner rendered.
- `resources/js/components/common/ConnectionStatusBanner.vue`: banner component.
- `.env:15`, `.env:45-46`, `.env:73-77`: realtime config.

Required fix:

- For production customer kiosk, do not expose raw websocket loss as a full customer-facing error if the app can still load menu/order APIs.
- Use a softer stale-sync indicator only when menu/order data is actually stale.
- For local/dev, either start the websocket server or disable realtime banner in customer kiosk mode.

Recommended implementation:

- Change kiosk banner logic from "Echo disconnected" to "menu/order sync stale".
- Keep POS/KDS operator banners more explicit.
- Add local dev runbook: start Laravel + queue + websocket server, or configure polling-only dev mode.

### 3.4 POS asks for customer/client ID

User expectation:

- Takeaway/emporter order should pass directly as a walk-in client.
- Cashier should not have to select a customer for a normal counter order.
- Delivery should ask customer/address details.

Confirmed cause:

- Backend request requires customer:
  - `app/Http/Requests/PosOrderRequest.php:40`: `customer_id` is required numeric.
- POS form initializes:
  - `resources/js/components/admin/pos/PosComponent.vue:833`: `customer_id: null`.
- POS tries to auto-select a seeded walking customer:
  - `resources/js/components/admin/pos/PosComponent.vue:1108-1113`.
- If no walking customer is found, POS leaves it null:
  - `resources/js/components/admin/pos/PosComponent.vue:1115`.
- Order creation stores:
  - `app/Services/OrderService.php:602`: `user_id => $request->customer_id`.

This explains the block exactly.

Required fix:

- Backend should resolve a branch-safe "walk-in customer" for non-delivery POS orders.
- POS should not block takeaway just because no explicit customer was selected.
- Delivery should remain stricter and require name/address/phone/address coordinates where needed.

Recommended backend policy:

- `order_type = TAKEAWAY/POS/counter`: `customer_id` can be nullable in request.
- Service resolves/creates a system walk-in customer, scoped safely.
- `order_type = DELIVERY`: customer/address required.
- Never trust frontend totals/prices.

Important:

- `app/Services/OrderService.php` is currently in the frozen staged set, so this fix is blocked until gate/staging is resolved.

### 3.5 Delivery address and Google Maps

User expectation:

- For delivery, cashier enters an address.
- Distance is calculated using Google Maps/geocoding/places.
- Fee should be computed by distance.

Confirmed current state:

- `.env:61`: `MIX_GOOGLE_MAP_KEY=` is empty.
- `resources/views/master.blade.php:111` exposes `googleMapKey`, but POS inline delivery autocomplete only works if `window.google.maps.places` already exists.
- `resources/js/components/admin/pos/PosComponent.vue:2177-2178` creates `AutocompleteService` only if Google Places is already loaded.
- POS component does not itself load the Google Maps script.

Conclusion:

- Google Places autocomplete will not work reliably in the current local config.
- Manual address may still be accepted, but distance calculation needs valid lat/lng to be correct.

Required fix:

- Configure a valid Google Maps key with Places + Geocoding enabled.
- Load Google Maps script once for POS delivery flow.
- Add a fallback manual-address path that is explicit when geocoding is unavailable.

### 3.6 Delivery fee is not "5 EUR per 5 km"

Current code:

- `database/seeders/OrderSetupTableSeeder.php:24-26`:
  - free delivery km = `2`,
  - basic charge = `1`,
  - charge per km = `1`.
- POS calculation:
  - `resources/js/components/admin/pos/PosComponent.vue:2133-2146`.
- Frontend checkout calculation:
  - `resources/js/components/frontend/checkout/CheckoutComponent.vue:754-767`.

Current formula:

```text
if distance > free_km:
  charge = basic_delivery_charge + ((distance - free_km) * charge_per_kilo)
else:
  charge = basic_delivery_charge
```

This is not "5 EUR per 5 km".

Required business decision before patch:

- Option A: every started 5 km block costs 5 EUR, including the first block:
  - 0.1-5 km = 5 EUR,
  - 5.1-10 km = 10 EUR,
  - 10.1-15 km = 15 EUR.
- Option B: keep a free radius, then every started 5 km block costs 5 EUR after that radius:
  - 0-free_km = 0 or base fee,
  - then `ceil((distance - free_km) / 5) * 5`.

Recommended technical fix:

- Move delivery fee calculation to backend or a shared backend-authoritative service.
- Frontend can display an estimate, but backend must recompute.
- Add parity tests for POS and frontend checkout.

---

## 4. Dashboard / Decomposition Status

### 4.1 What exists

Existing dashboard/admin pieces:

- Products: `/admin/items`.
- Categories: `/admin/settings/item-categories/list`.
- Product availability toggle exists in product list.
- Category wizard fields exist.
- Some projection/availability tests pass.

### 4.2 What does not exist yet

Not yet delivered as requested:

- A single "Catalog Manager" dashboard with categories sidebar, product grid, edit drawer, stock badges, offers, and live sync indicators.
- A real "Stock Manager" dashboard with quantities, thresholds, movements, adjustments, live updates.
- A unified "Order Live Board" dashboard for all POS/Kiosk/Web orders with status columns and handover.
- Branch realtime version dashboard showing kiosk/POS/KDS devices up-to-date or stale.
- Complete "offer as product" management.

### 4.3 Why it was not delivered

This is not blocked by a lack of technical understanding. It is blocked by phase governance:

- Current active work is Train A: release persistence, quote/payment/queue hardening, D-M13 unique queue number.
- Train B contains dashboard centralisation, catalog projection, roles, archive legacy.
- The plan says Train B is blocked until Train A is closed.
- Product edits are currently blocked by safety-check because `OrderService.php` is staged in a frozen zone.

So the correct answer is:

```text
The requested dashboard control plane is planned and decomposed, but not fully implemented.
The repo currently has legacy admin pieces, not the new unified operational dashboard.
```

---

## 5. What I Would Patch First After Safety Unlock

Priority order:

1. `KIOSK-LOCK-01`
   - `resources/js/components/DefaultComponent.vue`
   - `resources/js/router/modules/kioskRoutes.js`
   - kiosk route/layout tests
   - Goal: `/kiosk/idle` has no backend navbar/menu/admin/POS links.

2. `KIOSK-CONNECTION-02`
   - `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
   - `resources/js/components/common/ConnectionStatusBanner.vue`
   - connectivity tests
   - Goal: customer kiosk does not show scary websocket loss if menu/order APIs still work.

3. `POS-WALKIN-03`
   - `app/Http/Requests/PosOrderRequest.php`
   - `app/Services/OrderService.php`
   - `resources/js/components/admin/pos/PosComponent.vue`
   - POS feature tests
   - Goal: takeaway/counter order resolves walk-in customer automatically; delivery still requires customer/address.

4. `POS-DELIVERY-MAPS-FEE-04`
   - Maps loader/config
   - delivery fee backend authority
   - POS and frontend checkout fee parity
   - Goal: address flow works; fee matches agreed 5 EUR / 5 km rule.

5. `DASHBOARD-CONTROL-PLANE-05`
   - create unified Catalog/Stock/Orders admin views after Train A unlock
   - Goal: replace fragmented management experience with a usable real dashboard.

---

## 6. Exact Files Likely Needed For Correction

Immediate kiosk lock:

- `resources/js/components/DefaultComponent.vue`
- `resources/js/router/modules/kioskRoutes.js`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- test files under `tests/js/` or Playwright/static route tests.

Connection banner:

- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/common/ConnectionStatusBanner.vue`
- `resources/js/services/WebSocketService.js` if state semantics need refinement.

POS walk-in:

- `app/Http/Requests/PosOrderRequest.php`
- `app/Services/OrderService.php`
- possibly `app/Services/FrontendOrderService.php` if symmetry is required by order flow changes.
- `resources/js/components/admin/pos/PosComponent.vue`
- feature tests under `tests/Feature/Pos/`.

Delivery:

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/frontend/checkout/CheckoutComponent.vue`
- `resources/views/master.blade.php` or a reusable Google Maps loader helper.
- backend service/request/tests for delivery fee authority.
- settings/seeders only after deciding the business rule.

Dashboard:

- existing product/category modules can be reused.
- new components likely under:
  - `resources/js/components/admin/catalog/`
  - `resources/js/components/admin/stock/`
  - `resources/js/components/admin/orders/`
  - Vuex/store modules for catalog/stock/live orders.
- API controllers/routes for stock/catalog/live order operations.

---

## 7. Questions That Need Human Decision

Only one decision is blocking the delivery fee patch:

```text
Delivery pricing rule:
A) 0-5 km = 5 EUR, 5-10 km = 10 EUR, etc.
B) keep a free radius/base fee, then charge 5 EUR per started 5 km block after that.
```

For kiosk admin access:

```text
Should hidden kiosk admin be fully removed from customer builds, or allowed only behind an explicit maintenance mode/PIN?
```

---

## 8. Recommended Next State

Do not ask Claude for architecture yet. The root causes are clear.

Next mechanical step:

1. Resolve the current safety halt:
   - either complete/sign the gate for staged frozen order-service changes,
   - or unstage/finish the active Train A work according to the repo governance.
2. Apply `KIOSK-LOCK-01` first.
3. Then apply POS walk-in and delivery fixes.
4. Only after those blockers are fixed, return to the large dashboard/control-plane build.

Current final status:

```text
USER_BLOCKERS_DIAGNOSED: YES
PRODUCT_PATCH_APPLIED_IN_THIS_PASS: NO
REASON: safety-check HALT on staged frozen app/Services/OrderService.php
CLAUDE_NEEDED_NOW: NO
CLAUDE_NEEDED_LATER: only if governance/gate conflict or dashboard scope is expanded before Train A close
```
