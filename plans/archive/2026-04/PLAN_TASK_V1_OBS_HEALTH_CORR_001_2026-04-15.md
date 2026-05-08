# Plan – TASK_V1_OBS_HEALTH_CORR_001 – 2026-04-15

## TASK_ID
TASK_V1_OBS_HEALTH_CORR_001

## PRIMARY_MODEL
Composer (routine — infrastructure middleware, config, no business logic)

## TEST_STRATEGY
`local-validation` — PHPUnit: health endpoint, correlation ID propagation.

## PRIOR_CONTEXT
- SYNC_BACKBONE_001 (dependency) is complete — broadcasting/queue infrastructure is live.
- OUTBOX_001 created `DispatchDomainEventsJob` which already has a `correlation_id` column in `domain_events`. The job can be enhanced to propagate correlation_id via the new trait.
- `config/logging.php` already exists with standard Laravel channels.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Http/Controllers/HealthController.php` | New — /health, /health/live, /health/ready | Write | No | No |
| `routes/api.php` | Add health routes | Write | No | No |
| `app/Http/Middleware/CorrelationIdMiddleware.php` | New — UUID per request | Write | No | No |
| `app/Http/Kernel.php` | Register correlation middleware in api + web groups | Write | No | No |
| `config/logging.php` | Add `production_json` channel | Write | No | No |
| `app/Logging/JsonFormatter.php` | New — structured JSON log formatter | Write | No | No |
| `app/Traits/HasCorrelationId.php` | New trait for job correlation propagation | Write | No | No |
| `app/Jobs/DispatchDomainEventsJob.php` | Add HasCorrelationId trait | Write | No | No |
| `.env.example` | Add HEALTH_IPS_ALLOWED, LOG_CHANNEL vars | Write | No | No |
| `tests/Feature/HealthControllerTest.php` | New tests | Write | No | No |
| `tests/Feature/CorrelationIdMiddlewareTest.php` | New tests | Write | No | No |
| `docs/OBSERVABILITY.md` | New documentation | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Frozen zones (OrderService, FrontendOrderService)
- Database migrations — none
- APM / OpenTelemetry / Grafana / Prometheus — V1.5
- Auth guards/tokens

## INVARIANTS_AT_RISK
- None

## GATE_CONDITIONS
- None anticipated

## Execution Steps

### E1 — HealthController
Create `app/Http/Controllers/HealthController.php` with:
- `full()` → JSON: status (ok/degraded), version, uptime, subsystem checks (db, redis, queue, broadcast).
- `live()` → 200 OK plain text.
- `ready()` → 200 if all subsystems ok, 503 if any degraded.

### E2 — Health routes
In `routes/api.php` (or `routes/web.php`):
- `GET /health` → `HealthController@full` (no auth, but IP-restricted via env).
- `GET /health/live` → `HealthController@live` (public).
- `GET /health/ready` → `HealthController@ready` (public).

### E3 — CorrelationIdMiddleware
Create `app/Http/Middleware/CorrelationIdMiddleware.php`:
- Read `X-Correlation-ID` from request, or generate UUID.
- Set on request headers, inject into `Log::withContext`.
- Set on response headers.
Register in `Kernel.php` api + web middleware groups.

### E4 — JSON log formatter
Create `app/Logging/JsonFormatter.php` (extends Monolog's JsonFormatter).
Add `production_json` channel to `config/logging.php`.

### E5 — Job correlation propagation
Create `app/Traits/HasCorrelationId.php`:
- Captures correlation_id from log context at dispatch time.
- Restores it in `handle()` via `Log::withContext`.
Apply to `DispatchDomainEventsJob`.

### E6 — Tests
- `HealthControllerTest`: /health returns JSON schema, /health/live returns 200, /health/ready returns 200 or 503.
- `CorrelationIdMiddlewareTest`: generates UUID if absent, propagates if present, appears in response header.

### E7 — Documentation
Create `docs/OBSERVABILITY.md`.

### E8 — Update .env.example
Add `HEALTH_IPS_ALLOWED=` and document `LOG_CHANNEL=production_json` for production.

## SYMMETRY_NOTE
N/A

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened
