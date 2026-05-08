# OPS — Outbox monitoring & alerting

> Audience: ops / on-call / owner.
> Scope: pre-prod / prod.
> Addresses: **OPS-3 (P0)** of `plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md`.

The outbox pattern (`docs/OUTBOX_PATTERN.md`) persists every broadcast event
in `domain_events` before any real-time push. This runbook tells operators
**how to detect when the outbox falls behind** and **what to do about it**.

---

## 1. The pieces involved

| Layer | Component | Path |
|---|---|---|
| Persistence | `domain_events` table | (DB) |
| Queue dispatch | `DispatchDomainEventsJob` (queue=`high`) | `app/Jobs/DispatchDomainEventsJob.php` |
| Periodic rescue (every minute) | `foodking:outbox:rescue` | `app/Console/Commands/OutboxRescueCommand.php` |
| Hourly retry of failed | `foodking:outbox:retry-failed --since=1h` | `app/Console/Commands/OutboxRetryFailedCommand.php` |
| Pusher delivery ratio (every 5 min) | `foodking:pusher:monitor` | `app/Console/Commands/MonitorPusherDeliveryRatio.php` |
| Schedule registration | `app/Console/Kernel.php` | (registered) |
| Health endpoint | `GET /api/health/ready` | `app/Http/Controllers/HealthController.php` |

> **Honest gap (audit OPS-3 / SYN-1):** the audit narrative referenced a future
> `foodking:outbox:monitor` command and a 503 from `/api/health/ready` when
> stale > 10. As of 2026-05-08 the `monitor` command does **not** exist and
> `HealthController::ready()` only checks DB + Redis. The two existing
> commands above (`rescue`, `retry-failed`) plus the SQL queries in §3 below
> are the operator's tools today. Closing this gap is a follow-up app-code
> change tracked in F-015 / pre-prod hardening.

---

## 2. What "stale" means

A `domain_events` row is stale when:

- `dispatched_at IS NULL`, **and**
- `created_at < NOW() - INTERVAL 2 MINUTE`.

The 2-minute window is the same threshold used by `OutboxRescueCommand`'s
`stale(2)` scope. Anything within 2 minutes is in-flight; anything older has
missed at least one rescue tick.

A row is **dead** when:

- `dispatched_at IS NULL`, **and**
- `attempts >= 5` (rescue stops re-queuing past 5 attempts).

Dead rows require the manual retry command (`foodking:outbox:retry-failed`)
or operator inspection of `last_error`.

---

## 3. Manual staleness check (today's tooling)

Operators can probe the outbox with three SQL queries — also documented in
`docs/OUTBOX_PATTERN.md`. Run them on demand from a tinker / mysql shell.

### 3.1 Count of stale-but-recoverable

```sql
SELECT COUNT(*) AS stale_recoverable
FROM domain_events
WHERE dispatched_at IS NULL
  AND attempts < 5
  AND created_at < NOW() - INTERVAL 2 MINUTE;
```

Threshold guidance:

| Stale recoverable | Severity | Action |
|---|---|---|
| 0–10 | OK | none |
| 11–50 | WARN | tail logs (§5), check Soketi health, do nothing for 1 cycle |
| 51–200 | DEGRADED | run §4.1 |
| > 200 | CRITICAL | page on-call + run §4.1 + §4.2 |

### 3.2 Count of dead (attempts ≥ 5)

```sql
SELECT COUNT(*) AS dead
FROM domain_events
WHERE dispatched_at IS NULL
  AND attempts >= 5;
```

Any value > 0 needs operator review. After investigating, run:

```bash
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:outbox:retry-failed --since=1h'
```

### 3.3 Top recent failure messages

```sql
SELECT id, event_type, attempts, LEFT(last_error, 200) AS error_excerpt, created_at
FROM domain_events
WHERE dispatched_at IS NULL
  AND last_error IS NOT NULL
ORDER BY id DESC
LIMIT 20;
```

### 3.4 Convenience CLI

For ops who prefer a one-liner:

