# OPS — Backup & Rollback

> Audience: ops / owner.
> Scope: pre-prod / prod environments running FoodKing V1.
> Addresses: **OPS-1 (P0)** and **OPS-2 (P0)** of `plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md`.

This runbook is the single source of truth for:
- daily and on-demand DB backups,
- restoring from a backup,
- a fiscal-clean rollback procedure (NF525-aware),
- the disaster-recovery (DR) drill we run every quarter.

---

## 1. Files referenced

| Purpose | Path |
|---|---|
| Backup script | `scripts/ops/backup-db.sh` |
| Restore script | `scripts/ops/restore-db.sh` |
| systemd timer (alt: cron) | `deploy/systemd/foodking-scheduler.timer` |
| Cron alternative | `deploy/cron/foodking-cron.conf` |

All scripts are owner-tunable via a header block (`APP_HOME`, `ENV_FILE`,
`BACKUP_DIR`, `RETENTION_DAYS`).

---

## 2. Setup (one-time)

### 2.1 Backup directory

```bash
sudo mkdir -p /var/backups/foodking
sudo chown foodking:foodking /var/backups/foodking
sudo chmod 0750 /var/backups/foodking
```

The script writes each dump as `0600` so PII (delivery webhook bodies, customer
addresses) is not world-readable.

### 2.2 mysqldump prerequisites

The script uses `--single-transaction --routines --triggers --events`. The DB
user declared in `.env` (`DB_USERNAME`) needs:

- `SELECT` on the target DB,
- `LOCK TABLES` (only used as fallback by mysqldump on non-InnoDB tables),
- `EVENT`, `TRIGGER` for the `--events --triggers` flags,
- `PROCESS`, `REPLICATION CLIENT` (recommended for `--single-transaction`).

A least-privilege grant looks like:

```sql
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, PROCESS, REPLICATION CLIENT
  ON *.* TO 'foodking_backup'@'127.0.0.1';
```

If you prefer a dedicated backup user, override `DB_USERNAME` /
`DB_PASSWORD` for the script via a wrapper that exports them before invoking it.

### 2.3 Schedule

Pick **ONE** of the two scheduling backbones — never both (see
`deploy/cron/foodking-cron.conf` header for why):

#### Option A — systemd timer (preferred)

```bash
sudo cp deploy/systemd/foodking-scheduler.timer  /etc/systemd/system/
sudo cp deploy/systemd/foodking-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now foodking-scheduler.timer
```

The Laravel scheduler does **not** schedule `backup-db.sh` directly (Laravel
schedules PHP commands). Add a separate timer/cron for the backup itself:

```bash
# /etc/systemd/system/foodking-backup.timer
[Unit]
Description=FoodKing daily DB backup at 02:00

[Timer]
OnCalendar=*-*-* 02:00:00
Persistent=true
Unit=foodking-backup.service

[Install]
WantedBy=timers.target
```

```bash
# /etc/systemd/system/foodking-backup.service
[Unit]
Description=FoodKing daily DB backup runner

[Service]
Type=oneshot
User=foodking
ExecStart=/home/foodking/foodking/scripts/ops/backup-db.sh
```

#### Option B — cron

```bash
sudo crontab -u foodking deploy/cron/foodking-cron.conf
```

(`foodking-cron.conf` already has the `0 2 * * *` line for the backup.)

---

## 3. Daily backup (automatic)

Runs every day at 02:00 local. Output:

- File: `/var/backups/foodking/foodking_db_<TS>.sql.gz` (mode `0600`).
- Log line: `tag=foodking-backup` in syslog.
- Old files >30 days are pruned.

**Verify it ran:**

```bash
ls -lh /var/backups/foodking/ | tail -5
journalctl -t foodking-backup --since '24h ago'
```

---

## 4. Manual backup (on demand)

Before any risky deploy or migration:

```bash
sudo -u foodking /home/foodking/foodking/scripts/ops/backup-db.sh
```

Override the destination directory:

```bash
sudo -u foodking BACKUP_DIR=/data/backups/manual \
    /home/foodking/foodking/scripts/ops/backup-db.sh
```

The script verifies gzip integrity before exiting — if `gunzip -t` fails, exit
code is `3` and the corrupted file is **not** deleted (so you can investigate).

---

## 5. Restore procedure

> WARNING — this drops the database. Read the whole section before running.

### 5.1 Pre-flight

1. **Stop application traffic.** Either remove from load balancer or:
   ```bash
   sudo systemctl stop nginx
   ```
2. **Stop the queue worker** to prevent jobs draining old data after restore:
   ```bash
   sudo systemctl stop foodking-queue-worker
   ```
3. **Pause Soketi** (clients will reconnect after restore):
   ```bash
   sudo systemctl stop foodking-soketi
   ```
4. **Snapshot the current state** so you can roll forward again if needed:
   ```bash
   sudo -u foodking BACKUP_DIR=/var/backups/foodking/pre-restore \
       /home/foodking/foodking/scripts/ops/backup-db.sh
   ```

### 5.2 Restore

```bash
sudo /home/foodking/foodking/scripts/ops/restore-db.sh \
    /var/backups/foodking/foodking_db_20260507_020001.sql.gz
```

The script:

1. Verifies gzip integrity before any destructive call (`exit 1` if corrupt).
2. Prints a confirmation banner; you must type literal `CONFIRM` to proceed
   (or set `RESTORE_NONINTERACTIVE=1` for unattended DR runs).
3. Streams `zcat | mysql` over TCP (`--protocol=tcp` avoids the
   `localhost`-as-socket gotcha).
