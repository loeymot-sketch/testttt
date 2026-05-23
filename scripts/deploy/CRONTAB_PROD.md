# CRONTAB_PROD.md — FoodKing Le Cayenne Production Cron + Backup Setup

> Deploy-script reference for the Hetzner V1 LOCAL production host
> (Phase D D.3). Tag: 2026-05-23. Branch: `heal/cms-pr1-quickwins-2026-05-18`.
>
> **Scope.** Hardware: single Hetzner box (Le Cayenne V1 LOCAL). All
> schedule lanes target one host; `onOneServer()` is already declared in
> `app/Console/Kernel.php` so cross-host serialization remains correct if
> we ever scale horizontally (V2 SaaS).
>
> **Mandate.** Every cron action listed below is either (a) Laravel
> scheduler driven (single line in system crontab + everything else in
> `Kernel.php`) or (b) an OS-level lane (logrotate, certbot, health
> probe) that must be installed manually on the host. **Nothing in this
> document modifies app code** — it is a runbook for `/etc/cron.d/*`
> and `/etc/logrotate.d/*`.

---

## Section 1 — Laravel scheduler (mandatory, one line)

The only application cron entry. All actual schedule logic lives in
`app/Console/Kernel.php::schedule()`. This single line tells the OS to
poke Laravel every minute so it can decide what (if anything) to run.

### System crontab entry

Install as `/etc/cron.d/lecayenne-scheduler` (preferred — survives user
deletion) or in `crontab -e` of the `lecayenne` deploy user.

```cron
# /etc/cron.d/lecayenne-scheduler
# Laravel scheduler — every minute, runs Kernel.php::schedule() lanes.
# DO NOT add individual lanes here. Add them to Kernel.php.
* * * * * lecayenne cd /var/www/lecayenne && /usr/bin/php artisan schedule:run >> /var/log/lecayenne/schedule.log 2>&1
```

**Preconditions** (one-time bootstrap on the Hetzner host):

```bash
sudo install -d -m 0750 -o lecayenne -g lecayenne /var/log/lecayenne
sudo touch /var/log/lecayenne/schedule.log
sudo chown lecayenne:lecayenne /var/log/lecayenne/schedule.log
sudo chmod 0640 /var/log/lecayenne/schedule.log
# Sanity: verify schedule:list resolves and shows ~14 lanes
sudo -u lecayenne -- bash -lc 'cd /var/www/lecayenne && php artisan schedule:list'
```

### Lanes currently registered (cross-reference)

