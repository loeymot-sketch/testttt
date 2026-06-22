# Z4 — OSS Order Status Screen (Round 1 Wave Z findings)

**Auditor**: Z4 sub-agent (read-only, adversarial)
**Branch**: feature/mobile-app-le-cayenne-2026-05-10
**HEAD**: c3ba89863
**Verdict**: **GO-CONDITIONAL** — no P0, but two P1 raw-label / determinism gates must close before V1 ship; two P2 (stale-orders prune, branch enumeration) recommended for V1.1.

---

## Summary

OSS surface is implemented and wired:
- Route SPA: `/admin/order-status-screen` (router `auth: true` + `permissionUrl: order-status-screen`) but client guard **bypasses login** for public TV mount via `PUBLIC_FRIENDLY_AUTH_ROUTES` (`resources/js/router/index.js:206-213, 237-239`).
- Two backend endpoints:
  - Auth-gated admin: `GET /api/admin/oss-order` + `/popular-items` — `permission:order-status-screen` middleware (`routes/api.php:1030-1033`, `OrderStatusScreenController.php:21`).
  - Public unauth: `GET /api/frontend/oss-order` + `/popular-items` — `throttle:120,1` / `throttle:60,1` (`routes/api.php:1099-1104`, controller `publicIndex/publicMostPopularItems` lines 75-120).
- Sync architecture: dual — Echo (`OrderStatusChanged` / `OrderCreated` on `branch.{id}`) + `OssSyncService` polling fallback (60s when WS connected, 2s when disconnected; visibility-burst on tab focus, exp backoff on 5xx). Code at `resources/js/services/OssSyncService.js:8-27, 197-263`.
- Branch isolation logic: admin path uses `resolveBranchScope()` (`OrderStatusScreenOrderService.php:133-160`) which abort(403)s staff trying to access foreign branch — sentinel test `tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php` covers this. Public path uses `listForBranch()` (line 100-131) which intentionally **bypasses** auth-aware scope and `Auth::check()`-gated `BranchScope` (model line 92, scope `Auth::check()` guard at line 27).
- Payload is intentionally minimal: `CDSOrderDetailsResource` → `id, order_serial_no, token, queue_number, order_type, status` (no PII). Verified `app/Http/Resources/CDSOrderDetailsResource.php:15-25`.

No NF525 invariants touched. No fiscal / pricing / sequence files reside in OSS surface. No Sprint 2A/3C drift (SimpleOrderResource is unused here — OSS consumes `CDSOrderDetailsResource`).

---

## P0 findings (file:line)

**None.** No fiscal violation, no auth bypass, no PII leak, no Sprint 2A/3C regression.

---

## P1 findings (file:line)

### P1-Z4-01 — Raw label `label.popular_menu_items` shipped on customer wall
**File**: `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue:10`
```vue
<h3 class="text-[22px] font-semibold text-[#0057B7]">{{ $t("label.popular_menu_items") }}</h3>
```
Key **does not exist** in `lang/fr/all.php`, `lang/en/all.php`, or `lang/ar/all.php` (verified by exhaustive grep — only `oss_popular_region_aria` is defined, which is the ARIA region label, not the visible H3). Customer-facing TV display will render the literal string `"label.popular_menu_items"` above the popular-items grid — a raw label leak in the most visible region of the OSS. Visual-mandate breach (CLAUDE.md §6).
**Fix**: add `'popular_menu_items' => 'Articles populaires'` (FR), `'Popular menu items'` (EN), `'العناصر الأكثر شيوعا'` (AR) under the `label` group in each lang file.

### P1-Z4-02 — Order display is non-deterministic (no ORDER BY)
**File**: `app/Services/OrderStatusScreenOrderService.php:45-72` (`list()`) and `:103-126` (`listForBranch()`)
Both methods build the query then call `->get()` with **no `->orderBy()` clause**. MySQL is free to return rows in any order (especially when index pages flip), so the same wall can reshuffle PREPARING / PRÊT lists on every poll, breaking the "queue order" mental model customers depend on (customer with queue N°7 sees their token bounce around).
**Fix**: add `->orderBy('order_datetime', 'asc')` (FIFO — first prepared, first served). Symmetric in both methods so admin dashboard widget and customer wall agree.

