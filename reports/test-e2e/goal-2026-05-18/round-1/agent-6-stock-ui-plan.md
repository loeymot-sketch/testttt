# Agent 6 — Stock UI Dashboard Build Plan
**GOAL §7 Sub 5.2 (T-5.2.1 / T-5.2.2 / T-5.2.3)**
**Date** : 2026-05-18
**Author** : Agent 6 (Build Planner)
**Verdict** : DASHBOARD EXISTS PARTIALLY — heal + extend, NOT greenfield. Budget revisé : 3-4j-agent (vs 5-7j-agent annoncés BRAIN `feedback_v1_focus_no_saas_2026-05-08.md`).

---

## 1. Discovery results

### Backend EXISTS (95% complete)
- `app/Services/Menu/AvailabilityService.php` — SSOT toggle/lock/dispatch (verified)
- `app/Services/Ingredients/IngredientAvailabilityService.php`
- `app/Services/Stock/StockService.php`, `ChoiceAvailabilityResolver.php`
- `app/Models/StockLevel.php` — BranchScope global, `MANUAL_UNAVAILABLE_REASONS` whitelist (5 reasons), `manual_unavailable_reason` + `manual_unavailable_since` columns
- `app/Models/StockMovement.php`
- `app/Models/ItemBranchAvailability` — `is_available`, `unavailable_reason='stock_rupture'|'out_of_stock_manual'|'seasonal'|'recipe_change'|'supplier_issue'|'quality_issue'`, `unavailable_since`
- Events : `app/Events/{ItemAvailabilityChanged, ItemExtraAvailabilityChanged, ItemVariationAvailabilityChanged, IngredientAvailabilityChanged, StockLevelChanged}.php`
- Routes API (verified `routes/api.php:287-292`) :
  - `GET /api/admin/stock/scan-rupture/last-summary` → `StockRuptureDashboardController::lastSummary`
  - `GET /api/admin/stock/low-alerts` → `StockRuptureDashboardController::lowAlerts`
  - `POST /api/admin/stock/scan-rupture/run` → `StockRuptureDashboardController::run`
- Manual override endpoints EXIST (separate controller, NOT wired to dashboard UI) :
  - `POST /api/admin/availability/toggle` → `AvailabilityController::toggle` (item-level)
  - `POST /api/admin/availability/toggle-extra` → `AvailabilityController::toggleExtra`
  - `POST /api/admin/availability/toggle-variation` → `AvailabilityController::toggleVariation`
  - `GET /api/admin/menu/availability/branch/{branch}` → `AvailabilityController::showBranchAvailability` (F-016a-BIS snapshot)
- Permissions : `items_show` (read) + `items_create` (run scan) + `items_edit` (toggle availability). **Note** : NO `permission:stock` exists in Spatie seeder (`database/seeders/PermissionTableSeeder.php`). GOAL plan T-5.2.3 mention `permission:stock` is aspirational — current pattern reuses `items_*`. Choice : reuse `items_edit` (lowest-cognitive-load) OR add new `stock_management` permission (clean separation). **Recommendation : reuse `items_edit`** — V1 scope-minimal, parity with toggle endpoints already shipped.

### Frontend EXISTS (60% complete)
- `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` — 278 lines, FUNCTIONAL but **read-only** (lists rupture items + low alerts + manual scan trigger). No toggle/restore actions. No bulk select. No Pusher subscription.
- `resources/js/router/modules/stockRoutes.js` — registers `/admin/stock/rupture` with `permissionUrl: "items"`, `breadcrumb: "stock_rupture"`
- `resources/js/router/index.js:15+159` — module imported + registered
- `resources/js/components/admin/dashboard/StockLowAlertsWidget.vue` — homepage widget linking to `/admin/stock/rupture`
- `resources/js/components/layouts/backend/BackendMenuComponent.vue:93` — sidebar item `stock/rupture` icon `lab-stock`
- `resources/js/components/admin/dashboard/DashboardComponent.vue:128` — push menu entry

