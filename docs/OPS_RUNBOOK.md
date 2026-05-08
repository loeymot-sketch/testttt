# OPS — Master Runbook

> Audience: ops / on-call / owner.
> Scope: pre-prod / prod.
> Status: V1 go-live ready, Wave-C ops bundle.

This is the index + checklist runbook stitching together the three Wave-C
deliverables for go-live. **Use this document as your deploy and on-call
companion** — it is intentionally repeat-friendly.

---

## 1. Index

| Topic | Doc |
|---|---|
| DB backups + restore + rollback (NF525-aware) | [`OPS_BACKUP_AND_ROLLBACK.md`](OPS_BACKUP_AND_ROLLBACK.md) |
| Soketi systemd + healthcheck + scaling notes | [`OPS_SOKETI_DEPLOY.md`](OPS_SOKETI_DEPLOY.md) |
| Outbox monitoring + alert thresholds + escalation | [`OPS_OUTBOX_MONITORING.md`](OPS_OUTBOX_MONITORING.md) |
| Existing context | [`OUTBOX_PATTERN.md`](OUTBOX_PATTERN.md), [`REALTIME_SETUP.md`](REALTIME_SETUP.md), [`QUEUE_WORKER_SETUP.md`](QUEUE_WORKER_SETUP.md), [`DEPLOYMENT_GUIDE_V1.md`](DEPLOYMENT_GUIDE_V1.md) |

| Component | File |
|---|---|
| Backup script | `scripts/ops/backup-db.sh` |
| Restore script | `scripts/ops/restore-db.sh` |
| Soketi healthcheck | `scripts/ops/healthcheck-soketi.sh` |
| systemd installer | `scripts/ops/install-soketi-systemd.sh` |
| Soketi unit (template) | `deploy/systemd/foodking-soketi.service` |
| Queue worker unit (template) | `deploy/systemd/foodking-queue-worker.service` |
| Scheduler unit (template) | `deploy/systemd/foodking-scheduler.service` |
| Scheduler timer | `deploy/systemd/foodking-scheduler.timer` |
| Cron alternative | `deploy/cron/foodking-cron.conf` |

---

## 2. One-page deploy checklist (pre-go-live)

> Owner-driven. **Do not start go-live unless every box is ticked.**

### 2.1 Host prerequisites
- [ ] OS up to date (`apt update && apt upgrade -y`)
- [ ] PHP ≥ 8.1 with extensions matching `composer.json` (`php -m`)
- [ ] MySQL ≥ 8.0 / MariaDB ≥ 10.6 reachable over TCP from app host
- [ ] Redis ≥ 6 reachable; `redis-cli ping` → `PONG`
- [ ] Node.js ≥ 16 + Soketi installed (`which soketi`)
- [ ] systemd available (`systemctl --version`) **OR** cron (BSD/container fallback)

### 2.2 App
- [ ] Repo cloned to `/home/foodking/foodking` (or your `APP_HOME`)
- [ ] `.env` populated; `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `composer install --no-dev --optimize-autoloader` done
- [ ] `php artisan config:cache && php artisan route:cache` done
- [ ] `php artisan migrate --force` ran cleanly
- [ ] `php artisan storage:link` if needed
- [ ] `chown -R foodking:foodking storage bootstrap/cache`

### 2.3 Backups (OPS-1, OPS-2)
- [ ] `/var/backups/foodking` created, owned `foodking:foodking`, mode `0750`
- [ ] DB user has `SELECT, LOCK TABLES, EVENT, TRIGGER, PROCESS, REPLICATION CLIENT`
- [ ] `scripts/ops/backup-db.sh` runs cleanly and produces a `.sql.gz`
- [ ] `gunzip -t` on the produced file passes
- [ ] **Restore drill on staging** with `RESTORE_NONINTERACTIVE=1`: row counts match, `migrate:status` clean
- [ ] Backup is scheduled (systemd timer **or** cron — never both)
- [ ] First quarter DR drill scheduled in calendar (recurring)

### 2.4 Soketi (OPS-3, SYN-1, SYN-4)
- [ ] `soketi.json` app-id/key/secret rotated; matches `.env` PUSHER_*
- [ ] `install-soketi-systemd.sh` ran successfully
- [ ] `systemctl is-active foodking-soketi.service` → `active`
- [ ] `systemctl is-enabled foodking-soketi.service` → `enabled`
- [ ] `curl http://127.0.0.1:6001/usage` returns 200
- [ ] `healthcheck-soketi.sh` exits 0
- [ ] Crash test: `kill -9` PID, verify auto-restart within 10s
- [ ] systemd-analyze security score ≤ 4.0

