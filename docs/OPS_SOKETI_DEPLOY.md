# OPS — Soketi deployment

> Audience: ops / owner.
> Scope: pre-prod / prod.
> Addresses: **OPS-3 + SYN-1 (P0)** and **SYN-4** of `plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md`.

This runbook makes Soketi (the WebSocket broker carrying KDS / kiosk / customer
real-time updates) a **supervised, restart-on-crash, healthchecked** systemd
service. Without this, the audit's SYN-1 / OPS-3 risk applies: a silent Soketi
crash leaves KDS blind.

---

## 1. Files referenced

| Purpose | Path |
|---|---|
| systemd unit (template) | `deploy/systemd/foodking-soketi.service` |
| Installer (resolves binary) | `scripts/ops/install-soketi-systemd.sh` |
| Healthcheck script | `scripts/ops/healthcheck-soketi.sh` |
| Soketi config | `soketi.json` (repo root) |

The unit file contains placeholders (`__SOKETI_BIN__`, `__APP_USER__`,
`__REPO_DIR__`) that the installer replaces with values resolved at install
time. **Do not copy the template directly to `/etc/systemd/system/`.**

---

## 2. Install Soketi runtime

Soketi requires Node.js ≥ 16.

```bash
# Install Node 18 LTS (NodeSource example)
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Install Soketi globally
sudo npm install -g @soketi/soketi

# Verify
soketi --version
which soketi   # → /usr/local/bin/soketi or /usr/bin/soketi depending on distro
```

> The installer script (`install-soketi-systemd.sh`) does **not** install
> Soketi itself — it only renders + enables the systemd units. Install Soketi
> first.

---

## 3. Configure `soketi.json`

The repo ships a development `soketi.json`. **Replace** the default
`app-id` / `app-key` / `app-secret` with strong randoms in production. Keep
those three values in sync with the matching keys in `.env`:

```env
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

Generate strong values:

```bash
sudo -u foodking bash -c "openssl rand -hex 24"   # × 3 (id, key, secret)
```

Update both `soketi.json` and `.env`, then `php artisan config:clear`.

---

## 4. systemd setup

### 4.1 Run the installer

```bash
sudo /home/foodking/foodking/scripts/ops/install-soketi-systemd.sh \
     /home/foodking/foodking
```

What it does:

1. Verifies `EUID=0`, repo dir, and runtime user (`foodking` by default).
2. Resolves `command -v soketi` and `command -v php` so the unit's
   `ExecStart` is correct on this host.
3. Renders the three template units to `/etc/systemd/system/`.
4. `systemctl daemon-reload`, `enable`, `restart`.
5. Prints status of all three units.

### 4.2 Manual install (if you prefer)

If you do not want to run the installer:

```bash
SOKETI_BIN=$(command -v soketi)
PHP_BIN=$(command -v php)
APP_USER=foodking
REPO_DIR=/home/foodking/foodking

for unit in foodking-soketi.service foodking-queue-worker.service foodking-scheduler.service; do
    sed \
        -e "s|__SOKETI_BIN__|${SOKETI_BIN}|g" \
        -e "s|__PHP_BIN__|${PHP_BIN}|g" \
        -e "s|__APP_USER__|${APP_USER}|g" \
        -e "s|__REPO_DIR__|${REPO_DIR}|g" \
        "${REPO_DIR}/deploy/systemd/${unit}" \
        | sudo tee /etc/systemd/system/${unit} > /dev/null
done

sudo cp ${REPO_DIR}/deploy/systemd/foodking-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now foodking-soketi.service \
                            foodking-queue-worker.service \
                            foodking-scheduler.timer
```

### 4.3 Hardened by default

The unit applies systemd's standard hardening (excerpt):

```
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=__REPO_DIR__/storage
ProtectHome=read-only
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictRealtime=true
RestrictSUIDSGID=true
LockPersonality=true
```

Verify with:

```bash
systemd-analyze security foodking-soketi.service
```

Target rating: ≤ 4.0.

---

## 5. Healthcheck

`scripts/ops/healthcheck-soketi.sh` probes `http://127.0.0.1:6001/usage`. Exit
code `0` = OK, `1` = degraded.