---

## P2 findings

### P2-Z4-03 — Stale PREPARED orders never auto-removed until midnight
**File**: `app/Services/OrderStatusScreenOrderService.php:53-65, 111-120`
Query filters `status IN (PREPARING, PREPARED)` + `whereDate('order_datetime', Carbon::today())`. A PREPARED order never collected (customer no-show) stays on the wall until **midnight** when `Carbon::today()` rolls over. No time-window prune (e.g., `prepared_at < now()->subMinutes(30)` or move to a "stale" bucket). On a busy day this clutters the PRÊT column with orphans that confuse new customers ("Is N°42 mine? No, that's been there 2h").
**Fix (V1.1)**: either (a) add `OrderStatus::DELIVERED` / `OrderStatus::EXPIRED` transition when a PREPARED order ages 30min+, or (b) filter `where('updated_at', '>=', now()->subHour())` for status=PREPARED in the query.

### P2-Z4-04 — `mostPopularItems` cross-branch counts
**File**: `app/Services/OrderStatusScreenOrderService.php:84`
```php
return Item::with('media', 'category', 'offer')->withCount('orders')->where(['status' => Status::ACTIVE])->orderBy('orders_count', 'desc')->limit(9)->get();
```
`Item` model is presumably not BranchScope-bound (catalog is shared); the `withCount('orders')` counts **all branches' orders** for popularity ranking. Branch A's wall shows items that may be popular in branch B, not in branch A. For a multi-branch fast-food this is a wrong signal. Also no auth-aware filter: the public endpoint returns the same global ranking regardless of `?branch_id=`.
**Fix**: scope the subquery with `->withCount(['orders' => fn($q) => $q->where('branch_id', $branchId)])` when a branch is resolved (admin path: from `$branchScope`; public path: from `?branch_id=` or first active). If global ranking is acceptable as a design call, classify P3.

### P2-Z4-05 — Public endpoint allows branch enumeration / throughput disclosure
**File**: `routes/api.php:1099-1104`, `app/Http/Controllers/Admin/OrderStatusScreenController.php:75-100`
Any unauth IP can hit `GET /api/frontend/oss-order?branch_id=N` (throttle 120/min/IP) and receive queue counts for **any** branch. No PII leaks (payload is token/queue_number/serial only), but a competitor or curious actor can poll all branches and infer throughput, peak hours, current queue depth. For a multi-branch SaaS deployment this is a business-intelligence leak. Note: this is *intentional* per the controller doc-comment (line 47-73), but the trade-off is undocumented for fleet operators.
**Mitigation options**:
1. Require a signed branch token in `?branch_id=` (HMAC-stamped at SPA bootstrap from settings).
2. Lower throttle to `throttle:30,1` per (IP, branch_id) compound key.
3. Document the trade-off explicitly in `docs/MULTI_TENANT_PRIVACY.md` so V1 fleet operators consent.

### P2-Z4-06 — i18n AR (Arabic) coverage missing for OSS labels
**File**: `lang/ar/all.php`
Verified missing keys in AR: `preparing`, `ready`, `oss_main_aria`, `oss_popular_region_aria`, `popular_menu_items`. FR + EN have `preparing`/`ready`/`oss_main_aria`/`oss_popular_region_aria`; AR has none of them. If a customer wall is deployed in an AR locale (per `lang/ar/*` infrastructure exists), all OSS column headers + ARIA labels render as raw keys.
**Note**: AR is V1.x scope per BRAIN — but if V1 ships to a fleet with AR-default branches this becomes P1. Confirm with owner.

---

## P3 findings

