# GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27

Mode: EXECUTE after A.1 CLOSED
Purpose: persist and secure the OrderQuote subsystem, including fail-closed HMAC behavior.

## Objective

Track the quote subsystem and remove the known-key HMAC fallback. If `APP_KEY` is empty, quote signing must fail closed instead of using `foodking-order-quote`.

## Important Amendment

Claude's double audit found an allowlist inconsistency: an APP_KEY-empty test was required but no test file was allowed. This mission fixes that planning issue by adding:

`tests/Feature/OrderQuoteHmacKeyRequiredTest.php`

to the allowlist.

## Required Patch

In `app/Services/Order/OrderQuoteService.php`, `hmacKey()` must return `config('app.key')` only when non-empty. Empty APP_KEY must throw:

```php
throw new \LogicException('APP_KEY missing for OrderQuote HMAC');
```

## Allowlist

See `allowlist.txt`.

## Hard Prohibitions

- No frontend edits.
- No order-flow edits outside quote subsystem.
- No D-M13 changes.
- No payment gateway cleanup in this mission.
- No `git add -A`.

## Validation

```bash
php -l app/Services/Order/OrderQuoteService.php
php artisan test --filter='Quote|KioskQuote|OrderQuote|Pos\\QuoteBinding'
php artisan test
```

Expected result:

- quote targeted suite passes;
- full PHP suite has exactly one known D-M13 failure;
- APP_KEY-empty behavior is covered by an automated test.

## Output Contract

- Write `missions/GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27/report.md`.
- Write self-audit under `reports/audit/`.
- Verdict values: `A2_STATUS: CLOSED|REWORK|BLOCKED_ALLOWLIST_AMENDMENT`.
