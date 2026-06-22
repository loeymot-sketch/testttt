# PK-4 Resilience + TZ-awareness — STATUS (Round 1)

**Date:** 2026-05-18
**Master sub-agent:** PK-4 intersection (parallel with PK-1 / PK-2 / PK-3)
**Mode:** read-only audit + safe heal authorization
**Branch:** v1-0-1-hardening-2026-05-17 @ 9df4809b5

---

## Scope

Sync resilience surfaces and timezone-aware boundaries at the POS x KDS intersection:

- Outbox -> Pusher reconnect handling (storm detection, circuit breaker, jitter)
- Polling fallback cadence (KdsSyncService: 250ms floor, 60s ceiling — Wave 3 + Wave 3b clamps)
- TZ-aware bounds (c2613cab0 + 148dbebce + 4905138fa — Paris -> UTC conversion, KEEP)
- ws:heartbeat after broadcast (Wave 5 S-P0-A heal 65f59e82f)
- Outbox Cache::lock TTL bump 300s (Wave 3c P1)
- Webhook DLQ retention gap (24h-vs-180d — flagged in scope as F-3 backlog)

---

## Anchors verified

| Anchor | File | Lines | Status |
|---|---|---|---|
| Frontend sync service | `resources/js/services/KdsSyncService.js` | 25-27, 455-490 | CONVERGED |
| Backend bounds | `app/Services/KdsSyncService.php` | 77-94 | CONVERGED (Wave 2c) |
| Sister KDS list | `app/Services/KitchenDisplaySystemOrderService.php` | 104-107, 282-285 | CONVERGED (Wave 2b) |
| Sister OSS | `app/Services/OrderStatusScreenOrderService.php` | 77-80, 108, 208-211, 228 | DIRTY-OBSERVE — already healed Wave 2b + 3c |
| WebSocket reconnect | `resources/js/services/WebSocketService.js` | 81-102, 264-350 | CONVERGED (NEW-02) |
| Outbox dispatcher heartbeat | `app/Jobs/DispatchDomainEventsJob.php` | 127-131 | CONVERGED (Wave 5 S-P0-A) |
| Outbox retry lock | `app/Console/Commands/OutboxRetryFailedCommand.php` | 44, 50, 64 | DIRTY-OBSERVE — already at 300s (Wave 3c) |
| Webhook retry lock | `app/Console/Commands/OutboxWebhookRetryFailedCommand.php` | 64, 70 | DIRTY-OBSERVE — already at 300s (Wave 3c) |
| Cron schedule | `app/Console/Kernel.php` | 40-147 | VERIFIED |
| Sentinels | `tests/Feature/KDS/KdsSyncSargableTest.php` + `tests/Feature/KDS/KdsSyncTzAwareTest.php` + `tests/Feature/Services/SisterServicesTzAware{,V2}Test.php` + `tests/Feature/Observability/WsHeartbeatWriteSentinelTest.php` | — | EXIST |

---

## 4 Specialist verdicts

### Architect (architect.json)
- **2 VERIFIED** items: Pusher reconnect logic complete and defended; ws:heartbeat write-side now plumbed
- **1 VERIFIED** item: 60s drift timer keeps KDS alive even when WS healthy (belt-and-suspenders)
- **2 P3-INFO** deferred: undocumented fail-degraded default branch in `_baseCadence`; OSS sister-service DRY refactor

### SRE (sre.json)
- **All 7 session-A heals attested INTACT**: Wave 2c (148dbebce) + Wave 2b (c2613cab0) + Wave 3 (a1dd60f56) + Wave 3b (9ff26e12b) + Wave 3c (fe595a4d6 + 4905138fa) + Wave 5 (65f59e82f)
- **3 VERIFIED** items: dual-layer cadence clamps; uniform jitter spread prevents herd; retry locks safe under concurrency
- **1 VERIFIED** item: heartbeat best-effort try/catch is correct degraded-mode
- **1 P3-INFO** deferred: CLI default `--since=1h` vs cron `--since=24h` drift
- **1 RESOLVED-BY-DESIGN**: webhook DLQ 24h vs 180d retention gap is intentional (PCI dispute window)

### DBA (dba.json)
- **4 VERIFIED** items: TZ-aware bounds correct for prod MySQL UTC/Paris/TIMESTAMP; sargability preserved; DST behavior matches reality; deleted_ids branch needs no TZ conversion
- **1 P3-INFO** deferred: 10 KDS test failures are FIXTURE-side (SQLite driver), production behavior CORRECT — V1.0.X session-A scope per owner mandate

