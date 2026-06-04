# PR-C — KDS + Sync Ultra-Review VERDICT

> Read-only deep-code-review, 2026-05-18.
> Branch `v1-0-1-hardening-2026-05-17`. Scope per plan
> `plans/ultra-plans-2026-05-18/PR_C_KDS_SYNC_ULTRA_PLAN_REVIEW_2026-05-18.md`.
> 5 perspectives (Architect / Security / DBA / A11y / Tester) across 14 files.

---

## §1 Exec summary

Plan diagnostic quality is **uneven**: 1 of 4 P0s holds, 3 of 4 P0s are
over-categorized. The architecture is solid (outbox + Phase-1 lock claim
+ DispatchableAfterCommit + correlation_id dedupe + per-listener
`wasRecentlyCreated` guard mirrored across 7 outbox listeners). Channel
auth (`routes/channels.php:25-39`) is clean. Frozen-zone discipline
respected (`OrderStateMachine` not touched, KDS bump uses `lockForUpdate`
+ `recordTransition` per CLAUDE.md §7 V1 rule).

**Verdict** : `heal` — plan must be revised before execution. T2 as
written would silently break legitimate OSS / FCM / loyalty consumers
(see P0-2 reframe). Tasks T1, T5, T6, T8, T10, T11, T12 acceptable as
authored. T3, T4 are sentinel-only (downgrade severity P0→P1). T2 needs
reframe to FE-side filter and re-write.

Counts (post-review): **1 P0 confirmed** (`/sync` whereDate),
**5 P1** (was-P0×2 reframed + plan-P1×3 verified), **3 P2**, **2 P3**.

Single most-important issue : **T2 as authored is unsafe**. The plan
frames 5 `OrderStatusChanged::dispatch` sites as a "gate bypass". They
are not — every site has at least one legitimate non-KDS consumer
(`PreparingAndReadyComponent.vue:254-267` OSS list refresh,
`SendFcmOnOrderStatusChange`, `AwardLoyaltyPointsOnDelivery`). Routing
all 5 through `DispatchKdsTicket::shouldDispatchStatusChanged` (which
only permits `ACCEPT↔PREPARING↔PREPARED`) would suppress OSS visibility
on CANCELED, suppress kiosk auto-accept push to KDS, suppress FCM on
DELIVERED, suppress loyalty point award. The defect is FE-side : the KDS
Vue handler refreshes on every `OrderStatusChanged` regardless of
from/to. Fix belongs in `KitchenDisplaySystemComponent.vue:1762` (filter
on `parsed.payload.old_status` / `new_status` via
`KitchenReleaseRule::shouldDispatchStatusChanged` semantics mirrored in
JS), not in BE dispatch sites. Counter-count : plan claims 5 dispatch
sites; grep finds 7 (also `PaymentService.php:463`,
`CleanupStalePendingKioskOrders.php:79`).

---

## §2 P0 findings (1 confirmed)

### P0-1 [CONFIRMED] `/sync` whereDate non-sargable (perf regression)

- File:line — `app/Services/KdsSyncService.php:65,69`
- Verified — `whereDate('order_datetime', Carbon::today())` and
  `whereDate('order_datetime', '<=', Carbon::today())` present, both
  bypass `idx_orders_datetime`. Sibling `/list` was hardened
  2026-05-17 at `KitchenDisplaySystemOrderService.php:94,100` to
  `whereBetween(today, endOfDay)` + `where('order_datetime','<',Carbon::tomorrow())`.
- Impact — 3-5 s poll loop per KDS station amplifies full-scan cost.
  Burst (50+ stations on reconnect storm) hits this twice (active +
  inactive subqueries L102-104 also unscoped).
- Severity confirmed P0 (perf regression on hot path, multiple stations
  amplify; production-ready cycle hardening surface).
- Heal — T1 as written (byte-mirror `/list` pattern). Acceptance
  EXPLAIN range scan on `idx_orders_datetime` + sentinel test.

---

## §3 Plan-claimed P0s downgraded to P1 (3 reframes)

### P1-2 [REFRAMED was P0-2] `OrderStatusChanged::dispatch` "gate bypass" mischaracterised

- File:line — `OrderService.php:1632,1707,1853`,
  `FrontendOrderService.php:737,1232`,
  plus omitted-by-plan `PaymentService.php:463`,
  `CleanupStalePendingKioskOrders.php:79`. **7 sites, not 5.**
- Plan claim — all dispatch unconditionally and bypass
  `DispatchKdsTicket`. Heal T2 = route every site through
  `DispatchKdsTicket::shouldDispatchStatusChanged`.
