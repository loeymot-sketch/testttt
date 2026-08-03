# CV1-FIX-IDEMPOTENCY-RECOVERY-BRANCH-SCOPE

TASK_ID: CV1-FIX-IDEMPOTENCY-RECOVERY-BRANCH-SCOPE
PHASE: EXECUTE
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8
BRANCH: cycle/CV1-FIX-IDEMPOTENCY-RECOVERY-BRANCH-SCOPE

## Scope

Modified files staged for this mission:

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php`

The repository already had unrelated dirty changes in both service files. The mission commit stages only the idempotency recovery hunks and the new sentinel.

## Root Cause

`OrderService::posOrderStore()` had a branch-scoped pre-check, but its duplicate-key recovery catch still queried by `idempotency_key` alone. If the catch handled a `23000` while another branch already had the same key, recovery could return the wrong branch order.

`FrontendOrderService` was already branch-scoped in the working tree, but this mission centralizes the lookup in the same pattern to keep POS/Kiosk symmetry testable.

## Implementation

- Added `OrderService::findExistingOrderForIdempotencyRecovery($key, $branchId)`.
- POS pre-check and `23000` catch now both use that helper.
- Added `FrontendOrderService::findExistingFrontendOrderForIdempotencyRecovery($key, $branchId)`.
- Kiosk pre-check and `23000` catch now both use that helper.
- Added sentinel coverage proving same-key recovery never crosses branches.

## Validation

```bash
php artisan test tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php
```

Result: PASS, 4 passed.

```bash
php artisan test --filter='OrderService|FrontendOrderService|Idempotency|IdempotencyRecoveryBranchScopedTest'
```

Result: PASS, 27 passed.

```bash
php -l app/Services/OrderService.php
php -l app/Services/FrontendOrderService.php
php -l tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php
```

Result: PASS.

## Invariants

- Branch isolation: recovery lookup requires `branch_id`.
- Pricing SSOT: unchanged.
- Cache lock behavior: unchanged.
- Database schema: unchanged.
- Frontend/UI: untouched.

## Verdict

IMPLEMENTATION_VERDICT: PASS
VALIDATION_VERDICT: PASS
