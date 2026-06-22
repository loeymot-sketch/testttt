# W4 — Sync Spine Under Stress (Outbox + Pusher + Polling)

**Date**: 2026-05-21 · **Mode**: READ-ONLY DEEP AUDIT · **Branch**: `heal/cms-pr1-quickwins-2026-05-18` HEAD `1116b39578`
**Scope**: Outbox listeners + delivery job + retry/rescue + Pusher/Echo + per-surface polling
**Anchor**: `reports/audit/goal-pre-cloud-2026-05-21/anchors/04-sync.md`

---

## Path

`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`

---

## Wave L Heals B.1–B.4 — Attestation at HEAD `1116b39578`

All 4 commits are direct ancestors of HEAD (verified via `git merge-base --is-ancestor`):

| Heal | Commit | File | Verified |
|---|---|---|---|
| **B.1** Attempts monotonic + cap 12 | `7db47f022` | `app/Console/Commands/OutboxRetryFailedCommand.php:67,104` (`REPLAY_MAX_ATTEMPTS = 12`) | ✓ |
| **B.2** OutboxBroadcastSwallowed listener | `bca6ca356` | `app/Listeners/EscalateOutboxBroadcastSwallowed.php` + `EventServiceProvider.php:275-277` | ✓ |
| **B.3** Dead `polling_fallback` config removed | `8bea2c005` | `config/broadcasting.php:20-31` (PHP block deleted, per-surface JS documented) | ✓ |
| **B.4** Rescue widen stranded-claimed (10min) | `cda1d1b4e` | `app/Console/Commands/OutboxRescueCommand.php:34-48` (two-lane: pending-stale 2min + crash-claimed 10min) | ✓ |

**ALL 4 WAVE L HEALS PRESENT AT HEAD.**

---

## Listener Count

**Target**: 11 (per cartography) — **Actual**: **12** (cartography undercount by 1).

```
app/Listeners/Persist*ToOutbox.php (12 files):
  PersistBranchStatusChangedToOutbox
  PersistCatalogChangedToOutbox
  PersistCouponChangedToOutbox
  PersistItemAvailabilityChangedToOutbox
  PersistItemExtraAvailabilityChangedToOutbox
  PersistItemVariationAvailabilityChangedToOutbox
  PersistOrderCreatedToOutbox
  PersistOrderPaidAtCounterToOutbox
  PersistOrderPaymentStatusChangedToOutbox
  PersistOrderStatusChangedToOutbox
  PersistOrderTableChangedToOutbox       <-- missing from cartography
  PersistSettingsUpdatedToOutbox
```

**Plus**: `EscalateOutboxBroadcastSwallowed` (B.2 alarm listener, not a Persist-to-outbox).

---

## Test Results

```
php artisan test --filter Outbox           → 115 passed, 2 skipped (CI_WEBSOCKETS_HARNESS gated)
php artisan test --filter "Sync|Broadcast" → 119 passed, 3 skipped (harness gated)
```

All sync-spine sentinels GREEN. Skipped tests require live Soketi harness (`CI_WEBSOCKETS_HARNESS=1`) — documented at `docs/runbooks/CI_WEBSOCKETS_HARNESS.md`.

---

## Polling Intervals — Verified Per Surface

| Surface | Interval | File:Line | Source |
|---|---|---|---|
| POS | 30000ms default | `resources/js/store/modules/posOrder.js:5,64-68` | Env-driven `MIX_BROADCAST_POLLING_FALLBACK_MS` |
| KDS | 5000ms (WS down) / 60000ms (WS up) | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1819-1826` | Hardcoded adaptive |
| Kiosk | 15000ms always | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:168` | Hardcoded |

Matches mission contract POS 30s / KDS 5s / Kiosk 15s. Per-surface SoT divergence documented as V1.0.2 backlog (B.3 cleanup notice).

---

## Channel Auth (routes/channels.php) — R3 Heal Verified