### 2.5 Queue worker (SYN-1, OPS-3)
- [ ] `systemctl is-active foodking-queue-worker.service` → `active`
- [ ] `systemctl is-enabled foodking-queue-worker.service` → `enabled`
- [ ] `journalctl -u foodking-queue-worker.service` shows `processing` lines
- [ ] Restart-on-crash verified

### 2.6 Scheduler (OPS-3)
- [ ] **Exactly one** of:
  - [ ] systemd timer: `systemctl is-enabled foodking-scheduler.timer` + `is-active`
  - [ ] cron: `crontab -u foodking -l` shows the schedule line
- [ ] Verified `foodking:outbox:rescue` runs every minute (logs/journal)
- [ ] Verified `foodking:pusher:monitor` runs every 5 minutes

### 2.7 Healthcheck wiring
- [ ] Soketi healthcheck cron / monitor configured (every 5 min, auto-restart on fail)
- [ ] `/api/health/live` returns 200
- [ ] `/api/health/ready` returns 200 (DB + Redis OK)
- [ ] External monitor (UptimeRobot / Pingdom / etc.) probes `/api/health/ready`
- [ ] Alert thresholds wired per `docs/OPS_OUTBOX_MONITORING.md` § 6

### 2.8 Security & secrets
- [ ] `APP_KEY` set, never committed
- [ ] `PUSHER_APP_SECRET` rotated, not the dev placeholder
- [ ] DB password not the dev placeholder
- [ ] `chmod 0600 .env`
- [ ] `chmod 0600 /var/backups/foodking/*.sql.gz`
- [ ] Backups not synced to S3/cloud unless encrypted at rest (PII inside)

### 2.9 Reverse proxy / TLS
- [ ] Nginx config sets `client_max_body_size 1m` (DAT-2)
- [ ] WebSocket upgrade headers forwarded to Soketi
- [ ] HTTPS enforced; HSTS enabled
- [ ] `/api/health/ready` accessible **only** from internal monitoring IPs (`HEALTH_IPS_ALLOWED` env)

### 2.10 Smoke test
- [ ] Login as admin → opens dashboard
- [ ] Create POS order → ticket prints / preview shown
- [ ] Place kiosk order → KDS tile appears within 2 s
- [ ] Mark order ready on KDS → kiosk shows "ready"
- [ ] `/api/health/ready` → 200
- [ ] No 5xx in `journalctl -u nginx` for 5 min

If **any** box is unchecked, return to that section's deeper doc and resolve.

---

## 3. One-page rollback checklist

> Use this after a deploy goes wrong. Keep it short — go fast.

### 3.1 Decide rollback type (60 seconds)

```
1. Was a Z-ticket (NF525 daily fiscal close) emitted on the buggy build?
   YES → Fiscal-clean rollback. Owner approval REQUIRED. See §6.3 of
         OPS_BACKUP_AND_ROLLBACK.md. Default-prefer roll-forward (patch the bug).
   NO  → Safe rollback. Continue.

2. Is the bug a data-loss / corruption risk RIGHT NOW?
   YES → Stop traffic immediately:
         sudo systemctl stop nginx foodking-queue-worker foodking-soketi
   NO  → Continue under load if bug is "annoying" not "destructive".
```

### 3.2 Safe rollback (≈ 10 minutes)

- [ ] Take a fresh backup: `BACKUP_DIR=/var/backups/foodking/pre-rollback scripts/ops/backup-db.sh`
- [ ] Stop services: `sudo systemctl stop nginx foodking-queue-worker foodking-soketi`
- [ ] `git fetch --tags && git checkout v1.0.X-previous`
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `php artisan migrate:rollback --step=N` (N = bad-build migrations count)
- [ ] `php artisan config:cache && php artisan route:cache`
- [ ] Restart services: `sudo systemctl start foodking-soketi foodking-queue-worker nginx`
- [ ] Smoke test: `curl -fsS http://127.0.0.1/api/health/ready`
- [ ] File incident report in `reports/incidents/YYYY-MM-DD-rollback-*.md`

### 3.3 If services don't come back up

- [ ] `journalctl -xe -n 200`
- [ ] DB connectivity: `mysql -u foodking -p -e 'SELECT 1'`
- [ ] Redis: `redis-cli ping`
- [ ] Restore from pre-rollback backup if needed: `scripts/ops/restore-db.sh /var/backups/foodking/pre-rollback/foodking_db_*.sql.gz`

---

## 4. On-call cheat sheet