### Test coverage EXISTS (partial)
- `tests/Feature/Admin/StockRuptureDashboardEndpointsTest.php` — endpoints assertions (last-summary + low-alerts + run)
- `tests/js/stockRuptureRoute.spec.js` — route declaration sentinel
- `tests/Feature/Stock/*` — 19 backend tests (concurrency, scope, append-only, schema, decrement/release)
- `tests/e2e/{stock-rupture-sync,iter15-stock-cascade-regression,red-team-r3-rupture-stock-live-2026-05-07}.spec.js` — cross-surface cascade

### What is MISSING (build target)
1. **Manual rupture toggle UI** — current Vue has NO button to mark/restore an item rupture from dashboard. User must navigate `/admin/items/:id` per item.
2. **Bulk multi-select actions** — no checkbox column, no batch "mark all rupture" / "restore all".
3. **Real-time Pusher subscription** — dashboard polls every 60s but doesn't listen to `ItemAvailabilityChanged` broadcast. Cross-surface <2s contract (GOAL §7) not satisfied for the admin surface itself.
4. **i18n keys 18 missing** — `admin.stock_rupture.{title,subtitle,cron_enabled,cron_disabled,run_now,last_run,last_run_at,items_flipped,items_skipped,duration_ms,currently_86,none_unavailable,flipped_at,low_alerts,no_low_alerts,below_threshold}` referenced but absent from `resources/js/languages/{fr,en,ar}.json`. Verified : `fr.json:247` has only `stock_rupture: "Tableau de bord stock"` (breadcrumb), nested keys absent.
5. **CLAUDE.md surface URL drift** — `/admin/stock-rupture-dashboard` listed in CLAUDE.md §6 but real route is `/admin/stock/rupture`. Screenshot `tests/captures/phase-c-visual-mandate-2026-05-17/04-admin-stock-rupture-dashboard.png` shows **404 page** confirming the drift.
6. **Per-extra / per-variation rupture surface** — current dashboard only lists item-level ruptures. `ItemBranchAvailability` is item-scoped. `StockLevel` (per-variation, per-extra) low alerts surfaced but no toggle.
7. **Branch filter UI** — backend supports `?branch_id=X` but Vue auto-resolves from `summaries[0]`. No dropdown for multi-branch admins.
8. **`stockableLabel` N+1** — controller does `find()` per row in `lowAlerts` (up to 200 rows × 3 polymorphic types). DBA concern. Heal via single eager-loaded query batched by type.

### Observability dashboard pattern reference
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue` — battle-tested pattern : header (title + subtitle + refresh button + secondary actions), `aria-busy`, sections in `<article class="rounded border border-slate-200 bg-white p-4">`, `data-testid` everywhere, retry/drain admin gates. **Adopt this exact pattern for parity.**
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php` — backend pattern : permission middleware in `__construct`, scoped query helpers, JSON response with `fetched_at` ISO8601.

---

## 2. Functional spec — features dashboard must offer

| # | Feature | Status | Build target |
|---|---------|--------|--------------|
| F1 | Rupture list (items currently OUT) | DONE | keep |
| F2 | Stock low alert panel (`on_hand <= threshold_low`) | DONE | keep + N+1 heal |
| F3 | Manual scan trigger button | DONE | keep |
| F4 | Per-branch filter dropdown | PARTIAL (backend `?branch_id=`, no UI) | ADD `<select>` for multi-branch admins (branch_id=0) |
| F5 | Manual rupture toggle (per item, per extra, per variation) | MISSING | ADD button per row → `POST /api/admin/availability/toggle` (item) / `toggle-extra` / `toggle-variation` with reason `<select>` (5 whitelisted reasons) |
| F6 | Bulk multi-select actions (mark N items rupture / restore N items) | MISSING | ADD checkbox column + batch action bar (`Mark rupture` / `Restore`) — batch endpoint emits N sequential `AvailabilityService::toggle` (no new batch endpoint to minimize backend risk) |
| F7 | Real-time updates (Pusher Echo) | MISSING | SUBSCRIBE to `private-branch.{branchId}.availability` channel → listen `ItemAvailabilityChanged` / `ItemVariationAvailabilityChanged` / `ItemExtraAvailabilityChanged` → reload affected row OR full `loadAll()` debounced 500ms |
| F8 | Per-branch isolation (admin branch_id=0 sees all, manager scoped to own) | DONE (controller `scopedBranches`) | keep |
| F9 | Empty / loading / error states | PARTIAL (loading bool, no error banner) | ADD error toast + retry CTA on fail |
| F10 | Search filter (item name) | MISSING | ADD `<input>` client-side filter on rupture list (no backend change) |