### 5.1 Cron / timer wiring

Wire it every 5 minutes — auto-restart on failure (idempotent: systemd skips if
already running):

```cron
*/5 * * * * /home/foodking/foodking/scripts/ops/healthcheck-soketi.sh \
    >> /var/log/foodking-soketi-health.log 2>&1 \
    || /usr/bin/systemctl --no-block restart foodking-soketi.service
```

(Already included in `deploy/cron/foodking-cron.conf`.)

### 5.2 Alerting threshold

Two consecutive failures = alert. Page on:

- 3 healthcheck failures in a 15-minute window, OR
- `systemctl is-active foodking-soketi.service` returns anything other than `active`,
  OR
- `journalctl -u foodking-soketi.service --since '5 min ago' | grep -ic 'error'` > 5.

---

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `ExecStart=/usr/bin/soketi: No such file or directory` | Installer wasn't run, hardcoded path mismatch | Re-run `install-soketi-systemd.sh`; verify with `which soketi`. |
| `ECONNREFUSED 127.0.0.1:6001` from frontend | Soketi crashed silently | `journalctl -u foodking-soketi.service -n 100`; restart: `sudo systemctl restart foodking-soketi.service`. |
| KDS gets stale data even though Soketi is up | Outbox queue worker not running | See `docs/OPS_OUTBOX_MONITORING.md` § 4.1. |
| Connections drop every minute | Reverse-proxy WebSocket timeout | Set `proxy_read_timeout 86400s;` in nginx; ensure `Upgrade` header forwarded. |
| `Pusher partial failure` alerts (SYN-2) | Pusher-monitor command sees ratio < 90 % | `php artisan foodking:pusher:monitor` manually; check client-side Echo handlers + network. |
| Memory creep | Long-running connections; expected. | `RestartSec=5` already configured; or schedule weekly `systemctl restart foodking-soketi.service`. |

### 6.1 Capture a flight recorder

```bash
sudo journalctl -u foodking-soketi.service --since '1 hour ago' > /tmp/soketi-flight.log
sudo systemctl status foodking-soketi.service > /tmp/soketi-status.log
```

Attach both when filing an incident report.

---

## 7. Multi-instance scaling considerations (post-V1)

V1 ships a single Soketi instance per host. If/when you scale horizontally:

- **Sticky sessions are MANDATORY** at the load balancer (SYN-4). Soketi's
  default in-memory `appManager.driver=array` does not share state across
  nodes. Without sticky sessions, a client's subscribe-then-publish can hit
  different nodes and the publish will not fan out to the subscriber.
- Alternatives: switch to `appManager.driver=mysql` or use Pusher hosted.
- Minimum sticky-session config example for nginx (`ip_hash`):
  ```nginx
  upstream soketi_backend {
      ip_hash;
      server soketi-1:6001;
      server soketi-2:6001;
  }
  ```

**This is a roadmap item, not V1.** V1 SLA assumes one Soketi instance per host
+ the queue worker outbox pattern as the durable-delivery layer.

---

## 8. Manual test plan (after first deploy)

```bash
# 1. Unit is enabled + active
sudo systemctl is-enabled foodking-soketi.service   # → enabled
sudo systemctl is-active foodking-soketi.service    # → active

# 2. Listening on :6001
sudo ss -tlnp | grep ':6001'

# 3. /usage returns 200
curl -fsS http://127.0.0.1:6001/usage | jq .

# 4. Healthcheck script ok
sudo -u foodking /home/foodking/foodking/scripts/ops/healthcheck-soketi.sh
echo $?   # → 0

# 5. Crash + auto-restart
sudo systemctl kill -s SIGKILL foodking-soketi.service
sleep 6
sudo systemctl is-active foodking-soketi.service    # → active (restarted)

# 6. End-to-end real-time
# From a kiosk / KDS browser tab, place an order via POS and watch network panel:
# you should see the WebSocket frame from /app/<key> within 500 ms of POS save.

# 7. Hardening check
systemd-analyze security foodking-soketi.service    # → ≤ 4.0 OK
```

If any of step 1–7 fails, **Soketi is not production ready**.