4. Runs `php artisan migrate:status` as the application user to verify schema.

Exit codes: `0` ok, `1` pre-flight, `2` user aborted, `3` mysql failed,
`4` migrate:status non-zero.

### 5.3 Post-restore

```bash
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan cache:clear && \
    php artisan config:clear && \
    php artisan route:clear && \
    php artisan queue:restart'

sudo systemctl start foodking-soketi
sudo systemctl start foodking-queue-worker
sudo systemctl start nginx

# Smoke test
curl -fsS http://127.0.0.1/api/health/ready | jq .
```

If `/api/health/ready` returns 503, see `docs/OPS_OUTBOX_MONITORING.md` §
"What to do when health is degraded".

---

## 6. Rollback procedure (post-deploy regression)

Use this when a freshly deployed release has a critical bug and you must
revert. **NF525 fiscal sequence integrity must be preserved**: a rollback that
loses fiscal sequence numbers or duplicates them is non-compliant.

### 6.1 Decision tree

```
Was a Z-ticket emitted on the buggy build?
    ├── No  → Safe rollback path (§6.2)
    └── Yes → Fiscal-clean rollback path (§6.3) — owner approval required
```

### 6.2 Safe rollback (no Z emitted)

```bash
# 1. Snapshot current state
sudo -u foodking BACKUP_DIR=/var/backups/foodking/pre-rollback \
    /home/foodking/foodking/scripts/ops/backup-db.sh

# 2. Stop services
sudo systemctl stop nginx foodking-queue-worker foodking-soketi

# 3. Git revert to previous tag (do not force-push)
cd /home/foodking/foodking
sudo -u foodking git fetch --tags
sudo -u foodking git checkout v1.0.X-previous

# 4. Composer + cache + migrations rollback
sudo -u foodking composer install --no-dev --optimize-autoloader
sudo -u foodking php artisan migrate:rollback --step=N   # N = number of new migrations in the bad build
sudo -u foodking php artisan config:cache
sudo -u foodking php artisan route:cache

# 5. Restart
sudo systemctl start foodking-soketi foodking-queue-worker nginx
```

### 6.3 Fiscal-clean rollback (Z emitted)

If a Z-ticket was emitted on the buggy build, the fiscal_sequence_no
counter is at a value the law says we cannot regress past. Two options:

- **Option Z1 (preferred):** roll forward — patch the bug on top of the buggy
  build, do not restore. The buggy Z stays in history; we issue a corrective Z
  if the totals were wrong.
- **Option Z2 (last resort):** restore the pre-buggy backup, then **manually
  re-emit** the Z range with corrected data, advancing
  `fiscal_sequence_no` past the buggy max. This requires:
  1. owner sign-off in writing,
  2. legal advisor consult (NF525 article 88),
  3. an audit trail entry in `audit_logs` with `action='fiscal_rollback'`.

In both cases, document the incident in `reports/incidents/`.

### 6.4 Verify

```bash
# Fiscal sequence is monotonic
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:fiscal:verify-sequence'

# Outbox not stale
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan tinker --execute="echo App\\Models\\DomainEvent::whereNull(\"dispatched_at\")->where(\"created_at\", \"<\", now()->subMinutes(2))->count();"'

# Audit chain HMAC verified
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan audit:verify-chain'
```

---

## 7. Disaster recovery (DR) drill — quarterly

Per audit OPS-1: an untested backup is not a backup. Run this every quarter.

### 7.1 Quarterly checklist

- [ ] Pick a backup ≥ 7 days old from `/var/backups/foodking/`.
- [ ] Spin up an isolated staging host (or VM snapshot).
- [ ] Run `restore-db.sh` against it with `RESTORE_NONINTERACTIVE=1`.
- [ ] Verify row counts on at least: `orders`, `frontend_orders`,
      `domain_events`, `audit_logs`, `delivery_webhook_events`,
      `cash_drawer_sessions`.
- [ ] Run `php artisan migrate:status` — must be all `Yes`.
- [ ] Run `php artisan audit:verify-chain` — must succeed.
- [ ] Smoke-test login + create an order via API.
- [ ] Record the run in `reports/dr-drills/YYYY-MM-DD.md` with timing data
      (RTO target: ≤ 30 min for ≤ 5 GB DB).

### 7.2 What to look for

- **Backup file size not growing**: investigate retention policy or empty dump.
- **Duration creeping up**: consider `--compact` or split per-table dumps.
- **`gunzip -t` failed**: disk corruption — escalate to infra immediately.

---

## 8. Manual test plan (after first deploy)

Run these once after the first installation to validate the end-to-end pipeline:

```bash
# 1. Trigger a manual backup
sudo -u foodking /home/foodking/foodking/scripts/ops/backup-db.sh

# 2. Confirm file exists, integrity ok, mode 0600
ls -la /var/backups/foodking/
sudo -u foodking gunzip -t /var/backups/foodking/foodking_db_*.sql.gz
stat -c '%a' /var/backups/foodking/foodking_db_*.sql.gz   # → 600

# 3. Confirm logger entry
journalctl -t foodking-backup --since '5 min ago'

# 4. Test restore on a STAGING DB (never prod):
RESTORE_NONINTERACTIVE=1 sudo /home/foodking/foodking/scripts/ops/restore-db.sh \
    /var/backups/foodking/foodking_db_*.sql.gz

# 5. Schedule sanity (systemd timer path)
systemctl list-timers --all | grep foodking
```

If any of step 1–5 fails, **do not declare go-live ready**.