---

## 3. Tech plan

### Routes (no new file — extend `routes/api.php` after L292)
```php
// Manual override (REUSE existing AvailabilityController endpoints — no new wiring needed,
// just consume from dashboard UI). Endpoints already live at:
//   POST /api/admin/availability/toggle             AvailabilityController::toggle
//   POST /api/admin/availability/toggle-extra       AvailabilityController::toggleExtra
//   POST /api/admin/availability/toggle-variation   AvailabilityController::toggleVariation
//   GET  /api/admin/menu/availability/branch/{id}   AvailabilityController::showBranchAvailability
//
// No new routes required for F5/F6 — bulk emits N sequential POSTs from frontend.
```

### Controller delta (extend `StockRuptureDashboardController`)
- Heal `lowAlerts` N+1 : prefetch all `Item|ItemVariation|ItemExtra` IDs by type, single `whereIn` per type, dedup label resolver.
- Add optional `?type=item|variation|extra&search=foo` query params for client-side parity backend filter.

### Vue component tree
```
StockRuptureDashboardComponent.vue   (parent — extend, ~+250 lines)
├─ <StockDashboardHeader>            (TO BE EXTRACTED — child component, title + refresh + branch filter)
├─ <StockLastRunPanel>               (TO BE EXTRACTED — existing block 55-81)
├─ <StockRuptureList>                (TO BE EXTRACTED — existing block 83-111)
│  ├─ checkbox col (F6)
│  ├─ <StockToggleButton>            (NEW — per-row, opens reason modal)
│  └─ <StockBulkActionBar>           (NEW — sticky bottom when N>=1 selected)
├─ <StockLowAlertsList>              (TO BE EXTRACTED — existing block 113-141)
│  └─ <StockToggleButton>            (REUSE — variation/extra-aware)
└─ <StockReasonModal>                (NEW — choose reason from MANUAL_UNAVAILABLE_REASONS whitelist)
```
**Pragmatic decision** : keep single-file component for V1.0.1 (smaller PR, easier review). Extract only IF >500 lines after build. Mirror OutboxOverviewComponent which stays single-file at 600+ lines.

### API endpoints summary (all EXIST except F6 batch)
| Verb | Path | Controller | Permission |
|------|------|------------|------------|
| GET  | `/api/admin/stock/scan-rupture/last-summary` | `StockRuptureDashboardController@lastSummary` | `items_show` |
| GET  | `/api/admin/stock/low-alerts` | `StockRuptureDashboardController@lowAlerts` | `items_show` |
| POST | `/api/admin/stock/scan-rupture/run` | `StockRuptureDashboardController@run` | `items_create` |
| POST | `/api/admin/availability/toggle` | `AvailabilityController@toggle` | `items_edit` |
| POST | `/api/admin/availability/toggle-extra` | `AvailabilityController@toggleExtra` | `items_edit` |
| POST | `/api/admin/availability/toggle-variation` | `AvailabilityController@toggleVariation` | `items_edit` |
| GET  | `/api/admin/menu/availability/branch/{branch}` | `AvailabilityController@showBranchAvailability` | `items_edit` |

### Permission decision
- **Reuse `items_*`** (status quo) — zero migration, zero seeder change, parity with existing toggle endpoints.
- GOAL `permission:stock` aspirational rename DEFERRED to V1.0.2 if owner wants semantic clean-up (would touch RBAC seeder + 6+ middleware decls).

### Pusher / Echo wiring (F7)
- Add `mounted()` block in `StockRuptureDashboardComponent` :
```js
this._echo = window.Echo
  ?.private(`branch.${this.currentBranchId}.availability`)
  ?.listen('.item.availability.changed', () => this.debouncedReload())
  ?.listen('.item-variation.availability.changed', () => this.debouncedReload())
  ?.listen('.item-extra.availability.changed', () => this.debouncedReload());
```
- Debounced reload via `lodash.debounce(this.loadAll, 500)` to coalesce bursts.
- `beforeUnmount` : `window.Echo?.leave(...)`.
- **Verify channel exists** : `routes/channels.php` must declare `branch.{branchId}.availability`. If missing, add channel auth callback (TO BE CREATED in `routes/channels.php`).
- Verify events implement `ShouldBroadcast` + `broadcastOn()` returns `PrivateChannel('branch.{X}.availability')`. Audit needed before wiring — current events may broadcast to different channel names (e.g. `catalog.changed`). Adjust accordingly.

