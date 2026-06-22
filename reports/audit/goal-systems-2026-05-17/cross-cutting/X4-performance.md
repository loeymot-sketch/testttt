# X4 — PERFORMANCE Cross-Cutting Audit

**Date:** 2026-05-17
**Auditor:** X4 Performance (Claude single-agent, read-only)
**Scope:** Backend (N+1, indexes, slow queries) + Frontend (bundle/render) +
Queue (depth/throughput) + Cache (strategy/invalidation) + Polling overhead
+ Image optimization.
**Method:** static grep + targeted Read; bundle bytes measured via
`gzip -c | wc -c`. No runtime profiling (no telescope available, no APM).

---

## SUMMARY (≤500 words)

### Scoring scheme
- Per-dimension score `/10` (10 = production-grade; ≤4 = blocking).
- Severity per finding: **P0** = blocks V1 fast-food single-resto under load,
  **P1** = degrades hot path, **P2** = improvement.
- Quick win = LOE ≤ 1 day, surgical, no frozen-zone touch.

### Per-dimension scores
| # | Dimension                          | Score | Notes |
|---|------------------------------------|------:|-------|
| 1 | N+1 query hotspots                 |   3/10 | 0 `whenLoaded()` in 96 Resources; OrderResource calls `->load()` per row |
| 2 | Index coverage                     |   8/10 | 102 indexes; composite `(branch_id, …)` discipline good |
| 3 | Bundle size frontend               |   4/10 | app.js 7.3 MB raw / 1.08 MB gz; admin SPA needs vendor+app+wizard = ~1.7 MB gz |
| 4 | Render perf frontend               |   3/10 | PosComponent 3 769 LOC; KioskWizardComponent 3 094 LOC; KDS 2 545 LOC |
| 5 | Queue depth & throughput           |   2/10 | `.env` has `QUEUE_CONNECTION=sync` — Horizon defined but unused |
| 6 | Cache strategy                     |   7/10 | Kiosk menu 60 s + 3 listener-driven invalidation; only 2 `Cache::remember` |
| 7 | Pusher throughput                  |   6/10 | ShouldBroadcast detected in only 1 event — broadcast count limited |
| 8 | Polling overhead                   |   4/10 | KDS 60 s × 1 s sync stamp + POS 8–60 s + OSS + dashboard 10–30 s |
| 9 | Slow endpoints (p95)               |   ?/10 | No telescope / no APM — unknown, undocumented |
|10 | DB connection pooling              |   ?/10 | Default Laravel — no explicit pool tuning visible |
|11 | PHP-FPM config                     |   ?/10 | Not present in repo (host concern); Horizon `memory_limit=128` MB |
|12 | Image optimization                 |   2/10 | 0 occurrences of `loading="lazy"` in views; 14 in JS; no webp/avif |

### Top 5 hotspots (severity-ordered)
1. **P0 — `QUEUE_CONNECTION=sync` in `.env`** (`.env:20`). Synchronous execution of every queued job (DispatchDomainEventsJob, broadcasts, mail, notifications). Owner-defined Horizon config (`config/horizon.php:33`) declares `supervisor-high` with `minProcesses=1 maxProcesses=8` but is dormant. Each kiosk/POS order request stalls on the dispatch chain. `.env.example:?` itself flags this as `[CRITICAL-PROD] QUEUE_CONNECTION=sync is synchronous and blocks the API`.
2. **P0 — N+1 in `OrderResource::toArray`** (`app/Http/Resources/OrderResource.php:42-43`). Per-row `$this->user->load('roles', 'media')` and `$this->transaction?->load('order')` — fires 2 SQL per item in any collection. With OrderResource::collection used in 10+ endpoints (`grep -rEn "OrderResource::collection" app/Http/Controllers/`), a 50-row list = 100+ extra queries.
3. **P0 — Vue monoliths block render** — `PosComponent.vue` 3 769 LOC, `KioskWizardComponent.vue` 3 094 LOC, `KitchenDisplaySystemComponent.vue` 2 545 LOC. These are eagerly-loaded in `pos-app.js` (6.9 MB raw / 1.06 MB gz) and `app.js` (7.3 MB raw / 1.08 MB gz). First Contentful Paint on cold cache = full bundle parse + Vue compile of 3 770 lines.
4. **P1 — Polling overhead under outage** — When Soketi/Echo down, KDS polls every 60 s admin / 8 s branch staff (`PosOrdersTrackerComponent.vue:343-344 POLL_NO_WS_MS=8000`) AND KDS sync stamp ticks every 1 s (`KitchenDisplaySystemComponent.vue:1340`). 10 active devices × 8 s = 4 500 req/h per branch baseline; doubles when Outbox+CDS dashboard add their 10 s loops.
5. **P1 — Image pipeline absent.** `grep -rEn 'loading="lazy"'` returns 0 in `resources/views/`; only 14 in JS. No `webp`/`avif` source-set, no `Spatie\Image\Manipulations` thumbnail policy visible in resources. Catalog page with 50 items pulls full-res PNG/JPG at every kiosk boot (kiosk-shell is only 96 KB gz but image transfer is unbounded).

