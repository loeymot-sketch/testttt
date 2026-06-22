# D-M13 Queue Number Rollout

## Preflight

Run before applying the migration in production:

```sql
SELECT branch_id, queue_number, COUNT(*) AS duplicate_count
FROM orders
WHERE queue_number IS NOT NULL
GROUP BY branch_id, queue_number
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC;
```

This old full-history scan is informational only. It explains whether the previous draft would have failed, but it is not the final D-M13 blocker after the 2026-04-27 business-day decision.

The blocking preflight is:

```sql
SELECT
  branch_id,
  DATE(COALESCE(order_datetime, created_at, NOW())) AS business_date,
  queue_number,
  COUNT(*) AS duplicate_count
FROM orders
WHERE queue_number IS NOT NULL
GROUP BY branch_id, DATE(COALESCE(order_datetime, created_at, NOW())), queue_number
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC;
```

Expected blocking result: zero rows.

If duplicates exist, stop. Do not apply the migration until a signed backfill renames or corrects duplicate queue numbers.

## Migration

Migration file:

```text
database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php
```

It adds a unique index:

```text
orders_branch_business_date_queue_unique(branch_id, business_date, queue_number)
```

It also adds `orders.business_date` and backfills it from `order_datetime`, then `created_at`, then the database clock. The migration fails closed with a duplicate report if same-branch same-business-date duplicates are present.

## Application Behavior

- Queue numbers are monotone per branch per business date.
- Queue numbers reset daily because the DB uniqueness includes `business_date`.
- Lock timeout returns an explicit retryable error instead of generating a timestamp fallback.
- Duplicate-key during save retries allocation once before surfacing an error.

## Rollback

Rollback removes the business-day unique index and the `business_date` column:

```bash
php artisan migrate:rollback --path=database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php
```

Rollback does not rewrite queue numbers.

## Post-Deploy Checks

```bash
php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
php artisan test tests/Feature/QueueNumberConcurrencyTest.php
php artisan test --filter='QueueNumber|Kiosk|POS|Order'
```

For release validation, also run:

```bash
php artisan test
npx vitest run
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test
bash scripts/lint-fk-bundle-legacy.sh strict
```
