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
