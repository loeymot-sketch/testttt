# PR-C — KDS + Sync (state transitions, Outbox, Pusher/Echo, polling fallback, KDS-OSS-LiL2)
> Ultra-review + ultra-plan — read-only audit, 2026-05-18
> Branch `v1-0-1-hardening-2026-05-17` · Frozen-zone touch **NONE planned** · NF525 chain audit-only.

---

## §0 Scope + invariants

**In-scope (mutable)** — BE controllers `app/Http/Controllers/Admin/{KitchenDisplaySystem,KdsSync,OrderStatusScreen}Controller.php`; services `app/Services/{KitchenDisplaySystemOrder,KdsSync,OrderStatusScreenOrder}Service.php`; domain `app/Domain/Kds/KitchenReleaseRule.php`; listeners `app/Listeners/{DispatchKdsTicket,PersistOrderStatusChangedToOutbox,PersistCatalogChangedToOutbox,PersistItemAvailabilityChangedToOutbox}.php`; events `app/Events/{OrderStatusChanged,CatalogChanged,ItemAvailabilityChanged}.php`; job `app/Jobs/DispatchDomainEventsJob.php`; config `config/{kds,oss,catalog_v15}.php`; routes `routes/api.php:1005-1010,1032,1104-1109` + `routes/channels.php`; FE KDS `resources/js/components/admin/kitchenDisplaySystem/*.vue` + `helpers/kds*.js` + `store/modules/kds*.js` + `services/KdsSyncService.js`; FE OSS `resources/js/components/admin/orderStatusScreen/{PreparingAndReady,OrderStatusScreen}Component.vue` + `services/OssSyncService.js`; tests `tests/Feature/{Kds,OSS,Outbox,Sync}/`, `tests/Feature/Admin/KdsSyncControllerTest.php`, `tests/Feature/Kitchen*Test.php`, `tests/e2e/04-kds-status.spec.js`, `stock-rupture-sync.spec.js`.

**Frozen (audit-only)** — `OrderStateMachine.php` (`apply()` canonical; KDS reuses `recordTransition()` only); `Fiscal*Service.php` (KDS bump writes to `orders.status` only, NEVER `audit_logs`/`z_reports` — verified `KitchenDisplaySystemOrderService.php:197-207`); `BranchScope.php` (KDS queries inherit global scope); DB triggers `no_update`/`no_delete`.

**Invariants** — (1) Legal transitions per `OrderStateMachine::allows`; KDS-internal `KitchenReleaseRule::canTransition` = ACCEPT↔PREPARING↔PREPARED. (2) `branch.{branchId}` Echo auth (`routes/channels.php:23-37`). (3) Outbox `commit_before_dispatch` via `DispatchableAfterCommit` + Phase-1 lock claim (`DispatchDomainEventsJob.php:60-94`). (4) SYNC-2 budget ≤ 8 s. (5) `composition_snapshot` + `allergens_snapshot` read-only.

---

## §1 Architecture map

```
OrderService / FrontendOrderService / KitchenDisplaySystemOrderService
  ─►  OrderStatusChanged::dispatch (DispatchableAfterCommit)
  ─►  PersistOrderStatusChangedToOutbox  (DomainEvent insert · idempotency_key sha1)
  ─►  DB::afterCommit ─►  DispatchDomainEventsJob (queue=high · tries=6 · backoff 1/5/15/60/300)
        Phase 1 lockForUpdate + dispatched_at claim
        Phase 2 BroadcastManager → private-branch.{branch_id} as OrderStatusChanged
  ─►  Pusher/Soketi → Echo private channel
        KDS Vue subscribeEcho → _debouncedRefresh → /api/admin/kds-order
        OSS Vue subscribeEcho → list() → /api/admin/oss-order

WS DEGRADED/DISCONNECTED fallback:
  KDS  KdsSyncService.js (3/5/10s buckets) → /api/admin/kds-order/sync?since=…
  OSS  OssSyncService.js (60s conn / 2s disconn)   → /api/admin/oss-order
```

KDS bump: `KitchenDisplaySystemController::changeStatus` → service `:152-234` uses `lockForUpdate` + expected_status (HTTP 409 optimistic) + `OrderStateMachine::allows` guard + `recordTransition` + best-effort `DispatchKdsTicket::dispatch` gated on `KitchenReleaseRule::shouldDispatchStatusChanged`.

---

## §2 Findings (file:line verified)

### P0

- **P0-1 `/sync` whereDate non-sargable** — `KdsSyncService.php:65,69` still `whereDate('order_datetime',…)`. Sibling `/list` was hardened to `whereBetween(today,endOfDay)` per `[RED-team P1 perf 2026-05-17]` at `KitchenDisplaySystemOrderService.php:94-100`. Fix not mirrored. Per-station 3-5s poll → full scan amplifies linearly.
- **P0-2 `OrderStatusChanged::dispatch` gate bypass × 5** — `OrderService.php:1632,1707,1853` + `FrontendOrderService.php:737,1232` dispatch unconditionally. Only KDS service `:224` honours `DispatchKdsTicket` + `KitchenReleaseRule::shouldDispatchStatusChanged`. PENDING→ACCEPT, PREPARED→DELIVERED, *→CANCELED still broadcast → KDS+OSS full `index` refresh per non-kitchen event.
- **P0-3 OSS public wall has no fail-loud SYNC-2 sentinel** — `PreparingAndReadyComponent.vue:248` correctly early-returns `branchId<=0` (no Echo on public wall). `config/oss.php:29` + `config/catalog_v15.php:76` set `2_000` ms disconnected. No test fails-loud if regressed > 8s.
- **P0-4 Echo channel-name contract unattested** — persister writes `private-branch.{id}` (`PersistOrderStatusChangedToOutbox.php:52`, `PersistCatalogChangedToOutbox.php:83`); FE subscribes via `onEvents(branchId,…)` (`KitchenDisplaySystemComponent.vue:1762`) which auto-prefixes `private-` for Echo private channels. Silent zero-event failure mode on rename. No regression test.

### P1

- **P1-5 Stale TODO in `computeOrderVersion`** — `KdsSyncService.php:130-145` TODO claims `status_changed_at` needed, but `$locked->save()` at `KitchenDisplaySystemOrderService.php:197-198` touches `updated_at` via Eloquent timestamps. TODO misleading.
- **P1-6 KDS `changeStatus` duplicates `OrderStateMachine::apply()`** — service `:158-210` hand-rolls lockForUpdate + guard + transition + recordTransition (canonical at `OrderStateMachine.php:179-254`). 409-optimistic + branch-403 affordances belong upstream. V1 frozen-zone keeps this acceptable; flag V1.0.2.
- **P1-7 `publicMostPopularItems` branch fallback fragile** — `OrderStatusScreenController.php:111-135` + `OrderStatusScreenOrderService.php:120-146` fall back to "first ACTIVE branch ORDER BY id". Multi-branch fleet without `?branch_id` → wrong popular items.
- **P1-8 No constant for `private-branch.X` channel string** — two persisters hand-build the string; rename risk on V1.0.2 SaaS refactor.

### P2

