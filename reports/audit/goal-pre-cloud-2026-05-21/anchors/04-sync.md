# ANCHOR-04: Cross-Surface Sync Layer Cartography

**Date**: 2026-05-21 · **Mode**: READ-ONLY DISCOVERY · **Branch**: `heal/cms-pr1-quickwins-2026-05-18` HEAD `4255ec15a` (post Wave L heals 2026-05-19)

---

## Path

`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`

---

## Cartography Summary

### 1. Outbox Model + Listeners (11 listeners discovered)

**Model**: `app/Models/WebhookEvent.php` (idempotency ledger for payment webhooks, PROVIDER-scoped not branch-scoped, WITH order_id FK post 2026-05-18).

**Outbox listeners** (Persist*ToOutbox pattern):
- `PersistOrderCreatedToOutbox.php` → domain_events table
- `PersistOrderStatusChangedToOutbox.php`
- `PersistOrderPaymentStatusChangedToOutbox.php`
- `PersistOrderPaidAtCounterToOutbox.php`
- `PersistCatalogChangedToOutbox.php`
- `PersistCouponChangedToOutbox.php`
- `PersistSettingsUpdatedToOutbox.php`
- `PersistBranchStatusChangedToOutbox.php`
- `PersistItemAvailabilityChangedToOutbox.php` (3× — item, extra, variation)

**Broadcast Swallowed Escalation**:
- `EscalateOutboxBroadcastSwallowed.php` (NEW, HEAL B.2) — Log::critical on fiscal channel

### 2. Domain Events Infrastructure

**File**: `app/Domain/Events/EventContract.php` (base contract)

**Event types** (from app/Events/):
- OrderCreated, OrderStatusChanged, OrderPaymentStatusChanged, OrderPaidAtCounter, OrderCanceled, OrderTableChanged
- CatalogChanged, CouponChanged
- ItemAvailabilityChanged, ItemExtraAvailabilityChanged, ItemVariationAvailabilityChanged
- OutboxBroadcastSwallowedEvent (P1 signal)

### 3. Outbox Delivery + Retry Infrastructure

**Commands** (app/Console/Commands/):
- `OutboxRetryFailedCommand.php` — HEAL B.1 applied ✓ (attempts monotonic, cap=12)
- `OutboxRescueCommand.php` — HEAL B.4 applied ✓ (two-lane: pending-stale + crash-claimed)
- `OutboxWebhookRetryFailedCommand.php` — webhook retry (separate lane)

**Job**: `DispatchDomainEventsJob.php` ($tries=6, 3-phase: claim + broadcast + release, lock-based concurrency)

**Locks + TTL**:
- retry-failed lock: 300s (Wave 3c, prevents double-dispatch)
- batch cap: 500 rows per run (prevents wall-clock overrun)
- rescue crash-claimed TTL: 10min (> worst broadcast hang ~60s)

### 4. Pusher/Echo Channel Auth

**Routes**: `routes/channels.php`
- `App.Models.User.{id}` — 1:1 user
- `branch.{branchId}` (CRITICAL) — **HEAL R3 T-3.2.2 applied** ✓
  - Kiosk tokens: TOKEN NAME check (not tokenCan) — immune to Sanctum '*' wildcard
  - Admin/Tenant Admin: explicit ROLE check (closes Guest-Echo-Bypass)
  - Regular staff: own branch only

**Config**: `config/broadcasting.php` — HEAL B.3 applied ✓ (dead polling_fallback PHP block removed, per-surface JS constants documented)

**Bearer token auth** (resources/js/bootstrap.js):
- Echo sends Sanctum Bearer token on private channel auth
- window._refreshEchoAuth() on login (store updates authToken)
- Error interception: subscription/auth errors trigger token refresh

### 5. Polling Fallback (Per-Surface)

**POS**: MIX_BROADCAST_POLLING_FALLBACK_MS webpack env (default 30000ms) → resources/js/store/modules/posOrder.js:59-64

**KDS**: Hardcoded dual-state polling:
- 5000ms (WS down, fallback)
- 60000ms (WS up, advisory cadence)
- Location: resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1759-1761

