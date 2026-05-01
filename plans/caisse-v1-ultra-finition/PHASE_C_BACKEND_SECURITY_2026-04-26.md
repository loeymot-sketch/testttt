# PHASE C — Backend Security And Invariant Fixes

Status: BLOCKED_PHASE_A_UNSIGNED
Owner: Codex after Phase A and required Phase B tasks.

## Goal

Close backend bugs that affect V1 defensibility: branch IDOR, quote expiry semantics, payment idempotence, stale reorder pricing, legacy pricing fallback, tax casts, and error taxonomy.

## Tasks

### C.1 `CV1-FIX-POS-SHOW-BRANCH-IDOR`

Objective: remove or neutralize `withoutGlobalScope(BranchScope::class)` in POS show path.

Allowlist:
- `app/Http/Controllers/Admin/PosOrderController.php`
- `app/Services/OrderService.php` only if service guard requires explicit branch check
- `tests/Feature/Sentinels/PosOrderShowCrossBranchSentinelTest.php`
- related existing branch sentinels

Mandatory tests:
- `php artisan test --filter='PosOrderShowCrossBranchSentinelTest|OrderShowBranchGuardSentinelTest|OrderListBranchExactnessSentinelTest'`

Exit criteria:
- cashier branch A cannot show order branch B.
- admin behavior remains explicitly tested.
- no POS controller show path disables BranchScope without a compensating branch assertion.

### C.2 `CV1-FIX-QUOTE-EXPIRY-EXPLICIT-REJECT`

Objective: expired quote at commit returns explicit 409; no silent re-quote.

Allowlist:
- `app/Services/Order/OrderQuoteService.php`
- `tests/Feature/QuoteExpirationTest.php`
- new `tests/Feature/PosQuoteExpiredRejectedTest.php` if needed

Mandatory tests:
- `php artisan test --filter='QuoteExpirationTest|QuoteReplayIdempotencyTest|QuoteTamperTest|PosQuoteExpiredRejectedTest'`

Exit criteria:
- expired persisted quote cannot be sealed.
- response code maps to 409 / `quote_expired`.
- non-expired quote path unchanged.

### C.3 `CV1-FIX-PAYMENT-IDEMPOTENT-TX-NO`

Objective: prevent duplicate `Transaction` for same order/gateway/transaction number.

Allowlist:
- `app/Services/PaymentService.php`
- existing transaction model/service tests
- migration only if explicit schema gate is signed

Mandatory tests:
- `php artisan test --filter='PaymentIdempotentByTransactionNoTest|PaymentNoopIdempotencyTest|PaymentConfirmIdempotencyTest'`

Exit criteria:
- double gateway retry creates or returns one transaction.
- idempotency scope includes order and transaction number.
- no full ledger Option A behavior introduced.

### C.4 `CV1-FIX-POS-REORDER-REQUOTE`

Objective: reorder cart is re-quoted through backend quote service before reuse.

Allowlist:
- `app/Http/Controllers/Admin/PosOrderController.php`
- `app/Services/Order/OrderQuoteService.php` only if response helper needed
- POS reorder tests

Mandatory tests:
- `php artisan test --filter='PosReorderItemsRequotedTest|Quote|PosPricingSsotProofTest'`

Exit criteria:
- reorder returns current priced cart plus quote token/signature or forces quote refresh.
- stale item/tax/offer price is not reused as authoritative.

### C.5 `CV1-CLEANUP-LEGACY-PRICING-PATH`

Objective: remove dangerous `pricing.use_ssot_service=false` fallback path.

Allowlist:
- `app/Services/OrderService.php`
- `config/pricing.php` or equivalent config only if flag removed
- pricing/POS tests

Mandatory tests:
- `php artisan test --filter='PricingService|PosKioskPricingParity|PosPricingSsotProof|PosOrderTax|Quote'`
- `rg -n "use_ssot_service|legacy pricing|pricing.use_ssot_service" app config tests`

Exit criteria:
- no runtime branch can bypass `PricingService` for POS totals.
- tests prove forged totals ignored.

### C.6 `CV1-FIX-TAX-RATE-CAST`

Objective: cast `Tax::tax_rate` as numeric/decimal in model layer.

Allowlist:
- `app/Models/Tax.php`
- tax/pricing tests

Mandatory tests:
- `php artisan test --filter='TaxRateNumericCastTest|PosOrderTaxTest|PricingServiceTest'`

Exit criteria:
- cast is numeric-safe.
- no DB migration unless separate gate exists.

### C.7 `CV1-FIX-ORDERSERVICE-ERROR-TAXONOMY`

Objective: replace broad `catch (Exception)` in order lifecycle with typed exceptions and production-grade logs.

Allowlist:
- `app/Services/OrderService.php`
- `config/logging.php` if channel needed
- `tests/Feature/OrderServiceErrorTaxonomyTest.php`

Mandatory tests:
- `php artisan test --filter='OrderServiceErrorTaxonomyTest|PosOrder|PaymentConfirm|KdsTransition'`
- `php -l app/Services/OrderService.php`

Exit criteria:
- validation/domain exceptions stay controlled.
- system exceptions are error-level.
- no swallowed branch/pricing/dispatch errors.