- **P2-9 KDS i18n residue** — `KitchenDisplaySystemComponent.vue:2056` ticket title `Ticket cuisine` hardcoded; lane header `🖥️ Borne` raw. (6-day-old memory's 18-label claim partly stale — banners/cap/fallback/items_board now use `label.kds_*`.)
- **P2-10 Cadence config drift surface** — `config/catalog_v15.kds_fallback_polling` (BE) ↔ `KdsSyncService.js:19-27` (FE default) ↔ `window.foodkingConfig.kdsFallbackPolling` (Blade bridge) — not asserted equal.

### P3

- **P3-11 `_echoMarkedReady` one-shot comment drift** — `PreparingAndReadyComponent.vue:362` re-init pattern matches behaviour; comment slightly imprecise.
- **P3-12 `OssSyncService` connected cadence 60s** — sane; document the cost ceiling.

---

## §3 Tasks (ordered by risk)

> Acceptance + test path. NEW = create in dir.

### T1 [P0-1] Mirror whereBetween into `/sync`
- File:line — `KdsSyncService.php:63-72`: replace both `whereDate` calls with `whereBetween('order_datetime',[Carbon::today(),Carbon::today()->endOfDay()])` (non-advance) + `where('order_datetime','<',Carbon::tomorrow())` (advance), byte-mirroring `/list:91-102`.
- Acceptance — index `idx_orders_datetime` used (EXPLAIN range, not ALL); p95 ≤ 80 ms with 200 active orders.
- Test — `tests/Feature/Admin/KdsSyncControllerTest.php` extension + NEW `tests/Feature/Kds/KdsSyncSargableSentinelTest.php` (assert no `whereDate` literal via `DB::getQueryLog()`).

### T2 [P0-2] Route all `OrderStatusChanged::dispatch` through `DispatchKdsTicket`
- File:line — `OrderService.php:1632,1707,1853` + `FrontendOrderService.php:737,1232`. Inject `DispatchKdsTicket` (already in `KitchenDisplaySystemOrderService`) and replace direct dispatch.
- Acceptance — `KitchenReleaseRule::shouldDispatchStatusChanged($from,$to)` gates all 5 sites. PENDING→ACCEPT and PREPARED→DELIVERED + CANCELED produce zero `OrderStatusChanged` domain rows.
- Test — NEW `tests/Feature/Kds/KdsTicketDispatchGateSentinelTest.php` (5 sites → assert listener fires only on ACCEPT→PREPARING / PREPARING→PREPARED).

### T3 [P0-3] OSS SYNC-2 budget fail-loud sentinel
- File:line — `config/oss.php:29`, `config/catalog_v15.php:76`, `resources/js/services/OssSyncService.js:13-23`. Add doc-block referencing SYNC-2.
- Acceptance — sentinel asserts `interval_ms_when_disconnected ≤ 4000` (half of SYNC-2 8s); fails-loud on regression.
- Test — NEW `tests/Feature/OSS/OssSyncBudgetSentinelTest.php` + Vitest `tests/js/services/OssSyncService.spec.js` for FE default.

### T4 [P0-4] Echo channel-name contract sentinel
- File:line — `PersistOrderStatusChangedToOutbox.php:52`, `PersistCatalogChangedToOutbox.php:83`, `routes/channels.php:23-37`, `KitchenDisplaySystemComponent.vue:1754-1779`.
- Acceptance — persister channel JSON contains exactly `private-branch.{branch_id}`; FE `Echo.private('branch.'+id)` round-trips through `routes/channels.php`. Rename on either side → red.
- Test — NEW `tests/Feature/Outbox/ChannelNameContractSentinelTest.php` (regex on persisters) + `tests/e2e/04-kds-status.spec.js` extended (`window.Echo.connector.channels` reflect).

### T5 [P1] Outbox listener replay dedupe parity for availability listeners
- File:line — `PersistItemAvailabilityChangedToOutbox.php`, `PersistItemExtraAvailabilityChangedToOutbox.php`, `PersistItemVariationAvailabilityChangedToOutbox.php` (mirror `wasRecentlyCreated` early-return idiom from `PersistOrderStatusChangedToOutbox.php:64-66` + `PersistCatalogChangedToOutbox.php:92-94`).
- Acceptance — replay (firstOrCreate existing row) does NOT schedule `DispatchDomainEventsJob`.
- Test — extend `tests/Feature/Outbox/ListenerReplayDedupeTest.php` + `tests/Feature/Sync/ListenerReplayGuardTest.php`.

### T6 [P1-5] Retire stale TODO in `computeOrderVersion`
- File:line — `KdsSyncService.php:130-145`. Replace TODO with note citing Eloquent timestamps + `OrderStatusTransition.occurred_at` as the authoritative lineage ledger.
- Acceptance — TODO removed; comment cites verified `KitchenDisplaySystemOrderService.php:197-198` save() path.
- Test — N/A (doc); optional NEW `tests/Feature/Kds/KdsVersionMonotonicTest.php` (assert version monotonic across N rapid status flips).

### T7 [P1-6] V1.0.2 doc-only deferral for `apply()` unification
- File:line — `docs/decisions/DEFERRED_KDS_APPLY_UNIFICATION.md` NEW or append to `plans/v1-0-1-hardening/`; TODO comment `KitchenDisplaySystemOrderService.php:152-156`.
- Acceptance — V1.0.2 backlog written, enumerating 409-optimistic + branch-403 affordances. Frozen zone untouched.
- Test — extend `tests/Feature/KitchenReleaseRuleTest.php` with state machine parity assertion (no edit to `OrderStateMachine.php`).

### T8 [P1-7] `publicMostPopularItems` branch resolution hardening
- File:line — `OrderStatusScreenController.php:111-135` + `OrderStatusScreenOrderService.php:120-146`. When `?branch_id` absent on multi-branch fleet, log `[OSS] branch_fallback` warning (one-shot per process).
- Acceptance — multi-branch test asserts fallback returns 200 with `meta.branch_resolved`. Single-branch fleet behaviour unchanged.
- Test — extend `tests/Feature/OSS/OssCustomerScreenFilterTest.php`.

### T9 [P1-8] Outbox channel-string canonicalisation
- File:line — NEW `app/Events/Concerns/BranchChannelName.php` (`for(int $branchId): string` returning `"private-branch.{$branchId}"`). Wire `PersistOrderStatusChangedToOutbox.php:52` + `PersistCatalogChangedToOutbox.php:83` + any sibling.
- Acceptance — single source of truth; persisters use helper. Folded into T4 contract sentinel.
- Test — covered by T4.

### T10 [P2-9] KDS i18n residue closure
- File:line — `KitchenDisplaySystemComponent.vue:2056` (ticket title) + lane header `🖥️ Borne`. New keys `label.kds_ticket_title`, `label.kds_lane_kiosk` in `lang/{fr,en,ar}.json`.
- Acceptance — `grep -nE ">[A-Z][a-zà-ÿ]+( [a-zà-ÿ]+)+\s*<" KitchenDisplaySystemComponent.vue` returns ≤ 1 (printKitchenTicket inline HTML). 3 locales covered.
- Test — NEW `tests/js/components/kitchenDisplaySystem/I18nKeyCoverage.spec.js`.

### T11 [P2-10] Cadence config bridge integrity
- File:line — `KdsSyncService.js:447-465` reads `window.foodkingConfig.kdsFallbackPolling`; Blade bridge (find in `resources/views/admin/*.blade.php` or `resources/js/config/env.js`) must inject from `config('catalog_v15.kds_fallback_polling')`.
- Acceptance — env override `FK_CATALOG_KDS_DEGRADED_BASE_MS` propagates FE-side; smoke E2E reads same value.
- Test — extend `tests/e2e/04-kds-status.spec.js` (`evaluate(() => window.foodkingConfig.kdsFallbackPolling)`).

### T12 [P3] Consolidate SYNC contract documentation
- File:line — NEW `docs/SYNC_CONTRACT_V1.md` capturing channel naming, KDS 3/5/10s buckets, OSS 60s/2s cadence, SYNC-2 8s budget, commit-before-dispatch invariant, replay-dedupe pattern. Cross-link from `CLAUDE.md §6` + `§9`.
- Acceptance — single doc referenced by sentinels (T3, T4) and reports `reports/audit/v1-cloud-prep-insights-2026-05-18/`.
- Test — N/A.

---

## §4 Execution order

1. **T1+T2+T5** (BE correctness, no UI side-effects) — commit cluster `pr-c-sync-perf-and-gate`.
2. **T4+T9** (channel-name contract) — depends on T1/T2 churn settling.
3. **T3+T8** (OSS hardening cross-surface).
4. **T6+T7** (doc-only cleanups).
5. **T10+T11+T12** (hygiene + docs).

Smoke gate between clusters: `php artisan test --filter="Kds|Outbox|Sync|OSS"` + `npm run test -- KdsSync OssSync` + Playwright `04-kds-status.spec.js` + `stock-rupture-sync.spec.js`.

---

## §5 Risk register

| T | Risk | Mitigation |
|---|------|------------|
| T1 | wrong cutoff for advance branch | byte-mirror `/list:99-101` |
| T2 | E2E expects every-status broadcast | run `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-{D,E,F}.spec.js` |
| T3 | env override forgotten prod | sentinel fails-loud on `>4000ms` |
| T4 | Echo `private-` prefix implicit mismatch | E2E asserts `window.Echo.connector.channels` keys |
| T5 | parity drift across 3 availability listeners | shared assertion helper |
| T8 | log noise on single-branch fleet | gate warning behind `branches.count()>1` |
| T9 | constant introduction touches frozen surface | `app/Events/Concerns/` NEW namespace — non-frozen |

---

## §6 Acceptance for PR-C convergence

- All NEW tests + existing `tests/Feature/{Kds,OSS,Outbox,Sync}/` + `KdsSyncControllerTest.php` + `KitchenDisplaySystemOrderSortTest.php` + `KitchenReleaseRuleTest.php` green.
- `php artisan test --filter="Kds|Outbox|Sync|OSS"` and `npm run test -- Kds Sync OSS` green.
- Playwright `04-kds-status.spec.js` + `stock-rupture-sync.spec.js` + waves D/E/F green.
- Frozen-zone diff = 0 over the 12 frozen files (`CLAUDE.md §7`); `audit_logs` `count=26` + `last_hash=ca4ac1fdc208dae1` bit-identical.
- Visual mandate: capture `/kds` + `/admin/order-status-screen` post-merge; Read each PNG; confirm single banner zone (`KdsStatusBanner.vue`), V2 grid 4×2 default (`config/kds.php:24-28`), no raw labels.

---

## §7 Out-of-scope (defer V1.0.2)

- `OrderStateMachine::apply()` unification (P1-6 doc-only here, see T7).
- KDS V2 dark-mode + reduced-motion sweep (PR-D scope candidate).
- Soketi → managed Pusher Channels — infrastructure track.
- KDS multi-station expediter view (post-V1, see `feedback_kds_modern_research_required.md`).
- Full 18-raw-label sweep — dedicated i18n PR.

---

**Stale-memory correction**: 6-day-old `project_kds_audit_2026-05-11.md` lists 8 P0 of which **4 are already healed**: `allergenModal` typo (consistent `allergensModal` at `KitchenDisplaySystemComponent.vue:995-1019`), 5-stacked banners (`KdsStatusBanner.vue` consolidates), 32 px bump button (`KdsV2Grid.vue` ≥60 px), accordion-closed UX (V2 grid default per `config/kds.php:24-28`). Not P0s for this PR.