```bash
sudo -u foodking bash -c 'cd /home/foodking/foodking && php artisan tinker --execute="
echo \"stale_recoverable=\" . App\\Models\\DomainEvent::query()
        ->whereNull(\"dispatched_at\")
        ->where(\"attempts\", \"<\", 5)
        ->where(\"created_at\", \"<\", now()->subMinutes(2))->count() . PHP_EOL;
echo \"dead=\" . App\\Models\\DomainEvent::query()
        ->whereNull(\"dispatched_at\")
        ->where(\"attempts\", \">=\", 5)->count() . PHP_EOL;
"'
```

---

## 4. What to do when stale > 10

Triage in this order:

### 4.1 Confirm the queue worker is alive

```bash
sudo systemctl status foodking-queue-worker.service
sudo systemctl is-active foodking-queue-worker.service   # → active
```

If it's **not active**:

```bash
sudo systemctl start foodking-queue-worker.service
sudo journalctl -u foodking-queue-worker.service -n 100
```

Most "stale" surges trace back to a worker that exited and was not restarted.
The systemd unit (`Restart=always`) handles this in normal operation, but
verify after every deploy.

### 4.2 Confirm Soketi is reachable

```bash
sudo -u foodking /home/foodking/foodking/scripts/ops/healthcheck-soketi.sh
```

If exit code != 0, see `docs/OPS_SOKETI_DEPLOY.md` § 6 (Troubleshooting). A
Soketi outage produces a flood of stale `domain_events` because every
broadcast attempt fails.

### 4.3 Confirm the scheduler is running

```bash
# systemd path
systemctl list-timers --all | grep foodking-scheduler
journalctl -u foodking-scheduler.service --since '2 min ago'

# cron path
sudo grep -i 'foodking-scheduler\|schedule:run' /var/log/syslog | tail -20
```

If neither timer nor cron is running, `foodking:outbox:rescue` never fires —
all events go stale at the 2-minute mark. Fix by enabling **one** of:

```bash
sudo systemctl enable --now foodking-scheduler.timer
# OR
sudo crontab -u foodking /home/foodking/foodking/deploy/cron/foodking-cron.conf
```

(See `docs/OPS_BACKUP_AND_ROLLBACK.md` § 2.3 — never both.)

### 4.4 Force a rescue manually

```bash
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:outbox:rescue'
```

This re-queues stale rows (attempts < 5) onto the `high` queue. Combine with
`retry-failed` for dead rows:

```bash
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:outbox:retry-failed --since=24h'
```

### 4.5 Investigate root cause via `last_error`

Use the SQL in § 3.3. Common patterns:

| `last_error` excerpt | Cause | Fix |
|---|---|---|
| `cURL error 7: Failed to connect` | Soketi down | § 4.2 |
| `Pusher error: 4001` | App key mismatch | reconcile `soketi.json` ↔ `.env`, restart Soketi |
| `Connection refused [::1]:6379` | Redis down | restart Redis |
| `Maximum execution time` | Slow downstream — investigate Pusher / network latency | (rare; raise PHP-CLI timeout if persistent) |

---

## 5. Pusher delivery ratio (SYN-2)

`foodking:pusher:monitor` runs every 5 minutes (registered in
`app/Console/Kernel.php`) and compares dispatched envelopes vs. distinct client
acks on a sliding 5-minute window. It logs at `error` level + exits non-zero
when the ratio drops below 90 %.

### 5.1 Tail the alerts

```bash
sudo journalctl -t foodking-scheduler --since '15 min ago' | grep pusher:monitor
# OR if running under cron:
sudo tail -n 200 /var/log/foodking-scheduler.log | grep -i pusher
```

### 5.2 What a < 90 % ratio means

- **Ratio = dispatched / acked.**
- Dispatched is incremented by `DispatchDomainEventsJob` on a successful
  broadcast.
- Acked is incremented when frontend Echo handlers send the heartbeat back.

Low ratio possibilities:

1. Multiple Soketi instances without sticky sessions (SYN-4) — clients
   subscribed on instance A, publish lands on B, never delivered.
2. Echo handler memory leak (SYN-8) — page kept open for hours, handler GC'd
   but still counted as dispatched.
3. Rate limiting (SYN-9) — Pusher hosted plan throttling at peak.

Investigation playbook:

