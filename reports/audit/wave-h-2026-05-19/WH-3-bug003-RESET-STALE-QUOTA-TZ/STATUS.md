# WH-3 bug_003 — ResetStaleDailyQuotaCommand TZ inverts DATE predicate

**Status**: GREEN — heal applied, dual sentinels green, regression clean.
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Author**: WH-3 master sub-agent (autonomous)
**Date**: 2026-05-19

---

## Bug summary

`app/Console/Commands/ResetStaleDailyQuotaCommand.php` (Wave 3c heal,
commit 5e..) applied **TIMESTAMP-style TZ conversion to a DATE column**.
The Wave 3c author confused two bug-classes:

- For **TIMESTAMP columns** stored UTC by MySQL: the canonical heal is
  `Carbon::today(config('app.timezone'))->setTimezone('UTC')` so the
  Eloquent grammar formats the bound literal in UTC (matches MySQL
  session TZ). This is what `KdsSyncService`, `DashboardService`, etc.
  do correctly.
- For **DATE columns** (`$table->date(...)`, plain `Y-m-d`, no TZ
  semantics): the binding must be the Paris-local Y-m-d directly.
  Applying `setTimezone('UTC')->toDateString()` shifts the literal back
  one day across the Paris→UTC midnight boundary.

The migration `2026_04_15_230100_create_item_branch_availability_table.php`
declares `$table->date('daily_reset_at')`. So the predicate
`whereDate('daily_reset_at', '<', $todayForQuery)` was binding a
UTC-shifted Y-m-d while the column stored Paris-local Y-m-d.

### Concrete failure

- Paris-day 2026-05-19 00:05 (cron trigger): UTC clock = 2026-05-18 22:05.
- `Carbon::today('Europe/Paris')` → `'2026-05-19 00:00:00+01:00'`.
- `->setTimezone('UTC')` → `'2026-05-18 23:00:00+00:00'`.
- Eloquent `whereDate` formats with INSTANCE timezone → bound literal
  `'2026-05-18'`.
- Predicate: `WHERE DATE(daily_reset_at) < '2026-05-18'`.
- Row with `daily_reset_at='2026-05-18'` (yesterday Paris — the rows
  the cron exists to refresh) → **SKIPPED**.
- Items hitting `max_daily_qty` stay 86-rupture-flagged ONE FULL
  BUSINESS DAY longer than intended.

### Sentinel that enshrined the bug

`tests/Feature/Services/SisterServicesTzAwareV2Test::test_reset_stale_quota_command_binds_paris_today`
asserted that the binding **contains** `'2026-01-14'` (UTC-shifted) and
**must not contain** `'2026-01-15'` (Paris-local). The sentinel's
docblock even justified this as the correct contract. This is the
"sentinel enshrines the bug" anti-pattern that ultra-review caught.

---

## Heal applied

### 1. `app/Console/Commands/ResetStaleDailyQuotaCommand.php`

Replaced:
```php
$appTz = config('app.timezone');
$todayForQuery = Carbon::today($appTz)->setTimezone('UTC');
$todayForWrite = Carbon::today($appTz)->toDateString();
// ... whereDate('daily_reset_at', '<', $todayForQuery)
// ... 'daily_reset_at' => $todayForWrite
```

With:
```php
$today = Carbon::today(config('app.timezone'))->toDateString();
// ... whereDate('daily_reset_at', '<', $today)
// ... 'daily_reset_at' => $today
```

**Single variable** for predicate AND write. Inversion impossible by
construction. Mirrors the canonical pattern already in use in
`AvailabilityService::decrementForOrder()` (line 291) and
`AvailabilityService::toggle()` (line 64).

Observability preserved (`Log::info`, `$this->info()`, `--dry-run`,
`updated_at => now()`). Stale comment block (lines 36-45 pre-heal)
rewritten to explain why the DATE-vs-TIMESTAMP distinction matters —
prevents the same trap for the next reader.

### 2. `tests/Feature/Services/SisterServicesTzAwareV2Test.php`

Sentinel inverted:
- Now asserts binding **contains** `'2026-01-15'` (Paris-local, correct).
- Now asserts binding **does NOT contain** `'2026-01-14'` (UTC-shifted, bug regression).
- Docblock rewritten to explain the DATE column distinction and reference
  bug_003.

### 3. `tests/Feature/Sentinels/ResetStaleDailyQuotaTzCorrectSentinelTest.php` (NEW)

Behavioral sentinel — pins the real-world outcome, not just the SQL binding.

Pin: `2026-01-15 23:30:00 UTC` → Paris = 2026-01-16 00:30 (winter,
UTC+1, no DST ambiguity).

Seeds:
- Row with `daily_reset_at='2026-01-15'` (yesterday Paris), max_daily_qty=50,
  daily_consumed_qty=50 (86-flagged).
- Control row with `daily_reset_at='2026-01-16'` (Paris-today),
  daily_consumed_qty=12.

Runs `php artisan foodking:availability:reset-stale-quota` (real path,
not --dry-run).

Asserts:
- Stale row reset: `daily_reset_at='2026-01-16'`, `daily_consumed_qty=0`.
- Same-day row untouched: idempotent guard.

---

## TDD evidence

### Step 1 — Inverted sentinel RED with buggy code
```
FAIL Tests\Feature\Services\SisterServicesTzAwareV2Test
⨯ reset stale quota command binds paris today
ResetStaleDailyQuotaCommand MUST bind Paris-local Y-m-d for DATE column (= '2026-01-15').
Captured: 2026-01-14
```

### Step 2 — Same sentinel GREEN after command fix
```
PASS Tests\Feature\Services\SisterServicesTzAwareV2Test
✓ reset stale quota command binds paris today
```

### Step 3 — New behavioral sentinel RED on stashed-buggy code (counter-check)
```
FAIL Tests\Feature\Sentinels\ResetStaleDailyQuotaTzCorrectSentinelTest
bug_003: Yesterday-Paris row MUST be reset to today Paris (= 2026-01-16). Got: 2026-01-15.
```

### Step 4 — New behavioral sentinel GREEN with fix restored
```
PASS Tests\Feature\Sentinels\ResetStaleDailyQuotaTzCorrectSentinelTest
✓ yesterday paris row is reset when cron runs just past paris midnight
```

### Step 5 — Regression filter
```
php artisan test --filter "ResetStale|Availability|TzAware"
Tests:  131 passed, 1 skipped
```

---

## Files touched

1. `app/Console/Commands/ResetStaleDailyQuotaCommand.php` — fix + comment rewrite.
2. `tests/Feature/Services/SisterServicesTzAwareV2Test.php` — sentinel inversion (1 method, 1 docblock).
3. `tests/Feature/Sentinels/ResetStaleDailyQuotaTzCorrectSentinelTest.php` — NEW behavioral sentinel.

No frozen-zone touch. No migration. No config change.

---

## Commit

```
fix(cron-bug003): ResetStaleDailyQuota DATE column needs Paris-local Y-m-d not UTC-shifted
```
