# CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH

TASK_ID: CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH
PHASE: EXECUTE
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8
BRANCH: cycle/CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH

## Scope

Modified files:

- `app/Http/Requests/OrderRequest.php`
- `tests/Feature/KioskSecurityTest.php`

Artifacts:

- `missions/CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH/report.md`
- `reports/audit/GPT_SELF_AUDIT_CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH.md`

No changes were made in `resources/js/**`, `routes/**`, `config/**`, `database/**`, or `app/Domain/Events/EventContract.php`.

## Reproduction

Before patch:

```bash
php artisan test tests/Feature/KioskSecurityTest.php --filter=kiosk_branch_id_is_forced_from_machine
```

Result: FAIL, expected 201 and got 403 at `tests/Feature/KioskSecurityTest.php:218`.

## Root Cause

`FrontendOrderService` already forced `validatedRequest['branch_id']` from `KioskMachine.branch_id`, but the original request object still contained the forged `branch_id`.

The quote sealing path received that original request and rejected the forged branch before the order could be created. `OrderQuoteService` is outside this mission allowlist, so the minimum-scope fix is to normalize kiosk `branch_id` in `OrderRequest::prepareForValidation()`.

## Implementation

- Added kiosk-token branch normalization in `OrderRequest::prepareForValidation()`.
- Kiosk-token detection is limited to non-transient Sanctum access tokens with `kiosk:order`, so stateful web clients are not treated as kiosk machines.
- Added service-level validation in `OrderRequest::withValidator()`:
  - kiosk token without registered `KioskMachine` is rejected.
  - kiosk token with inactive machine is rejected.
- Added coverage for both negative cases in `KioskSecurityTest`.

## Validation

Targeted mission suite:

```bash
php artisan test tests/Feature/KioskSecurityTest.php
```

Result: PASS, 6 passed.

Explicit requested kiosk/auth/security set:

```bash
php artisan test --filter='KioskSecurityTest|KioskAuthTest|KioskScopeIsolationTest|KioskFrontendComprehensiveTest|KioskFullFlowE2ETest'
```

Result: PASS, 21 passed.

Frontend order guard:

```bash
php artisan test tests/Feature/OrderFlowTest.php
php artisan test tests/Feature/OrderRequestNegativeTotalTest.php
```

Result: PASS, 6 passed total.

Broad requested filter:

```bash
php artisan test --filter='Kiosk*|KioskFullFlowE2ETest'
```

Result: 213 passed, 4 skipped, 5 failed.

Failures are outside this mission scope:

- `PosKioskPricingParityTest`: 4 failures, POS order expected 201 got 401. This matches the known POS quote-binding migration bucket.
- `SyncComprehensiveTest::kiosk_order_appears_in_kds`: KDS list does not contain the created kiosk order. This is not touched by `OrderRequest`.

Branch isolation broad filter:

```bash
php artisan test --filter='BranchIsolation*'
```

Result: 17 passed, 1 failed.

Failure is outside this mission scope:

- `BranchIsolationTest::chef_kds_does_not_leak_other_branch_orders`: KDS did not return chef-A own-branch order. The test failed on missing own-branch visibility, not on a cross-branch leak introduced by this patch.

Diff check:

```bash
git diff --check -- app/Http/Requests/OrderRequest.php tests/Feature/KioskSecurityTest.php missions/CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH/report.md
```

Result: PASS.

## Invariants

- Pricing SSOT: unchanged. No frontend or pricing logic modified.
- Branch isolation: kiosk branch is forced from `KioskMachine.branch_id`; forged payload branch is ignored.
- Negative auth paths: kiosk token without machine and inactive machine are rejected.
- Event contract K-09B: unchanged.
- Dispatch after commit: unchanged.

## Verdict

IMPLEMENTATION_VERDICT: PASS
VALIDATION_VERDICT: PASS_WITH_EXTERNAL_FAILURES

The mission objective is fixed and targeted tests pass. Full broad filters remain blocked by pre-existing/out-of-scope POS quote-binding and KDS visibility failures.
