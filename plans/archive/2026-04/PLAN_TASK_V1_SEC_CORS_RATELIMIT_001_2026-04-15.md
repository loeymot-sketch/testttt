# Plan – TASK_V1_SEC_CORS_RATELIMIT_001 – 2026-04-15

## TASK_ID
TASK_V1_SEC_CORS_RATELIMIT_001

## PRIMARY_MODEL
Composer (routine — config, middleware, no business logic)

## TEST_STRATEGY
`local-validation` — PHPUnit: CORS rejection test, rate limit 429 test.

## PRIOR_CONTEXT
Current state:
- `config/cors.php`: `allowed_origins => ['*']`, `supports_credentials => false` — wide open.
- `RouteServiceProvider`: defines `api` (120/min) and `kiosk-orders` rate limiters only.
- Existing route-level throttles: auth/login (5/min), kiosk-orders, coupon, loyalty, subscriber, dining-order. Admin CRUD and POS have NO throttle beyond the global 120/min api limiter.
- No `ThrottlesLogins` trait used — login lockout relies only on route-level `throttle:5,1`.
- `Kernel.php` api group applies `throttle:api` globally.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `config/cors.php` | Whitelist origins, enable credentials | Write | No | No |
| `app/Providers/RouteServiceProvider.php` | Add rate limiter definitions | Write | No | No |
| `routes/api.php` | Apply throttle middleware to admin/POS route groups | Write | No | No |
| `.env.example` | Add CORS domain env vars | Write | No | No |
| `tests/Feature/Security/CorsTest.php` | New — CORS rejection test | Write | No | No |
| `tests/Feature/Security/RateLimitTest.php` | New — 429 test | Write | No | No |
| `docs/RATE_LIMITS_MATRIX.md` | New — rate limit documentation | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Auth logic (no 2FA, no guard changes)
- Frozen zones (OrderService, FrontendOrderService)
- Business logic, pricing, status transitions
- Database migrations

## INVARIANTS_AT_RISK
- None

## GATE_CONDITIONS
- None anticipated

## Execution Steps

### E1 — CORS whitelist
Update `config/cors.php`:
```php
'allowed_origins' => array_values(array_filter([
    env('APP_URL'),
    env('KIOSK_DOMAIN'),
    env('ADMIN_DOMAIN'),
])),
'supports_credentials' => true,
'max_age' => 86400,
```
Update `.env.example` to document `KIOSK_DOMAIN` and `ADMIN_DOMAIN` vars.

### E2 — Rate limiter definitions
In `RouteServiceProvider::configureRateLimiting()`, add:
- `admin-mutation`: 30/min by user_id (fallback IP)
- `pos-order-create`: 60/min by user_id
- `pos-order-update`: 120/min by user_id
- `login-lockout`: 10 attempts per 10 min by email+IP, lockout 15 min

Keep existing `api` (120/min) and `kiosk-orders` limiters unchanged.

### E3 — Apply throttle to unprotected route groups
In `routes/api.php`:
- Admin mutation routes (POST/PUT/PATCH/DELETE under `/admin/`): add `throttle:admin-mutation`
- POS order creation: add `throttle:pos-order-create`
- POS order update: add `throttle:pos-order-update`
- Replace login `throttle:5,1` with `throttle:login-lockout` for enhanced lockout behavior

Do NOT modify existing kiosk-order, coupon, loyalty, subscriber throttles (already correct).

### E4 — Tests
1. `tests/Feature/Security/CorsTest.php`:
   - Request with `Origin: https://evil.com` → CORS headers absent or blocked
   - Request with `Origin: APP_URL` → CORS headers present

2. `tests/Feature/Security/RateLimitTest.php`:
   - 31 sequential admin mutation requests → 31st returns 429
   - Verify `Retry-After` header present on 429 response

### E5 — Documentation
Create `docs/RATE_LIMITS_MATRIX.md` with full route × limit × key × justification table.

## SYMMETRY_NOTE
N/A

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened
