# Observability — FoodKing V1

## Health Endpoints

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /api/health` | None (IP-restricted in prod via HEALTH_IPS_ALLOWED) | Full system status |
| `GET /api/health/live` | None | Liveness probe (200 = process up) |
| `GET /api/health/ready` | None | Readiness probe (200 = healthy, 503 = degraded) |

### Response schema (`/api/health`)
```json
{
  "status": "ok|degraded",
  "version": "1.0.0",
  "timestamp": "2026-04-15T20:00:00+00:00",
  "subsystems": {
    "db": { "status": "ok" },
    "redis": { "status": "ok" },
    "queue": { "status": "ok", "default_size": 0, "high_size": 0 },
    "broadcast": { "status": "ok", "driver": "pusher" }
  }
}
```

## Correlation ID

Every HTTP request gets a `X-Correlation-ID` header (generated or propagated). This ID:
- Appears in all log entries for the request.
- Propagates to queued jobs via `HasCorrelationId` trait.
- Is stored in `domain_events.correlation_id` for outbox events.
- Is returned in the response header.

### Tracing a request
```bash
grep "correlation_id.*abc123" storage/logs/laravel.json.log
```

## Structured Logging

In production, set `LOG_CHANNEL=production_json` for JSON-formatted logs to `storage/logs/laravel.json.log`.

### Log entry format
```json
{
  "timestamp": "2026-04-15T20:00:00+02:00",
  "level": "INFO",
  "message": "Order created",
  "context": { "correlation_id": "abc-123", "user_id": 5, "branch_id": 1 },
  "channel": "production_json"
}
```

## Environment Variables

| Var | Purpose | Default |
|---|---|---|
| `HEALTH_IPS_ALLOWED` | CSV of IPs allowed for /health (empty = all) | empty |
| `LOG_CHANNEL` | Active log channel | `stack` |