Source of truth: `app/Console/Kernel.php` lines 22–265. Verified
2026-05-23. **Do not duplicate these in /etc/cron.d/** — they are
driven by `schedule:run`.

| # | Lane (name)                              | Cadence              | Kernel.php line | Notes                                                                 |
|---|------------------------------------------|----------------------|-----------------|-----------------------------------------------------------------------|
| 1 | `purge-expired-otps`                     | every 15 min         | 29–34           | DB facade direct DELETE on stale OTPs                                 |
| 2 | `foodking:outbox:rescue`                 | every minute         | 40–43           | Re-queues domain_events attempts<5                                    |
| 3 | `foodking:outbox:monitor --threshold=10` | every minute         | 50–53           | F-015 staleness alerter, non-zero exit pages                          |
| 4 | `outbox-retry-failed`                    | hourly               | 64–69           | attempts>=5 within last 24h                                           |
| 5 | `webhook-retry-failed`                   | hourly               | 76–81           | Stripe/SenangPay DLQ retry, 24h window                                |
| 6 | `CleanupStalePendingKioskOrders` job     | every 5 min          | 83–86           | Cleans abandoned kiosk carts                                          |
| 7 | `pos:purge-parked-orders`                | daily 03:15          | 89–92           | 24h TTL on POS parked snapshots                                       |
| 8 | **`foodking-backup-daily`**              | **daily 03:00**      | **105–110**     | **NF525 DB backup (see §2)**                                          |
| 9 | `outbox-prune --older-than-days=90`      | daily 04:00          | 119–125         | Prevents domain_events unbounded growth                               |
| 10| `webhook-prune --older-than-days=180`    | daily 04:15          | 135–141         | PCI dispute window                                                    |
| 11| `SloEvaluatorJob`                        | every 5 min          | 143–146         | Observability SLO eval                                                |
| 12| `stock-scan-rupture`                     | every 5 min (gated)  | 148–153         | Only if `catalog_v15.auto_86_preventive_cron.enabled=true`            |
| 13| `foodking-fiscal-retry-alloc`            | every minute         | 161–165         | NF525 sequence-alloc retry for orphan kiosk orders                    |
| 14| `availability-reset-stale-quota`         | daily 00:05          | 174–178         | Quota counters reset for low-traffic branches                         |
| 15| **`fiscal-chain-monitor-all-branches`**  | **daily 03:30**      | **192–226**     | **NF525 dual-chain verify per active branch (see §6)**                |
| 16| **`foodking-fiscal-archive-daily`**      | **daily 02:00**      | **234–264**     | **NF525 signed ZIP+JSON per active branch**                           |

Lanes #8/#15/#16 are the **NF525 compliance triple** — they MUST run
nightly and any non-zero exit pages the on-call.

---

## Section 2 — Backup rotation (NF525 6-year retention)

### 2.1 — Daily backup (already wired)

Lane #8 above. The Laravel command `foodking:backup-daily`
(`app/Console/Commands/Backup/RunDailyBackup.php`) handles **all four
retention tiers** in one shot:

- **Daily** — 30 day rolling window. Output: `storage/backups/db-daily/YYYY-MM-DD.sql.gz`
- **Weekly** — 12 week rolling window. Triggered on Sunday inside the
  command. Output: `storage/backups/db-weekly/YYYY-WW.sql.gz`
- **Monthly** — 12 month rolling window. Triggered on the 1st of each
  month inside the command. Output: `storage/backups/db-monthly/YYYY-MM.sql.gz`
- **Quarterly (NF525 6-year archive)** — 24 quarter retention.
  Triggered on the 1st of Jan/Apr/Jul/Oct inside the command.
  Output: `storage/backups/db-quarterly/YYYY-Q[1-4].sql.gz`

> **Owner decision (Q14 2026-05-21).** Backup is Laravel-scheduler
> driven, NOT a separate /etc/cron.d entry. This keeps the single source
> of truth in `Kernel.php` and inherits `withoutOverlapping(60)` +
> `onOneServer()`. Verified in PROJECT_BRAIN §2 + Q14 owner deliverable.

### 2.2 — Daily verification probe (OS-level)

Append this to `/etc/cron.d/lecayenne-scheduler` (after the schedule
line) — it is an OS-level safety net that fires independently of the
Laravel scheduler.

```cron
# Backup OK-tail sanity probe — fires daily at 04:30 (after backup-daily
# at 03:00 + outbox/webhook prune at 04:00/04:15). Pages if the last
# `foodking:backup-daily` log line did not end in "OK".
30 4 * * * lecayenne tail -n 1 /var/www/lecayenne/storage/logs/backup.log | grep -q "OK" || /usr/local/bin/lecayenne-pager "backup-daily MISSING OK"
```

The `/usr/local/bin/lecayenne-pager` script is owner-decision (§7 —
Sentry / Pushover / email — currently a placeholder that writes to
`/var/log/lecayenne/alerts.log`).

### 2.3 — Off-host replication (V1 cloud-prep — DEFERRED)

V1 LOCAL Le Cayenne single-box runs **on-disk only** (no off-host
copy). Acceptable per CLAUDE.md "no cloud until owner initiates" and
the feedback memo `feedback_no_cloud_until_owner_initiates.md`. V1.0.X
cloud-prep backlog item: rsync `storage/backups/` nightly to a second
Hetzner storage box once owner gives the go.

### 2.4 — NF525 retention floor (audit_logs / z_reports — NEVER deleted)

The `audit_logs` and `z_reports` tables are **append-only by DB
trigger** (BEFORE DELETE → SIGNAL SQLSTATE '45000'). Backups capture
them; nothing in this document deletes rows. **NF525 6-year retention
is enforced at the row level, not the file level** — even if a SQL
dump is pruned past 6 years, the table itself keeps every row forever.

---

## Section 3 — Log rotation

Install `/etc/logrotate.d/lecayenne` with the configuration below.
This rotates **application log files only**. Database rotation is
forbidden — see 3.3.

### 3.1 — File contents

```bash
sudo install -m 0644 -o root -g root /dev/stdin /etc/logrotate.d/lecayenne <<'EOF'
# /etc/logrotate.d/lecayenne — FoodKing application log rotation.
# DB tables (audit_logs / z_reports / fiscal_sequences) are append-only
# and rotated AT THE ROW LEVEL — never at the file level. See §3.3.

/var/www/lecayenne/storage/logs/*.log {
    daily
    rotate 90
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    create 0640 lecayenne lecayenne
    dateext
    dateformat -%Y-%m-%d
    su lecayenne lecayenne
    sharedscripts
    postrotate
        # Laravel rolls its own log handle on next write — no SIGHUP needed.
        true
    endscript
}

/var/log/lecayenne/*.log {
    daily
    rotate 90
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    create 0640 lecayenne lecayenne
    dateext
    dateformat -%Y-%m-%d
    su lecayenne lecayenne
}
EOF

# Validate without rotating
sudo logrotate -d /etc/logrotate.d/lecayenne
```

### 3.2 — Files covered

- `storage/logs/laravel.log` — application log
- `storage/logs/backup.log` — backup command output (consumed by §2.2)
- `storage/logs/restore-drills.log` — quarterly drill journal (§6)
- `storage/logs/fiscal.log` — NF525 channel (configured in
  `config/logging.php`, `'fiscal'` channel)
- `/var/log/lecayenne/schedule.log` — Laravel scheduler stdout/stderr
- `/var/log/lecayenne/alerts.log` — pager placeholder output

**Retention.** 90 daily rotations × 1 file = ~90 days kept on host.
NF525 6-year archive **does not depend on these files** — fiscal events
land in `audit_logs` (NEVER deleted) and the daily signed archive ZIP
created by `foodking:fiscal:archive` (lane #16, kept indefinitely on
disk).

### 3.3 — NF525 forbidden zone

**Do NOT add `audit_logs` or `z_reports` to any rotation/purge
configuration.** They are append-only DB tables, not log files. The
`BEFORE DELETE` trigger will SIGNAL SQLSTATE '45000' and refuse any
DELETE/TRUNCATE — but a misconfigured logrotate against them would
still be a flagged-by-auditor finding. **Keep DB and file lanes
strictly separated.**

---

## Section 4 — Health monitoring

### 4.1 — App health probe (every 5 min)

Append to `/etc/cron.d/lecayenne-scheduler`:

```cron
# App health probe — every 5 min. Fires the pager script (owner gate §7)
# if /api/health returns anything other than HTTP 200.
*/5 * * * * lecayenne /usr/local/bin/lecayenne-health-check >> /var/log/lecayenne/health.log 2>&1
```

Companion script `/usr/local/bin/lecayenne-health-check`:

```bash
sudo install -m 0755 -o root -g root /dev/stdin /usr/local/bin/lecayenne-health-check <<'EOF'
#!/usr/bin/env bash
# FoodKing Le Cayenne health probe.
# Exits 0 if /api/health returns 200, otherwise fires pager + exits 1.
set -euo pipefail
URL="https://lecayenne.fr/api/health"
TS="$(date -u +%FT%TZ)"
STATUS="$(curl -fsS -o /tmp/lecayenne-health.body -w '%{http_code}' --max-time 8 "$URL" || echo "000")"
if [[ "$STATUS" != "200" ]]; then
    /usr/local/bin/lecayenne-pager "health-check FAIL http=$STATUS at $TS"
    echo "[$TS] FAIL http=$STATUS" >&2
    exit 1
fi
echo "[$TS] OK"
EOF
```

### 4.2 — Fiscal chain monitor (already wired)

Lane #15 in §1 — `fiscal:verify-chain` runs daily 03:30 per active
branch and logs to the `fiscal` channel. **Do not add this to
/etc/cron.d/** — it is already in `Kernel.php`. Verified
2026-05-23 against `app/Console/Kernel.php:192-226`.

### 4.3 — Disk + RAM threshold (lightweight)

Append to `/etc/cron.d/lecayenne-scheduler`:

```cron
# Disk usage probe — pages if any mounted FS is >85% full.
15 * * * * root df --output=pcent,target -x tmpfs -x devtmpfs | awk 'NR>1 && int($1)>85 {print $0; rc=1} END{exit rc}' || /usr/local/bin/lecayenne-pager "disk pressure"
```

---

## Section 5 — Certbot / TLS renewal

### 5.1 — Initial issue (one-time, manual)

```bash
sudo snap install --classic certbot
sudo ln -sf /snap/bin/certbot /usr/bin/certbot
sudo certbot --nginx -d lecayenne.fr -d www.lecayenne.fr \
    --non-interactive --agree-tos --email ops@lecayenne.fr
```

### 5.2 — Renewal (automatic)

The snap installation **registers a systemd timer automatically** —
no crontab entry needed:

```bash
systemctl list-timers | grep -i certbot
# Expect: snap.certbot.renew.timer  — twice daily
```

### 5.3 — Sanity check (recommended monthly)

```bash
sudo certbot renew --dry-run
```

If the systemd timer is for any reason unavailable on this host, fall
back to a crontab entry:

```cron
# Fallback — only if systemd timer is NOT registered. Run twice daily
# per LE recommendation; --quiet keeps no-op runs silent.
17 3,15 * * * root /usr/bin/certbot renew --quiet --post-hook "systemctl reload nginx"
```

---

## Section 6 — Restore drill (NF525 quarterly compliance)

NF525 requires that backups are not just **created** but **proven
restorable**. We drill once per quarter and journal the outcome.

### 6.1 — Schedule

Append to `/etc/cron.d/lecayenne-scheduler`:

```cron
# NF525 restore drill — runs on the 5th of each quarter (Jan/Apr/Jul/Oct)
# at 05:00. Picks the latest quarterly backup, restores to a scratch DB,
# re-walks the fiscal chain, journals result.
0 5 5 1,4,7,10 * lecayenne /usr/local/bin/lecayenne-restore-drill >> /var/www/lecayenne/storage/logs/restore-drills.log 2>&1
```

### 6.2 — Drill script (skeleton)

`/usr/local/bin/lecayenne-restore-drill`:

```bash
sudo install -m 0755 -o root -g root /dev/stdin /usr/local/bin/lecayenne-restore-drill <<'EOF'
#!/usr/bin/env bash
# NF525 restore drill — restores the latest quarterly backup to a scratch
# DB and re-verifies the audit_logs HMAC chain.
set -euo pipefail
TS="$(date -u +%FT%TZ)"
SCRATCH_DIR="/tmp/lecayenne-restore-test"
SCRATCH_DB="lecayenne_drill_$(date +%Y%m%d)"
BACKUP_DIR="/var/www/lecayenne/storage/backups/db-quarterly"
LATEST="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -n1 || true)"
if [[ -z "$LATEST" ]]; then
    echo "[$TS] DRILL FAIL — no quarterly backup found in $BACKUP_DIR"
    exit 1
