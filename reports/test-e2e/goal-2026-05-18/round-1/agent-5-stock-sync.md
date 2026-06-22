# Agent 5 — Stock + Cross-Surface Sync Audit (Round 1)

**Mission** : GOAL Production Readiness Phase A — System 5 (Stock + Sync), sub-systems 5.1, 5.3, 5.4
**Mode** : READ-ONLY, anti-fiction (every claim anchored to opened file + line)
**Date** : 2026-05-18, branch `v1-0-1-hardening-2026-05-17`, HEAD `1235e3e1a`

---

## 1. Anchor Verification

| Anchor | Found | Notes |
| --- | --- | --- |
| `app/Models/StockLevel.php` | YES (92 lines) | BranchScope global + `MANUAL_UNAVAILABLE_REASONS` whitelist + non-negative invariants |
| `app/Models/StockMovement.php` | YES (66 lines) | BranchScope global + append-only (`UPDATED_AT=null` + LogicException on update/delete) |
| `app/Services/Menu/AvailabilityService.php` | YES (726 lines) | Single entry point for branch-scoped 86 + 7 toggle helpers + cancel/refund release |
| `app/Services/Stock/StockService.php` | YES (468 lines) | Order-driven decrement/release + idempotency_key + auto-86 sync |
| `app/Services/Stock/ChoiceAvailabilityResolver.php` | YES (~370 lines) | Snapshot for kiosk/POS wizard read paths |
| `app/Services/Ingredients/IngredientAvailabilityService.php` | YES (72 lines) | Cascade-by-name (V1.6+) |
| 10 `Persist*ToOutbox` listeners | **11 found** | spec said 10, `PersistSettingsUpdatedToOutbox.php` added 2026-05-17 (Wave 5G R9 heal) |
| `wasRecentlyCreated` guard | 14 hits across 11 listener files | **100% coverage** |
| `InvalidateKioskMenuCacheOnItemAvailabilityChanged` + `InvalidateMenuProjectionOnIngredientChange` | YES | both best-effort with try/catch |
| Outbox prune command | YES (`PruneOutboxCommand`) + scheduled daily 04:00 | Wave 5E heal |
| E2E `stock-rupture-sync.spec.js` | YES | scenarios 1-6 already implemented |

---

## 2. Sub 5.1 — Stock Backend Findings

### T-5.1.1 — StockLevel schema + indexes + BranchScope — **PASS**

`database/migrations/2026_04_27_143120_create_stock_levels_table.php` :
- UNIQUE `(branch_id, stockable_type, stockable_id)` (line 22)
- Composite index `(stockable_type, stockable_id)` (line 23)
- CHECK constraints on non-MySQL : `on_hand >= 0`, `reserved >= 0`, `reserved <= on_hand` (lines 26-30)
- FK cascade on `branch_id` and `stockable_id` lifecycle correct
- `StockLevel::booted()` lines 64-75 mirrors CHECK constraints (SQLite parity)

BranchScope global (line 25) — closes the cross-tenant leak risk documented in iter12 P0 STOCK 2026-05-09.

### T-5.1.2 — StockMovement append-only — **PASS**

`app/Models/StockMovement.php` lines 10, 46-55 :
- `UPDATED_AT=null` (no UPDATE column even via Eloquent timestamp)
- `static::updating` throws `LogicException('stock_movements is append-only.')`
- `static::deleting` throws same
- Migration line 19 : `idempotency_key` UNIQUE — fail-fast on dup decrement
- Index `(branch_id, stock_level_id, created_at)` line 22 — efficient audit queries

### T-5.1.3 — DecrementStockOnOrderCreated race condition — **PASS-with-note**

**Critical interpretation** : there is NO `Cache::lock` in `DecrementStockOnOrderCreated.php` (40 lines) or `StockService::mutateForOrderInTransaction`. Defense is **purely DB-level**, which is arguably stronger than cache-level :

