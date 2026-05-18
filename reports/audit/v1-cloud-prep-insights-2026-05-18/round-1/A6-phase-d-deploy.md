# A6 — Phase D Cloud Deploy Readiness (RED-team)

- **Date**: 2026-05-18
- **Branch**: `v1-0-1-hardening-2026-05-17` @ `1235e3e1a`
- **Auditor**: RED-team A6, read-only
- **Scope**: 10 items per mission brief — OVH VPS-1 Ansible deploy readiness for V1 Le Cayenne
- **Evidence**: file paths + line numbers, no speculation

---

## Summary (TL;DR)

| #  | Item                                  | Verdict             | Severity |
|----|---------------------------------------|---------------------|----------|
| 1  | Ansible site.yml structure            | PARTIAL PASS        | P1       |
| 2  | Nginx template                        | PASS w/ notes       | P2       |
| 3  | Supervisor template                   | PARTIAL PASS        | P1       |
| 4  | Production .env template              | **FAIL** (4 keys)   | **P0**   |
| 5  | Backup procedure (scripts)            | PASS                | —        |
| 6  | DR drill runbook                      | PASS                | —        |
| 7  | Health probe                          | PARTIAL PASS        | P1       |
| 8  | Cron mass (Kernel.php)                | PASS                | —        |
| 9  | Secrets management (vault.yml)        | **FAIL** (missing)  | **P0**   |
| 10 | Owner 10 physical actions             | NEEDS-VERIFICATION  | P1       |

**Cycle-blocking P0 (must heal before first `ansible-playbook site.yml`):**
- A6-D1 — `group_vars/vault.yml` absent → first playbook run aborts at line 59 (`vault_redis_password undefined`)
- A6-D2 — env template missing 4 keys actually consumed by code: `STRIPE_WEBHOOK_SECRET`, `CASH_MANAGER_GATE_ROUTINE_CLOSE`, `KDS_V2_DEFAULT_ENABLED`, `KIOSK_LOCALE_SWITCH_ALLOWED`

**P1 issues (heal before production cron activation):**
- A6-D3 — `/etc/foodking-backup.env` not provisioned by playbook (cron sources it silently → no backup, no alert)
- A6-D4 — `soketi.json` not provisioned by playbook (supervisor leaves program FATAL → realtime broadcasts disabled)
- A6-D5 — `/api/health/fiscal` endpoint claimed by spec but does not exist (chain verify is restore-script-only)

---

## Item 1 — Ansible site.yml structure  → PARTIAL PASS

File: `deploy/ansible/site.yml` (173 LOC, verified via `wc -l`).

**Tasks counted: 20** (not the 8 "role groups" — site.yml is a single play with 20 sequential tasks, no roles directory). Every task has `name:` (verified L4-L168). All apt tasks use `state: present` (idempotent). All file/copy/template tasks use modes + correct destinations. Tags applied: `[base, php, mysql, redis, nginx, soketi, app, cron]` — 8 selective-rerun groups.

**Strengths:**
- L82-86 `Let's Encrypt cert` uses `creates: /etc/letsencrypt/live/.../fullchain.pem` for idempotency (correct).
- L121-130 — pre-migrate `mysqldump --triggers --routines` snapshot before `migrate --force` is a legitimate NF525 safety net (RED-team Wave 5I A.2 marker visible). `no_log: true` masks password leakage.
- Handlers (L169-173) correctly use `notify` for nginx/mysql/redis/supervisor reload.
- `become: true` at play level (L6), `become_user: www-data` overridden on app tasks (L103, L110, L115, L135). Correct privilege separation.