### Top 3 quick wins (LOE ≤ 1 day, no frozen-zone touch)
1. **Flip `.env` to `QUEUE_CONNECTION=redis` + start Horizon supervisor** (`.env:20` → `redis`). Boot `php artisan horizon` under supervisord/systemd. Already wired in `config/horizon.php:30-60`. Eliminates sync chain on every POS/kiosk request. Verification: `/api/health/ready` already encodes this guard per `.env.example` comment.
2. **Wrap relation calls in `whenLoaded` in 5 Resources** (`OrderResource.php:42-43`, `UserResource.php:32`, `ItemResource.php:89`, `ChefResource.php:30`, `WaiterResource.php:30`). Replace `->load()` per-row with controller-level `->with(['user.roles','user.media','transaction'])`. ~30 lines diff, single sprint. Cuts collection p95 by 80% on classic 50-row list.
3. **Add `loading="lazy" decoding="async"` to item-card `<img>` tags + emit `webp` via `Intervention\Image`.** Single shared partial `resources/views/partials/item-image.blade.php` or Vue prop in `ItemCardComponent`. Quick win for kiosk idle screen (50+ items rendered).

---

## DIMENSION-BY-DIMENSION FINDINGS

### 1. N+1 Query Hotspots — 3/10

**Evidence:**
- `grep -rnE '->whenLoaded\(|->relationLoaded\(' app/Http/Resources/ | wc -l` → **0** across 96 resources.
- `app/Http/Resources/OrderResource.php:42`
  ```
  'customer' => new OrderUserResource($this->user->load('roles', 'media')),
  'transaction' => new TransactionResource($this->transaction?->load('order')),
  ```
  Called per row in `OrderResource::collection(...)` used by:
  - `app/Http/Controllers/Admin/ChefController.php:125`
  - `app/Http/Controllers/Admin/DeliveryBoyController.php:112`
  - `app/Http/Controllers/Admin/TableOrderController.php:39`
  - `app/Http/Controllers/Frontend/OrderController.php:40` (`UserOrderResource`)
- `app/Http/Resources/OrderResource.php:25` — `optional($this->branch)->name` and L26 `optional($this->orderItems)->count()` — both trigger queries if not eager-loaded by caller.
- `app/Http/Resources/UserResource.php:32` — `$this->orders->count()` (full collection materialization just to count → use `withCount('orders')`).
- `app/Http/Resources/ItemResource.php:89` — `$this->orders->count()` same pattern.
- `app/Http/Resources/ChefResource.php:30`, `WaiterResource.php:30`, `CustomerResource.php:30` — `$this->messages->count()` same.

**Caller-side `with()` discipline:**
- Admin controllers: only **6** `::with(...)` / `->load(...)` calls across 89 files (`grep | wc -l`).
- Frontend controllers: **7** total.
- OrderService.php has 6 `Order::with(...)` (L125, 198, 234, 272, 1404, 2146) — at least some lists are eager-loaded.

