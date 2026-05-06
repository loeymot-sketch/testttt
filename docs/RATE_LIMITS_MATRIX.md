# Rate Limits Matrix — FoodKing V1

## CORS Policy
- `allowed_origins`: restricted to `APP_URL`, `KIOSK_DOMAIN`, `ADMIN_DOMAIN` (env vars).
- Wildcard `*` is prohibited — enforced by CI test (`CorsTest::test_cors_config_does_not_contain_wildcard`).
- `supports_credentials: true` for Sanctum cookie auth.

## Rate Limits

| Route pattern | Limit | Key | Justification |
|---|---|---|---|
| `POST /api/auth/login` | 10 / 10 min | email + IP | Anti brute-force, 15 min lockout |
| `POST /api/auth/kiosk-login` | 10 / 10 min | email + IP | Same |
| `POST /api/auth/forgot-password/*` | 3 / 60 min | IP | OTP abuse prevention |
| `POST /api/auth/signup/*` | 5 / min | IP | Signup abuse |
| Admin CRUD (POST/PUT/PATCH/DELETE `/api/admin/*`) | 30 / min | user_id | Admin mutation frequency cap |
| `POST /api/admin/pos/quote` (pricing preview) | 120 / min | user_id | SSOT quote bursts + E2E; still under `throttle:api` baseline |
| `POST /api/admin/pos/counter-collect/*` | 120 / min | user_id | Encaissement / annulation borne — même relèvement que quote |
| `throttle:pos-quote` on same route | 120 / min | user_id | Dedicated bucket (not `pos-order-create`) |
| POS order creation | 60 / min | user_id | ~1 order/sec with margin |
| POS order update | 120 / min | user_id | Frequent status changes at peak |
| Kiosk order creation (`POST /api/frontend/order`) | config-driven / min | IP | Configurable via `kiosk.order_rate_limit` |
| Coupon checking | 10 / min | IP | Prevents coupon brute-force |
| Loyalty check/register | 10 / min, 5 / min | IP | Prevents loyalty abuse |
| Frontend subscriber | 5 / min | IP | Spam prevention |
| Table dining order | 20 / min | IP | QR order spam prevention |
| All other API routes | 120 / min | user_id or IP | Global API baseline |

## Baseline
Every API route inherits the global `throttle:api` (120 req/min, keyed by user id or IP) from the `api` middleware group in `app/Http/Kernel.php`. Named limiters (`admin-mutation`, `pos-order-create`, `pos-order-update`, `kiosk-orders`, `login-lockout`) layer **stricter** caps on top of this baseline — they never loosen it.

Web routes (`routes/web.php`) are limited to installer bootstrap and a payment callback, which are explicitly excluded from throttling because they are user-initiated redirects or first-run setup.

## Test coverage
- `tests/Feature/Security/CorsTest.php` — asserts no wildcard origin, rejects unknown origins on preflight, echoes whitelisted `APP_URL` back.
- `tests/Feature/Security/RateLimitTest.php` — exercises the admin-mutation cap and the login-lockout ceiling via HTTP.
- `tests/Unit/Security/RateLimiterConfigTest.php` — regression guard that every named limiter is still registered with its documented per-minute cap.

## Adding new limits
1. Define limiter in `RouteServiceProvider::configureRateLimiting()`.
2. Apply via `throttle:limiter-name` middleware on the route or group.
3. Update this matrix.
4. Add a case in `RateLimiterConfigTest::EXPECTED_LIMITERS` (static cap guard) and a live test in `RateLimitTest.php` (end-to-end).

## Idempotency Matrix

HTTP-level idempotency (`X-Idempotency-Key`) is layered ON TOP of throttling for state-mutating POS and kiosk routes. See `docs/IDEMPOTENCY.md` for the integrator contract. Middleware: `App\Http\Middleware\IdempotencyKeyMiddleware`, alias `idempotency`. Feature flag `IDEMPOTENCY_MIDDLEWARE_ENABLED` (default `false`).

| Route | Header required when flag ON | TTL | Scope | Notes |
|---|---|---|---|---|
| `POST /api/admin/pos` | yes | 24h | `(branch_id, user_id, key)` | Stacks with `throttle:pos-order-create` (60/min). |
| `POST /api/admin/pos-order/change-payment-status/{order}` | yes | 24h | same | Stacks with `throttle:pos-order-update` (120/min). |
| `POST /api/admin/pos-order/select-delivery-boy/{order}` | yes | 24h | same | Stacks with `throttle:pos-order-update`. |
| `POST /api/admin/online-order/change-payment-status/{order}` | yes | 24h | same | Inherits `throttle:admin-mutation` (30/min). |
| `POST /api/admin/online-order/select-delivery-boy/{order}` | yes | 24h | same | Inherits `throttle:admin-mutation`. |
| `POST /api/admin/table-order/change-payment-status/{order}` | yes | 24h | same | Inherits `throttle:admin-mutation`. |
| `POST /api/frontend/order` | yes | 24h | same | Stacks with `throttle:kiosk-orders`. Frontend already emits the header. |
| `POST /api/frontend/order/{frontendOrder}/payment-confirm` | yes | 24h | same | Borne CB Windows : ensure terminal echoes its own UUID. |

Routes NOT covered (pricing preview / read-only / counter-collect helpers) intentionally skip the middleware — they have no side effects worth de-duplicating.

**Behavioral matrix**

| Header | Server response | Notes |
|---|---|---|
| absent on listed route | `422 MISSING_IDEMPOTENCY_KEY` | client MUST retry with a key |
| absent on non-listed route | passes through | back-compat |
| present, replay match (same payload hash) | original 2xx body + `Idempotency-Replayed: true` + `Idempotency-Stored-At: <ISO8601>` | idempotent retry |
| present, replay match, payload differs | `409 IDEMPOTENCY_KEY_CONFLICT` + `Idempotency-Key-Conflict: true` | client must pick a fresh key |
| present, twin in flight, no completion in `race_wait_ms` | `425 IDEMPOTENCY_IN_FLIGHT` | client MAY retry |
| present, storage unavailable, `fail_open=false` | `503 IDEMPOTENCY_STORAGE_UNAVAILABLE` | ops alert |
| present, storage unavailable, `fail_open=true` | passes through | relies on app-layer UNIQUE backstop |

**Validation regex**: `^[A-Za-z0-9._\-]{8,64}$` (UUID-v4 compliant).

**Test coverage**:
- `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php` — 8 unit-style scenarios
- `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php` — 5 scenarios on real opt-in routes
- `tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php` — applicative branch-scoped recovery (defense-in-depth, untouched).