fi
mkdir -p "$SCRATCH_DIR"
echo "[$TS] DRILL START — backup=$LATEST scratch_db=$SCRATCH_DB"
mysql -u root -e "CREATE DATABASE \`$SCRATCH_DB\`;"
zcat "$LATEST" | mysql -u root "$SCRATCH_DB"
# Re-walk chain on scratch DB via the same artisan command, --branch=1 by
# default. For full per-branch verification, owner gate.
cd /var/www/lecayenne
DB_DATABASE="$SCRATCH_DB" php artisan fiscal:verify-chain --branch=1
EXIT=$?
mysql -u root -e "DROP DATABASE \`$SCRATCH_DB\`;"
rm -rf "$SCRATCH_DIR"
if [[ $EXIT -eq 0 ]]; then
    echo "[$TS] DRILL OK — backup=$LATEST CHAIN_OK"
    exit 0
else
    echo "[$TS] DRILL FAIL — backup=$LATEST chain verify exit=$EXIT"
    /usr/local/bin/lecayenne-pager "NF525 restore drill FAILED — see restore-drills.log"
    exit 1
fi
EOF
```

### 6.3 — Manual ad-hoc drill (any time)

```bash
sudo -u lecayenne /usr/local/bin/lecayenne-restore-drill
tail -n 5 /var/www/lecayenne/storage/logs/restore-drills.log
```

### 6.4 — Logging

Drill output journals into `storage/logs/restore-drills.log`, which is
covered by §3 rotation. Each entry contains the timestamp, backup
filename, and `CHAIN OK` or `CHAIN FAIL`. Quarterly review of this log
is part of the audit trail.

---

## Section 7 — Owner gate items (NOT installed in V1)

These items have explicit owner-pending decisions and **must remain
deferred** until owner initiates. Per `feedback_no_cloud_until_owner_initiates.md`
+ CLAUDE.md §15.

| Item                       | Decision pending                                          | Default while pending                                          |
|----------------------------|-----------------------------------------------------------|----------------------------------------------------------------|
| TPE Senangpay integration  | V1.0.1 post Phase D (D8). Owner triggers go-live.         | Simulation only via `POS_SIMULATION_HARDWARE=true` (dev only). |
| Monitoring / alerting      | Sentry vs DataDog vs homegrown (Pushover/email).          | `lecayenne-pager` writes to `/var/log/lecayenne/alerts.log`.   |
| DNS provider               | Cloudflare vs OVH vs Hetzner DNS for `lecayenne.fr`.      | Whatever currently resolves — no automated migration.          |
| Off-host backup mirror     | rsync to second storage box vs S3 vs none.                | On-disk only (§2.3). Single point of failure accepted by owner.|
| Cloud / horizontal scale   | V2 SaaS. Owner archived as "vision avant production".     | `onOneServer()` already declared in Kernel.php — V2-ready.     |

Each item above corresponds to a Graphiti feedback entry. **None of
this script touches them.** When owner initiates, a separate plan
will land — do not speculate or pre-wire.

---

## Appendix A — Bootstrap checklist (Hetzner host)

Run in order on a fresh production host:

```bash
# 1. Directories + ownership
sudo install -d -m 0750 -o lecayenne -g lecayenne /var/log/lecayenne
sudo install -d -m 0750 -o lecayenne -g lecayenne /var/www/lecayenne/storage/backups/db-daily
sudo install -d -m 0750 -o lecayenne -g lecayenne /var/www/lecayenne/storage/backups/db-weekly
sudo install -d -m 0750 -o lecayenne -g lecayenne /var/www/lecayenne/storage/backups/db-monthly
sudo install -d -m 0750 -o lecayenne -g lecayenne /var/www/lecayenne/storage/backups/db-quarterly