### RED-team (red.json)
- **12/12 adversarial probes PASS** including: Pusher server restart, single broadcast drop with drift-timer recovery, DST spring-forward (2026-03-29 23h window), DST fall-back (2026-10-25 25h window), POS<->KDS drift, outbox lock TTL vs DLQ surge, Redis outage heartbeat degradation, misconfig floor + ceiling attacks, concurrent admin+cron retry, hostile storm flood, no raw `Carbon::today()` reaching Eloquent
- **1 P3-INFO** deferred: AvailabilityService DATE-column Paris-day exception should be documented in CLAUDE.md §9
- **1 RESOLVED-BY-DESIGN**: 10 KDS test failures = V1.0.X session-A scope

---

## 4-list (consolidated findings)

| ID | Severity | Action | Note |
|---|---|---|---|
| PK4-ARCH-01 | VERIFIED | — | Pusher reconnect complete |
| PK4-ARCH-02 | VERIFIED | — | ws:heartbeat write-side plumbed |
| PK4-ARCH-03 | VERIFIED | — | 60s drift timer safety net |
| PK4-ARCH-04 | P3-INFO | defer V1.0.1 | Doc the fail-degraded `_baseCadence` default |
| PK4-ARCH-05 | P3-INFO | defer V1.0.1 | OSS DRY refactor (DIRTY-OBSERVE) |
| PK4-SRE-01 | VERIFIED | — | Dual-layer cadence clamps |
| PK4-SRE-02 | VERIFIED | — | Uniform jitter — no thundering herd |
| PK4-SRE-03 | VERIFIED | — | Retry locks safe under concurrency |
| PK4-SRE-04 | P3-INFO | defer V1.0.1 | Align CLI `--since` default to 24h (DIRTY-OBSERVE) |
| PK4-SRE-05 | RESOLVED-BY-DESIGN | — | 24h-vs-180d webhook gap intentional |
| PK4-SRE-06 | VERIFIED | — | Heartbeat best-effort try/catch correct |
| PK4-DBA-01 | VERIFIED | — | TZ bounds correct for prod MySQL |
| PK4-DBA-02 | VERIFIED | — | Sargability preserved (idx_orders_datetime) |
| PK4-DBA-03 | VERIFIED | — | DST behavior reflects real calendar day |
| PK4-DBA-04 | VERIFIED | — | deleted_ids needs no TZ conversion |
| PK4-DBA-05 | P3-INFO | defer V1.0.X | 10 KDS test failures = SQLite fixture, prod correct |
| PK4-RED-01 | VERIFIED | — | 12/12 adversarial probes PASS |
| PK4-RED-02 | P3-INFO | defer V1.0.1 | Document AvailabilityService DATE-column exception in CLAUDE.md §9 |
| PK4-RED-03 | RESOLVED-BY-DESIGN | — | 10 KDS test failures = V1.0.X session-A |

**Severity tallies:** P0=0, P1=0, P2=0, P3-INFO=5, VERIFIED=12, RESOLVED-BY-DESIGN=2

---

## Healing decision

**Heals applied this audit: 0**

Justification:
1. **All session-A heals attested intact** (Waves 2b/2c/3/3b/3c/Wave 5). Zone is already converged.
2. **DIRTY-OBSERVE files** under parallel agent ownership: `OutboxRetryFailedCommand.php`, `OutboxWebhookRetryFailedCommand.php`, `OrderStatusScreenOrderService.php` (per git status at session start). Mandate says non-frozen non-dirty only.
3. **Owner mandate** for 10 KDS test failures: flag for session-A, DO NOT heal in this audit.
4. **All P3-INFO findings** are docs/refactor work that the V1.0.1 backlog absorbs.

No manufactured heal would have improved the zone. Discipline observed.

---

## Files modified

None.

---

## Backlog added (V1.0.1)

1. **PK4-ARCH-04** — Add 1-line comment at `KdsSyncService.js:311` documenting fail-degraded default
2. **PK4-ARCH-05** — Extract OSS query-builder private method (DRY refactor)
3. **PK4-SRE-04** — Align `OutboxRetryFailedCommand.php` CLI default `--since=1h` -> `--since=24h` to match cron
4. **PK4-RED-02** — Document `AvailabilityService` DATE-column Paris-day exception in `CLAUDE.md §9`

## Backlog added (V1.0.X session-A)

5. **PK4-DBA-05 / PK4-RED-03** — 10 KDS test failures (SQLite TZ-fixture). Already in V1.0.X per owner mandate. Documented in PROJECT_BRAIN + commit 9df4809b5.

---

## Round-1 verdict

`P0=0 | P1=0 | P2=0 | P3-INFO=5 | VERIFIED=12 | RESOLVED-BY-DESIGN=2 | heals_applied=0 | backlog_added=5`

**Zone PK-4 (Resilience + TZ-awareness) is PRODUCTION-CONVERGED.**
