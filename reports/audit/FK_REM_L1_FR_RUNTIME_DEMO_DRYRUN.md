# FK-REM-L1-FR-RUNTIME-DEMO-DRYRUN - Audit Report - 2026-04-27

TASK_ID: `FK-REM-L1-FR-RUNTIME-DEMO-DRYRUN`  
Mode: safe implementation + audit  
Verdict: PASS TARGETED

## Scope Delivered

This pass addresses the user's visible legacy/Bangladesh/demo complaint without deleting historical data.

Implemented:

- Added `foodking:cleanup-demo-data --dry-run --json`.
- Changed the default currency seeder from USD to EUR.
- Removed `bn.json` and `de.json` from the Vue i18n runtime bundle.
- Kept `fr`, `en`, `ar` in runtime because kiosk accessibility/RTL tests and branch locale contracts currently support Arabic.
- Added a sentinel test proving the cleanup command reports legacy rows without mutating the database.

## Files Changed

| File | Change |
| --- | --- |
| `app/Console/Commands/CleanupDemoDataCommand.php` | New dry-run audit command for legacy/demo rows |
| `database/seeders/CurrencyTableSeeder.php` | EUR is now primary seeded currency; DEMO no longer seeds BDT/INR/NGN/ARS |
| `resources/js/i18n.js` | Runtime messages now load only `fr`, `en`, `ar` |
| `tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php` | New sentinel coverage |

## What The Command Checks

Counts reported:

- Faker/legacy branch names such as `Turcotte`, `Pagac`, `Sauer`.
- Users with `+880`.
- Addresses containing `Dhaka` or `Bangladesh`.
- Order addresses containing `Dhaka` or `Bangladesh`.
- Non-EUR currencies.
- Legacy currency codes `BDT`, `INR`, `NGN`, `ARS`.
- Language rows outside `fr`, `en`, `ar`.

The command is intentionally non-destructive:

```text
mutates_database = false
```

## Validation

Passed:

```text
php -l app/Console/Commands/CleanupDemoDataCommand.php
php -l tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php
php artisan test tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php
npx vitest run tests/js/kioskRtl.spec.js tests/js/kioskSettingsStore.spec.js tests/js/userReportedBlockersRuntime.spec.js
npm run production
bash .cursor/hooks/safety-check.sh
git diff --check (changed L1/T0 files)
```

Regression smoke also passed after the staging unlock:

```text
php artisan test tests/Feature/QueueNumberConcurrencyTest.php
php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php
php artisan test tests/Feature/Pos/QuoteBindingTest.php
php artisan test tests/Feature/PosOrderRequestNullableTotalTest.php
```

Notes:

- Direct local `php artisan foodking:cleanup-demo-data --dry-run --json` attempted against the default MySQL connection was blocked by the sandbox/database socket (`SQLSTATE[HY000] [2002] Operation not permitted`). The command logic is validated under the PHPUnit SQLite test database.
- No historical data was deleted.

## Remaining Cleanup

Not done yet:

- Replace old `Dhaka Bangladesh` DEMO order/address seeders.
- Convert the large `UserTableSeeder` DEMO block to French demo actors or disable it behind a stricter demo flag.
- Decide whether `ar` should remain a V1 runtime language or move post-V1. Current tests and kiosk settings expect `ar`, so Codex kept it.
- Run a gated data migration if existing local database rows must be rewritten, not only hidden.

## Risk Review

Pricing: no pricing logic changed.  
Branch isolation: no branch query changed.  
Kiosk lock: preserved.  
Data deletion: none.  
Runtime bundle: `bn/de` removed; `ar` preserved.