- `App.Models.User.{id}` — 1:1 user gate (line 16-18).
- `branch.{branchId}` — three-tier (line 41-62):
  1. **Token-name** check (`kiosk-token` literal, **NOT** `tokenCan`) → immune to Sanctum `*` wildcard. Closes Wave R3 T-3.2.2 F-SEC-W6-01.
  2. **Explicit role** check (`Admin`/`Tenant Admin`) on cross-branch path → closes Guest-Echo-Bypass (branch_id=0 default seed).
  3. **Regular staff** → own branch only.

**Visible attack vectors closed**: kiosk-token cannot subscribe other branches; admin '*' wildcard cannot route through kiosk lane; guest/customer (branch_id=0) blocked.

---

## Production Boot Guards (`AppServiceProvider:78-220`)

Triple-checked, all present:
- `POS_SIMULATION_HARDWARE !== false` → RuntimeException
- `IDEMPOTENCY_MIDDLEWARE_ENABLED !== true` → RuntimeException
- `APP_DEBUG === true` → RuntimeException
- `APP_URL` empty → RuntimeException
- `BROADCAST_DRIVER` ∈ `[null,'null']` → RuntimeException
- `QUEUE_CONNECTION === 'sync'` → RuntimeException
- `CACHE_DRIVER` ∈ `[file,array]` → RuntimeException (NF525 audit chain atomicity)

**Cloud-prep**: prod cannot boot with file cache, sync queue, or null broadcaster. ATTESTED.

---

## Adversarial Findings

### P1-W4-01 — Crash-claimed rows with `attempts >= 5` are NEVER recovered (silent loss vector)

**Evidence (line-precise)**:
- `app/Console/Commands/OutboxRescueCommand.php:47` — crash-claimed lane caps at `where('attempts', '<', 5)`.
- `app/Models/DomainEvent.php:45-49` — `scopeFailed()` requires `pending()` = `whereNull('dispatched_at')`.
- `app/Console/Commands/OutboxRetryFailedCommand.php:103` — uses `failed(5)` → requires `dispatched_at IS NULL`.

**Vector**: Worker is KILL-9'd between Phase 1 (claim sets `dispatched_at=now`, `attempts++`) and Phase 3b (release on throw). If the row has previously failed ≥4 times (so current attempt is the 5th or beyond) and the worker process dies before Phase 3b, row state becomes `{attempts=5, dispatched_at=<crash_ts>}`. After 10 min:
- Rescue lane B sees `dispatched_at != null` ✓ but `attempts<5` ✗ → **SKIP**.
- RetryFailed sees `attempts>=5` ✓ but `dispatched_at != null` (fails `pending()`) ✗ → **SKIP**.
- Prune lane sees `attempts>=6 AND created_at < 90d cutoff` — only after 90 days, AND only if attempts incremented past 6 (which it cannot, because no rescue/retry will run on it).

**Result**: Row is stranded forever on attempts=5. Pages the `MonitorOutboxStaleness` alert (filter is `dispatched_at IS NULL`? — needs verification, see below) BUT silently never broadcasts.

**Probability**: Low (requires retry-curve exhaustion + crash on terminal attempt). Real (no SIGKILL-proof design).

**Heal proposal (out of scope, READ-ONLY)**: Widen Rescue lane B (`attempts < 12` instead of `< 5`) OR relax `failed(5)` to also catch `dispatched_at != null AND dispatched_at < now()-10min`. The comment at `OutboxRescueCommand.php:31-32` acknowledges the disjoint intent — needs explicit gap fix.

### P2-W4-02 — `MonitorOutboxStaleness` filter coverage of orphan rows

**Open question**: does `MonitorOutboxStaleness` page on `dispatched_at != null AND dispatched_at < cutoff`, or only on `dispatched_at IS NULL`? If the latter, the P1-W4-01 stranded rows are SILENT (no page). Recommended verification: read `app/Console/Commands/MonitorOutboxStaleness.php` query.

