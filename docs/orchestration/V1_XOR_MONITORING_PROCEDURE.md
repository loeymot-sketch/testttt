# FoodKing — XOR monitoring (`item_wizard_profiles`)

**Context:** Migration `2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner` adds `CHECK ((item_id IS NOT NULL) <> (item_category_id IS NOT NULL))`. On **MySQL before 8.0** or **MariaDB before 10.2.1**, `CHECK` constraints are ignored, so invalid rows (both NULL or both NOT NULL) can exist without DB enforcement.

**Script:** `scripts/xor-violation-check.sh` — counts XOR violations via Laravel (reads `.env` through `php artisan`, no DB password in the shell script).

## When to run

| Phase | Frequency |
| --- | --- |
| First 24h after production cutover | Every hour |
| Days 2–8 post-cutover | Once per day |
| After that | Once per week while still monitoring |

Reduce to weekly only after **7 consecutive stable days** (0 violations) **and** DB version confirmed **MySQL 8.0+** (or MariaDB 10.2.1+ with `CHECK` enforced — verify with `SHOW CREATE TABLE item_wizard_profiles` / docs for your minor version).

## Cron template

```cron
# FoodKing XOR violation monitoring (post-cutover 24h)
0 * * * * cd /var/www/foodking && bash scripts/xor-violation-check.sh --quiet --alert-webhook "https://hooks.slack.com/services/XXX" >> /var/log/foodking/xor-monitoring.log 2>&1
```

Adjust `cd` path and webhook URL for your environment (Slack incoming webhook, Discord, PagerDuty, etc.). Ops must create and rotate webhooks outside the repository.

## On violation (count greater than zero)

1. **DB version:** run `SELECT VERSION();` (or connect with `mysql -e`). If the server is below the versions above, plan an upgrade or rely on **application-layer** enforcement (e.g. validate XOR before save) until the DB enforces `CHECK`.
2. **Rows:** violating rows are neither item-owned nor category-owned wizard profiles — investigate calling code paths and recent deploys.
3. **Volume / data loss risk:** if many rows are corrupt, consider restore from backup after incident process.

**Log file:** append-only detail under `reports/monitoring/xor-violations-YYYY-MM-DD.log` (on the host where the script runs; path is relative to repo root by default).

## Decommissioning

After **7 stable days** (no violations), **MySQL 8.0+** (or equivalent) confirmed, and optional spot-checks clean: reduce cron to weekly, then remove cron jobs when ops agrees monitoring is no longer required.