```bash
# Check Soketi instance count (V1: must be 1)
sudo systemctl list-units --state=active | grep soketi

# Check open WebSocket connection count
curl -fsS http://127.0.0.1:6001/usage | jq

# Inspect recent broadcast volumes (last hour)
sudo -u foodking bash -c 'cd /home/foodking/foodking && php artisan tinker --execute="
echo App\\Models\\DomainEvent::query()
    ->whereNotNull(\"dispatched_at\")
    ->where(\"dispatched_at\", \">\", now()->subHour())
    ->count();
"'
```

---

## 6. Alert thresholds (recommended for monitoring)

Wire these into your monitoring stack (Prometheus, Datadog, Cronitor, etc.).
Until app-code adds metric exporters, scrape the SQL counts via a small ops
script and feed them into your monitor.

| Metric | Threshold | Severity | Notify |
|---|---|---|---|
| `outbox_stale_recoverable` | > 50 for 5 min | WARN | Slack #ops |
| `outbox_stale_recoverable` | > 200 for 2 min | CRITICAL | Page |
| `outbox_dead` | > 0 for 1 hour | WARN | Slack #ops + email owner |
| `outbox_dead` | > 50 | CRITICAL | Page |
| `pusher_monitor_exit_code` | non-zero (ratio < 90 %) for 15 min | WARN | Slack #ops |
| `pusher_monitor_exit_code` | non-zero for 60 min | CRITICAL | Page |
| `foodking-queue-worker.service active` | inactive | CRITICAL | Page |
| `foodking-scheduler.timer enabled & active` | inactive | CRITICAL | Page |
| `foodking-soketi.service active` | inactive | CRITICAL | Page |
| `/api/health/ready` HTTP code | 503 for 2 min | CRITICAL | Page |

Sample wrapper for a metrics scraper:

```bash
#!/usr/bin/env bash
# /usr/local/bin/foodking-outbox-metrics.sh
set -euo pipefail
cd /home/foodking/foodking
sudo -u foodking php artisan tinker --execute='
    $stale = App\Models\DomainEvent::query()
        ->whereNull("dispatched_at")->where("attempts","<",5)
        ->where("created_at","<",now()->subMinutes(2))->count();
    $dead  = App\Models\DomainEvent::query()
        ->whereNull("dispatched_at")->where("attempts",">=",5)->count();
    echo "outbox_stale_recoverable $stale\noutbox_dead $dead\n";
'
```

---

## 7. Escalation path

1. **Tier 1 (ops on-call):** acknowledge alert within 15 min. Run § 4 triage.
2. **Tier 2 (lead dev):** escalate after 30 min if not resolved, or
   immediately if Soketi is corrupted / queue worker is crashing on start.
3. **Tier 3 (owner + legal):** required if any of:
   - dead rows > 50,
   - Pusher ratio stays < 90 % for > 1 hour during business hours,
   - any `domain_events` row's `payload` contains a fiscal Z-related event
     and is past 1 hour stale (potential NF525 audit trail risk).

Document every incident in `reports/incidents/YYYY-MM-DD-outbox-*.md`.

---

## 8. Manual test plan (after first deploy)

```bash
# 1. Confirm scheduler is firing the rescue
sudo journalctl -u foodking-scheduler.service --since '5 min ago' | grep -i outbox

# 2. Confirm queue worker is consuming the high queue
sudo journalctl -u foodking-queue-worker.service --since '5 min ago' | grep -i Dispatch

# 3. Inject a fault (staging only) by stopping Soketi for 30 sec
sudo systemctl stop foodking-soketi.service
# Place a POS order via UI; wait 30 sec
# Verify domain_events row exists with attempts > 0 and dispatched_at NULL
# Restart Soketi
sudo systemctl start foodking-soketi.service
# Verify within 60 sec the row gets dispatched_at set (rescue + queue retry)

# 4. Manual rescue command runs cleanly
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:outbox:rescue'

# 5. retry-failed runs cleanly
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:outbox:retry-failed --since=1h'

# 6. Pusher monitor exits 0 in normal conditions
sudo -u foodking bash -c 'cd /home/foodking/foodking && \
    php artisan foodking:pusher:monitor; echo exit=$?'
```

If any step fails, **outbox is not production ready** — see § 4 triage.