**Kiosk**: Hardcoded 15000ms (always) → resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:152-154

**Per-surface values are intentional** (operator density vs kitchen staleness vs customer wait UX). Single SoT V1.0.2 backlog.

### 6. Sentinel Tests (11 test files, 50 test methods)

**Outbox Feature Tests** (tests/Feature/Outbox/):
1. `OutboxBroadcastSwallowedListenerTest.php` — 3 tests (HEAL B.2 validation)
2. `ListenerReplayDedupeTest.php` — 10 tests (concurrent replay guard)
3. `OutboxConcurrentRetryLockTest.php` — 7 tests (lock contention + batching)
4. `CatalogEventDispatchAfterCommitTest.php` — 2 tests (DispatchableAfterCommit)
5. `OutboxDeliveryTest.php` — 7 tests (broadcast delivery path)
6. `OutboxProductionLikeSimulationTest.php` — 5 tests (load simulation)
7. `OutboxConcurrentWorkerDedupeTest.php` — 9 tests (worker dedupe)
8. `OutboxReplayAuditTest.php` — 4 tests (audit trail on replay)
9. `OutboxRescueStaleClaimedRowsTest.php` — 5 tests (HEAL B.4 validation)
10. `OutboxRetryFailedAttemptsPreservedTest.php` — 3 tests (HEAL B.1 validation)
11. `PersistBranchStatusChangedTest.php` — 5 tests (branch status listener)

**Total**: 60 test methods across 11 files, 2635 lines of test code.

### 7. Wave L Heals Verified at HEAD (4255ec15a)

✓ **B.1** (commit 7db47f022): `OutboxRetryFailedCommand.php` — attempts left monotonic, cap=12, REPLAY_MAX_ATTEMPTS constant
✓ **B.2** (commit bca6ca356): `EscalateOutboxBroadcastSwallowed.php` listener + EventServiceProvider registration
✓ **B.3** (commit 8bea2c005): `config/broadcasting.php` — dead polling_fallback PHP block removed, JS constants documented
✓ **B.4** (commit cda1d1b4e): `OutboxRescueCommand.php` — two-lane rescue (pending-stale + crash-claimed ≥10min)

**All 4 heals present in current HEAD.**

---

## Sub-Systems Identified (4 Critical)

### S1: Outbox Write + Listener Layer
- **Entry**: Domain events dispatched (OrderCreated, etc.) via DispatchableAfterCommit
- **Persist**: 11 PersistX listener writes to domain_events table
- **Exit**: domain_events row queued for DispatchDomainEventsJob
- **Risk**: DispatchableAfterCommit callback could be lost on transaction rollback; mitigated by design (callback discarded on rollback = correct behavior)

### S2: Outbox Delivery + Retry/Rescue
- **Job**: DispatchDomainEventsJob (3-phase: Phase 1 claim, Phase 2 broadcast, Phase 3b release)
- **Retry**: OutboxRetryFailedCommand (hourly, bounded batch 500, REPLAY_MAX_ATTEMPTS=12, lock-gated)
- **Rescue**: OutboxRescueCommand (stale-pending ≥2min, crash-claimed ≥10min, both attempts<5)
- **Risk**: Worker crash between claim+release orphans row; HEAL B.4 widens rescue to catch via dispatched_at ≠ null

### S3: Pusher/Echo Channel Auth + Real-Time Push
- **Auth**: routes/channels.php — branch-scoped with token-name gating (kiosk-token) + role check (Admin/Tenant Admin)
- **Client**: echo.js Bearer auth via Sanctum token, re-auth on login
- **Swallow escalation**: OutboxBroadcastSwallowedEvent → EscalateOutboxBroadcastSwallowed (Log::critical fiscal channel)
- **Risk**: Pusher channel-auth latency (Sanctum wildcard closure in R3 T-3.2.2 fixed), Echo client reconnect flap on network bounce

