# RUN_P_MEGA_W8_B_THROTTLE_EXECUTE_2026-04-20

EXECUTE_DELEGATION: foodking-complex-implementer
PRIMARY_MODEL: GPT-5.4
TASK_ID: P_MEGA_W8_SECURITY_OBSERVABILITY_2026-04-20
SCOPE: W8.B.3 P-MEGA-21 K-6.3 + K-6.4 throttle merge
HEAD_AT_START: d8202bc94
OUTCOME: PASSED

## Changes

- `app/Providers/RouteServiceProvider.php`
  - `kiosk-orders` key changed from `ip` only to `kiosk:{user_id|guest}|{ip}`
  - `login-lockout` identifier fallback changed to `email ?: username ?: anon`
  - Existing config caps and JSON 429 payloads preserved
- `.env.example`
  - documented `KIOSK_ORDER_RATE_LIMIT=5` next to throttle settings
- `tests/Unit/Security/RateLimiterConfigTest.php`
  - aligned login-lockout SSOT to `config('auth.login_lockout.max_attempts')`
- `tests/Feature/Auth/KioskThrottleKeysTest.php`
  - added 5 feature cases for kiosk keying, recovery window, same-IP machine isolation, email lockout, anon fallback

## Verification

- Baseline: `php artisan test tests/Feature/Security/RateLimitTest.php tests/Unit/Security/`
- Targeted: `php artisan test tests/Feature/Auth/KioskThrottleKeysTest.php tests/Feature/Security/RateLimitTest.php tests/Unit/Security/RateLimiterConfigTest.php`
- Expanded: `php artisan test tests/Feature/Security/ tests/Unit/Security/ tests/Feature/Auth/`
- Result: all green in local scope

## Invariants checked

- Backend pricing SSOT: untouched
- OrderStatus enum usage: untouched
- `branch_id` isolation: untouched
- dispatch-after-commit: untouched
- OFF-LIMITS W8.A files not modified

## Notes

- D4 `TrustProxies::$proxies` remains out of application scope and should be reviewed separately by ops.
