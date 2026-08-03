# Backend POS + Kiosk Validation Report

Date: 2026-04-26  
Scope: Laravel backend, POS order flow, kiosk order flow, quote binding, idempotency, loyalty, payments, branch isolation.

## Verdict

`BACKEND_POS_KIOSK_VERDICT: PASS_WITH_D_M13_AND_ROUTE_TOOLING_BLOCKERS`

The backend business path is broadly functional. The full PHPUnit run has exactly one failing test, the expected D-M13 queue-number uniqueness sentinel. A separate route tooling probe found a missing Senangpay gateway class that can break route reflection/deploy diagnostics.

## Test Evidence

| Command | Result | Notes |
| --- | ---: | --- |
| `php artisan test` | FAIL | `1080 passed, 8 skipped, 1 failed` |
| `php artisan route:list --path=api` | FAIL | missing Senangpay gateway class |
| `curl /api/health/live` under local serve | PASS | `200 OK`, body `OK` |
| Branch isolation lint | PASS | no `branch_id LIKE` filters |
| Enum status lint | PASS | no hardcoded order status literals |
| POS pricing lint | PASS | signoff-pending warning until 2026-05-10 |

## Functional Areas That Passed In The Full Suite

The full PHPUnit run includes and passes the recent high-risk areas except D-M13:

- POS quote binding and pricing SSOT.
- Kiosk quote integrity and quote-token-required paths.
- Kiosk forced branch behavior.
- Kiosk loyalty double-redeem and ledger atomicity.
- POS and kiosk idempotency recovery branch scoping.
- Outbox contract K-09B.
- Event contract and after-commit dispatch.
- KDS and order status screen services.
- Payment confirmation/idempotency tests.
- Fiscal/NF525 related tests present in the suite.
- Branch/security/rate-limit tests present in the suite.

## Blocking Findings

### P0 — D-M13 queue uniqueness is still intentionally red

The failing sentinel proves there is no database-level unique guard on `(branch_id, queue_number)`.

Required action:
- human-signed D-M13 decision;
- backfill/deduplication strategy;
- migration with DB-specific behavior verified;
- remove timestamp fallback paths as part of the same queue-number hardening.

### P1 — Route reflection fails on missing Senangpay gateway

Files:
- `app/Providers/RouteServiceProvider.php:116-121`
- `app/Http/PaymentGateways/Routes/senangpay.php:4`

The route file imports `App\Http\PaymentGateways\Gateways\Senangpay`, but no such gateway implementation exists. This does not break the tested POS/Kiosk order path, but it can break deployment tooling, route caching, and diagnostics.

Required action:
- either implement the missing gateway class if Senangpay is supported;
- or remove/disable the Senangpay route under an explicit payment gateway decision.

### P1 — Quote HMAC must fail closed

File:
- `app/Services/Order/OrderQuoteService.php:473`

Current behavior falls back to a known string if `APP_KEY` is empty. This should be changed to a hard failure with a sentinel.

### P1 — Queue microtime fallback must be removed with D-M13

Files:
- `app/Services/FrontendOrderService.php:421`
- `app/Services/OrderService.php:498`
- `app/Services/OrderService.php:873`
- `app/Services/OrderService.php:1295`

These fallbacks are incompatible with a strong uniqueness story. They should not be patched independently from D-M13 because migration, locking, retry, and collision behavior need one coherent plan.

### P2 — POS show branch-scope bypass needs continuous sentinels

File:
- `app/Http/Controllers/Admin/PosOrderController.php:55`

The controller loads with `withoutGlobalScope(BranchScope::class)` then delegates to the service. This may be an intentional service-level authorization design, but it remains a high-value branch isolation sentinel target.

## Backend Release Gate

Backend cannot be called release-ready until:

1. D-M13 is closed and full PHPUnit is green.
2. Route reflection no longer fails on the missing Senangpay gateway.
3. APP_KEY-empty quote HMAC behavior fails closed.
4. Queue microtime fallback is removed or replaced by deterministic DB-safe retry behavior.