### Tests to create
- `tests/Feature/Admin/StockRuptureDashboardManualToggleTest.php` (TO BE CREATED) — covers F5 : item toggle, extra toggle, variation toggle, reason validation, branch-scope deny 403.
- `tests/Feature/Admin/StockRuptureDashboardBulkActionsTest.php` (TO BE CREATED) — covers F6 : N=10 sequential toggles, idempotency keys all distinct, partial failure rollback semantics.
- `tests/Feature/Admin/StockRuptureDashboardBranchIsolationTest.php` (TO BE CREATED) — branch manager (branch_id=5) cannot toggle item on branch_id=7 (403).
- `tests/Feature/Admin/StockRuptureDashboardPusherChannelTest.php` (TO BE CREATED) — `ItemAvailabilityChanged::broadcastOn()` returns expected channel name.
- `tests/Feature/Admin/StockRuptureDashboardLowAlertsN1Test.php` (TO BE CREATED) — `lowAlerts` endpoint with 200 rows uses ≤3 queries (one per stockable_type).
- `tests/js/stockRuptureDashboardComponent.spec.js` (TO BE CREATED at `tests/js/`) — Vitest : mount, axios mock, click toggle, click bulk action, Echo subscribe/unsubscribe lifecycle, i18n keys resolved (no raw `admin.stock_rupture.X`).
- `tests/e2e/stock-rupture-dashboard-manual-override.spec.js` (TO BE CREATED at `tests/e2e/`) — Playwright : admin login → visit `/admin/stock/rupture` → mark item rupture with reason `supplier_issue` → verify row appears in "currently 86" list → kiosk side-load `/kiosk/idle` → confirm item hidden <2s (Pusher path) OR <5s (polling fallback).

---