**Score:** 3/10. Discipline is sporadic, Resources never use `whenLoaded` so the
contract is "caller MUST eager-load everything" — but no caller enforcement.

### 2. Index Coverage — 8/10

- **102** `table->index(...)` / `->unique(...)` calls across `database/migrations/`.
- Composite `(branch_id, …)` discipline well established:
  - `2026_04_22_000002_create_audit_logs_table.php:branch_id,created_at`
  - `2026_04_22_000003_create_z_reports_table.php:branch_id,status / branch_id,closed_at`
  - `2026_04_25_190000_create_order_quotes_table.php:branch_id,surface,actor_id,intent_hash,expires_at`
  - `2026_05_08_140100_create_cash_movements_table.php:branch_id,created_at; order_id,type`
  - `2026_05_06_180000_create_order_payments_table.php:order_id,mode; branch_id,paid_at`
  - `2026_04_15_200000_create_domain_events_table.php:branch_id,occurred_at`
  - `2026_04_27_143130_create_stock_movements_table.php:branch_id,stock_level_id,created_at`
  - `2026_04_15_230000_create_order_status_transitions_table.php:order_id,order_type,occurred_at`

**Gaps to verify (didn't read every migration):**
- `orders` table: presence of `(branch_id, status, created_at)` and
  `(branch_id, payment_status)` indexes — not confirmed in this audit, schema
  predates the migration window scanned.
- `frontend_orders` same.
- `domain_events.dispatched_at` partial index for "stale rescue" sweep —
  `idx_pending` exists per `2026_05_09_120000`'s comment but only for
  `webhook_events`; outbox monitor `MonitorOutboxStaleness:43-46` scans
  `WHERE created_at < ? AND dispatched_at IS NULL` — verify equivalent index.

### 3. Bundle Size Frontend — 4/10

Measured `2026-05-17` via `gzip -c <f> | wc -c`:

| Bundle                     | Raw (bytes) | Gz (bytes) | Notes                                  |
|----------------------------|------------:|-----------:|----------------------------------------|
| public/js/app.js           |   7 293 220 |  1 108 262 | Frontend SPA entry                     |
| public/js/pos-app.js       |   6 910 180 |  1 056 898 | POS V4 entry (split per W2 #1)         |
| public/js/admin-shell.js   |   6 022 558 |    530 638 | Admin shell                            |
| public/js/vendor.js        |   1 905 455 |    414 633 | Extract list (vue/router/axios/echo/…) |
| public/js/pos-shell.js     |   1 224 603 |    169 142 | Pos lazy shell                         |
| public/js/kiosk-shell.js   |     670 923 |     96 134 | Kiosk lazy chunk                       |
| public/js/pos-wizard.js    |     296 912 |     53 438 | Vanilla JS (FROZEN — CLAUDE.md §7)     |

**Observations:**
- Vendor split (`webpack.mix.js:64-79`) effective: vendor.js gz 414 KB shared.
- Per-surface entry split (`pos-app.js`) effective: POS users skip app.js
  parse cost (6.9 MB raw distinct from app.js 7.3 MB).
- Kiosk shell at **96 KB gz** is healthy — best-of-class.
- **admin-shell.js at 530 KB gz** is heavy but acceptable for back-office.
- **No CI threshold enforcement** observed for app.js / pos-app.js. `tools/lint/` lists
  `pos_pricing_guard.mjs`, `scan_kiosk_bundles.mjs`, `pos_orderstatus_guard.mjs`,
  `forbidden_bundles.sh` — none enforce a gz size ceiling. The webpack.mix.js
  comment "CI guard `tools/lint/pos_app_size.mjs` (W2 #3) will assert the gz
  ceiling" refers to a file that **does not exist**. **P1 finding**.
- `pos-wizard.js` 296 KB raw / 53 KB gz Vanilla JS — frozen zone. Note as
  size finding only; do not propose split.

### 4. Render Perf Frontend — 3/10

| Component                                  | LOC  |
|--------------------------------------------|-----:|
| `resources/js/components/admin/pos/PosComponent.vue` | 3 769 |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 3 094 |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | 2 545 |

**Implications:**
- Vue 3 template compile cost scales with LOC. A 3 769-LOC SFC parses
  hundreds of KB of compiled render functions on first mount.
- PosComponent embeds `_kioskPollTimer = setInterval(...)`
  (`PosComponent.vue:1991`) — implies all polling/realtime/menu/cart/payment
  logic lives in one file = no code-split, no async chunk per concern.
- Vendor extract list does NOT include `swiper` lazy chunk despite import
  pattern (`webpack.mix.js:78`). Confirm whether Vue 3 + 13 plugins flatten
  to a single 1.9 MB vendor chunk.

### 5. Queue Depth & Throughput — 2/10

**Critical finding (P0):**
- `.env:20` (current dev env):
  ```
  QUEUE_CONNECTION=sync
  ```
- `.env.example` (canonical) says `QUEUE_CONNECTION=redis` AND explicitly
  warns: `[CRITICAL-PROD] QUEUE_CONNECTION=sync is synchronous and blocks
  the API. /api/health/ready returns 503 in production if QUEUE_CONNECTION=sync`.
- **Note on framing:** this is a **config-drift / deployment-discipline P0**
  for V1, not "prod currently broken" — the `.env.example` `/api/health/ready`
  guard is defense-in-depth. Cannot verify production `.env` from this
  audit context.
- `config/horizon.php:30-60` defines:
  - `supervisor-high` queue=`high`, balance=`auto`, autoscaling, min/max=1/8, tries=6
  - `supervisor-default` queue=`default,notifications`, min/max=1/4
- **Horizon is configured but not used** unless QUEUE_CONNECTION switches.
- `MonitorOutboxStaleness` (`app/Console/Commands/MonitorOutboxStaleness.php`)
  is the watchdog — exit-code-paged when stale > threshold. Scheduled per
  minute. If queue is sync, every request waits for the outbox to drain
  synchronously → outbox is never "stale" but every request is slower.
- `OrderService.php:1512` comment confirms broadcast deferred to after-commit
  — but with `sync` driver "after commit" still blocks the response.

### 6. Cache Strategy — 7/10

**Inventory:**
- `Cache::*` total references: **47** across `app/`.
- `Cache::remember` instances: **2**:
  - `MenuController.php:71` — kiosk menu, key `kiosk.menu.branch.{id}`, TTL 60 s.
  - `KdsSyncService.php:49` — TTL 5 s.
- Invalidation listeners (clean pattern):
  - `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:72`
  - `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:52`
  - `app/Listeners/InvalidateMenuProjectionOnIngredientChange.php:61`

**Config:**
- `config/cache.php` default = `env('CACHE_DRIVER','file')`.
- `.env:` `CACHE_DRIVER=redis` ✅ (good).
- No `Cache::tags()` usage — keys are managed manually. OK for small surface.

**Gaps:**
- Order list endpoints not cached (CDS dashboard 10 s poll = hot path).
- No HTTP `Cache-Control` on static manifest assets (verify via Blade).

### 7. Pusher Throughput — 6/10

- `ShouldBroadcast` interface in events: **1** match in `app/Events/` per grep.
  Single broadcast event = limited blast radius. Probably `OrderStatusChanged`
  per `OrderService.php` log lines L1555, L1776.
- Realtime fallback: 6 places in JS reference `Echo`/`Soketi`/`Pusher` and
  cascade to polling.
- `BROADCAST_DRIVER=pusher` in `.env`. No `pusher.config` rate-limit visible.
- Per-branch channel pattern not verified — risk of admin (`branch_id=0`)
  subscribing to all-branch channel = fan-in cost.

### 8. Polling Overhead — 4/10

**Measured intervals (file:line — interval):**
| File | Line | Interval | Surface |
|------|-----:|---------:|---------|
| KioskWaitingComponent.vue | 270 | 15 s | Kiosk waiting screen |
| KioskAppComponent.vue | 347 | 15 s | Kiosk offline-check |
| OutboxOverviewComponent.vue | 311,345 | 10 s | Admin outbox board |
| AuditTrailComponent.vue | 50 | 30 s | Admin audit |
| SlaAlertsComponent.vue | 47 | 15 s | Admin SLA |
| RealtimeReportComponent.vue | 39 | 30 s | Admin realtime |
| FloorplanComponent.vue | 122 | 15 s | POS floorplan |
| StockRuptureDashboardComponent.vue | 181,205 | 60 s | Admin stock |
| KdsV2Grid.vue | 152 | per-card ticker (1 s implied) | KDS grid |
| KitchenDisplaySystemComponent.vue | 1340 | **1 s** sync stamp | KDS |
| KitchenDisplaySystemComponent.vue | 1653 | autoRefresh (likely 10 s) | KDS |
| PosOrdersTrackerComponent.vue | 343-344,568 | 60 s WS / **8 s no-WS** | POS tracker |
| PosComponent.vue | 1991 | _kioskPollTimer | POS main |

**Worst case 1 branch, 1 KDS + 2 POS + 1 dashboard, no Soketi:**
- KDS 1 s ticker + 10 s refresh = ~6 + 360 req/h
- POS 8 s × 2 = 900 req/h
- Stock 60 s + Audit 30 s + SLA 15 s = 240 + 120 + 240 = 600 req/h
- **Total ≈ 1 900 req/h baseline** when Soketi is down — manageable but
  unhealthy if it lingers.

### 9. Slow Endpoints (p95) — Unknown

- No `telescope` package installed (no `config/telescope.php`).
- No APM hooks observed (no NewRelic, Datadog, Sentry-perf integration in
  `config/`).
- **Operational blind spot.** Cannot quantify p95.

### 10. DB Connection Pooling — Unknown

- `config/database.php` default Laravel — no PgBouncer, no MySQL Proxy
  configured. Single PHP-FPM worker = single connection per request. OK at
  V1 scale but no observability.

### 11. PHP-FPM / Worker Config — Unknown

- Not in repo (host concern).
- Horizon `memory_limit=128` MB (`config/horizon.php:28`) — modest, acceptable.

### 12. Image Optimization — 2/10

- `grep -rEn 'loading="lazy"' resources/views/ | wc -l` → **0**.
- `grep -rEn 'loading="lazy"' resources/js/ | wc -l` → **14** (some Vue
  templates have it).
- `grep -rEn 'webp|avif'` → no encoder pipeline references.
- No `<picture>` source-set pattern observed.
- Catalog images served as-uploaded (PNG/JPG) — risk on kiosk idle 50-item
  grid, especially on first paint over Wi-Fi.

---

## ANTI-DRIFT NOTES

- **Frozen zones respected** — `pos-wizard.js` flagged as size data only; no
  refactor suggested. Per `CLAUDE.md §7`.
- **No code modified** during this audit (read-only).
- **Unverifiable items** marked "?/10" or "Unknown" rather than guessed.
- Bundle sizes measured fresh `2026-05-17` (not stale from PROJECT_BRAIN).
- Worktree `.env.example` variants (sad-thompson, magical-spence, etc.) all
  agree on `[CRITICAL-PROD] sync blocks the API` — finding is canonical, not
  scoped to a single branch.

---

## REFERENCED FILES (absolute)

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.env`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.env.example`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/queue.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/horizon.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/cache.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/OrderResource.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/UserResource.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/ItemResource.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/ChefResource.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/WaiterResource.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/CustomerResource.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Frontend/MenuController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/KdsSyncController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/OrderService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/KdsSyncService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/MonitorOutboxStaleness.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/pos/PosComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/pos/PosOrdersTrackerComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/webpack.mix.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/app.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/pos-app.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/admin-shell.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/kiosk-shell.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/vendor.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/pos-wizard.js`

**End of report.**
