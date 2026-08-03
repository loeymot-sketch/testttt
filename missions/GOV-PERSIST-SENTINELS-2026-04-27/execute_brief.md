# GOV-PERSIST-SENTINELS-2026-04-27

Mode: EXECUTE after human-approved Train A bootstrap
Purpose: persist security/contract sentinels created during Phase A override, without touching product code.

## Objective

Track the V1 POS/Kiosk security and contract test evidence so CI can reproduce the current state. The expected post-mission state is full PHPUnit with exactly one known failure: `QueueNumberUniquenessSentinelTest` / D-M13.

## Allowlist

See `allowlist.txt`.

Priority files observed by the Train A plan:

- `tests/Feature/Concerns/HasPosQuoteBinding.php`
- `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php`
- `tests/Feature/KioskQuoteIntegrityTest.php`
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php`
- `tests/Feature/PaymentConfirmAbilityTest.php`
- `tests/Feature/PaymentConfirmCrossBranchTest.php`
- `tests/Feature/PaymentConfirmMachineResolverTest.php`
- `tests/Feature/QuoteCurrencyOriginTest.php`
- `tests/Feature/QuoteDiscountAuthoritativeTest.php`
- `tests/Feature/QuoteExpirationTest.php`
- `tests/Feature/QuoteReplayIdempotencyTest.php`
- `tests/Feature/QuoteTamperTest.php`
- `tests/Feature/Payment/PaymentMethodAttemptAuditTest.php`
- `tests/Feature/Payment/PaymentMethodRestrictedTest.php`
- `tests/Feature/Payment/StripeActivationGuardTest.php`
- `tests/Feature/Payment/WebPaymentDisabledTest.php`
- `tests/Feature/Sentinels/*.php`

## Hard Prohibitions

- No `app/**` edits.
- No `database/**` edits.
- No `resources/**` edits.
- No test weakening, skipping, or deleting.
- No `git add -A`; stage explicit paths only.
- Do not make D-M13 green in this mission.

## Validation

```bash
php artisan test
git diff --cached --name-only
```

Expected result:

- `php artisan test` has exactly one failure: `Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest`.
- staged file list is only within the allowlist.

## Output Contract

- Write `missions/GOV-PERSIST-SENTINELS-2026-04-27/report.md`.
- Write self-audit under `reports/audit/`.
- Verdict values: `A1_STATUS: CLOSED|REWORK`.
