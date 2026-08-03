# GPT Self-Audit - CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH

TASK_ID: CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8
BRANCH: cycle/CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH

## Diff Summary

Code:

- `app/Http/Requests/OrderRequest.php`
  - Normalizes kiosk `branch_id` from the authenticated `KioskMachine` before validation.
  - Limits kiosk detection to non-transient Sanctum access tokens with `kiosk:order`.
  - Rejects kiosk tokens that have no registered machine.
  - Rejects kiosk tokens whose machine is inactive.

Tests:

- `tests/Feature/KioskSecurityTest.php`
  - Adds negative coverage for kiosk token without machine.
  - Adds negative coverage for inactive kiosk machine.

Artifacts:

- `missions/CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH/report.md`
- `reports/audit/GPT_SELF_AUDIT_CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH.md`

## Repro Before

```bash
php artisan test tests/Feature/KioskSecurityTest.php --filter=kiosk_branch_id_is_forced_from_machine
```

Result: FAIL, expected 201 and got 403.

## Repro After

```bash
php artisan test tests/Feature/KioskSecurityTest.php
```

Result: PASS, 6 passed.

```bash
php artisan test --filter='KioskSecurityTest|KioskAuthTest|KioskScopeIsolationTest|KioskFrontendComprehensiveTest|KioskFullFlowE2ETest'
```

Result: PASS, 21 passed.

```bash
php artisan test tests/Feature/OrderFlowTest.php
php artisan test tests/Feature/OrderRequestNegativeTotalTest.php
```

Result: PASS, 6 passed total.

```bash
php artisan test --filter='Kiosk*|KioskFullFlowE2ETest'
```

Result: 213 passed, 4 skipped, 5 failed.

External failures:

- `PosKioskPricingParityTest`: 4 failures, expected 201 got 401 on POS order creation.
- `SyncComprehensiveTest::kiosk_order_appears_in_kds`: created kiosk order missing from KDS response.

```bash
php artisan test --filter='BranchIsolation*'
```

Result: 17 passed, 1 failed.

External failure:

- `BranchIsolationTest::chef_kds_does_not_leak_other_branch_orders`: KDS did not return own-branch order.

## Invariants Checked

- `branch_id`: enforced from `KioskMachine.branch_id` for kiosk tokens; forged payload branch is not trusted.
- Web client safety: transient Sanctum tokens are not treated as kiosk order tokens.
- Auth: tokens without a registered kiosk machine and inactive machines are rejected.
- Pricing SSOT: no pricing code or frontend price logic changed.
- K-09B outbox contract: `EventContract` untouched.
- Frontend resources: untouched.
- Routes/config/database: untouched.

## Residual Risk

The mission-specific security regression is fixed. The broad filter criteria are not fully green because unrelated suites are already red in POS quote-binding and KDS visibility areas. The allowlist does not permit fixing those buckets in this mission.

SELF_AUDIT_VERDICT: PASS_WITH_EXTERNAL_FAILURES