### 4.1 Quick health probes

```bash
# Liveness + readiness
curl -fsS http://127.0.0.1/api/health/live
curl -fsS http://127.0.0.1/api/health/ready | jq .

# Soketi
curl -fsS http://127.0.0.1:6001/usage | jq .
sudo -u foodking /home/foodking/foodking/scripts/ops/healthcheck-soketi.sh

# Outbox
sudo -u foodking bash -c 'cd /home/foodking/foodking && php artisan tinker --execute="
echo \"stale=\" . App\\Models\\DomainEvent::query()
    ->whereNull(\"dispatched_at\")->where(\"created_at\", \"<\", now()->subMinutes(2))->count() . PHP_EOL;
echo \"dead=\"  . App\\Models\\DomainEvent::query()
    ->whereNull(\"dispatched_at\")->where(\"attempts\", \">=\", 5)->count() . PHP_EOL;
"'

# Service status snapshot
sudo systemctl --no-pager status \
    foodking-soketi.service \
    foodking-queue-worker.service \
    foodking-scheduler.timer
```

### 4.2 Common fires

| Symptom | First action | Doc |
|---|---|---|
| `/api/health/ready` 503 | Probe DB + Redis with `curl ... | jq` | n/a |
| KDS not updating | Soketi healthcheck → queue worker status → outbox stale count | [`OPS_OUTBOX_MONITORING.md`](OPS_OUTBOX_MONITORING.md) § 4 |
| Soketi unit failed | `journalctl -u foodking-soketi.service -n 100` | [`OPS_SOKETI_DEPLOY.md`](OPS_SOKETI_DEPLOY.md) § 6 |
| Outbox dead rows growing | `php artisan foodking:outbox:retry-failed --since=24h` | [`OPS_OUTBOX_MONITORING.md`](OPS_OUTBOX_MONITORING.md) § 4 |
| Backup didn't run | `journalctl -t foodking-backup --since '24h ago'` | [`OPS_BACKUP_AND_ROLLBACK.md`](OPS_BACKUP_AND_ROLLBACK.md) § 8 |
| Pusher ratio < 90 % alert | Check Soketi instance count, sticky sessions, Echo handler leaks | [`OPS_OUTBOX_MONITORING.md`](OPS_OUTBOX_MONITORING.md) § 5 |
| Disk full | Old backups + Laravel logs. `du -sh /var/backups/foodking storage/logs` | n/a |

### 4.3 Restart playbook (least-disruptive first)

```
1. php artisan queue:restart                   # graceful worker restart
2. sudo systemctl restart foodking-queue-worker.service
3. sudo systemctl restart foodking-soketi.service
4. sudo systemctl reload nginx                 # config-only
5. sudo systemctl restart nginx                # last resort
```

After any restart involving Soketi, expect 5–30 s of WebSocket reconnect noise
across all clients — this is normal.

---

## 5. Quarterly maintenance

- [ ] Run a DR drill (restore from backup) — `OPS_BACKUP_AND_ROLLBACK.md` § 7
- [ ] Rotate `PUSHER_APP_SECRET` + redeploy `soketi.json` (followed by Soketi restart)
- [ ] Verify retention pruning works: backups > 30 days are gone from `/var/backups/foodking/`
- [ ] Review `audit_logs` chain integrity: `php artisan audit:verify-chain`
- [ ] Review `domain_events` size; archive `dispatched_at IS NOT NULL AND created_at < NOW() - INTERVAL 7 DAY` rows (SYN-7 cleanup follow-up)
- [ ] Review systemd-analyze security score for all three units
- [ ] Update this runbook with anything that bit you in the last quarter

---

## 6. Changelog

- **2026-05-08** — initial Wave-C ops bundle (backup + rollback + Soketi systemd + outbox monitoring). Owner-ready greenfield, no app-code changes.

---

## 7. Anti-patterns (do not do)

- **Do not** schedule both systemd-timer and cron — see `deploy/cron/foodking-cron.conf` header.
- **Do not** install the systemd unit templates verbatim; always run `install-soketi-systemd.sh` (paths are placeholders).
- **Do not** restore over a live prod DB without first running a fresh
  `backup-db.sh` (you cannot roll forward without it).
- **Do not** skip the gzip integrity check; corrupted backups are the worst
  kind of "we have backups".
- **Do not** rotate `APP_KEY` without re-encrypting `delivery_platforms.credentials` (DAT-7).
- **Do not** deploy with `APP_DEBUG=true`.
- **Do not** open `/api/health/ready` to the public internet (PII inside `subsystems`).