- Counter-evidence — `DispatchKdsTicket::shouldDispatchStatusChanged`
  only permits `ACCEPT→PREPARING` and `PREPARING→PREPARED`
  (`app/Domain/Kds/KitchenReleaseRule.php:51-54`). Each of the 7 sites
  has at least one legitimate non-KDS downstream consumer registered in
  `EventServiceProvider.php:138-143`:
  - `PreparingAndReadyComponent.vue:254-267` OSS Vue subscriber calls
    `this.list()` on every `OrderStatusChanged` — needed for CANCELED
    (so customer wall removes the line) and for PREPARED→DELIVERED
    (removes from "Prêt" column).
  - `SendFcmOnOrderStatusChange` mobile push — needed on every status
    transition customer-relevant (PENDING→ACCEPT, PREPARED→DELIVERED).
  - `AwardLoyaltyPointsOnDelivery` — needed exactly on
    `PREPARED→DELIVERED` or `OUT_FOR_DELIVERY→DELIVERED` (NOT a kitchen
    transition).
- Real defect — `KitchenDisplaySystemComponent.vue:1762` handler
  refreshes on every `OrderStatusChanged` regardless of (from,to).
  KDS over-refreshes when OSS / loyalty events arrive.
- Heal owner — FE-side handler filter, not BE dispatch site refactor.
  Mirror `KitchenReleaseRule::shouldDispatchStatusChanged` semantics in
  the Vue handler : compare `parsed.payload.old_status` and
  `new_status` against the JS-side mirror, skip
  `_debouncedRefresh()` if not a kitchen transition.
- Severity — P1 (perf optimisation FE-side, no correctness break).
  **Do not run T2 as written.**

### P1-3 [REFRAMED was P0-3] OSS SYNC-2 missing sentinel (gap, not defect)

- File:line — `config/oss.php:29`, `config/catalog_v15.php:76`,
  `resources/js/services/OssSyncService.js:13-23`.
- Verified — `intervalMsWhenDisconnected = 2_000` ms hardcoded default,
  also overridable via `FK_CATALOG_OSS_FALLBACK_DISCONNECTED_INTERVAL_MS`.
  2 s ≤ 4 s = half of 8 s SYNC-2 budget. Current state is **compliant**.
  `PreparingAndReadyComponent.vue:248` early-returns
  `if (branchId <= 0) return;` on public wall.
- Real risk — silent regression if someone bumps env / default above
  4 s. No fail-loud sentinel exists today.
- Severity — P1 sentinel gap (preventive). Heal T3 as written.

### P1-4 [REFRAMED was P0-4] Echo channel-name contract sentinel gap

- File:line — `app/Listeners/PersistOrderStatusChangedToOutbox.php:52`,
  `app/Listeners/PersistCatalogChangedToOutbox.php:83`,
  `resources/js/services/eventContract.js:337-338`.
- Verified — persisters write `'private-branch.' . $branchId` ;
  FE `eventContract.js:337` builds `channelName = `branch.${branchId}``
  and calls `window.Echo.private(channelName)` which auto-prefixes
  `private-`. Round-trip works today via
  `routes/channels.php:25-39` callback.
- Real risk — rename on either side silently zero-events. No
  regression test on the contract.
- Severity — P1 sentinel gap. Heal T4 + T9 (BranchChannelName helper)
  as written. Note : T9 doesn't touch frozen zone (new namespace
  `app/Events/Concerns/`), safe.

---

## §4 P1 findings (3 additional verified)

### P1-5 Stale TODO in `computeOrderVersion`

- File:line — `app/Services/KdsSyncService.php:130-145`.
- Verified — TODO claims `status_changed_at` column needed for accurate
  versioning, but `KitchenDisplaySystemOrderService.php:197-198`
  `$locked->save()` updates `updated_at` via Eloquent timestamps, so
  the version derived from `updated_at.getTimestamp()` is correct on
  status flips. TODO misleading.
- Heal T6 as written (replace with comment citing Eloquent timestamps
  + `OrderStatusTransition.occurred_at` as the canonical lineage
  ledger).

### P1-6 KDS `changeStatus` duplicates `OrderStateMachine::apply()`

- File:line — `app/Services/KitchenDisplaySystemOrderService.php:158-210`
  vs canonical `app/Domain/Order/OrderStateMachine.php:179-254`.
- Verified — KDS service hand-rolls lockForUpdate + branch-403 +
  expected_status 409 + recordTransition. `apply()` does the first
  three of those four. 409-optimistic + branch-403 affordances are
  KDS-specific UX — could move to `apply()` overload as deferred work.