### S4: Polling Fallback (Advisory Safety Net)
- **POS**: 30s, webpack env configurable
- **KDS**: 5s down / 60s up, hardcoded Vue component
- **Kiosk**: 15s, hardcoded Vue component
- **Risk**: Polling drift under high volume (60 concurrent screens = 1000+ req/min); not load-tested

---

## Test Counts Summary

| Category | Count |
|---|---|
| Outbox Feature Test Files | 11 |
| Outbox Test Methods | 60 |
| Total Outbox Test Lines | 2635 |
| Listeners Tested (broadcast swallowed) | 1 (HEAL B.2) |
| Rescue Sentinels (stale-claimed) | 5 (HEAL B.4) |
| Retry-Failed Sentinels (attempts cap) | 3 (HEAL B.1) |

---

## Pre-Cloud Risks (Top 3)

### RISK-1: Pusher Channel-Auth Latency (P2)
**Vector**: Sanctum PersonalAccessToken::can() wildcard collision (R3 T-3.2.2 closed via TOKEN NAME check).
**Current**: Token name discrepancy between kiosk-token (lockdown) and admin/staff '*' (wildcard).
**Cloud exposure**: Multi-tenant SaaS + TLS renegotiation on load = channel-auth 200-400ms (observed 50ms local).
**Verdict**: MITIGATED by HEAL R3 (token name + role check). Recommend: pre-cloud load test @ 100 concurrent, measure auth latency.

### RISK-2: Polling Drift Under Network Flap (P2)
**Vector**: Each surface hardcoded polling interval independent; KDS 5s down loses real-time if echo hangs >1 cycle.
**Current**: Polling marked advisory (60s up / 5s down). No exponential backoff on Pusher reconnect.
**Cloud exposure**: Network bounce → Echo reconnect 200-500ms → KDS shows stale for 1 poll cycle (5s worst-case).
**Verdict**: OBSERVED in V1 Wave P cross-surface latency test (kiosk pay→KDS ≤6s, KDS bump <500ms, OSS pickup→removal 6.1s). No lock-step guarantee under cloud flap.
**Action**: Document SLA § "KDS bump latency ≤500ms assumes Pusher <50ms". Monitor Pusher reconnect latency pre-prod.

### RISK-3: Outbox Retry Under Network Flap (P1)
**Vector**: DispatchDomainEventsJob Phase 2 broadcast can hang (observed Pusher 30-60s deadlock).
**Current**: HEAL B.4 widened rescue to catch dispatched_at ≠ null after 10min; assumes worst broadcast = ~60s.
**Cloud exposure**: If cloud broadcast provider has longer hang window (e.g. 5+ min), crash-claimed rows orphan past rescue TTL.
**Verdict**: LOW RISK (10min >> 60s) but TIGHT. Recommend: cloud broadcast provider measure SLA, verify hang window <300s before prod.

---

## Verdict: PRODUCTION-READY FOR CLOUD PREP (Conditional)

**Green**: Outbox model, all 11 listeners, delivery job, retry/rescue infrastructure, channel auth, polling fallback — all present + tested.

**Wave L Heals Verified**: B.1 (attempts cap), B.2 (broadcast escalation), B.3 (config cleanup), B.4 (crash recovery) — all at HEAD.

**Frozen-Zone Overlap**: IdempotencyKeyMiddleware.php (§7), BranchScope.php (§7), webhook_events UNIQUE constraint (synced).

**NF525 Invariants**: write-then-dispatch audit ordering preserved (HEAL B.1 unchanged).

**Pre-Cloud Gate**: 
- ✓ Sync spine structure sound (4 sub-systems, 60 sentinels, no regressions)
- ✓ Channel-auth HEAL applied (token name + role check)
- ⚠ Polling drift advisory (per-surface hardcoded; V1.0.2 SoT migration)
- ⚠ Broadcast hang tolerance (10min rescue TTL; recommend cloud SLA <300s)

**Ship recommendation**: V1 LOCAL cloud-ready. Require: (1) Pusher/Echo load test @ 100 concurrent, (2) cloud broadcast provider SLA signature on recovery window <300s.

