# FLOW 7 — Idempotency Under Concurrent Load Evidence

**Scenario** : POST `/api/admin/pos/` with the same `X-Idempotency-Key` header twice
concurrently (via `Promise.all`). Assert only ONE order is created in the DB.

## Setup

- Idempotency key: `SYNC-FLOW7-IDEMPOTENCY-<timestamp>` (per-test unique)
- Payload: item 362 (Boisson Seule), quantity=1, cash payment
- Both POSTs sent in parallel via Playwright `Promise.all`
- Order count snapshot before + after

## Measurements

| Metric | Value | Verdict |
|---|---|---|
| Concurrent POST roundtrip | 344ms (both) | OK |
| HTTP statuses | [201, 201] | OK (replay cache returned same response) |
| Order count delta | **1** | **OK** (single creation) |
| Idempotency contract | PASS (2 2xx, 0 409) | **OK** |

## Idempotency Architecture (verified per CLAUDE.md §9)

- HTTP `X-Idempotency-Key` header on POST mutating endpoints
- Scope = (branch_id, user_id, hash(key))
- Dual-layer defense:
  1. Middleware cache (`IdempotencyKeyMiddleware`)
  2. DB UNIQUE constraint on `webhook_events` (`provider, webhook_id`)
- 2xx-only replay cache, conflict 409 if payload differs from cached version

## Observed Behavior

Both POSTs returned HTTP 201 with the SAME response body (cached replay).
DB count went from N to N+1 (exactly one new order).

This confirms the middleware cache path is working: the second concurrent POST
either:
- (a) Found the first's response in cache and replayed (most likely 344ms latency), OR
- (b) Got blocked by Cache::lock and after release found the result in cache

Either way, **no double creation**.

## Verdict

**GREEN.** Idempotency contract honored under concurrent load.

## References

- Middleware: `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- Upstream lock: `app/Services/Order/OrderService.php:587` (Cache::lock per branch+user+key)
- Route guards (idempotency middleware): `routes/api.php:728` (POS create) +
  16 other endpoints (kiosk-orders, table orders, cash drawer, etc.)
