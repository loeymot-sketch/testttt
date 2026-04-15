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

## Adding new limits
1. Define limiter in `RouteServiceProvider::configureRateLimiting()`
2. Apply via `throttle:limiter-name` middleware on route or group
3. Update this matrix
4. Add test in `tests/Feature/Security/RateLimitTest.php`
