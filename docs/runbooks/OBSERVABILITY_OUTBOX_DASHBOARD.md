# OBSERVABILITY — Outbox Pipeline Dashboard

Mission: **CV1-OBSERVABILITY-OUTBOX-001**
Trigger: RED-R3 + RED-R5 §3 — when `laravel-websockets` or `queue:work` crashes
in production, ops have NO visual signal. Stock rupture broadcasts vanish
silently, KDS misses updates, customer screens go stale. This dashboard makes
the outbox pipeline observable from the admin SPA.

---

## 1. Surface

| Element | Path | Auth |
| --- | --- | --- |
| SPA route | `/admin/observability/outbox` | logged-in admin (Spatie role `Admin` or `Tenant Admin`) |
| Read endpoint | `GET /api/admin/observability/outbox` | `auth:sanctum` + `role:Admin\|Tenant Admin` |
| Retry endpoint | `POST /api/admin/observability/outbox/retry-failed` | `auth:sanctum` + `role:Admin\|Tenant Admin` + `throttle:10,1` |
| Drain endpoint | `POST /api/admin/observability/outbox/drain-failed` | `auth:sanctum` + `role:Admin\|Tenant Admin` + `throttle:5,1` |

Implementation lives in `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`
(methods `outboxOverview`, `outboxRetryFailed`, `outboxDrainFailed`). The
controller was **enriched** rather than duplicated — see mission note "ENRICHIR
plutôt que créer un nouveau" — but the new methods are isolated from the
pre-existing `index()` / `clientMetrics()` JSON contracts.

---

## 2. Response shape

```json
{
  "generated_at": "2026-05-07T10:42:18.000000Z",
  "pending": {
    "count": 7,
    "rows": [
      {
        "id": 12345,
        "event_type": "OrderUpdated",
        "aggregate_type": "order",
        "aggregate_id": 9871,
        "branch_id": 3,
        "attempts": 2,
        "last_error": "broker unreachable",
        "occurred_at": "...",
        "created_at": "..."
      }
    ]
  },
  "dispatched_24h": {
    "count": 4321,
    "latency_p50_ms": 18,
    "latency_p95_ms": 230,
    "latency_p99_ms": 980,
    "samples": 4321
  },
  "queue_high": {
    "available": true,
    "count": 12,
    "oldest_age_seconds": 47
  },
  "failed_jobs": {
    "available": true,
    "count": 3,
    "rows": [
      {
        "id": 78,
        "uuid": "...",
        "queue": "high",
        "connection": "database",
        "failed_at": "...",
        "exception_first_line": "Symfony\\\\... — connection refused"
      }
    ]
  },
  "health": {
    "queue_work": {
      "status": "up",
      "last_signal_age_seconds": 12,
      "method": "heuristic_jobs_reserved_or_event_dispatched_within_90s"
    },
    "websockets_serve": {
      "status": "up",
      "last_signal_age_seconds": 8,
      "method": "heuristic_cache_heartbeat_or_recent_dispatch_within_60s"
    }
  }
}
```

---

## 3. Health probe heuristics — known limitations

The dashboard does **not** shell out to `pgrep` or query the supervisor. Per-
request `shell_exec` is a security risk and brittle across environments
(Docker, k8s, bare metal). Instead, the V1 health card is a **derived signal**:

| Probe | Heuristic | Detects |
| --- | --- | --- |
| `queue_work.status` | `jobs.reserved_at` within last 90s OR `domain_events.dispatched_at` within last 90s | Worker actively claiming jobs OR successfully publishing |
| `websockets_serve.status` | `cache('ws:heartbeat')` within 60s OR `domain_events.dispatched_at` within 60s | Broadcast pulse keepalive (if present) OR observable broadcast traffic |

**Failure modes the heuristic does NOT catch:**
- A `queue:work` worker that is alive but stuck on a single hanging job for >90s
  with the queue otherwise idle. Mitigation: this is what `failed_jobs.count`
  surfaces after the worker times out.
- A `websockets:serve` daemon that accepts TCP connections but stops
  broadcasting (rare). Mitigation: client-side reconnect-storm metric in
  `sync_metrics` is the secondary signal.

**Future hardening (out of scope for V1):**
- Add `Cache::set('ws:heartbeat', now())` in a periodic broadcaster pulse
  (e.g., a `WebSocketsHeartbeatJob` scheduled every 30s).
- Real process probe via a dedicated /healthz endpoint exposed by each
  daemon (requires deployment topology decisions).

---

## 4. Operational use

### Symptom: customers report orders not updating in real time

1. Open `/admin/observability/outbox`.
2. Check the **health row** at top:
   - `queue:work DOWN` → restart the worker (`supervisorctl restart laravel-worker:*`).
   - `websockets:serve DOWN` → restart the daemon (`supervisorctl restart laravel-websockets:*`).
3. If both UP but **pending count grows**, look at `pending.rows[].last_error`:
   - "broker unreachable" → broadcast driver misconfigured.
   - HTTP 5xx → upstream notification provider down.
4. If the issue is upstream, click **Retry failed** to re-queue events with
   `attempts → 0`, `last_error → null` once the upstream recovers.

   Scope note: the dashboard's **Retry failed** is broader than the artisan
   command `foodking:outbox:retry-failed`. The console command requires
   `attempts >= 5` AND `created_at >= cutoff`; the dashboard endpoint
   requeues **any pending event with a recorded `last_error`** (capped to
   `limit` = 50 by default, max 200). This is intentional: the UX of a
   "Retry failed" button matches operator mental model of "retry anything
   visibly broken right now". For batch / cron-style retries with the
   stricter threshold, keep using the console command.

### Symptom: failed_jobs table balloons after an outage

1. Click **Drain failed > 24h** to purge `failed_jobs` rows older than 24h.
2. The endpoint refuses `older_than_hours < 1` (HTTP 422) — explicit guard
   against accidental "wipe everything" calls.
3. The endpoint **never** touches `domain_events` or fiscal tables; refunds,
   Z-reports and audit log are immune.

---

## 5. Polling + UX

The Vue component (`OutboxOverviewComponent.vue`) polls every 10s by default
(`pollIntervalMs` prop). Polling is paused when `document.hidden === true` to
avoid burning quota during long staff sessions; an immediate refresh fires on
`visibilitychange` when the tab regains focus.

`beforeUnmount()` clears both the interval and the visibility listener so a
SPA route change doesn't leak a background poller.

---

## 6. Tests

| Layer | File | Purpose |
| --- | --- | --- |
| PHPUnit | `tests/Feature/Observability/OutboxOverviewControllerTest.php` | 401 unauth, 403 non-admin, JSON shape, retry/drain semantics, drain refuses 0h |
| Vitest | `tests/js/observabilityOutboxRoute.spec.js` | Route declaration, router registration, 5 data-testid sections, polling primitives |

---

## 7. Frozen-zone audit

This work touches **none** of:
- `app/Services/Fiscal/*`
- `AuditLogService` / `app/Services/Audit/AuditLogService.php`
- `FiscalSequenceService` / `app/Services/Fiscal/FiscalSequenceService.php`

The retry/drain actions:
- `outboxRetryFailed` re-queues `domain_events` rows that already failed and
  are pending — same flow as `php artisan foodking:outbox:retry-failed`. No
  fiscal data is touched.
- `outboxDrainFailed` deletes from `failed_jobs` only, with a strict cutoff
  (≥1h). `domain_events` is never deleted by this endpoint.
