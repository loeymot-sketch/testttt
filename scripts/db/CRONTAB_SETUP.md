# Automated Daily DB Backup — Owner Setup

**Owner decision Q14 (2026-05-21)** : daily DB backup automated via Laravel scheduler with NF525 6-year retention.

## TL;DR — one line to add

```cron
* * * * * cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && php artisan schedule:run >> /dev/null 2>&1
```

That single crontab line invokes Laravel's scheduler **every minute**. The scheduler itself decides which commands to run when. The daily backup is configured to run at **03:00 Europe/Paris** (see `app/Console/Kernel.php`, search `foodking-backup-daily`).

## Step-by-step setup

```bash
# 1. Open the crontab editor
crontab -e

# 2. Paste this line (replace /Users/1millnonstop/... with your absolute project path if different)
* * * * * cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && php artisan schedule:run >> /dev/null 2>&1

# 3. Save + exit. Verify:
crontab -l | grep schedule:run
```

That's it. The scheduler will fire `foodking:backup-daily` at 03:00 next.

## What gets backed up + where it lands

```
storage/backups/
├── db-daily/
│   ├── daily-2026-05-21.sql.gz       ← last 30 days
│   ├── daily-2026-05-20.sql.gz
│   └── ...
├── db-monthly/
│   ├── monthly-2026-05.sql.gz        ← last 12 months
│   └── ...
└── db-quarterly/
    ├── quarterly-2026-Q2.sql.gz      ← last 24 quarters = 6 years (NF525)
    └── ...
```

| Tier | When | Retention | NF525 |
|---|---|---|---|
| Daily | 03:00 Europe/Paris every day | 30 days | — |
| Monthly | 03:00 on the 1st of each month (copy of that day's daily) | 12 months | — |
| Quarterly | 03:00 on 1 Jan / 1 Apr / 1 Jul / 1 Oct | 24 quarters (6 years) | ✓ Article L102 B CGI |

The daily file is also a complete logical mysqldump (`--single-transaction --routines --triggers --events`), so `audit_logs` and `z_reports` (the NF525 chain-signed tables) are captured inside every dump. **The quarterly tier is the legal compliance lane**; daily + monthly are operational convenience.

This is independent of `fiscal:archive` (Kernel.php:216), which produces per-day signed ZIP+JSON archives of just the fiscal tables. Both lanes coexist.

## How to verify the schedule is registered

```bash
php artisan schedule:list | grep backup-daily
# Expected:
#   0    3 * * *  php artisan foodking:backup-daily ...   Next Due: ...
```

## How to verify the cron is actually running

After 24 hours have passed since you added the cron line :

```bash
# 1. Listing storage/backups/db-daily should show today's file
ls -la storage/backups/db-daily/
# Expected: daily-YYYY-MM-DD.sql.gz with today's date, sized > 1 KB

# 2. Tail the structured log (observability channel writes to log/observability.log)
tail -n 50 storage/logs/observability.log | grep backup.daily
# Expected: `"event":"backup.daily.ok"` entries

# 3. Failure marker should NOT exist (it's deleted on success)
ls storage/backups/.last-failure 2>/dev/null && echo "WARNING: backup failed — read the file" || echo "OK"
```

If the failure marker exists, `cat storage/backups/.last-failure` will print a JSON object with `reason` and `exit_code` — diagnose from there.

## Manual run (any time)

```bash
# Run now, regardless of schedule
php artisan foodking:backup-daily

# Dry-run (prints the plan, doesn't execute mysqldump)
php artisan foodking:backup-daily --dry-run

# Tune the sanity floors (defaults: hard=1024 bytes, warn=1MB)
php artisan foodking:backup-daily --min-bytes=10240 --warn-bytes=5242880

# Skip rotation (dump only, no monthly/quarterly + no cleanup)
php artisan foodking:backup-daily --no-rotate
```

## How to restore a backup

```bash
# 1. Pick the file you want
ls -la storage/backups/db-daily/

# 2. Restore into a fresh schema (NEVER restore over production without thinking)
mysql -u <user> -p -e "CREATE DATABASE foodking_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c storage/backups/db-daily/daily-YYYY-MM-DD.sql.gz | mysql -u <user> -p foodking_restore

# 3. Inspect / verify before pointing the app at it
mysql -u <user> -p foodking_restore -e "SELECT COUNT(*) FROM audit_logs, z_reports;"
```

The dumps are produced with `--set-gtid-purged=OFF`, so they restore cleanly on any MySQL server (no GTID collision). See the restore drill (`RESTORE_DRILL_2026-05-21.md`) for a verified PASS proof.

## Common issues

| Symptom | Cause | Fix |
|---|---|---|
| Cron line present, no daily file appears next day | Cron user does not have `php` on PATH | Use absolute path : `/usr/local/bin/php artisan schedule:run` |
| `mysqldump: command not found` in the log | Binary not on cron's PATH | Set `PATH=/usr/local/bin:/usr/bin:/bin` at the top of the crontab file |
| Backup file is `0` bytes | DB unreachable / privilege missing | `cat storage/backups/.last-failure` ; check DB connectivity |
| `dump too small` error | Truncation / DB write failure mid-dump | Inspect `storage/logs/observability.log` for the previous mysqldump warnings |
| `.partial` files accumulate in db-daily | Previous run crashed | Rotation auto-cleans `.partial` older than 24h ; manual : `rm storage/backups/db-daily/*.partial` |

## V1.0.X follow-ups (NOT in scope today)

- **Encryption at rest** : when `BACKUP_ENCRYPTION_KEY` is set, gpg-encrypt the gzip. TODO in `RunDailyBackup.php`.
- **Off-site replication** : per owner mandate, V1 stays local. Cloud (S3 / Glacier) is post-cloud-go-live.
- **Restore drill cron** : monthly automated drill (restore latest backup → schema-only verify → drop) — currently manual via the procedure above.

## Safety constraints honored

- No frozen-zone modifications (fiscal services, audit chain triggers untouched).
- Credentials never leak : password passes via `MYSQL_PWD` env, never argv ; config read via Laravel `config()`, not `cat .env`.
- Atomic write : `.partial` → rename, so a mid-dump crash never leaves a fake-valid file.
- `withoutOverlapping(60)` prevents a hung 03:00 backup from spawning a duplicate at the next minute tick.
- `onOneServer()` correct for future multi-node deployment.

— **Q14 — owner decision 2026-05-21**