1. `DB::transaction` wrap (`StockService.php:49`)
2. `lockForUpdate()` on the targeted `StockLevel` row (line 90 + 394 for release)
3. UNIQUE `stock_movements_idempotency_unique` constraint (migration line 19) — atomic dedupe
4. Movement key = `sha1(reason|order_class|order_id|line_uid|stockable_type|stockable_id)` (lines 327-344) — deterministic per (order, line, target)
5. `wasRecentlyCreated`-equivalent : `StockMovement::query()->where('idempotency_key', $movementKey)->exists()` early return (line 102)
6. Boundary detection (`crossedAvailabilityBoundary`, line 451) only emits `StockLevelChanged` when on_hand actually crosses 0

`Cache::lock` IS used **upstream** at the order creation boundary :
- `OrderService.php:587` — idempotency lock per (branch, user, idempotency key)
- `FrontendOrderService.php:156` — kiosk equivalent
- `OrderService.php:2334` — stock-rupture-protected confirmation lock

→ The decrement listener inherits idempotency from the upstream order-create lock. Calling this a "race condition" is incorrect framing. **PASS**.

### T-5.1.4 — Cache invalidation chain — **PASS**

The chain on `ItemAvailabilityChanged` (registered `EventServiceProvider.php:173-181`) fires 4 listeners in order :
1. `BumpMenuSnapshotOnItemAvailabilityChanged` (in-process, fault-tolerant) — bumps `MenuSnapshot::bump($branchId)`
2. `InvalidateKioskMenuCacheOnItemAvailabilityChanged` — `Cache::forget('kiosk.menu.branch.{id}')` (sub-second)
3. `PersistCatalogChangedToOutbox` — fan-out to all active branches for global flips
4. `PersistItemAvailabilityChangedToOutbox` — branch-scoped row

All wrapped in `try/catch` Log::warning. The snapshot bump is documented as freshness hint, not blocking.

**One minor inconsistency** : `InvalidateKioskMenuCacheOnCatalogChange` (catalog branch) calls `MenuSnapshot::bump()` (line 53), but `InvalidateKioskMenuCacheOnItemAvailabilityChanged` does NOT bump snapshot — it relies on `BumpMenuSnapshotOnItemAvailabilityChanged` running ahead. This is correct (listeners registered in order) but fragile if ordering changes. **P2-MINOR** — document the implicit ordering contract.

### Stock Backend Sub 5.1 — Sentinels existing