- Severity P1 V1.0.2-deferred per plan (frozen-zone constraint keeps
  V1 acceptable).
- Heal T7 doc-only as written.

### P1-7 `publicMostPopularItems` branch fallback fragile

- File:line — `app/Http/Controllers/Admin/OrderStatusScreenController.php:111-135`,
  `app/Services/OrderStatusScreenOrderService.php:120-146`.
- Verified — fallback to first ACTIVE branch by id when `?branch_id`
  absent. Multi-branch fleet → wrong popular items if the wall didn't
  pass branch_id. Single-branch fast-food (V1 Le Cayenne) → no impact.
- Heal T8 as written (log fallback warning gated on `branches.count() > 1`).

### P1-8 No constant for `private-branch.X` channel string

- File:line — `PersistOrderStatusChangedToOutbox.php:52`,
  `PersistCatalogChangedToOutbox.php:83`,
  `PersistItemAvailabilityChangedToOutbox.php:46`,
  `PersistItemExtraAvailabilityChangedToOutbox.php:32`,
  `PersistItemVariationAvailabilityChangedToOutbox.php:31`.
- 5 hand-built channel string sites (plan listed 2). Heal T9 as
  written, extended to all 5 sites.

---

## §5 P2 findings

### P2-9 KDS i18n residue partial

