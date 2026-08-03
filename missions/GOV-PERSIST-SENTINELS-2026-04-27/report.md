# GOV-PERSIST-SENTINELS-2026-04-27 — Report

Date: 2026-04-26
Mode: EXECUTE
Delegation: codex-extension
Product code edits: none

## Summary

A.1 persisted the V1 POS/Kiosk security and contract test evidence by staging only allowlisted test/helper files.

`A1_STATUS: CLOSED`

## Scope

Staged files: 35
Scope validation: `ALLOWLIST_CACHED_OK`
Diff check: `CACHED_DIFF_CHECK_OK`

Staged categories:

- `tests/Feature/Concerns/HasPosQuoteBinding.php`
- `tests/Feature/KioskQuote*Test.php`
- `tests/Feature/PaymentConfirm*Test.php`
- `tests/Feature/Payment/*.php`
- `tests/Feature/Quote*Test.php`
- `tests/Feature/Pos/QuoteBindingTest.php`
- `tests/Feature/Sentinels/*.php`

No files under `app/**`, `database/**`, or `resources/**` were staged by this mission.

## Validation

Command:

```bash
php artisan test
```

Result:

```text
Tests: 1 failed, 8 skipped, 1080 passed
Failed: Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest
```

This is the expected D-M13 gate failure and the only failure.

Full log:

`reports/validation/train-a-a1-2026-04-26/phpunit-full.log`

## Notes

The full suite surfaced repeated French integrity warnings in the menu seeder for visible English words:

- `Nos Sandwichs`
- `Nos Burgers`
- `Nos Salades`
- `Chicken & Tenders`

These warnings do not fail A.1. They should feed the later French-first UI/i18n audit already requested by the human gate decisions.

## Invariants Checked

- Pricing SSOT: tests staged only, no pricing code changed.
- Branch isolation: sentinels staged; no branch code changed.
- Order status enum: tests staged only, no status code changed.
- Dispatch after commit: tests staged only, no dispatch code changed.
- D-M13: intentionally not fixed in A.1.

## Verdict

`A1_STATUS: CLOSED`