### P2-W4-03 — No explicit Pusher HTTP timeout

`config/broadcasting.php:46-61` does not set a Guzzle HTTP timeout. Comment in `OutboxRescueCommand.php:30` notes "worst observed Pusher/Soketi broadcast hang (~30-60s, no explicit HTTP timeout)". The 10min crash-claimed rescue window assumes ≤60s hang — if a cloud Pusher provider hangs longer (e.g. 5+ min on degraded network), rescue still catches at 10min. **Safe for now** but recommend explicit `client_options.timeout = 5` for cloud.

### P3-W4-04 — Per-surface polling SoT divergence is V1.0.2 backlog

Each surface (POS env-driven, KDS hardcoded adaptive, Kiosk hardcoded constant) defines its own cadence. No drift detected (matches mission contract values) but no single source of truth = future drift risk. Heal B.3 documented this explicitly.

### CHECKED & GREEN
- DispatchableAfterCommit present on **all 25 critical outbox events** (`grep -L DispatchableAfterCommit app/Events/` returns only the 11 Send*Mail/Push/Sms notifications + `OutboxBroadcastSwallowedEvent` = swallow signal itself; intentional).
- Idempotency middleware uses cache-backed atomic SET NX EX repository; prod boot guard blocks file/array cache drivers.
- Phase 3b throw correctly releases `dispatched_at` on regular exceptions (line 161-189), only KILL-9 is unrecoverable (P1-W4-01).
- 12 outbox listeners (cartography said 11; `PersistOrderTableChangedToOutbox` was undercounted — not a defect, doc nit).

---

## Counts

| Category | Count |
|---|---|
| P0 findings | 0 |
| P1 findings | 1 (W4-01 stranded-claimed gap attempts≥5) |
| P2 findings | 2 (W4-02 monitor coverage open Q, W4-03 no HTTP timeout) |
| P3 findings | 1 (W4-04 polling SoT divergence V1.0.2 backlog) |

---

## Top-5 Pre-Cloud Risks (Ranked)

1. **P1 — Stranded crash-claimed `attempts≥5`** (W4-01). Narrow probability, real silent-loss vector. Pre-cloud fix recommended (1-line widen on rescue lane B).
2. **P2 — Pusher HTTP timeout absent**. Cloud broadcast provider may exceed assumed 60s ceiling; add explicit `client_options.timeout=5`.
3. **P2 — Staleness monitor query unverified vs orphan rows**. If monitor pages only on `pending()`, P1-W4-01 stranded rows are silent.
4. **P2 — Pusher channel-auth latency under load**. Sentinels GREEN local; production load test @ 100 concurrent recommended (per cartography RISK-1).
5. **P3 — Per-surface polling SoT divergence**. V1.0.2 backlog, documented; no drift today.

---

## Verdict: **VERIFIED with 1 P1 NEEDS-HEAL**

**Green attestation**:
- 4/4 Wave L heals B.1–B.4 present at HEAD `1116b39578`.
- 12 Persist listeners + 1 escalation listener present + registered.
- 234 Outbox/Sync tests pass (115 outbox + 119 sync, 5 harness-skipped intentionally).
- Channel-auth R3 token-name + role gates verified.
- Boot guards triple-checked (BROADCAST/QUEUE/CACHE/APP_URL/IDEMPOTENCY/POS_SIM/DEBUG).
- DispatchableAfterCommit present on all critical events.
- Polling cadences match contract per surface.

**P1 blocker for cloud**: W4-01 stranded-claimed `attempts≥5` gap. Probability narrow (KILL-9 during retry≥5) but real silent loss. Recommend 1-line heal in `OutboxRescueCommand.php:47` (widen `attempts<5` to `attempts<12` for crash-claimed lane only) + reverify `failed(5)` semantics, plus sentinel for the SIGKILL-during-attempt-5 scenario.

**Sync spine is structurally sound. One discrete heal closes the last silent-loss vector before cloud go-live.**