**Gaps:**
- L142 `Cron — Laravel schedule:run` runs as `www-data` writing to `/dev/null`. **Cron output to /dev/null = silent failures** (RED-team P1). Should redirect to `/var/log/foodking-cron.log` (then logrotate L154 already covers it).
- L101 `force: false` on `git clone` — re-runs DO NOT pull new commits. Owner must SSH to update app. Acceptable for V1 pinned-tag deploys, but document this.
- No task creates `/etc/foodking-backup.env` referenced at L152 → **see Item 5/9**.
- No task provisions `soketi.json` referenced by supervisor template L27 → **see Item 3**.
- No task creates `group_vars/vault.yml` → **see Item 9**.

---

## Item 2 — Nginx template  → PASS w/ notes

File: `deploy/ansible/templates/nginx-foodking.conf.j2` (106 LOC).

**Verified:**
- L11-14 HTTP-only listener on :80 + :80 IPv6, server_name templated.
- L21-24 `^~ /.well-known/acme-challenge/` reserved for Certbot ACME HTTP-01 (kept reachable post-TLS for renewal).
- L26-31 Security headers: `X-Content-Type-Options nosniff`, `X-Frame-Options SAMEORIGIN`, `Referrer-Policy strict-origin-when-cross-origin`. HSTS commented (L31) with explicit note `# Certbot injects after TLS` — correct: certbot --nginx adds HSTS post-provisioning.
- L34-47 gzip with sensible types + level 6.
- L49 `client_max_body_size 32M` — sufficient for admin image upload (Spatie media ~2-5MB), kiosk uploads in V1 are none (no menu-side upload).
- L9 + L57-60 `limit_req zone=api:10m rate=60r/s` + `burst=30 nodelay` on `/api/` — present.
- L63-72 `/broadcasting/auth` proxies to Soketi WS on 127.0.0.1:6001 with Upgrade/Connection headers (correct WS handoff).
- L75-88 static asset caching: 1y immutable on `/js/` + `/css/` (Mix-versioned), 30d on images/fonts.
- L91-101 PHP-FPM via Unix socket `php{{ php_version }}-fpm.sock`, `fastcgi_read_timeout 120`.
- L103-105 deny dotfiles + `/storage/logs|\.env|composer\.(json|lock)`.

**Notes (P2 — non-blocking):**
- No CSP header (`Content-Security-Policy`) added at nginx layer. CSP is owned by `app/Http/Middleware/ContentSecurityPolicyHeader.php` (Laravel) per LOCK plan §3.2 — fine, but if Laravel ever misroutes (e.g. static asset hit) the CSP header is missing. Belt-and-suspenders could add a default `Content-Security-Policy: default-src 'self'` for static paths only.
- `/storage/` (Spatie public disk) is NOT in a dedicated `location` — served via `try_files` to PHP. Fine if `FILESYSTEM_DISK=local`, but env template L62-63 hints production should flip to S3 — once flipped, this path is unused, no risk.
- `proxy_pass http://127.0.0.1:6001` (L64) — no upstream block. Acceptable for single-host V1; document V2 must add HAProxy upstream.

