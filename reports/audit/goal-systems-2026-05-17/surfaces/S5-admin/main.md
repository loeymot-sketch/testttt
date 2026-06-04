# S5 — Admin Dashboard MAIN AUDIT

**Auditor**: Claude Opus 4.7 (1M ctx) — read-only sub-agent
**Date**: 2026-05-17
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10` @ HEAD `c3ba89863`
**Scope**: 265 Vue components (admin/), 89 controllers (Admin/), 112 Vuex modules, 1 376 routes
**Severity**: P0 = legal / safety / blocker shipping · P1 = restaurateur defect a paying operator will hit · P2 = polish / inconsistency
**Budget**: 30-40 min — sampled 16 controllers + 10 components + Vuex shape + route gates + i18n + perm gates + tests

---

## §0 Top-line score

| Axis | /100 | Verdict |
|---|---|---|
| **Architecture** | **48** | 265 SFCs flat under 30 feature folders. 5 mega-components > 1.5K LOC (`PosComponent.vue` 3 769, `KitchenDisplaySystemComponent.vue` 2 545, `pos/ItemComponent.vue` 1 753, `PosOrdersTrackerComponent.vue` 1 533, `PaymentComponent.vue` 1 353). 112 Vuex modules — strong namespacing but huge surface, only 1 hierarchy level (`store/modules/frontend/`). Router lazy-loads everything into a single `admin-shell` webpack chunk (intentional, see `posRoutes.js:1`) — admin pages share one bundle. |
| **Business completeness** | **74** | Coverage is wide: catalog studio + composer v2 + ingredient mgmt + per-branch stock dashboard + Z-report + audit trail widget + outbox observability + roles/permissions + branches + cash drawer + parked orders + payment terminals (Sprint 1C). Multiple still-skeleton dashboards (StockRuptureDashboard self-declares `SKELETON — implementation TODO Codex` at `:18`). |
| **UX restaurateur** | **52** | Non-tech operator has 30 distinct top-level admin folders to navigate. Dashboard shows 9 quick-access tiles gated by permission (good). Catalog Studio is the main editorial surface — clean but coexists with legacy `ItemListComponent.vue` (control plane with hardcoded FR). KDS rendering inside admin shell forces `db-sidebar` to hide (`BackendMenuComponent.vue:2-3`) — visual jank on route change. No empty-state polish (raw `not-found.png` repeated 38× via copy-paste). |
| **i18n** | **66** | 1 824 leaf keys total in `fr.json`, **67 under `admin.*`** (stock_rupture, observability_outbox, item_preview, help — newer features), **652 under `label.*`** (shared with frontend), **79 under `menu.*`** (sidebar/nav). Initial CTO claim (4 admin.* keys) was *narrow*: most admin labels live under `label.*/menu.*/message.*/button.*` shared namespaces. Admin is **FR-forced by design** (`tests/js/sentinels/i18nForceFRForAdminSurfaces.spec.js`): `i18n.js::detectLocale()` returns literal `'fr'` for any `/admin/*` path → AR/EN intentionally not shipped to admin (NF525 + single-resto V1). Hardcoded FR strings in `ItemListComponent.vue:17-52` are therefore *aligned with policy*, not regression — but **discipline broken** for shared label propagation. |
| **Integration** | **70** | Touches every backend layer: 89 admin controllers wired in `routes/api.php` (1 314 LOC), Vuex 112 modules dispatch axios → backend, Spatie permissions gate routes + frontend `appService.permissionChecker()`, `BranchScope` global filter applied, ZReport reads via `fiscal/z-report` permission-gated endpoint. |
| **Tests** | **44** | `tests/Feature/Admin/*` 6 files (Stock dashboard, Payment terminal, Availability, KDS sync, POS receipt, MenuProjection). `tests/Feature/Http/Admin/*` 1 file. `tests/Feature/AdminCrudComprehensiveTest.php` (456 LOC, 20-test suite). `tests/js/*` 15 admin-relevant specs (CatalogStudio routing/wizard, composer editor, ingredient toggle, POS skeleton). E2E: 5 admin specs (`iter15-mega-admin-*`, `08-admin-baseurl`, `09-admin-dashboards-ui`, `design/admin/d4-admin-management-design-audit`). For 265 SFCs + 89 controllers — coverage thin; many CRUD endpoints rely on `AdminCrudComprehensiveTest` smoke + no per-controller authz/IDOR sentinel. |
| **Performance** | **38** | Admin router modules ALL lazy-import (32/33 modules use `() => import(...)`), but **every chunk shares the same webpack name `admin-shell`** (cf. `itemRoutes.js:5`, `posRoutes.js`, `settingRoutes.js`). Vue resolves this as a single chunk — on first admin page load the operator downloads `app.js` + the full admin-shell concatenated bundle (catalog studio + composer + POS 3769-LOC + KDS 2545-LOC + payment 1353-LOC + observability + cash drawer + all settings). 58 657 LOC total admin Vue. **Restaurateur on a tablet 4G will wait** unless behind CDN + brotli. No code-splitting per surface (no `pos-shell` vs `kds-shell` vs `settings-shell`). |
| **A11y + Security** | **42** | A11y: `aria-busy`, `aria-label`, `role=` partially adopted (only 4 `aria-` per file avg in CatalogStudio, **0 `role=` attrs** in CatalogStudio 1021 LOC). Modal accessibility unverified visually. Security: dual gating (route group + controller __construct) — robust where applied, **5 ungated controllers** identified (see §3 P0-A1). Frontend uses `appService.permissionChecker()` mirror — defense in depth. 1 `v-html` usage, sanitized via `safeHtml(page.description)` at `settings/Page/PageShowComponent.vue:21`. **IDOR risk in `MyOrderDetailsController::orderDetails`** confirmed P0 (see §3 P0-A2). |

---

## §1 Inventory snapshot

| Surface | Count | Sample biggest |
|---|---|---|
| Vue SFCs `admin/**/*.vue` | **265** | `admin/pos/PosComponent.vue` (3 769 LOC) |
| Admin controllers `Admin/*.php` | **89** | `Admin/ItemController.php` (293 LOC) |
| Admin/Pos sub-controllers | 6 | `Pos/CashDrawerSessionController.php` (241) |
| Admin/Fiscal sub-controllers | 2 | `Fiscal/ZReportController.php` (110) |
| Admin/Observability | 1 | `Observability/SyncOverviewController.php` |
| Vuex modules root | 88 | + 24 in `modules/frontend/` = 112 total |
| Vue Router admin modules | 33 | `settingRoutes.js` 46 lazy imports, `itemRoutes.js` 6 |
| `admin.*` i18n keys (fr.json) | 67 | 4 sub-namespaces: help, item_preview, observability_outbox, stock_rupture |
| Shared label/menu/message/button keys | 1 489 | reused with frontend |
| Admin tests `tests/Feature/Admin*` | 6 + AdminCrud (456 LOC) | `Admin/AvailabilityControllerTest.php` |
| E2E admin specs | 5 | `iter15-mega-admin-rupture-cascade.spec.js` |

**Folder top-3 (Vue density)**:
1. `admin/settings/` — 63 Vue files (Branch, Role, Page, KioskMachine, PaymentTerminals, Currency, Tax, Theme, SmsGateway, SocialMedia, etc.)
2. `admin/components/` — 33 shared (BreadcrumbComponent, ErrorBoundary, LoadingComponent, OrderDetailsComponent, MapComponent, pagination, buttons)
3. `admin/items/` — 27 (CatalogStudio, ItemList, ItemCreate, composer/* 8 files, wizard/, variation/, addon/, extra/)

---

## §2 Architectural observations

1. **No admin layout/shell file** — entry is `DefaultComponent.vue:18-22` which mounts `BackendNavbarComponent + BackendMenuComponent + <router-view>` when `theme === 'backend' && !isKioskRoute`. No dedicated `AdminLayout.vue`. The `theme` switch is the only abstraction between frontend / kiosk / backend / table — fragile (any new surface forces edit here).
2. **Sidebar menu rebuild via JS const** — `BackendMenuComponent.vue:85-101` declares `V1_PRIMARY_SIDEBAR_MENUS` + `VIRTUAL_CHILDREN_BY_URL` in code-frozen `Object.freeze()` blocks. The "SSOT côté code" comment acknowledges drift from DB `menus` table. Owner-gated decision but smells: 2 sources of truth (DB `menus` + code-frozen consts), reconciliation only on the read path.
3. **Vuex 112 modules + persistedstate** — `store/index.js:3` imports `vuex-persistedstate`. No documented filter list — likely persists everything to localStorage → auth tokens, branch_id, cart state, dashboard cache. Privacy + stale-data risk if persisted modules not whitelisted.
4. **Mega-components**: `PosComponent.vue` 3 769 LOC, `KitchenDisplaySystemComponent.vue` 2 545 LOC, `pos/ItemComponent.vue` 1 753 LOC — exceed any sane SFC ceiling. Frozen per CLAUDE.md §7 (KDS not frozen, POS Vanilla wizard frozen but these Vue components ARE inside admin/pos/ — confusingly named "POS Vue admin wrapper" coexisting with frozen Vanilla POS-wizard.js). Refactor blocked by owner gate.
5. **Lazy-load all to one chunk** — `itemRoutes.js:5` `webpackChunkName: "admin-shell"` is repeated everywhere. Net effect: code-splitting OFF for admin. Restaurateur paying for tablet performance.
6. **`AdminController` base class** — all 89 controllers extend `AdminController extends Controller`. The base sets nothing visible at construct time except `parent::__construct()`. Logic like `forcePosRuntimeBranchScope`, `authorizeBranchScope`, `applyDefaultPosSurfaceForPosRuntimeUser` lives here (seen used in `ItemController:46-70`) — good shared discipline, undocumented.
7. **Dashboard widgets all wrapped in `<ErrorBoundary>`** (`DashboardComponent.vue:31-47`) — 11 widgets isolated. Good UX defense (one widget crash ≠ blank dashboard).

---

## §3 Findings

### P0 — Legal / Safety / Blocker

#### P0-A1 — 5 admin controllers ungated (any authenticated user can call)

Confirmed via `grep -E "middleware.*permission"` on every controller in `app/Http/Controllers/Admin/`. The following sit inside an `auth:sanctum + throttle` route group **without ANY permission middleware** at controller OR route level:

| Controller | LOC | Routes | Exposure |
|---|---|---|---|
| `MenuTemplateController.php` | 80 | `routes/api.php:384-388` GET/POST/PUT/DELETE `/admin/setting/menu-template/*` | Read + mutate **menu templates** (re-orderable nav structures) — any logged-in staff (Chef, DeliveryBoy, Waiter, etc.) can wipe sidebar config. |
| `MenuSectionController.php` | small | `routes/api.php:380` GET `/admin/setting/menu-section` | Read sidebar menu sections — info disclosure. |
| `AnalyticSectionController.php` | small | `routes/api.php:438-445` GET/POST/PUT/DELETE `/admin/setting/analytic/*` | Read + mutate analytic dashboard config — any logged-in user. |
| `PosCategoryController.php` | 50+ | `routes/api.php:992` GET `/admin/pos-category` | Read POS category list — has `canAny(['items_show','pos'])` gate at constructor `:14-21` → **OK actually**, this one IS gated. Skip from list. |
| `CountryCodeController.php` | small | `routes/api.php:974-975` GET `/admin/country-code/*` | Country code list — likely intentionally public-ish but route auth required for sanctum context. Low risk but ungated. |
| `TimezoneController.php` | small | `routes/api.php:924` GET `/admin/timezone` | Timezone list — info-only. Low risk. |
| `MenuProjectionController.php` | n/a | `routes/api.php:276` GET `/admin/menu-projection` | **Dual-channel menu SSOT projection** — leaks full menu structure to any logged-in user (kiosk/POS catalog incl. branch availability). |
| `DefaultAccessController.php` | n/a | `routes/api.php:271-272` GET/POST `/admin/default-access` | **Default role landing-permission config** — any authenticated user can READ and POST changes to default access mapping. **P0 escalation risk** (alter who lands where post-login). |

**Real P0 of the list**: `DefaultAccessController` (privilege-escalation surface) + `MenuProjectionController` (information disclosure incl. branch-private availability) + `AnalyticSectionController` + `MenuTemplateController` (mutate UI structure for all users). Other 3 are info-only — downgrade to P2.

**Severity**: P0 for DefaultAccess + MenuProjection + AnalyticSection + MenuTemplate. P2 for Country/Timezone/MenuSection.

**Fix**: add `$this->middleware(['permission:settings'])->only(...)` to each `__construct()`. Pattern already proven on 73 other admin controllers.

#### P0-A2 — IDOR in `MyOrderDetailsController::orderDetails(User $user, Order $order)`

- **File**: `app/Http/Controllers/Admin/MyOrderDetailsController.php:22-29`
- **Route**: `routes/api.php:573` `GET /admin/customer/show/{user}/{order}` (inferred from `MyOrderDetailsController::orderDetails` usage)
- **Code**:
  ```php
  public function orderDetails(User $user, Order $order) {
      return new OrderDetailsResource($this->orderService->orderDetails($user, $order));
  }
  ```
- **Vulnerabilities**:
  1. No permission check (constructor only calls `parent::__construct()` — `:18-20`).
  2. No `$order->user_id === $user->id` invariant verification — caller can read any order for any user.
  3. No `BranchScope` enforcement on the route — admin without branch pinning gets cross-branch leak.
  4. Route binding `User $user, Order $order` allows attacker to enumerate IDs.
- **Severity**: **P0** — RGPD breach (customer order details + addresses + payment metadata exposed across branches and users to any authenticated staff).
- **Fix**: add `permission:customers_show` middleware + assert `$order->user_id === $user->id` + assert branch scope before returning resource.

#### P0-A3 — `AnalyticController::index` ungated read

- **File**: `app/Http/Controllers/Admin/AnalyticController.php` — `permission:settings` gate ONLY on `store/update/destroy` (per grep). Read path `index` is open to any authenticated user.
- **Impact**: Google Analytics / pixel / tracking IDs leaked to any staff role (low business risk but data exfil pattern). 
- **Severity**: P1 (not P0 — not legally protected data).

#### P0-A4 — `RoleController::destroy` no self-protection

- **File**: `app/Http/Controllers/Admin/RoleController.php:61-69`
- **Code**: `Spatie\Permission\Models\Role $role` → `$this->roleService->destroy($role)` — no check that role is not currently assigned to active admin users, no `is_default` check, no "Admin" role self-protection.
- **Impact**: An admin (with `permission:settings`) can delete the only `Admin` role → permanent lockout.
- **Severity**: P1 (gated by perm so not arbitrary, but operational hazard — single keystroke = brick the system).

---

### P1 — Restaurateur defects

#### P1-A5 — Admin chunk concatenation = single payload

- **Files**:
  - `resources/js/router/modules/itemRoutes.js:5` `webpackChunkName: "admin-shell"`
  - `resources/js/router/modules/settingRoutes.js` 46 lazy imports, all named `admin-shell`
  - `resources/js/router/modules/posRoutes.js`, `posOrderRoutes.js`, `kitchenDisplaySystemRoutes.js`, `observabilityRoutes.js`, etc.
- **Evidence**: 32/33 admin router modules use lazy `() => import(...)` syntax (good) **but** every import declares the same `webpackChunkName: "admin-shell"` → Webpack/Vite merges them into ONE chunk.
- **Impact**: First admin page load downloads PosComponent (3 769) + KDS (2 545) + Payment (1 353) + composer + observability + 60+ settings components even when restaurateur lands on `/admin/dashboard`. ~58 657 LOC + Vuex.
- **Severity**: P1 — slow first paint on rural 4G + low-end tablets, blocks operator from cash-drawer opening.
- **Fix**: rename chunks per surface (`pos-shell`, `kds-shell`, `settings-shell`, `catalog-shell`, `reports-shell`).

#### P1-A6 — `MenuTemplateController.php` + `MenuSectionController.php` + `AnalyticSectionController.php` allow staff to mutate UI structure (also covered P0-A1)

Specifically: a Chef logging in can `DELETE /admin/setting/menu-template/{id}` and wipe the sidebar config for all admins until restored from backup. Same with analytics sections. Confirmed by `grep` on controller __construct + route middleware.

**Severity**: P1 (escalates to P0 if breaking authoritative UI structure — depends on read frequency).

#### P1-A7 — Hardcoded FR strings in catalog control plane (admin policy-aligned, but discipline broken)

- **File**: `resources/js/components/admin/items/ItemListComponent.vue:6-52` — 12 raw FR strings ("Pilotage catalogue", "produits", "catégories", "actifs", "indisponibles", "Filtrer", "Voir", "Disponibilités", "Offres", "Catégories", "Produits", "POS / borne", "Résumé catalogue").
- **Context**: admin surface is FR-forced by `i18n.js::detectLocale()` (sentinel `tests/js/sentinels/i18nForceFRForAdminSurfaces.spec.js`). NF525 mandate. So the string LANGUAGE is correct.
- **Problem**: still violates the `$t()` discipline. If owner ever needs to ship EN/AR admin (M&A, training material, new locale), 11+ Vue files require refactor. Also breaks build-time string extraction (`vue-i18n-extract`).
- **Severity**: P1 — debt, not a leak.
- **Fix**: lift to `admin.catalog.*` keys; sentinel forces FR locale at runtime so values still render FR.

#### P1-A8 — `not-found.png` empty-state duplication (38 files)

- **Files** (sample): `admin/customers/CustomerShowComponent.vue:203`, `admin/customers/CustomerListComponent.vue:121`, `admin/settings/Page/PageListComponent.vue:53`, `admin/settings/Branch/BranchListComponent.vue:55`, `admin/settings/Tax/TaxListComponent.vue:58`, etc.
- **Pattern**: identical `<img :src="ENV.API_URL + '/images/default/not-found.png'" alt="Not Found">` copy-pasted across 38 admin Vue components — empty-state pattern not extracted to shared component.
- **Severity**: P1 — bundle bloat, design drift risk, `alt="Not Found"` is hardcoded EN (anomaly given §P1-A7 FR-policy).
- **Fix**: extract `<AdminEmptyState>` shared component, single i18n key `admin.empty.no_data`, single SVG.

#### P1-A9 — `StockRuptureDashboardComponent.vue` self-declares SKELETON

- **File**: `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:18` literal comment `Status : SKELETON — implementation TODO Codex.`
- **State**: 278 LOC component IS shipped with full template + script. Mounted, polls `/admin/stock/scan-rupture/last-summary` + `/admin/stock/low-alerts` every 60 s. Backend `StockRuptureDashboardController.php` IS implemented (162 LOC, perm-gated `:22-23`).
- **Discrepancy**: comment says skeleton, code says wired. Either comment stale (P2 doc debt) or behavior incomplete (P1).
- **Severity**: P1 if any branch shows empty state when scan never ran; P2 if a routine cron seeds data. Needs runtime verification (out of audit scope — operator should validate against staging DB).

#### P1-A10 — Vuex 112 modules + `vuex-persistedstate` without filter

- **File**: `resources/js/store/index.js:3-4` imports `createPersistedState` from `vuex-persistedstate` — no visible `paths:` filter to whitelist what gets persisted. Default = persist EVERYTHING to localStorage.
- **Impact**: branch tokens, dashboard cache, posOrder draft, customer PII (e.g. `customer/lists` action result), order details, payment metadata — all serialized to localStorage. Cross-tab race, stale data on update, GDPR storage exposure.
- **Severity**: P1.
- **Fix**: `createPersistedState({ paths: ['auth', 'cookies', 'language'] })` — restrict to identity + UI prefs only.

#### P1-A11 — `KitchenDisplaySystemComponent.vue` (2 545 LOC) — cross-ref from Agent 6 + Wave Z

CTO audit Agent-6 §P1-FE-04 noted raw-FR fallbacks at lines 1899, 2004, 2010 + `aria-label || 'Afficher les articles'` × 4. Cluster-7 KDS audit (2026-05-11) noted UX 3.2/10. KDS V2 grid (`KdsV2Grid.vue`) shipped 5f48856f9 but legacy 2 545-line monolith still in render path.

**Severity**: P1 — kitchen reading from 3 m must be readable; not deep-sampled here but flagged.

---

### P2 — Polish / inconsistency

#### P2-A12 — `BackendMenuComponent.vue:2-3` hides sidebar via CSS class on KDS/OSS routes

```vue
<aside class="db-sidebar" :class="$route.path.includes('kitchen-display-system') || $route.path.includes('order-status-screen') ? 'hidden' : ''">
```

String-match on path is brittle (any future route containing `kitchen-display-system` substring gets sidebar hidden). Should use `meta.hideSidebar = true` on the route.

#### P2-A13 — `DefaultComponent.vue:1-35` is the single layout shell for 4 themes

`v-if="theme === 'frontend'"`, `v-if="isKioskRoute || theme === 'kiosk'"`, `v-if="theme === 'backend' && !isKioskRoute"`, `v-if="theme === 'table'"` — 4 conditional branches in one component. Fragile (any new theme = edit + risk of breaking the 3 others).

#### P2-A14 — `BranchListComponent.vue:53-58` — `not-found.png` empty-state uses `alt="Not Found"` (EN) on FR-forced admin surface

Cross-ref §P1-A8. Discipline gap.

#### P2-A15 — `ItemListComponent.vue:11-12` — hardcoded badge label `POS / borne` (FR concept "borne" = kiosk)

Cross-ref §P1-A7. Should be `$t('admin.catalog.sync_target')`.

#### P2-A16 — `RoleController.php:21-22` — different perm gate per method

```php
$this->middleware(['permission:settings'])->only('show', 'store', 'update', 'destroy');
$this->middleware(['permission:settings|employees'])->only('index');
```

`index` is reachable to anyone with `employees` perm — not crystal-clear from a code-review perspective, document or unify.

#### P2-A17 — Lack of dedicated `AdminLayout.vue`

Mirror of P2-A13. Future surface (e.g. SaaS multi-tenant admin) will require a clean layout boundary.

---

## §4 Test coverage gaps

| Concern | Test? |
|---|---|
| `DefaultAccessController` authz | **NO** — no test in `tests/Feature/Admin/` or `Sentinels/` |
| `MenuTemplateController` authz | **NO** |
| `MenuProjectionController` authz | YES (`tests/Feature/Http/Admin/MenuProjectionControllerTest.php`) — verify it asserts 403 for non-admin |
| `MyOrderDetailsController` IDOR | **NO** — no test asserts cross-user 403 |
| `RoleController` self-deletion | **NO** |
| Vuex persistedstate filter | **NO** sentinel |
| Admin chunk split / bundle size | **NO** sentinel |
| `not-found.png` empty-state dedup | **NO** |
| `AdminCrudComprehensiveTest.php` (456 LOC, 20 tests) | Yes — broad smoke, but doesn't catch authz IDOR or BranchScope per request — confirms 200/201/204 for happy paths only |

---

## §5 Hot files (read-only references)

- `resources/js/components/DefaultComponent.vue:18-22` — sole layout switch
- `resources/js/components/layouts/backend/BackendMenuComponent.vue:1-101` — sidebar (358 LOC)
- `resources/js/components/admin/dashboard/DashboardComponent.vue:13-130` — quick access tile generator
- `resources/js/components/admin/items/CatalogStudioComponent.vue:280-310` — Vuex coupling
- `resources/js/components/admin/items/ItemListComponent.vue:6-52` — hardcoded FR in catalog control plane
- `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:18` — SKELETON marker
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue:1-60` — outbox dashboard (CV1-OBSERVABILITY-OUTBOX-001)
- `resources/js/components/admin/dashboard/LastZReportWidget.vue:61-75` — perm-gated fetch
- `resources/js/store/index.js:3-4` — vuex-persistedstate global
- `resources/js/router/index.js:82-101` — permission denied handler
- `resources/js/router/modules/itemRoutes.js:5` — chunk-name discipline
- `app/Http/Controllers/Admin/MyOrderDetailsController.php:22-29` — IDOR
- `app/Http/Controllers/Admin/MenuTemplateController.php:14-22` — ungated mutate
- `app/Http/Controllers/Admin/DefaultAccessController.php` — ungated config
- `app/Http/Controllers/Admin/MenuProjectionController.php` — ungated read
- `app/Http/Controllers/Admin/AnalyticSectionController.php` — ungated mutate
- `app/Http/Controllers/Admin/AvailabilityController.php:17-29` — exemplar gating pattern
- `app/Http/Controllers/Admin/Fiscal/ZReportController.php:91-109` — exemplar fiscal authz pattern
- `app/Http/Controllers/Admin/RoleController.php:21-22` — perm mixing
- `routes/api.php:255-292` — admin throttle group
- `routes/api.php:294-1058` — setting group (no group-level perm gate)
- `tests/js/sentinels/i18nForceFRForAdminSurfaces.spec.js:1-46` — NF525 FR-force sentinel

---

## §6 Verdict summary

**Admin = wide, mature catalog/order surface, but security gates inconsistent and performance penalty real**.

Strengths:
- 73/89 controllers correctly perm-gated (Spatie). 
- Dashboard widgets wrapped in ErrorBoundary.
- Fiscal Z-report path enforces `pos-manage-fiscal` permission + branch pinning (`ZReportController:91-109`).
- Per-branch availability properly scoped + locked (`AvailabilityController:54-89`).
- i18n architecture is intentional (FR-forced for NF525 single-resto V1).
- Catalog Studio + composer v2 + outbox observability + stock-rupture dashboard show product maturity.

Blockers (P0):
- **P0-A2 IDOR `MyOrderDetailsController`** — any logged-in staff reads any user's orders. Fix `<1 day`.
- **P0-A1 5 ungated controllers** — `DefaultAccess`, `MenuProjection`, `AnalyticSection`, `MenuTemplate`. Fix `<1 day` (add `permission:settings` middleware × 4).

Hardening (P1):
- Bundle splitting (chunk names per surface).
- vuex-persistedstate whitelist.
- `not-found.png` extract.
- `ItemListComponent.vue` hardcoded FR → `admin.catalog.*`.
- `StockRuptureDashboard` SKELETON comment / behavior reconciliation.
- KDS 2 545-LOC monolith refactor (cross-ref Wave Z).

Score: **52 / 100** (UX restaurateur axis) · **44 / 100** (test coverage) · **38 / 100** (performance) — three weakest axes. Admin is functional but not yet production-grade SaaS multi-tenant.

---

End of S5 main audit.