### P3-Z4-07 — Hardcoded hex colors (no design-system var)
**File**: `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:18, 23, 25, 29, 38, 43, 50`, `PopularItemComponent.vue:10, 17, 18`
Colors hardcoded: `#B0004D` (preparing header), `#1AB759` (ready header), `#1F1F39` (body text), `#A0A3BD` (empty state em-dash), `#991B1B` (queue-number text), `#6E7191` (popular-items name), `#0057B7` (popular-items title). Empty-state `#A0A3BD` on white = ~3.0:1 contrast — below WCAG AA 4.5:1 for normal text. An em-dash placeholder so user-experience impact is low, but a11y audit will flag.
**Fix (V1.x design pass)**: extract to `--oss-color-preparing-header`, `--oss-color-ready-header`, etc. so the design system can re-skin. Raise empty-state placeholder to `#6E7191` or stronger to clear AA 4.5:1.

### P3-Z4-08 — `console.warn` paths reachable in production
**File**: `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:221, 231`
```js
console.warn('[OSS] Echo subscription failed:', e.message);
console.warn('[OSS] Echo unsubscribe error:', e.message);
```
Customer-facing TV runs in production browser. A failed Echo subscribe (network blip) prints to the visible-on-some-TVs DevTools console. Most TVs run headless Chrome so user impact is nil, but it's a hygiene smell aligned with `[P13_LOG_HYGIENE]` comments already in the file (lines 219, 229).
**Fix**: gate behind `if (window.foodkingConfig?.appEnv !== 'production')` or remove entirely now that the polling fallback covers the broken-Echo case.

### P3-Z4-09 — No `localStorage` cache to bridge between page reload and first poll
**File**: `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:88-93`
On mount, the component renders empty columns until the first XHR completes (~200ms on local, ~1s on slow LAN). A TV that reboots or refreshes flashes empty PRÉPARATION / PRÊT columns briefly. Low impact (TVs rarely reload) but a `sessionStorage`-backed last-known-state would bridge the gap. Defer to V1.x.

### P3-Z4-10 — `transition-group` keys on `item.id` only, with no `move` class for reorderings
**File**: `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:22-28, 42-49`
Vue transition-group is used (`oss-slide`, `oss-pop`) but only `enter` / `leave` are defined — no `move` transition. When the underlying list reorders (no ORDER BY today, but with P1-Z4-02 fix the order will be stable so this is moot). Plus add `.oss-slide-move` and `.oss-pop-move` for smooth visual continuity.

---

## Frozen-zone diff status

**0 — OSS surface has no frozen files.** Verified against CLAUDE.md §7 frozen list: FiscalSequenceService, ZReportService, AuditLogService, BranchScope, PricingService, OrderStateMachine, IdempotencyKeyMiddleware, KioskWizardComponent, KioskAppComponent, KioskUpsellComponent, pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php. None of these are read or modified in OSS code path.

The `[#B0004D]` and `[#1AB759]` hex literals in `PreparingAndReadyComponent.vue` are local component styles — not the frozen kiosk/POS surfaces.

---

## Evidence pointers

- Controller: `app/Http/Controllers/Admin/OrderStatusScreenController.php:13-121`
- Service: `app/Services/OrderStatusScreenOrderService.php:1-161`
- Resource (data shape): `app/Http/Resources/CDSOrderDetailsResource.php:15-25` (no PII), `app/Http/Resources/CDSPopularItemResource.php:17-26` (no PII)
- Vue root: `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue:1-62`
- Vue body: `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:1-353`
- Vue popular: `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue:1-63`
- Sync service: `resources/js/services/OssSyncService.js:1-427` (heavy iter15-mega-fix C-016/D-002/C-034 plumbing; sound, visibility, backoff all handled)
- Vuex store: `resources/js/store/modules/orderStatusScreenOrder.js:1-70` (auth-aware URL switching)
- Router: `resources/js/router/modules/orderStatusScreenRoutes.js:1-17` + `resources/js/router/index.js:206-260` (auth bypass via `PUBLIC_FRIENDLY_AUTH_ROUTES`)
- Routes: `routes/api.php:1030-1033` (admin), `:1099-1104` (public)
- BranchScope guard: `app/Models/Scopes/BranchScope.php:17-41` (Auth::check() gate at L27 → public endpoint bypasses by design)
- Order model: `app/Models/Order.php:89-92` (`addGlobalScope(new BranchScope())`)
- Sentinel test passing: `tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php:28-54`
- Echo events: `app/Events/OrderStatusChanged.php:15`, `app/Events/OrderCreated.php:19` (BroadcastableOrder contract, after-commit dispatch)
- Existing AntiGravity / sync tests: `tests/Feature/AntiGravityTest.php:334-345`, `tests/Feature/SyncComprehensiveTest.php:201-225`
- JS unit tests: `tests/js/orderStatusScreenOssSync.spec.js`, `tests/js/ossSyncFallback.spec.js`