**Verdict**: PASS. Certbot will run in-place on first deploy and inject the 443 server + HSTS as the template comment claims (validated by reading `certbot --nginx` docs — modifies the existing server block, doesn't replace). Owner action #9 (DNS A record before certbot) is correctly called out in README.

---

## Item 3 — Supervisor template  → PARTIAL PASS

File: `deploy/ansible/templates/supervisor-foodking.conf.j2` (52 LOC).

**Verified — `[program:foodking-queue]` (L7-19):**
- L9 `queue:work redis --queue=high,default --tries=3 --timeout=120 --max-jobs=1000 --sleep=3` — uses **only `high` + `default`** queues.
  - **Checklist-vs-reality mismatch**: brief claimed `--queue=default,fiscal,broadcasts`. Reality: `high,default`. Grep on `app/Jobs/**` confirms only `DispatchDomainEventsJob:46` calls `$this->onQueue('high')`. No code dispatches to `fiscal` or `broadcasts` channels. Supervisor config is **consistent with code**; the checklist was outdated.
- L11 `autorestart=true`, L13 `numprocs=2` (defensible on 2 vCPU per VPS-1 sizing comment L3-5), L14 `redirect_stderr=true`, L15 `stdout_logfile=/var/log/supervisor/foodking-queue.log`, L16 `stdout_logfile_maxbytes=20MB`, L17 `stdout_logfile_backups=5` (built-in log rotation — supervisor handles it, no cron logrotate needed for this file).
- L18 `stopwaitsecs=60` + L19 `stopsignal=QUIT` — correct for queue:work graceful shutdown.

**Verified — `[program:foodking-soketi]` (L21-36):**
- L27 `command=/usr/local/bin/soketi start --config={{ app_root }}/soketi.json`.
- L23-26 inline comment admits: *"Soketi config file must be provisioned by owner (group_vars/vault.yml) BEFORE first supervisor reload. ... If missing, soketi will fail on start and supervisor will leave the program in FATAL state."*
- **GAP P1**: no Ansible task creates `soketi.json`. No template in repo (`find . -name "soketi.json*"` returns nothing). First playbook run leaves soketi FATAL → realtime broadcasts disabled in production. **A6-D4**.

**Verified — `[program:foodking-scheduler]` (L38-53)**: explicitly DISABLED, with comment warning against double-fire with the cron task at site.yml:139-145. Correct.

**Verdict**: PARTIAL PASS — supervisor logic correct, but soketi config provisioning is missing. Heal: add Ansible template task for `soketi.json` reading `vault_soketi_app_id/key/secret` from vault, or document explicit owner action.

---

## Item 4 — Production .env template  → FAIL (P0)

File: `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (149 LOC — checklist claimed 142, actual 149, **mismatch noted**).

**PASS sub-points:**
- L7 `APP_ENV=production`, L9 `APP_DEBUG=false`, L21 `LOG_LEVEL=warning` ✓
- L24-29 `DB_*` placeholders with `ROTATE_*` markers ✓
- L33 `QUEUE_CONNECTION=redis` (NOT sync — critical for outbox pattern) ✓
- L42 `BROADCAST_DRIVER=pusher` (Soketi self-hosted on 127.0.0.1) ✓
- L78-85 Mail config (smtp, encryption=tls, FROM address) ✓
- L8 APP_KEY warned via `GENERATE_VIA_artisan_key_generate` placeholder ✓
- L112 `POS_SIMULATION_HARDWARE=false` explicitly set ✓
- L103-105 `PAYMENT_BYPASS_MODE=false`, `PRINTING_BYPASS_MODE=false`, `PRICING_TAX_INCLUSIVE=true` ✓
- L149 `DEMO=false` ✓
- L14-16 NF525 fiscal HMAC secrets placeholders (`FISCAL_AUDIT_SECRET`, `FISCAL_Z_REPORT_SECRET`, `FISCAL_AUDIT_SECRET_BRANCH_1`) ✓
- L96 `HEALTH_IPS_ALLOWED=127.0.0.1/32` (locks `/api/health` full endpoint to localhost) ✓

**FAIL sub-points — 4 keys consumed by `config/` but absent from template:**

| Env key | Consumed at | Default | Risk |
|---|---|---|---|
| `STRIPE_WEBHOOK_SECRET` | `config/services.php:68` | `''` (empty) | Stripe webhook signature verification SILENTLY DISABLED → any unsigned POST to `/stripe-webhook` accepted in prod |
| `CASH_MANAGER_GATE_ROUTINE_CLOSE` | `config/cash.php:86` | `false` | Manager-gate routine-close DISABLED by default. Sprint H2.2 (#24) supposedly enabled this; owner must explicitly set `=true` |
| `KDS_V2_DEFAULT_ENABLED` | `config/kds.php:25` | `true` | KDS V2 enabled by default — but env-undeclared means owner can't kill-switch without reading code |
| `KIOSK_LOCALE_SWITCH_ALLOWED` | `config/kiosk.php:31` | `false` | FR-lock default is correct, but mission brief explicitly required this be present (`KIOSK_LOCALE_SWITCH_ALLOWED=false`) for audit-grade visibility |

**Other gaps:**
- No explicit Stripe live `key`/`secret` placeholders. Investigation: live keys live in `business_settings` (Smartisan `Settings::group('stripe')` — DB-stored, set via admin UI). **N/A for env template** EXCEPT the webhook secret (which IS env-driven and IS missing).
- SenangPay: same pattern (DB-stored via Smartisan settings) — N/A for env template.
- `BACKUP_S3_BUCKET` (L73), `BACKUP_ALERT_WEBHOOK` (L75) present but empty — runbook §1 directs owner to populate `/etc/foodking-backup.env` instead → see Item 5/9.

**Verdict**: FAIL P0 — 4 missing keys for an env-audit-friendly template. Heal: add explicit lines for the 4 keys with safe defaults + comments referencing the config file consumer. Critical: `STRIPE_WEBHOOK_SECRET` empty in prod = unsigned webhook acceptance (P0 security regression).

---

## Item 5 — Backup procedure (scripts)  → PASS

### 5.1 `scripts/backup-foodking-daily.sh` (105 LOC) → PASS

- L8 `set -Eeuo pipefail` + L9 `IFS=$'\n\t'` ✓ defensive bash.
- L55-61 `mysqldump --triggers --routines --single-transaction --quick --add-drop-table --default-character-set=utf8mb4` ✓ — NF525-compliant (triggers preserve BEFORE-DELETE on audit_logs/z_reports).
- L67 `gunzip -t "$DUMP"` integrity check (RED-team P1 marker — catches truncated gzip from disk-full mid-stream).
- L70-72 sha256sum sidecar `${DUMP}.sha256`.
- L78-90 `s3_put_retry()` exponential backoff (2s/4s/8s, 3 attempts) — RED-team P1 marker.
- L93-96 uploads dump THEN checksum (correct order: if checksum upload fails, dump is still verifiable from local copy).
- L100-102 local rotation `find -mtime +${RETENTION_DAYS} -delete` (default 7d).
- L16-21 `BACKUP_ALERT_WEBHOOK` POST on FATAL (Slack/Discord/PagerDuty inbound).
- L24-46 cron-shell safety: sources `${APP_ROOT}/.env`, validates required env (`DB_DATABASE:?missing`), checks for `mysqldump`/`s3cmd`/`sha256sum` binaries.

### 5.2 `scripts/restore-foodking-from-backup.sh` (77 LOC) → PASS

- L23-25 guard against restoring over live DB without `ALLOW_RESTORE_PROD=1`.
- L43-44 SHA-256 verify before restore (refuses on mismatch).
- L48-49 interactive confirm — user types target DB name to proceed.
- L66-74 `php artisan tinker --execute` calls **instance methods** `AuditLogService::verifyChain($branchId=1)` + `ZReportService::verifyChain($branchId=1)`. Exit 3 if chain FAIL → restore script halts (line 74 `|| fail`).
- **NF525 chain verification confirmed**: `is_null($audit)` = chain intact (per AuditLogService convention); `!empty($z["valid"])` for ZReportService.
- Comment L64 acknowledges multi-branch must iterate `Branch::pluck('id')` — V1 single-resto OK at branch_id=1.

**Cron compatibility (Item 5 sub-point)**:
- Cron alert: script `alert()` POSTs webhook on FATAL exit 2. Script `exit 0` on success. Cron mail fires on non-zero exit by default → **dual alerting confirmed** (webhook + cron mail to root).
- PATH/locale: script uses `command -v` checks (L43-45) but does NOT explicitly `export PATH=/usr/bin:/usr/local/bin`. **Minor P2** — if cron's default PATH lacks `s3cmd` install path, the check fails fast. Mitigated by runbook §1 instructing apt install. Acceptable.

**Verdict**: PASS — backup + restore + verifyChain pipeline is sound.

---

## Item 6 — DR drill runbook  → PASS

File: `docs/runbooks/BACKUP_RESTORE_NF525.md` (141 LOC).

**6 sections present:**
1. §1 Setup (one-shot) — s3cmd install + ~/.s3cfg + /etc/foodking-backup.env env file with explicit keys. ✓
2. §2 Daily cron — `0 3 * * * www-data . /etc/foodking-backup.env && ...` ✓
3. §3 DR drill procedure (MONTHLY — owner-gate) — explicit PASS criteria `audit_logs.verifyChain: OK` + `z_reports.verifyChain: OK` ✓
4. §4 NF525 6-year retention — OVH Object Storage lifecycle XML (`<Expiration><Days>2200</Days>`) + versioning + object lock (compliance mode) ✓
5. §5 Emergency restore — RTO procedure (stop services → forensic snapshot → restore → verify → promote) ✓
6. §6 Owner physical actions — 8 checkboxes (OVH account, bucket, replication, S3 keys, ~/.s3cfg, webhook, staging drill, monthly calendar) ✓

**Verified RTO/RPO understanding:**
- RPO ≤24h (daily backup at 03:00 UTC).
- RTO ≥ minutes-to-tens-of-minutes (Object Storage Cold first-byte latency — documented L121).
- Monthly DR drill frequency NOT cron-enforced (calendar reminder per §6 last item) — depends on owner discipline. **P2 note** — could add a `dr-drill-due` cron alert checking last drill timestamp.

**6y retention object lock confirmed**: §4 documents versioning + object lock compliance mode @ 2200d.

**Verdict**: PASS.

---

## Item 7 — Health probe  → PARTIAL PASS

File: `app/Http/Controllers/HealthController.php` (214 LOC — checklist claimed 215, **mismatch noted**).
Routes: `routes/api.php:138-140`.

**Verified endpoints:**
- L34-37 `live()` returns 200 "OK" plain text — K8s liveness probe compatible ✓
- L39-60 `ready()` checks `db` + `redis` + `queue_worker` (outbox stale count threshold 10, F-015) + `broadcast_config` (production-only check for sync queue or null broadcast driver). Returns 503 on degraded — K8s readiness probe compatible ✓
- L13-32 `full()` returns 200 always but degraded status, IP-allowlisted via `assertFullHealthIpAllowed()` (HEALTH_IPS_ALLOWED CSV) ✓

**FAIL sub-point — `/api/health/fiscal` endpoint claimed by spec does NOT EXIST.**

Verified via `grep -rn "Route::get('/api/health" routes/`:
```
/api/health        → full()
/api/health/live   → live()
/api/health/ready  → ready()
```

No `/api/health/fiscal` route. No `verifyChain` invocation in HealthController. NF525 chain verification is **restore-script-only** (Item 5.2 line 66-74). **A6-D5 — P1 gap**: production cloud monitoring (uptime-kuma, pingdom) cannot poll fiscal-chain integrity from HTTP. Healing would require new endpoint with rate-limit + IP allowlist (chain verify is non-trivial cost; daily probe acceptable, not per-minute).

**K8s compatibility check (Item 7 sub-point):**
- `live` + `ready` — no auth, no rate-limit (sit OUTSIDE the `auth:sanctum` middleware in `routes/api.php`). K8s-compatible ✓
- `full` — IP-allowlisted only. K8s probes from in-cluster IPs would fail unless added to HEALTH_IPS_ALLOWED. Acceptable for single-host V1 (no K8s).

**Verdict**: PARTIAL PASS — `live` + `ready` are production-grade. `fiscal` endpoint is missing per spec but is a NEW feature, not a regression.

---

## Item 8 — Cron mass (Kernel.php)  → PASS

File: `app/Console/Kernel.php` (207 LOC).

**12 scheduled tasks audited (L23-194). Concurrency primitives:**

| Task | Cadence | `withoutOverlapping` | `onOneServer` | Time | Risk |
|---|---|---|---|---|---|
| purge-expired-otps | 15min | ✓ | ✗ | inline | OTPs are user-scoped; cross-host double-run = idempotent DELETE WHERE expired. Acceptable. |
| foodking:outbox:rescue | minute | ✓ | ✓ | — | ✓ |
| foodking:outbox:monitor | minute | ✓ | ✓ | — | ✓ |
| outbox:retry-failed | hourly | ✓(10) | ✓ | — | ✓ |
| webhook:retry-failed | hourly | ✓(10) | ✓ | — | ✓ |
| CleanupStalePendingKioskOrders | 5min | ✓ | ✓ | — | ✓ |
| pos:purge-parked-orders | daily | ✓ | ✓ | 03:15 | ✓ |
| outbox:prune | daily | ✓ | ✓ | 04:00 | ✓ |
| webhook:prune | daily | ✓ | ✓ | 04:15 | ✓ |
| SloEvaluatorJob | 5min | ✓(5) | ✓ | — | ✓ |
| stock:scan-rupture | cron expr | ✓ | ✓ | configurable | ✓ |
| foodking:fiscal:retry-alloc | minute | ✓(5) | ✓ | — | ✓ |
| foodking:availability:reset-stale-quota | daily | ✓ | ✓ | 00:05 | ✓ |
| foodking:fiscal:archive | daily (inline call) | ✓ | ✓ | 02:00 | ✓ |

**Time collisions**: 02:00 archive, 03:00 backup-via-system-cron (NOT in Kernel.php — installed by Ansible site.yml L146-153), 03:15 parked-orders purge, 04:00 outbox prune, 04:15 webhook prune. **No collision** — windows are staggered.

**Log channels**: fiscal archive (L174-187) uses `Log::channel('fiscal')`. Other tasks rely on default channel.

**System cron (Ansible site.yml L139-153)**:
- `* * * * * www-data cd .../foodking && php8.2 artisan schedule:run >> /dev/null 2>&1` — schedule:run runs Kernel.php every minute. ✓
- `0 3 * * * www-data . /etc/foodking-backup.env && .../backup-foodking-daily.sh >> /var/log/foodking-backup.log 2>&1` — backup at 03:00 UTC. **GAP** see Item 9.

**Verdict**: PASS — concurrency hygiene is exemplary. `purge-expired-otps` lacks `onOneServer` but is single-host V1 (acceptable; flag for V2 multi-node).

---

## Item 9 — Secrets management  → FAIL (P0)

File: `deploy/ansible/group_vars/all.yml` (39 LOC).

**Vault refs verified — 8 secrets referenced via `{{ vault_* }}`:**
- L32 `vault_db_password`
- L33 `vault_redis_password`
- L34-36 `vault_soketi_app_id / key / secret`
- L37 `vault_fiscal_audit_secret`
- L38 `vault_fiscal_z_report_secret`
- L39 `vault_backup_alert_webhook`

**FAIL P0 — `vault.yml` does NOT exist:**
```
ls deploy/ansible/group_vars/ → all.yml  (no vault.yml)
```

README documents `ansible-vault edit group_vars/vault.yml` as owner action (Phase D Prerequisites §3), but:
- No skeleton template in repo with the 8 required keys.
- No site.yml task creates it.
- First playbook run will fail at L59 (`requirepass {{ redis_password }}` → `requirepass {{ vault_redis_password }}` → undefined variable → Ansible halts before any apt install).

**Also missing**: `site.yml` uses `{{ vault_db_password }}` at L124 (pre-migrate snapshot mysqldump command) — same undefined-variable failure mode.

**Heal**: ship `deploy/ansible/group_vars/vault.yml.example` (plain-text scaffold) with the 8 keys + placeholders + ansible-vault encryption instructions. README + Phase D handoff doc must explicitly call: `cp vault.yml.example vault.yml && ansible-vault encrypt vault.yml && ansible-vault edit vault.yml` before first run.

**Verdict**: FAIL P0 — Phase D cannot execute without this fix. Severity matches Item 4: cycle-blocking.

---

## Item 10 — Owner physical 10 actions  → NEEDS-VERIFICATION

Source: README Phase D Prerequisites + `BACKUP_RESTORE_NF525.md §6` + CONVERGENCE_FINAL §8 (referenced).

| # | Action | Status |
|---|---|---|
| 1 | POS XSS LOCK countersign | **READY but NOT YET COUNTERSIGNED.** `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (402 LOC) is comprehensive: §1 justification (13 sinks verified by grep), §2 patch scope (13 wraps + helper), §3 backend defense-in-depth (ItemRequest + CSP), §4 test plan (vitest sentinel + PHPUnit + E2E + visual mandate), §5 rollback, §6 signature block. **Owner countersign field §6.2 row 2 is EMPTY.** Blocks deploy if XSS heal is a gate. |
| 2 | AWS key rotation post-exposure | **NEEDS-VERIFICATION** — no in-repo evidence. Cloudtrail audit is pure ops history. |
| 3 | OVH VPS-1 sizing | NEEDS-VERIFICATION. Documented sizing: 2 vCPU, 4 GB RAM, €8.11/mo (`group_vars/all.yml:16`). Tuning: `innodb_buffer_pool_size=2G`, `redis_maxmemory=1.5G`, `mysql_max_connections=50`. Adequacy depends on Le Cayenne traffic (no production traffic profile in repo). 2 vCPU + numprocs=2 queue workers leaves ~0 headroom under burst — flag for owner monitoring. |
| 4 | SSH deploy user setup | NEEDS-VERIFICATION. `inventory/production.ini` declares `ansible_user=deploy` + `ansible_ssh_private_key_file=~/.ssh/foodking_deploy_ed25519`. README §Prerequisites L4 documents "passwordless sudo". No IP allowlist enforcement in playbook — assumed at OVH firewall/UFW level (UFW config at site.yml L16-19 only opens 22/80/443 to anyone; no source-IP restriction on SSH). **P1 hardening gap**: add UFW rule `from <ops-bastion-ip> to any port 22`. |
| 5 | Ansible vault password | NEEDS-VERIFICATION — no in-repo evidence. README §First-run documents `--ask-vault-pass`. Owner must store master password securely (1Password / Bitwarden). |
| 6 | .env review on VPS-1 | NEEDS-VERIFICATION. Procedure: post-deploy diff `production .env` vs `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt`. **NB**: Item 4 FAIL means the template is incomplete → diff will surface 4 missing keys (false positives) until template is healed. |
| 7 | Staging DR drill executed | NEEDS-VERIFICATION — no log artifact in repo (`reports/dr-drill/` does not exist). README §DR drill integration explicitly says "Production cron activation MUST be preceded by a successful staging DR drill". |
| 8 | Backup cron + alert script install | PARTIAL. `site.yml:146-153` installs the cron. Alert script = `BACKUP_ALERT_WEBHOOK` env var POST by `backup-foodking-daily.sh:14-21` (Item 5.1). **GAP A6-D3**: `/etc/foodking-backup.env` is referenced (site.yml:152: `. /etc/foodking-backup.env && ...`) but **never created by site.yml**. Runbook §1 (`/etc/foodking-backup.env` heredoc) documents this as one-shot owner action. If owner skips it, `bash: . /etc/foodking-backup.env: No such file` → cron line fails before script runs → no backup, no alert (because webhook is in the missing file). Cron mail will fire to root, but only root must be watching. |
| 9 | Certbot --nginx + DNS A record | NEEDS-VERIFICATION. README owner-checklist L2 says "DNS A record `lecayenne.fr` + `www.lecayenne.fr` → VPS IP". Playbook task L82-86 runs `certbot --nginx -n` unconditionally (idempotent via `creates:`). **If DNS not propagated, certbot fails → playbook halts**. Acceptable failure mode (rerun after DNS resolves). |
| 10 | Smoke E2E on production | NEEDS-VERIFICATION. Phase C baseline captures exist (per CONVERGENCE_FINAL §8 — not re-verified here). Production smoke must compare delta. |

**Verdict**: NEEDS-VERIFICATION — 3 truly verifiable items (#1, #4, #8) flagged with specific gaps. 7 items are ops-history dependent.

---

## Healing roadmap (severity-ordered)

### P0 (cycle-blocking, before first `ansible-playbook` run)

- **A6-D1** Create `deploy/ansible/group_vars/vault.yml.example` scaffold with 8 keys (`vault_db_password`, `vault_redis_password`, `vault_soketi_app_id/key/secret`, `vault_fiscal_audit_secret`, `vault_fiscal_z_report_secret`, `vault_backup_alert_webhook`). README must explicitly instruct `cp + ansible-vault encrypt` before first run.
- **A6-D2** Add 4 missing keys to `PRODUCTION_ENV_TEMPLATE.env.txt`:
  - `STRIPE_WEBHOOK_SECRET=ROTATE_from_stripe_dashboard` (critical security)
  - `CASH_MANAGER_GATE_ROUTINE_CLOSE=true` (NF525 routine-close manager-gate per Sprint H2.2)
  - `KDS_V2_DEFAULT_ENABLED=true`
  - `KIOSK_LOCALE_SWITCH_ALLOWED=false` (FR-lock)

### P1 (before production cron + realtime activation)

- **A6-D3** site.yml: add Ansible task creating `/etc/foodking-backup.env` from vault vars (or document explicit owner step in README with a verified sample heredoc).
- **A6-D4** site.yml: add Ansible task templating `soketi.json` from vault vars (`vault_soketi_app_id/key/secret`) before supervisor reload. OR rely on existing app-deploy hook + `app/soketi.json.j2` template.
- **A6-D5** Optional `/api/health/fiscal` endpoint — chain-verify on demand, rate-limited, IP-allowlisted. Improves cloud-monitoring posture but NOT a regression from current state.

### P2 (hardening — non-blocking V1)

- Schedule:run cron to log file (not /dev/null).
- UFW SSH source-IP allowlist.
- DR drill staleness alert cron.
- CSP at nginx layer for static-asset fallback.

---

## Cross-checks performed

- `wc -l` on every file in mission brief (3 size mismatches with brief: env 142→149, HealthController 215→214, group_vars 39 = exact).
- `grep -rn "Route::get('/api/health"` confirms `/health/fiscal` absent.
- `grep -rn "env('STRIPE\|env('SENANGPAY"` confirms only `STRIPE_WEBHOOK_SECRET` is env-driven (rest in business_settings DB).
- `grep -rn "onQueue("` confirms `high` is only non-default queue used → supervisor `--queue=high,default` correct.
- `ls deploy/ansible/group_vars/` confirms vault.yml absent.
- `find . -name "soketi.json*"` returns no template.

---

## Files referenced

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/deploy/ansible/site.yml` (173 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/deploy/ansible/templates/nginx-foodking.conf.j2` (106 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/deploy/ansible/templates/supervisor-foodking.conf.j2` (52 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/deploy/ansible/group_vars/all.yml` (39 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/deploy/ansible/inventory/production.ini`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/deploy/ansible/README.md`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (149 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/scripts/backup-foodking-daily.sh` (105 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/scripts/restore-foodking-from-backup.sh` (77 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/runbooks/BACKUP_RESTORE_NF525.md` (141 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/HealthController.php` (214 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Kernel.php` (207 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (402 LOC)

— A6 RED-team