- File:line — `KitchenDisplaySystemComponent.vue` (2639 lines, not
  audited line-by-line; spot-check confirms `🖥️ Borne` and some
  ticket-print FR strings remain). Plan claim of "ticket title `Ticket
  cuisine` at L2056" — file is now 2639 lines so the exact line may
  have shifted ; the class of issue is verified.
- Heal T10 as written, with explicit grep-driven acceptance criterion.

### P2-10 Cadence config drift surface (BE / FE / Blade bridge)

- File:line — `config/catalog_v15.php:79-89`,
  `resources/js/services/KdsSyncService.js:447-465`
  (`window.foodkingConfig.kdsFallbackPolling`), Blade bridge not
  audited.
- No assertion ties the three together. Heal T11 as written + extend
  E2E to read `window.foodkingConfig.kdsFallbackPolling`.

### P2-NEW DELIVERED-transition removes from OSS — verify cross-surface coherence

- Sister concern with today's POS-first-page / OSS DELIVERY exclusion
  fix (memory `project-pos-first-page-oss-filter-2026-05-18`). The fix
  fail-closes the wall to `whereIn(order_type, [KIOSK, TAKEAWAY])`,
  but a CANCELED OrderStatusChanged broadcast still triggers OSS
  refresh (`PreparingAndReadyComponent.vue:266 this.list()`). After
  `list()` the now-cancelled row is whereIn-filtered out, so the line
  disappears — correct. **No regression introduced today**.

---

## §6 P3 findings

### P3-11 `_echoMarkedReady` one-shot comment drift

- File:line — `PreparingAndReadyComponent.vue:362
  this._echoMarkedReady = new Set();` is re-init at end of
  `_hydrateFromRows`. Behaviour correct; comment slightly imprecise.
  Doc-only fix.

### P3-12 `OssSyncService` connected cadence 60 s sane

- File:line — `OssSyncService.js:9 intervalMsWhenConnected: 60_000`.
  60 s when WS is up is fine (Echo handles realtime; polling is
  drift-resist only). Document the cost ceiling. Doc-only.

---

## §7 Critical-questions answer table

1. **P0s verified ?** Q1 only P0-1 holds (`whereDate`). P0-2 reframed
   to P1 (FE handler filter, not BE dispatch refactor — T2 unsafe
   as written, plan count off by 2). P0-3 / P0-4 = sentinel gaps,
   not defects (downgrade P1).
2. **State-machine integrity ?** Verified — every status assignment
   in `OrderService.php:1575,1688,1793` and
   `FrontendOrderService.php:578,723,1192` is paired with
   `OrderStateMachine::recordTransition` and gated either by
   `ValidStatusTransition` rule or KDS-specific `KitchenReleaseRule`.
   `OrderStateMachine.php` (FROZEN) not touched.
3. **Echo channel-auth A → B leak ?** No.
   `routes/channels.php:25-39` enforces : (a) kiosk token bound to
   its `KioskMachine.branch_id` ; (b) Admin (`branch_id=0`) wildcard ;
   (c) staff strict equality. Token ability `kiosk:order` correctly
   restricts kiosk surface. Clean.
4. **Outbox listener coverage ?** 7 of 7 verified
   (`PersistOrderStatusChangedToOutbox:64-66`,
   `PersistOrderCreatedToOutbox:57-59`,
   `PersistOrderPaidAtCounterToOutbox:54-56`,
   `PersistOrderPaymentStatusChangedToOutbox:69-71`,
   `PersistCatalogChangedToOutbox:90-94`,
   `PersistItemAvailabilityChangedToOutbox:89-91`,
   `PersistItemExtraAvailabilityChangedToOutbox:66-68`,
   `PersistItemVariationAvailabilityChangedToOutbox:65-67`,
   `PersistCouponChangedToOutbox:82-84`,
   `PersistSettingsUpdatedToOutbox:75-77`). All carry
   `wasRecentlyCreated` early-return. Solid parity.
5. **Polling-fallback cadence coherence KDS / OSS / POS ?** No shared
   config key — KDS reads `catalog_v15.kds_fallback_polling`, OSS
   reads `catalog_v15.oss_fallback_polling`, POS reads
   `catalog_v15.pos_fallback_polling`. All three live in same file ;
   no drift between BE and FE for KDS (verified `KdsSyncService.js:447-465`
   bridges via `window.foodkingConfig.kdsFallbackPolling`). T11 closes
   the BE-FE assertion gap.
6. **KDS bump idempotency on double-tap ?** Robust.
   `KitchenDisplaySystemOrderService.php:158-210` wraps in
   `DB::transaction` + `Order::lockForUpdate()->firstOrFail()` +
   `expected_status` check (HTTP 409 on mismatch) + `fromLocked ===
   $newStatus` idempotent early-return. Double-tap → first wins, second
   gets 409 with structured log entry `[KDS_409]`.
7. **V2 unified-projection flag (`FK_CATALOG_UNIFIED_PROJECTION_ENABLED`)
   impact on KDS payload ?** KDS does NOT consume
   `PosMenuProjection`. KDS reads `Order::with('orderItems',
   'address', 'user')` directly via Eloquent. No flag dependence. Risk
   surface from today's POS first-page R-1 P0 (featured-field dropped
   on flag-flip) does NOT exist for KDS.
8. **NF525 chain — does KDS bump write to `audit_logs` ?** No. Status
   flip writes to `orders.status` + `order_status_transitions`
   (recordTransition). `audit_logs` is written by NF525 endpoints
   (PaymentService, FiscalSequenceService, ZReportService — frozen).
   KDS bump is **NF525-decoupled**. Chain integrity preserved.
9. **SYNC-2 ≤ 8 s budget ?** Holds today.
   `OssSyncService.js:16 intervalMsWhenDisconnected = 2_000` ms +
   ~80 ms request RTT = 2.1 s wall observability under WS outage. With
   Echo live = sub-second. Risk is silent regression — T3 sentinel
   addresses it.

---

## §8 Out-of-scope confirmations (deferred V1.0.2)

- `OrderStateMachine::apply()` unification (frozen zone V1 rule).
- KDS V2 dark-mode + reduced-motion sweep (PR-D candidate).
- Full 18-raw-label i18n sweep (dedicated PR).
- Multi-station expediter view (post-V1).

---

## §9 Acceptance for PR-C convergence (revised)

- **T2 BLOCKED** — revise to FE-side handler filter at
  `KitchenDisplaySystemComponent.vue:1762`. Mirror
  `KitchenReleaseRule::shouldDispatchStatusChanged` semantics in JS.
  Add sentinel `tests/Feature/Kds/KdsHandlerOverRefreshSentinelTest.js`
  (Vitest) on the FE side, NOT on BE.
- T1 / T5 / T6 / T8 / T10 / T11 / T12 land as written.
- T3 / T4 / T9 reframe severity P0→P1 but keep heal payload
  (sentinels still valuable).
- T7 doc-only deferral acceptable.

NF525 chain audit attestation : `audit_logs count=26, last_hash =
ca4ac1fdc208dae1` per BRAIN §3. KDS code does not interact with
chain. Frozen-zone diff = 0 over the 12 protected files. Visual
mandate to be exercised post-merge per plan §6.

---

## §10 Final verdict

**`heal`** — single P0 (T1 `/sync` whereDate) ships safely as written.
All other plan-claimed P0s reframe to P1 or sentinel-only. **T2 must
be rewritten before execution** — the proposed cure (route all dispatch
sites through `DispatchKdsTicket`) breaks OSS, FCM, loyalty consumers.
Real defect lives in the KDS Vue handler. Drift correction documented
in §3 / §7.
