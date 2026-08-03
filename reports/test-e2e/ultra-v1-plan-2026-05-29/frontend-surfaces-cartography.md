# Frontend / Display Surfaces Cartography — KDS / OSS / Admin Dashboards

> Read-only ultra-audit. UX / A11y / Frontend specialist. 2026-05-29.
> Repo: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
> Method: Bash `find`/`grep`/`wc` + `Read` (dedicated Grep/Glob tools were
> unavailable this session). Every cited path was opened or matched live; all
> counts below are from grep that returned output. No component, route, or i18n
> key is invented; unverifiable items are marked NOT FOUND per the
> anti-hallucination contract.

---

## Display Surfaces — Verified Cartography (file:line + route)

### KDS — Kitchen Display System (mature, well-architected)
- **Main component**: `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — **2880 lines**.
  - `:1075` `import kdsSyncService from "../../../services/KdsSyncService"` — component delegates sync to the service; it renders + handles bump/recall.
  - `:1527-1540` `kdsSyncService.on('sync', ({ gatedIds, orders, deleted_ids }) => …)` + `kdsSyncService.on('error', …)` + `kdsSyncService.start(this.authBranchId())` — **event-driven order ingestion** (delta: orders + deleted_ids).
  - `:1482` recall-ticker `setInterval`, `:1542` 1 s sync-stamp ticker ("synced Xs ago" badge), `:2485` `kdsSyncService.stop()` on teardown.
  - Bump/recall handlers in template: `:427` `:aria-label="\`${$t('button.kds_bump')} — ${item.item_name}\`"`, `:434` `$t('button.kds_recall')`, `kdsIsBumped`/`kdsCanRecall`/`kdsBump`/`kdsRecall`.
  - **i18n/a11y density (verified counts)**: **123× `$t(`**, **74× `aria-`**, **16× `role=`**, 32× `catch`, 8 focus refs, 5 empty-state checks, 3 `beforeUnmount/unmounted`. Very strong i18n + ARIA.
- **Sub-components**: `KdsV2Grid.vue` (576), `KdsOrderCard.vue`, `KdsOrderLine.vue`, `KdsStatusBanner.vue`, `KdsUndoToast.vue` (bump-undo), `KdsHistoryDrawer.vue` (recall/history).
- **Sync service** `resources/js/services/KdsSyncService.js` (503 lines) — **hybrid push + adaptive poll**:
  - Endpoint `:145` `GET /api/admin/kds-order/sync?since=<lastSince>&branch=…&include_deleted=true` — **delta sync** with a per-order `_versionMap` (`:177`) for id+version dedupe (`tests/js/kdsDedupeByIdVersion.spec.js`).
  - Adaptive cadence `:25-34`: `CADENCE_FLOOR_MS=250`, `CADENCE_CEILING_MS=60000`; states highActivity **3 s** / degraded **5 s** / disconnected **10 s**, +jitter; `:190` 60 s high-activity window after a change. Floor/ceiling guard against owner-misconfig freezing the board.
  - Backoff on 5xx (`:329-330`), token-hydration skip (`:138`), `:100` `stop()`. Hooks `window._wsService` for WS push.
- **Bump-tracking store** `resources/js/store/modules/kds.js` (88 lines, fully read): `localStorage` per-order bumped-items map (`kds.bumped_items_v1`).
  - `:36-43` `isReadyOrder` — ready only when **every** line is bumped.
  - `:66-85` `recallItem` — **60 s grace window** (`now - b[itemId] >= 60000` → `grace_expired`).
  - `:3-9` `kdsStatusPayload` → `{ id, status, expected_status }` — optimistic-concurrency push (server rejects stale, tested by `tests/Feature/KdsExpectedStatusConflictTest.php`).
- **Route**: `router/modules/kitchenDisplaySystemRoutes.js` (chunk `admin-kds`), `name: 'admin.kitchen-display-system'`. `router/index.js:118-119` adds alias `/kds` → redirect. (CLAUDE.md §6 `/kds` is a real alias — no drift.)

### OSS — Order Status Screen (mature, customer-facing wall)
- **Shell**: `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` — 68 lines (fully read). `<ConnectionStatusBanner suppress-transient />` + a 2-col grid `role="main"` `:aria-label="$t('label.oss_main_aria')"` → `<PreparingAndReadyComponent />`. PopularItem sidebar removed by owner (`[Wave Q-3]`): OSS shows only EN PRÉPARATION / PRÊT.
- **Board widget**: `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` — 489 lines.
  - `:25/:48` `:aria-label="$t('label.preparing'/'label.ready')"`, `:28/:50` i18n'd headers, `:40/:62` `v-if="…length === 0" … —` **explicit per-column empty state**.
  - Order ingestion is **dual-path**: `:234-249` `ossSyncService.on('sync', …)` + `:257-289` `subscribeEcho()` listening to Echo broadcasts `OrderStatusChanged` / `OrderCreated` via `onEvents(branchId, …)`, with a documented **double-chime dedupe guard** (`_echoMarkedReady`, `:269-276`) so Echo and the poll-driven `list()` don't both fire the ready cue.
  - `:387-398` `list()` → `$store.dispatch('orderStatusScreenOrder/lists')` with `.catch` → `alertService.error(... || $t('message.something_wrong'))` — **error surfaced**.
  - Customer-display polish: 4-tone Web-Audio ready chime (`:329-367`) gated off on the **unauthenticated public wall** (`authBranchId() <= 0`, `:338`), Screen Wake Lock, 10 s ready-flash window (`[Wave S-3]`, TV-readable at ≥3 m), `:251` `ossSyncService.stop()` on teardown.
- **Sync service** `resources/js/services/OssSyncService.js` (451 lines) — same hybrid model. Cadence `:9/:16`: **connected 60 s**, **disconnected 2 s** fallback (Echo/Pusher is primary in prod; poll only when WS 6001 down). Backoff 5 s→30 s cap (`:329`), `:224-231` `visibilitychange` burst-poll on focus regain, `:34-35` ceiling/floor clamp (fixes a prior `999999999`-freeze bug), `:90` `stop()`.
- **Route**: `router/modules/orderStatusScreenRoutes.js:8` `/admin/order-status-screen`, `:9` alias `/order-status-screen`, `name: 'admin.order-status-screen'`, `meta.permissionUrl: 'order-status-screen'`. `router/index.js:216-224` marks OSS **public-mountable** (customer wall, special auth).

### Admin dashboards (Vue SPA components, not Blade)
- **Stock rupture**: `admin/stock/StockRuptureDashboardComponent.vue` (649). Route `router/modules/stockRoutes.js` (chunk `admin-shell`). Tested: `tests/js/stockRuptureRoute.spec.js`, `stockRuptureDashboardMount.spec.js`, `stockRuptureDashboardComponent.spec.js`.
- **Cash overview**: `admin/cashOverview/CashOverviewComponent.vue` (538). Route `router/modules/cashOverviewRoutes.js:11` `/admin/cash-overview`, `name: 'admin.cash-overview'` (chunk `admin-reports`; Admin + Branch Manager RBAC via `CashOverviewController`).
- **Items catalog**: `admin/items/ItemListComponent.vue` (845) + `CatalogStudioComponent.vue` (971) + `items/composer/` tree (`ProductComposerEditorComponent`, `ComposerPublishDiffModal`, `ComposerVersionConflictBanner`, …). Routes `router/modules/itemRoutes.js`.

---

## Vue inventory (`find … -name '*.vue'` → **390** components)
- **admin/** (bulk): `settings/` (~70, largest), `items/` + `items/composer/` (~25), `dashboard/` (~17 widgets incl. `StockLowAlertsWidget`, `SlaAlertsComponent`, `LastZReportWidget`), `pos/` + `pos/v5/` DS (PosV5Button/Card/Numpad/Pill/QtyStepper/SearchInput/StatChip/TotalRow/TrancheRow), `kitchenDisplaySystem/` (7), `orderStatusScreen/` (3), `stock/` (1), `cashOverview/` (1), `onlineOrders/`, `posOrders/`, `tableOrders/`, reports.
- **frontend/kiosk/** (very large): `KioskWizard/App/Upsell/Cart/Confirmation`, 5 error screens, a `ds/` kiosk DS (15 `Ks*` atoms incl. `KsA11ySettings`, `KsVirtualKeyboard`, `KsConsentModal`), a `steps/` wizard tree (Pain/Viande/Sauce/Garnitures/Supplements/Taille/FritesStyle/Menu/GenericChoices).
- **frontend/** (non-kiosk): account, auth, checkout, home, menu, search, pages.
- **table/** + **layouts/table/**: dine-in (disabled in V1 per memory).
- **layouts/**, **common/** (`ConnectionStatusBanner.vue`), `DefaultComponent.vue`.

---

## Frozen-zone files confirmed present (Y/N)
| File | Present |
|---|---|
| `frontend/kiosk/KioskWizardComponent.vue` | **Y** |
| `frontend/kiosk/KioskAppComponent.vue` | **Y** |
| `frontend/kiosk/KioskUpsellComponent.vue` | **Y** |
| `admin/pos/PaymentComponent.vue` | **Y** |
| `admin/pos/v5/PosV5TrancheRow.vue` | **Y** |

All 5 present (read-only — not opened/modified).

---

## Maturity score
- **KDS: 8.5 / 10** — Hybrid Echo-push + adaptive 3/5/10 s poll with backoff, jitter, floor/ceiling clamp, and id+version delta dedupe; sync extracted to `KdsSyncService` so the component just renders; optimistic `expected_status` concurrency; per-line bump w/ all-lines-ready gate; 60 s recall grace; undo toast + history drawer; localStorage persistence; 123× `$t` / 74× `aria-` / 16× `role` / 32× `catch`; deep test coverage (10+ Vitest + 10+ PHPUnit + Playwright). Only real knock: the **2880-line god-component** (maintainability/regression hazard on a kitchen-critical screen).
- **OSS: 8 / 10** — Same hybrid service, dual-path Echo+poll with double-chime dedupe, clean teardown, per-column empty state, i18n'd headers + aria-labels, error surfaced via `alertService`, plus customer-wall craft (4-tone chime gated off the public wall, Wake Lock, 10 s TV-readable flash, visibility burst-poll). Knocks: **no `aria-live` region** for status transitions (P2), and `suppress-transient` banner hides brief reconnects (P3 — verify prolonged-outage cue is loud enough).
- **Admin dashboards (stock/cash/items): existence + tests VERIFIED, deep behaviour not scored** (538–971-line components, not opened line-by-line).

---

## Findings (adversarial)

### `[P2] KitchenDisplaySystemComponent.vue (2880 lines) — god-component`
- **Evidence** [VERIFIED]: one SFC of 2880 lines owns grid + bump + recall + undo + sync wiring + ticket-print HTML (`:2225`). Sync is correctly extracted, but rendering/interaction is monolithic.
- **Impact**: highest regression surface in the operational UI; hard to unit-test render paths in isolation; risky to patch on a kitchen-critical screen.
- **Fix**: push more interaction logic into `KdsV2Grid`/cards (already children). Not urgent for V1 (works + tested) — flag for V1.0.x hardening.

### `[P2] resources/js/store/modules/kds.js — bumped-items localStorage map has no in-module prune`
- **Evidence** [VERIFIED full file]: `bumpItem` writes `kds.bumped_items_v1`; module exposes no `clearOrder`/prune mutation. Recall is 60 s-gated but the persisted map isn't pruned within this module when an order leaves the board.
- **Impact**: map grows across long service days; if a backend order id were ever reused, a new order's line could read as already-bumped. Kitchen-correctness edge.
- **Fix**: confirm the component / `KdsSyncService.applyOrders` prunes entries for orders absent from the feed (it already receives `deleted_ids` — wire a `clearOrder` mutation to that). If already done elsewhere, downgrade to P3.

### `[P2] OSS PreparingAndReadyComponent.vue — no aria-live announcement on status transition (KDS has it, OSS doesn't)`
- **Evidence** [VERIFIED]: `grep aria-live` returns **0** across all three OSS components (`OrderStatusScreenComponent`, `PreparingAndReadyComponent`, `PopularItemComponent`). By contrast **KDS is exemplary** — `KdsV2Grid.vue:119` `<div class="sr-only" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>`, `KitchenDisplaySystemComponent.vue` 16× aria-live, `KdsUndoToast.vue` `aria-live="assertive"`. OSS columns carry only static `:aria-label` + an audio chime; no live region wraps the order lists — a screen-reader user gets no spoken cue when an order flips to PRÊT.
- **Impact**: WCAG 4.1.3 (status messages) gap **specific to OSS**. Low blast radius on a customer wall → P2.
- **Fix**: mirror the KDS `sr-only aria-live="polite"` pattern in `PreparingAndReadyComponent.vue` — announce "Commande N° X prête" when an order enters the PRÊT column (the `_markNewReady` hook is the natural place).

### `[P3] OrderStatusComponent.vue duplicated across 4 directories`
- **Evidence** [VERIFIED]: identical name in `admin/components`, `admin/components` root, `frontend/components`, `table/components` — customer order-tracking widget cloned per surface (distinct from the OSS screen).
- **Impact**: a status-mapping/i18n fix in one copy silently misses the others.
- **Fix**: consolidate to one shared component with surface props.

### `[P3] OSS suppress-transient banner could mask a prolonged outage`
- **Evidence** [VERIFIED OrderStatusScreenComponent.vue:3-9]: reconnect banner intentionally suppressed on OSS; staleness relies on terminal `session_invalid` + chime/poll cadence (2 s disconnected fallback).
- **Impact**: a 30–90 s hiccup shows no visible "reconnecting" cue on the customer wall; data just stops refreshing until next success.
- **Fix**: verify `session_invalid` is visible enough; consider a discreet "last updated Xs ago" stamp on the OSS surface.

### `[P3] No raw-i18n-key leakage found — i18n is healthy`
- **Evidence** [VERIFIED]: KDS main 123× `$t`, OSS shell + board fully `$t`-driven, the `kds.js` store carries no UI strings (correct — pure state). Hardcoded-FR scan of the KDS template surfaced only a print-ticket `<title>Ticket cuisine</title>` (`:2225`, inside a generated print document, acceptable). No `kds.*`/`oss.*` raw keys leaking to screen.
- **Note**: positive finding — operational screens are well-localized.

---

## Existing tests (verified-real, abundant)
- **Vitest** (`vitest.config.mjs`, `tests/js/`): `kdsState`, `kdsSyncCadence`, `kdsCadenceFloor`, `kdsAriaI18n`, `kdsDedupeByIdVersion`, `kdsSource`, `kdsV2KillSwitch`, `kdsTimerEscalation`, `kdsStationFilter`, `kdsLineSemantics`, `kdsCustomization`, `kdsAllergens`, `kdsBumpRecall`, `kdsLegacyDeliveryAllLanes`, `kdsVisualHealsWaveT`, `orderStatusScreenOssSync`, `ossChimePublicWall`, `posOssCadenceCap`, `posSyncFallback`, `stockRuptureRoute`, `stockRuptureDashboardMount`, `stockRuptureDashboardComponent`. (All under `tests/js/`.)
- **PHPUnit** (`phpunit.xml`, `tests/Feature/`): `KDSFlowTest`, `KdsTransitionWhitelistTest`, `KdsExpectedStatusConflictTest`, `KdsChangeStatusConcurrencyTest`, `KdsBranchFilterExactTest`, `KDSScopeRestrictionTest`, `KdsPaginationOverflowTest`, `KitchenDisplaySystemOrderSortTest`, `OSSReadOnlyTest`, `DeliveryBoyOrderStatusOrderingTest`.
- **Playwright** (`tests/Playwright/`, `tests/e2e/`): `KdsMultiScreenPlaywrightTest.spec.js`, `round3-verify-heals.spec.js`, `rush-sync-flow.spec.js`, `rush-100-cross-surface.spec.js`, `f4-button-matrix-visual.spec.js`.
- Catalog (`ItemList`/`CatalogStudio`) component-level Vitest: NOT FOUND in this sweep (item logic likely covered by backend tests — not exhaustively searched).

---

### Cross-surface note (for sync-system specialist)
KDS and OSS share a near-identical hybrid: Echo/Pusher push primary (`branch.{id}` events `OrderStatusChanged`/`OrderCreated`; KDS also `_wsService`) + adaptive HTTP poll fallback (KDS 3/5/10 s by state w/ id+version delta dedupe; OSS 60 s connected / 2 s disconnected). Both clamp cadence to [250 ms, 60 s] (a prior `999999999` freeze-the-wall misconfig was patched). OSS dedupes Echo-vs-poll double-fire of the ready chime. This matches BRAIN's measured 137–161 ms sync latency (push path). **Primary residual risk: soketi/WS availability on the V1 local box** — if WS 6001 is down, both screens silently degrade to the poll fallback (functional, but confirm soketi is in the local supervisor process list so push stays live).