**Verified non-issues (do not re-raise)**:
- "EN PRÉPARATION" / "PRÊT" at PreparingAndReadyComponent.vue:12, 33 are **HTML comments only** — no rendered raw string. Actual visible H3 uses `$t("label.preparing")` line 19 and `$t("label.ready")` line 39, both defined in FR + EN.
- ARIA region labels at `OrderStatusScreenComponent.vue:13` and `PopularItemComponent.vue:6` are defined in FR + EN (`oss_main_aria`, `oss_popular_region_aria`).
- Vue list keys are stable (`item.id` from server) — no v-for index-key smell.
- The PREPARED-chime is gesture-gated lazy-init (`PreparingAndReadyComponent.vue:97-107, 253-283`) so no AudioContext autoplay warnings (iter15-mega-fix C-034 round-7 closed this).
- `_echoMarkedReady` Set de-duplicates Echo + poll double-chime (`PreparingAndReadyComponent.vue:206, 291-298`) — AUDIT-P1 closed.
- Polling cadence in dev: 2s when WS down; 60s when WS up + visibility-burst on focus regain (`OssSyncService.js:8-27, 197-241`) — meets SYNC-2 8s budget.
- BranchScope sentinel test guards `?branch_id=0` privilege escalation on the **admin** endpoint (`OssAdminBranchPolicySentinelTest.php:28-54`). Public endpoint has no equivalent test because it's intentionally permissive by design — see P2-Z4-05.

---

## Convergence recommendation

**Round 2 should target P1-Z4-01 and P1-Z4-02 as fix-required-before-ship gates:**

1. Add `popular_menu_items` to all 3 lang files (`lang/fr/all.php`, `lang/en/all.php`, `lang/ar/all.php`). Single-line edit each. Visual recapture mandatory per CLAUDE.md §6.
2. Add `->orderBy('order_datetime', 'asc')` to both `OrderStatusScreenOrderService::list()` (line 71-72) and `::listForBranch()` (line 125-126). PHPUnit `OssAdminBranchPolicySentinelTest` should regress green.

**Defer to V1.1**:
- P2-Z4-03 stale-order prune (needs OrderStateMachine touch — owner gate, frozen-zone adjacent).
- P2-Z4-04 mostPopularItems branch scope (design call: per-branch vs global popularity).
- P2-Z4-05 branch enumeration mitigation (multi-tenant SaaS hardening — pair with broader privacy audit).
- P2-Z4-06 AR i18n parity (depends on fleet locale strategy).

**No-action P3s** (P3-Z4-07 to P3-Z4-10) — design-system / hygiene; bundle into a V1.x cleanup PR.

**Cross-wave note**: Z4 surface does NOT consume `SimpleOrderResource` (Sprint 2A/3C delivery enrichment) — uses its own `CDSOrderDetailsResource`. Delivery heal commits c3ba89863 / a8b363dd6 do not regress OSS. If sister sessions flag Sprint 2A/3C drift on KDS or Sync, OSS is uncoupled.

---

**Report path**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/wave-z-2026-05-16-claudemax/round-1/Z4-findings.md`