- `tests/Feature/Stock/StockLevelSchemaTest.php`
- `tests/Feature/Stock/StockMovementsAppendOnlyTest.php`
- `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php`
- `tests/Feature/Stock/StockConcurrentDecrementTest.php`
- `tests/Feature/Stock/StockBranchIsolationTest.php`
- `tests/Feature/Stock/AvailabilityDecrementConcurrencyTest.php`
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`
- `tests/Feature/Stock/StockSymmetryDiffTest.php`
- 8 more under `tests/Feature/Stock/` (~16 total)
- `tests/Feature/Menu/AvailabilityServiceTest.php` + 5 siblings

---

## 3. Sub 5.3 — Sync to All Surfaces Findings

### T-5.3.1 — 11 Persist*ToOutbox listeners attestation

| # | Listener | Event | Channel mode | Idempotency-key shape |
| --- | --- | --- | --- | --- |
| 1 | PersistOrderCreatedToOutbox | OrderCreated | single branch | `sha1(event_type|aggregate_id)` ONE-SHOT |
| 2 | PersistOrderStatusChangedToOutbox | OrderStatusChanged | single branch | `sha1(type|id|old|new|correlation)` transition |
| 3 | PersistOrderPaymentStatusChangedToOutbox | OrderPaymentStatusChanged | single branch | same transition shape |
| 4 | PersistOrderPaidAtCounterToOutbox | OrderPaidAtCounter | single branch | ONE-SHOT |
| 5 | PersistOrderTableChangedToOutbox | OrderTableChanged | single branch | transition (prev_table, new_table) |
| 6 | PersistItemAvailabilityChangedToOutbox | ItemAvailabilityChanged | branch or fan-out | transition (item, branch, is_available, type, correlation) |
| 7 | PersistItemExtraAvailabilityChangedToOutbox | ItemExtraAvailabilityChanged | branch | transition |
| 8 | PersistItemVariationAvailabilityChangedToOutbox | ItemVariationAvailabilityChanged | branch | transition |
| 9 | PersistCatalogChangedToOutbox | CatalogChanged (+bridged from 4 events) | fan-out per active branch | `sha1(type|entity_type|entity_id|branch|change_type|correlation)` per branch |
| 10 | PersistCouponChangedToOutbox | CouponChanged | fan-out per branch | per-branch transition |
| 11 | PersistSettingsUpdatedToOutbox | SettingsUpdated | branch or fan-out | `sha1(type|branch|keys_sorted|correlation)` |

**Freshness signal** : `PersistSettingsUpdatedToOutbox` is **NEW** (2026-05-17, Wave 5G R9), bringing the count to 11. Spec lists 10; reality is 11.

### T-5.3.2 — wasRecentlyCreated guard — **100% PASS**

`grep -l wasRecentlyCreated app/Listeners/*.php` returns **11 / 11** listener files. Documented references :
- 9 listeners use the `[Sprint 5C Z8-P1-01 2026-05-16]` heal comment
- 2 fan-out listeners (Catalog, Coupon, Settings) use `if ($domainEvent->wasRecentlyCreated)` accumulator pattern — only newly-inserted rows enter the dispatch list

Defense-in-depth : `DispatchDomainEventsJob::handle()` (`app/Jobs/DispatchDomainEventsJob.php:65-94`) also gates with `lockForUpdate` + `dispatched_at` check — so even if listener replay races past the guard, the job phase-1 atomic claim absorbs the dup. Two layers.

### T-5.3.3 — Outbox pruning — **PASS**

`app/Console/Commands/PruneOutboxCommand.php` (Wave 5E heal, 2026-05-17) :
- Predicate (safe-set UNION) : `dispatched_at < cutoff` OR `(attempts >= 6 AND created_at < cutoff)`
- Default `--older-than-days=90`
- Chunked batch delete (default 1000) — bounded lock window
- `--dry-run` flag supported
- NF525 safety : explicitly does NOT touch `audit_logs` or `z_reports` (docblock line 25-27)
- Scheduled daily at 04:00 with `withoutOverlapping()` + `onOneServer()` + `runInBackground()` (`app/Console/Kernel.php:100-106`)

Complementary lanes :
- `foodking:outbox:rescue` every minute (re-queues `stale(2 min)` AND `attempts < 5`)
- `foodking:outbox:monitor --threshold=10` every minute (pages humans)
- `foodking:outbox:retry-failed --since=24h` hourly (`attempts >= 5` lane, Sprint 3B P1-SYNC-02)

### T-5.3.4 — Polling 5s fallback per surface — **Tableau corrigé** (anti-fiction)

The "5s polling" framing in the spec is too narrow. Actual per-surface cadence :

| Surface | File | Cadence | Purpose |
| --- | --- | --- | --- |
| KDS | `public/js/admin-kds.js:1551, 1209, 1181` | `_pollingInterval()` (dynamic) + sync-stamp timer + wait-interval | Order list + sync stamp + KDS card lifecycle |
| KDS Echo | `public/js/admin-kds.js:1588-1590` | live | `ItemAvailabilityChanged` -> `_onItemAvailabilityChanged` -> Vuex `kdsInflight` |
| POS | `public/js/pos-shell.js:1091, 2956, 4206, 4667` | varied (kiosk poll timer ~5s, quote refresh, offline flush) | Cart + quote + offline-queue |
| POS Echo | `public/js/pos-shell.js:4712-4714` + `pos-app.js:62459-62461` | live | `ItemAvailabilityChanged` → `_handleItemAvailabilityChanged` (cart line removal) |
| OSS | `public/js/admin-oss.js:939, 1196` | polling fallback state (`POLLING` state machine) | Auto-disconnect after fallback timeout |
| Kiosk | `KioskAppComponent.vue:347` (15s) | offline-queue sync (NOT menu polling) | Pending count + abandoned count |
| Kiosk menu | `KioskAppComponent.vue:542-580` | live Echo on `private-branch.{id}` | `ItemAvailabilityChanged` + `CatalogChanged` + `ComposerProfileChanged` + `CouponChanged` |
| Kiosk menu cache | `Cache::forget('kiosk.menu.branch.{id}')` | sub-second on broadcast | TTL backstop = `config('kiosk.menu_cache_ttl', 60)` |

**Polling fallback contract** : `config/broadcasting.php` lines 22-35 — env-tunable `BROADCAST_POLLING_FALLBACK_MS` default 30000ms. The earlier "5s" spec claim corresponds to POS kiosk poll, not generic.

### BranchScope on listeners — **No leak (by design)**

None of the 11 `Persist*ToOutbox` listeners apply BranchScope queries against `DomainEvent` model (which has NO global scope — operational outbox table, not tenant data). Multi-tenant isolation is enforced 3 layers downstream :
1. Explicit `branch_id` written to the row from event constructor
2. `channel` JSON = `["private-branch.{branch_id}"]`
3. `routes/channels.php:25-39` — `branch.{branchId}` callback : admin (branch_id=0) bypass, kiosk token requires KioskMachine.branch_id match, staff requires user.branch_id match

**Acceptable architecture. Not a finding.**

### Sub 5.3 findings

- **P2-MINOR Sync-01** : `InvalidateKioskMenuCacheOnItemAvailabilityChanged` does not bump `MenuSnapshot` directly — relies on `BumpMenuSnapshotOnItemAvailabilityChanged` being registered ahead in the listener array. Implicit ordering contract not documented. Suggest inline comment or extract into orchestrator listener.
- **P2-MINOR Sync-02** : `PersistCatalogChangedToOutbox` and `InvalidateKioskMenuCacheOnCatalogChange` both fan-out across all active branches synchronously. `InvalidateKioskMenuCacheOnCatalogChange.php:31-36` warns at 100 branches. For Le Cayenne (1 branch) this is fine, but the warning is informational only — no circuit breaker. **V1 OK / V1.x backlog**.
- **P3-INFO Sync-03** : `DispatchDomainEventsJob` backoff curve = `[1, 5, 15, 60, 300]` × 6 tries = 381s worst-case (`DispatchDomainEventsJob.php:40-42`) — bounded, well-documented. Aligns with SLO `outbox_dispatch_latency_p95 < 2s`.
- **OK Sync-04** : `wasRecentlyCreated` covers all 11. `DispatchDomainEventsJob` phase-1 atomic claim is second layer.
- **OK Sync-05** : Outbox prune scheduled, NF525-safe, chunked.
- **OK Sync-06** : `[WAVE5-DATA-004]` bridge in `EventServiceProvider.php:186-196` makes Extra/Variation availability changes ALSO fire `PersistCatalogChangedToOutbox` — so kiosk receives a generic `CatalogChanged` even before the dedicated `Item{Extra,Variation}AvailabilityChanged` handler. Defense in depth.

---

## 4. Sub 5.4 — Cross-Surface E2E Rupture Cascade

### Existing coverage (`tests/e2e/stock-rupture-sync.spec.js` 80 lines header inspected)

Spec already implements 6 scenarios :
1. Item rupture branch A → POS A pastille rupture + Kiosk A item hidden + Kiosk B intact
2. Extra rupture branch A → wizards POS+Kiosk A filter, Kiosk B preserved
3. Variation rupture branch A → wizards POS+Kiosk A filter, Kiosk B preserved
4. Re-availability → POS+Kiosk A item returns
5. Auto-86 sur quota `max_daily_qty`
6. Order rejection if extra rupture mid-transition (defensive backend `assertItemsOrderableForBranch` AC7 F-016)

Existing budget : `const SYNC_BUDGET_MS = 2_000` (line 53) — **matches the contract**. Resilience strategy : 3-tier evidence (HTTP toggle / DB / UI best-effort, gated on dev-server availability).

### Proposed enhancements (T-5.4.2 — graceful wizard degradation)

**Spec path** : `tests/e2e/stock-rupture-cascade-wizard-degradation-2026-05-18.spec.js` (NEW)

**Scenario** :
1. Customer opens kiosk wizard for tacos → composes through step 1 (size) → step 2 (meat) → step 3 (sauces, mid-flight)
2. Admin sets sauce `Algerienne` to rupture via `/api/admin/menu/availability/extras/toggle`
3. Within `SYNC_BUDGET_MS=2000`, Kiosk receives `ItemExtraAvailabilityChanged` broadcast
4. **Expected UX** : sauce option disabled in-place with visible "Indisponible" badge + toast "Sauce Algerienne désormais indisponible", **NOT** wizard abort
5. If customer had already SELECTED Algerienne pre-rupture → soft-confirm modal "Cette sauce n'est plus disponible, voulez-vous : (a) Choisir une autre sauce (b) Annuler la commande"
6. Backend defense : even if customer bypasses UI guard, `OrderService::create` calls `assertItemsOrderableForBranch(useRowLock: true)` (`AvailabilityService.php:209`) which throws 422 if any line refers to unavailable item

**Gap identified** : Soft-confirm modal in kiosk wizard not visually attested. The current handlers in `pos-app.js:130542+` are documented as "Remove cart lines whose menu row is now unavailable" but the equivalent kiosk wizard mid-session handler (`KioskAppComponent.vue:_handleCatalogChangeMidSession`) needs a Read pass + visual attestation in cycle execution.

### Proposed enhancements (T-5.4.3 — strict latency)

Existing `SYNC_BUDGET_MS` is recorded as evidence in `findings.json` but not enforced as test failure when exceeded. Tighten via `expect(syncMs).toBeLessThan(SYNC_BUDGET_MS)` after each scenario, gated on `ensureSpaUp()` so CI without queue:worker still passes by skipping.

---

## 5. Visual Capture Specs

Admin observability stack for outbox health :

| URL | Component | Capture |
| --- | --- | --- |
| `/admin/observability/sync-overview` | `SyncOverviewController.php` (mentioned line 373) | Outbox pending count + failed count + dispatch latency p50/p95 |
| `/admin/observability/outbox` | (route exists via `tests/js/observabilityOutboxRoute.spec.js`) | Outbox failed events table + manual retry button |
| `/admin/stock-rupture-dashboard` | (route exists via `tests/js/stockRuptureRoute.spec.js`) | Sub 5.2 scope (Agent 6) |

Visual captures to attach in implementation cycle :
- `01-admin-sync-overview-healthy.png` — green panels, recent dispatch latency
- `02-admin-outbox-failed-empty.png` — empty failed table on clean run
- `03-admin-outbox-failed-with-rows.png` — simulated failed dispatch (Pusher off) → row with `last_error` populated
- `04-kiosk-menu-after-rupture.png` — item card shows "Indisponible" badge within 2s
- `05-pos-cart-after-rupture.png` — POS cart line auto-removed with toast

---

## 6. Acceptance Gate — Sentinels & Listener Tests

### Outbox sentinels (existing)
- `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php`
- `tests/Feature/Sentinels/AvailabilityToggleSeparateThrottleSentinelTest.php`
- `tests/Feature/Sentinels/KdsItemAvailabilityEchoSentinelTest.php`
- `tests/Feature/Sentinels/StockManualReasonSurfacingSentinelTest.php`

### Outbox listener replay + dispatch
- `tests/Feature/Outbox/ListenerReplayDedupeTest.php`
- `tests/Feature/Outbox/OutboxDeliveryTest.php`
- `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php`
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- `tests/Feature/OutboxTest.php`
- `tests/Feature/OutboxRescueTest.php`

### Stock + Availability listener attestation
- `tests/Feature/Menu/AvailabilityDispatchAfterCommitTest.php`
- `tests/Feature/Menu/AvailabilityServiceExtrasVariationsTest.php`
- `tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php`
- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`
- `tests/Feature/Menu/MenuAvailabilityToggleEndpointsTest.php`
- `tests/Feature/Menu/AvailabilityControllerDelegationTest.php`
- `tests/Feature/Menu/AvailabilityToggleAuthzMatrixTest.php`
- `tests/Feature/Ingredients/IngredientAvailabilityChangedAfterCommitTest.php`
- `tests/Feature/Stock/StockAvailabilityAfterCommitTest.php`
- `tests/Feature/Stock/StockScanRuptureCommandTest.php`
- `tests/Feature/Stock/StockReleaseOnCancelTest.php`
- `tests/Feature/Stock/StockReleaseOnRefundTest.php`
- `tests/Feature/Stock/NotifyStockLowOnStockLevelChangedTest.php`
- `tests/Feature/Availability/StockReleaseTest.php`

### E2E
- `tests/e2e/stock-rupture-sync.spec.js` — 6 scenarios
- `tests/e2e/red-team-r3-rupture-stock-live-2026-05-07.spec.js`
- `tests/e2e/iter15-stock-cascade-regression.spec.js`
- `tests/e2e/cv1-pos-availability-live-validation-2026-05-08.spec.js`
- **NEW PROPOSED** `tests/e2e/stock-rupture-cascade-wizard-degradation-2026-05-18.spec.js`

### Frontend unit
- `tests/js/stockRuptureRoute.spec.js`
- `tests/js/observabilityOutboxRoute.spec.js`
- `tests/js/adminAvailabilityToggle.spec.js`
- `tests/js/posItemAvailabilityHandler.spec.js`
- `tests/js/availabilityToggleErrorSurfacing.spec.js`
- `tests/js/itemListBranchAvailability.spec.js`
- `tests/js/posAvailabilityLiveGuard.spec.js`

---

## 7. Cross-System Flags (Stock+Sync IS the cross-system)

| System | Impact | Severity |
| --- | --- | --- |
| **POS (Agent 1)** | Echo `private-branch.{id}` ItemAvailabilityChanged → `pos-shell.js:4712` + `pos-app.js:62459` cart line removal. Polling fallback 5s/15s/30s. **Flag** : if POS Echo handler fails silently, cart accept goes through and `assertItemsOrderableForBranch` rejects at submit with 422 — UX = pop-up error not graceful in-line. P2. |
| **Kiosk (Agent 2)** | Echo `private-branch.{id}` 4 broadcastAs (`KioskAppComponent.vue:546-576`). Cache invalidation sub-second. **Flag** : mid-wizard rupture handler `_handleCatalogChangeMidSession` not visually attested in current cycle. **P1** for T-5.4.2 cycle. |
| **KDS (Agent 3)** | Echo subscription `admin-kds.js:1588`. Visual marker for in-flight tickets affected by rupture via `_onItemAvailabilityChanged → kdsInflight Vuex` (`admin-kds.js:1945`). **OK** — Z8-P1-01 healed. |
| **OSS (Agent 4)** | Polling fallback active (`admin-oss.js:939, 1196`). Auto-disconnect after fallback timeout. **OK** — read-only surface, rupture has no UX impact on customer-facing receipt display. |
| **Admin (Agent 7)** | StockManager dashboard endpoint `getBranchAvailabilitySnapshot` (`AvailabilityService.php:414-460`). FormRequest `MANUAL_UNAVAILABLE_REASONS` whitelist (`StockLevel.php:35-41`). **OK**. |
| **Mobile/Web** | Standalone — no live sync expected (carte blanche owner). Out of scope. |

---

## Verdict

Sub 5.1 Stock Backend : **GREEN** (16 sentinels + 6 menu/availability tests + 1 ingredients test)
Sub 5.3 Sync : **GREEN** (11/11 wasRecentlyCreated, prune scheduled, 3 retry lanes, channel auth)
Sub 5.4 E2E : **GREEN baseline** (6 scenarios) + **1 P1 gap** (wizard mid-session graceful degradation + strict latency assertion)

Net new findings : 1 P1 (T-5.4.2 visual attestation), 3 P2-MINOR (Sync-01 ordering contract, Sync-02 fan-out circuit breaker V1.x, POS-graceful-degradation), 1 P3-INFO (backoff curve documentation).

Cycle B implementation cost estimate : 1.5 j-agent (matches plan Wave 5 budget).