## 4. Visual / design spec — flat per owner pref (`feedback_design_flat_organized.md`)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Tableau de bord Stock                                  [Toutes branches ▾]│
│ Surveille les ruptures et alertes stock-bas               [Actualiser]    │
│                                                            [Lancer scan]  │
│  [ Cron actif ✓ ]                                                         │
├──────────────────────────────────────────────────────────────────────────┤
│ ⏱ Dernier scan                                                            │
│ ┌──────────────┬──────────────┬──────────────┬──────────────┐            │
│ │ 14:32 (il 2m)│ Flipped: 3   │ Skipped: 1   │ Durée: 42ms  │            │
│ └──────────────┴──────────────┴──────────────┴──────────────┘            │
├──────────────────────────────────────────────────────────────────────────┤
│ 🔴 Articles en rupture (3)        [🔍 Rechercher...]                      │
│ ┌──┬──────────────────────────────┬──────────┬──────────┬──────────────┐│
│ │☐ │ Big Burger                   │ Branche 1│ il y a 2m│ [Restaurer]  ││
│ │☐ │ Chicken Burger XL            │ Branche 1│ il y a 5m│ [Restaurer]  ││
│ │☐ │ Frites grandes               │ Branche 1│ il y a 8m│ [Restaurer]  ││
│ └──┴──────────────────────────────┴──────────┴──────────┴──────────────┘│
│                                                                          │
│ ── Sticky bar quand sélection ≥ 1 ──                                     │
│ [ 2 sélectionnés ] [Restaurer tout] [Marquer rupture (raison ▾)] [×]    │
├──────────────────────────────────────────────────────────────────────────┤
│ ⚠ Alertes stock bas (12)                                                  │
│ ┌──┬─────────────────────────┬──────────┬────────────┬─────────────────┐│
│ │☐ │ Sauce Algérienne (extra)│ Branche 1│  3 / 5     │ [Mettre rupture]││
│ │☐ │ Coca 33cl (variation)   │ Branche 1│  4 / 10    │ [Mettre rupture]││
│ └──┴─────────────────────────┴──────────┴────────────┴─────────────────┘│
└──────────────────────────────────────────────────────────────────────────┘
```

**Design rules** (flat, organized) :
- White cards `bg-white border border-slate-200 rounded p-4` (matches OutboxOverviewComponent exactly).
- NO gradients, NO shadows-lg, NO decorative emojis in production (icons via `lab lab-*`).
- Color semantics : rose-50/rose-700 (rupture), amber-50/amber-900 (low alert), emerald-50/emerald-700 (cron OK), slate-* (neutral).
- Section spacing `space-y-4` parent, `mt-3` inside cards.
- Sticky bulk bar : `sticky bottom-0 bg-white border-t border-slate-200 px-4 py-3 shadow-sm` (only shadow allowed — bottom bar elevation).
- Reason modal : centered `<dialog>` Vue overlay, 5 radio buttons (1 per `MANUAL_UNAVAILABLE_REASONS` enum), confirm/cancel CTAs.

**Viewports tested** : 1920×1080 (desk full), 1280×800 (laptop), 768×1024 (tablet portrait — sidebar collapsed).

**i18n keys (18 to add to `fr.json`/`en.json`/`ar.json` under `admin.stock_rupture.*`)** :
```
title, subtitle, cron_enabled, cron_disabled, run_now, last_run, last_run_at,
items_flipped, items_skipped, duration_ms, currently_86, none_unavailable,
flipped_at, low_alerts, no_low_alerts, below_threshold,
+ NEW for F5/F6 :
mark_rupture, restore, restore_selected, mark_rupture_selected, reason_label,
reason_out_of_stock_manual, reason_seasonal, reason_recipe_change,
reason_supplier_issue, reason_quality_issue, search_placeholder,
branch_filter_all, confirm_bulk, toggle_success, toggle_error
```

---

## 5. Acceptance gate

- [ ] All 5 NEW PHPUnit Feature tests GREEN
- [ ] Existing `StockRuptureDashboardEndpointsTest` still GREEN (no regression)
- [ ] Vitest `stockRuptureDashboardComponent.spec.js` GREEN — 100% i18n keys resolved
- [ ] Playwright `stock-rupture-dashboard-manual-override.spec.js` GREEN
- [ ] Visual capture 3 viewports (1920/1280/768) — analyzed via Read tool, no raw labels, layout intact, flat design respected
- [ ] CLAUDE.md §6 surface URL corrected `/admin/stock-rupture-dashboard` → `/admin/stock/rupture`
- [ ] `tests/captures/phase-c-visual-mandate-*` rerun returns dashboard render (not 404)
- [ ] Cross-surface E2E (admin toggle → kiosk hide <2s) GREEN — proves F7 Pusher OR polling fallback
- [ ] Frozen-zones diff = 0 (no `BranchScope.php`, no `IdempotencyKey*`, no fiscal services touched)
- [ ] Lint (`npm run lint`) + Vue compile (`npm run dev`) GREEN
- [ ] 0 `permission:stock` references (decision : reuse `items_edit`)

---

## 6. Effort estimate

| Workstream | Hours (agent) |
|---|---|
| Backend : heal `lowAlerts` N+1 + add optional `?type/?search` query params + 1 new test | 2h |
| Backend : verify Pusher channel `branch.{X}.availability` declared in `routes/channels.php`, audit 3 events `broadcastOn`, add channel if missing | 2h |
| Backend : 4 new Feature tests (manual toggle, bulk, branch isolation, Pusher channel) | 4h |
| Frontend Vue : add toggle buttons, reason modal, checkbox col, bulk bar, branch filter dropdown, search filter, Echo subscribe lifecycle | 8h |
| Frontend : add 26 i18n keys × 3 langs (fr/en/ar) | 1h |
| Frontend Vitest spec (mount + axios mock + Echo mock + i18n assertions) | 3h |
| Playwright E2E manual override + cross-surface cascade | 4h |
| Visual capture 3 viewports + Read tool analysis + iteration if defect | 2h |
| CLAUDE.md surface URL fix + STOCK section doc update | 0.5h |
| Buffer (review, regression, owner gate prep) | 3.5h |
| **TOTAL** | **30h ≈ 3-4 jours-agent** |

(vs. BRAIN estimate "5-7j" — saved 1-3 days because dashboard skeleton already shipped + manual override backend already exists)

---

## 7. Cross-system flags

- **Connects to Agent 5 (Sub 5.3 Stock Sync)** : F7 Pusher Echo wiring depends on Outbox → Pusher pipeline being healthy. If Agent 5 finds `Outbox` lag > 2s, dashboard real-time UX degrades → polling fallback 60s catches it. Coordinate channel naming convention with Agent 5.
- **Connects to Agent 5 (Sub 5.4 Cascade E2E)** : T-5.4.1 admin toggle → kiosk/POS/KDS/OSS reflect. Manual toggle button shipped here is the *entry point* of that cascade. E2E test `stock-rupture-dashboard-manual-override.spec.js` partially covers Agent 5 T-5.4.1.
- **Permission scope** : T-5.2.3 GOAL says `permission:stock`. **DECISION : reuse `items_edit`/`items_show`/`items_create`** (no new permission added). Doc decision in `PROJECT_BRAIN.md §6 DECISIONS LOG` post-build. If owner wants `stock_management` separate role, mark V1.0.2 backlog (touches RBAC seeder + 6 middleware decls + frontend `permissionUrl` meta — separate cycle).
- **Frozen-zone interaction** : `app/Models/Scopes/BranchScope.php` already applied to `StockLevel` + relevant models (verified `StockLevel.php:25`). No frozen-zone touch needed for this build.
- **NF525 interaction** : NONE. Stock rupture flow is non-fiscal — no `audit_logs` HMAC chain impact, no `z_reports` impact, no `fiscal_sequence_no` allocation impact.
- **i18n debt** : 18 existing keys + 8 new = 26 keys × 3 langs = 78 entries. Coordinate with any agent touching `admin.*` namespace.

---

## 8. Risks & mitigations

| Risk | Probability | Mitigation |
|------|-------------|------------|
| Pusher channel naming mismatch with existing events | M | Audit before wire (1h discovery), adjust to existing convention |
| Bulk action N=100 → 100 sequential POST → 4s+ UX | L | Add backend batch endpoint OR client-side concurrency limit `Promise.all` 10-by-10 (decision deferred to build) |
| Reason modal a11y (focus trap, Esc to close, screen reader announce) | M | Reuse existing modal component pattern (search admin codebase first) |
| Branch dropdown for branch_id=0 admin shows 50+ branches → unusable | L | Add search-inside-dropdown OR scope V1 to ≤10 branches (Le Cayenne = 1 branch, non-blocking) |
| `manual_unavailable_since` clock skew (DB vs app) | L | Already handled by Eloquent `datetime` cast — TZ-aware |
| i18n AR (RTL) layout breaks sticky bulk bar | M | Add `dir="auto"` on bulk bar + capture AR viewport |

---

## 9. Anti-fiction declarations

- `app/Http/Controllers/Admin/StockRuptureDashboardController.php` — VERIFIED, opened L1-162
- `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` — VERIFIED, opened L1-278
- `resources/js/router/modules/stockRoutes.js` — VERIFIED, opened L1-17
- `app/Models/StockLevel.php` — VERIFIED, opened L1-91 (`MANUAL_UNAVAILABLE_REASONS` enum confirmed)
- `app/Http/Controllers/Admin/AvailabilityController.php` — VERIFIED, opened L1-230 (toggle endpoints confirmed)
- `routes/api.php` — VERIFIED L280-292 (3 dashboard routes + showBranchAvailability)
- `tests/Feature/Admin/StockRuptureDashboardEndpointsTest.php` — VERIFIED L1-113
- `tests/captures/phase-c-visual-mandate-2026-05-17/04-admin-stock-rupture-dashboard.png` — VERIFIED via Read (shows 404 page)
- Files marked `(TO BE CREATED)` : confirmed absent via `find` / `grep`
- `permission:stock` : confirmed ABSENT from `database/seeders/PermissionTableSeeder.php` and no controller uses it
- CLAUDE.md URL `/admin/stock-rupture-dashboard` : confirmed PRESENT L205, real route `/admin/stock/rupture` confirmed in `stockRoutes.js:7` — drift documented

---
**END agent-6-stock-ui-plan.md**