# 2. Pager placeholder (owner gate §7 will replace this)
sudo install -m 0755 -o root -g root /dev/stdin /usr/local/bin/lecayenne-pager <<'EOF'
#!/usr/bin/env bash
echo "[$(date -u +%FT%TZ)] $*" >> /var/log/lecayenne/alerts.log
EOF

# 3. Install cron files
# (Paste the /etc/cron.d/lecayenne-scheduler block from §§1, 2.2, 4.1, 4.3, 6.1)
sudo systemctl reload cron

# 4. Install logrotate
# (Paste the /etc/logrotate.d/lecayenne block from §3.1)
sudo logrotate -d /etc/logrotate.d/lecayenne   # dry-run validation

# 5. TLS
sudo snap install --classic certbot
sudo ln -sf /snap/bin/certbot /usr/bin/certbot
sudo certbot --nginx -d lecayenne.fr -d www.lecayenne.fr

# 6. Smoke
sudo -u lecayenne -- bash -lc 'cd /var/www/lecayenne && php artisan schedule:list'
sudo -u lecayenne /usr/local/bin/lecayenne-health-check
```

---

## Appendix B — Cross-reference matrix (Kernel.php verified 2026-05-23)

| Section claim                              | Kernel.php evidence                  |
|--------------------------------------------|--------------------------------------|
| Daily backup 03:00 NF525 6y retention      | Lines 105–110 `dailyAt('03:00')`     |
| Fiscal archive 02:00 per active branch     | Lines 234–264 `dailyAt('02:00')`     |
| Fiscal chain verify 03:30 per active branch| Lines 192–226 `dailyAt('03:30')`     |
| Outbox prune 04:00, 90d retention          | Lines 119–125                        |
| Webhook prune 04:15, 180d retention        | Lines 135–141                        |
| POS parked-order purge 03:15 (24h TTL)     | Lines 89–92                          |
| `activeBranchIds()` covers status 1 + 5    | Lines 305–312                        |
| `onOneServer()` declared on all daily lanes| Verified per lane                    |
| `withoutOverlapping()` on every long lane  | Verified per lane                    |

No drift between this document and `Kernel.php` at the time of writing.
If `Kernel.php` changes, regenerate Section 1 table.

---

*Last verified: 2026-05-23. Kernel.php HEAD: branch `heal/cms-pr1-quickwins-2026-05-18`.*
