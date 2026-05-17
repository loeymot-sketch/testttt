# Agent 4 — SRE / Production-Readiness Audit
**Audit date:** 2026-05-16
**Target:** Le Cayenne (first restaurant) go-live
**Auditor lens:** Can a real restaurant, run by a non-senior-dev owner, survive opening day on this system?
**Method:** Read-only inspection of CI, scheduler, jobs, runbooks, env, deploy docs, health endpoints, dependencies.

---

## TL;DR scores

| Dimension | Score | Verdict |
|---|---|---|
| **Production-readiness** | **38 / 100** | NO-GO for opening day. Plumbing exists, hardening does not. |
| **Operational simplicity (non-senior-dev owner)** | **22 / 100** | Owner cannot run incident response alone. Every runbook is `DRAFT_SKELETON_NOT_SIGNED`. |

The system has thoughtful primitives (outbox pattern, fiscal HMAC chain, preflight command, /health endpoints, scheduler with `onOneServer()`, dedicated fiscal log channel with 400-day retention) but they sit on top of an unrotated leaked AWS key, a known phpspreadsheet CVE, zero automated backups, draft-only runbooks, and no deploy script. Score is dragged down by the **gap between "files exist" and "scheduled + tested + operable by owner."**

---

## 1. CI/CD pipeline

### What exists
5 workflows in `.github/workflows/`:
- `phpunit.yml` (lines 33-172) — Full PHPUnit suite against MySQL 8.0 + Redis; invariants grep gate; migration drift check (lines 129-140); memory JSONL manifest check.
- `vitest.yml` (lines 11-34) — Vitest + POS lint guards (`pos:lint:status`, `pos:lint:pricing`).
- `playwright.yml` (lines 33-213) — **OPT-IN ONLY** via `e2e-required` label (lines 37-40).
- `ci-sync-rupture-harness.yml` (lines 27-46) — Real Pusher + soketi outbox harness, **only triggers on a path-restricted whitelist** (8 specific files in `paths:`).
- `legacy-guards.yml` — Banner / imports / routes / bundle scan on FE changes.

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **No SAST / security scanner in CI.** Zero PHPStan, Psalm, snyk, Trivy, dependency-review, secret-scanner workflow. The leaked AWS key in commit `a4a88df06` would have been caught by any of these. | `.github/workflows/` contains only 5 yml files, none security-scanning. |
| **P0** | **Playwright E2E ships off by default.** Most PRs merge with **zero browser-level verification**. Comment at `playwright.yml:6-11` admits "5+ runs consécutifs en échec total" → owner disabled the gate, never re-enabled it. | `playwright.yml:37-40` requires `e2e-required` PR label. |
| **P1** | **Node version drift.** `playwright.yml:82` pins Node 18 while `vitest.yml:20` + `ci-sync-rupture-harness.yml:119` use Node 20. CI may pass on one and fail on the other. | grep `node-version` in workflows. |
| **P1** | **No CD pipeline.** No deploy workflow, no canary script invocation from CI, no release tagging step. `bin/` has only two graphiti scripts. | `ls bin/` → `graphiti-ingest.sh`, `graphiti-p0-long-drain.sh`. |
| **P1** | Outbox harness only triggers on 8 whitelisted paths (`ci-sync-rupture-harness.yml:30-43`). Any indirect break (e.g., model factory change) goes uncaught. | `paths:` block. |
| **P2** | PHP version pinned to 8.2 in 3 workflows, but `composer.json:11` accepts `^8.1.0`. Owner laptop may run different version than CI. | composer.json:11. |

---

## 2. Deployment

### What exists
- `docs/DEPLOYMENT_GUIDE_V1.md` — Markdown checklist (PHP+ext, MySQL, Node, Composer, env, `composer install --no-dev`, `npm run prod`, `php artisan optimize`).
- `app/Console/Commands/PreflightProductionCommand.php` — Solid 15-point pre-deploy gate (APP_ENV, APP_DEBUG, APP_KEY, timezone, cache driver, queue connection, broadcast driver, session driver, log level/channel, ops commands present, fiscal secrets length, chain verify flag, DB reachable, cache reachable). Lines 38-303.

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **No deploy script.** `bin/` is empty save for graphiti utilities. No `deploy.sh`, no `release.sh`, no symlink-flip script, no `php artisan migrate --force` automation, no `optimize:clear` step automation. Owner deploys by SSHing in and pasting from `DEPLOYMENT_GUIDE_V1.md` — error-prone, no audit trail. | `ls bin/`. |
| **P0** | **No rollback procedure.** Guide describes forward deploy only; `RUNBOOK_ROLLBACK_CANARY_2026-04-25.md` is `DRAFT_SKELETON_NOT_SIGNED`. No tested rollback drill. | grep "Status: DRAFT" reports/runbooks. |
| **P1** | **No secrets manager.** `.env` is hand-edited locally (`.env` is gitignored per commit `1e0611aeb`), no Vault/SSM injection, no rotation cadence. | `ls .env*`. |
| **P1** | Deploy guide hardcodes PHP 8.1 OR 8.2 ("Version `^8.1.0`") while CI pins 8.2. Mismatched → "works on staging" trap. | `docs/DEPLOYMENT_GUIDE_V1.md` line 13. |

---

## 3. Backups

| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **No automated backup.** `storage/backups/` contains human-named pre-mutation dumps (`menu-heal-v2-2026-05-14`, `ultra-review-heal-2026-05-16`, `menu-v3-2026-05-14`, `ultra-goal-2026-05-13`, `menu-reset-2026-05-13`) — these are MANUAL snapshots before risky operations, not operational backups. No daily rotation, no off-host copy, no retention policy. | `ls storage/backups/`. |
| **P0** | **No restore drill documented.** Zero runbook with "step 1: locate latest dump, step 2: `mysql -u... < dump.sql`, step 3: replay outbox since timestamp X." Owner cannot recover from "I accidentally dropped the orders table". | grep -r "RESTORE\|mysqldump" docs/ reports/runbooks/ → no operational recipe. |
| **P0** | **Backups not encrypted, not off-site.** All `.sql` files sit on the same filesystem as the database. If host loses disk, both DB and backups disappear together. | `find storage/backups -name "*.sql"`. |
| **P1** | NF525 requires 6-year fiscal-data retention. `FiscalArchiveCommand` runs daily at 02:00 (`Kernel.php:120-153`), but no cron exists that pushes the archive bundle to immutable storage (S3 with object-lock or equivalent). Local-disk archives are NOT 6-year durable. | `config/logging.php:182-188` says 400-day retention on `fiscal.log` channel "fits inside offline archive pipeline" but no such pipeline is wired. |

---

## 4. Monitoring

### What exists
- `/api/health` (full, IP-restricted), `/api/health/live`, `/api/health/ready` — `HealthController.php:13-213`.
- `/ready` probes DB, Redis, **outbox staleness** (>10 events older than 30s → 503; lines 143-168), and broadcast config sanity in production (lines 181-213). This is genuinely well thought out.
- `MonitorOutboxStaleness` command (`MonitorOutboxStaleness.php:29-86`) — scheduled every minute (`Kernel.php:49-52`).
- `SloEvaluatorJob` runs every 5 minutes (`Kernel.php:81-84`).
- Dedicated log channels: `security` (90d), `observability` (90d JSON), `fiscal` (400d), `hardware` (30d) — `config/logging.php:121-188`.

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **Alerts are Log::error only.** No pager, no Slack webhook, no Sentry. `MonitorOutboxStaleness.php:80` calls `Log::error(...)` and exits non-zero — that only fires an alert if a supervisor (Sentry/Logtail/etc.) is wired up. Owner has not wired any. The `slack` channel exists in `config/logging.php:74-79` but `LOG_SLACK_WEBHOOK_URL` is not set in `.env`. | grep `LOG_SLACK_WEBHOOK_URL` .env → not present. |
| **P0** | **No external uptime monitor.** No mention of UptimeRobot, BetterUptime, or equivalent pinging `/health/live` from outside. If the host goes silent at 22:00 during dinner rush, owner finds out from a customer complaint. | No config or doc for external monitoring. |
| **P1** | `/ready` outbox-staleness threshold (`HealthController.php:151`) is hard-coded to 10 events / 30s. Per-restaurant tuning impossible without code change. | line 151. |
| **P1** | No log aggregation: `production_json` channel writes to local disk only (`config/logging.php:148-156`). No Loki/Logtail/CloudWatch shipper. Forensic search after an incident = SSH + grep. | line 152. |

---

## 5. Offline mode

### What exists
- Kiosk has a real offline queue: `resources/js/helpers/kioskOfflineQueue.js` (referenced from runbook lines 17-18 — file size suggests ~338 lines).
- `KioskOfflineConflictModalComponent.vue` and `KioskWaitingComponent.vue` handle re-sync UI.
- Idempotency middleware exists (CLAUDE.md §9 — `IdempotencyKeyMiddleware`).

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **POS has NO offline path.** `public/js/pos-wizard.js` does not reference `navigator.onLine`, no localStorage outbox, no replay logic. POS at counter goes down → no orders, no cash trail, no fallback. For a restaurant, this is the most likely failure mode. | grep `navigator.onLine` in `public/js/pos-wizard.js` → 0 matches. |
| **P0** | **Offline-paid kiosk orders blocked by design** but no operational fallback documented. `RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md` line 16: "CB/TR offline tenté alors que gate V1 refuse tout contournement serveur." When the network drops, customer can browse but cannot pay → kiosk becomes a brochure. No "fall back to cashier" instruction. | runbook line 16 + status DRAFT_SKELETON_NOT_SIGNED. |
| **P1** | Outbox depth visible at `/health/ready` but not exposed to KDS/OSS UI. Staff don't know when sync is broken. | HealthController.php:143-168. |

---

## 6. Queue & jobs

### What exists
- Scheduler is **wired** via Laravel 9 default `app/Console/Kernel.php:schedule()` — lines 21-154.
- Critical schedules: `outbox:rescue` every minute, `outbox:monitor --threshold=10` every minute, `outbox:retry-failed --since=24h` hourly, `CleanupStalePendingKioskOrders` every 5 min, `pos:purge-parked-orders` daily 03:15, `stock:scan-rupture` cron-configurable, `fiscal:retry-alloc` every minute, `availability:reset-stale-quota` daily 00:05, `fiscal:archive` daily 02:00.
- All use `withoutOverlapping()` + `onOneServer()` — good multi-node hygiene.
- `config/horizon.php` exists (the preflight check at `PreflightProductionCommand.php:204-208` even warns if it's missing).
- 4 Jobs: `DispatchDomainEventsJob`, `CleanupStalePendingKioskOrders`, `SendFcmNotificationJob`, `SloEvaluatorJob`.

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **Default queue driver is `sync`.** `config/queue.php:16`: `'default' => env('QUEUE_CONNECTION', 'sync')`. Local `.env` line 20: `QUEUE_CONNECTION=sync`. **Outbox pattern silently degrades** — `DispatchDomainEventsJob` becomes synchronous, blocking the HTTP request. In production this must be `redis` or `database`. Preflight catches it (`PreflightProductionCommand.php:114-122`) but the owner doesn't run preflight before every restart. | config/queue.php:16, .env:20. |
| **P1** | **No supervisor / systemd unit for `queue:work` shipped.** No `bin/queue-worker.service`, no `supervisor.conf` in repo. Owner is expected to set this up by hand from `docs/REALTIME_SETUP.md`. | `ls bin/` + grep "supervisor" docs/. |
| **P1** | `OutboxRescueCommand` only re-queues rows with `attempts < 5` (line 19). Rows that hit attempts=5 stay pending forever unless `outbox:retry-failed` (scheduled hourly, `Kernel.php:63-68`) catches them within 24h. Past 24h, they go to manual triage — no documented recipe. | OutboxRescueCommand.php:19 + Kernel.php:63-68 `--since=24h`. |
| **P1** | `database-uuids` failed jobs driver (`config/queue.php:88`) — good — but no `php artisan queue:retry` cron, no failed-job alerting. | config/queue.php:87-91. |

---

## 7. Disaster recovery

### What exists
- 10 runbook files in `reports/runbooks/` covering fiscal-sequence-break, kiosk-network-loss, KDS-multiscreen-desync, dispatch-queue-saturated, outbox-blocked, printer-failure, TPE-failure, rollback-canary, post-launch-observability, and an index.
- A "freeze caisse + escalate L4" doctrine is consistent across them.

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P0** | **ALL 10 runbooks are `DRAFT_SKELETON_NOT_SIGNED`.** Header line 3 of each: `Status: DRAFT_SKELETON_NOT_SIGNED`. They explicitly say "Owner (DRAFT): NF525-QA" and `## 3. Diagnostic step-by-step` items often resolve to "Observation incident; aucune commande dédiée." (RUNBOOK_FISCAL_SEQUENCE_BREAK line 42, 47, 60, 65, 72, 91, 95). A runbook with no commands cannot be executed by a non-senior-dev owner under pressure. | grep "DRAFT_SKELETON" reports/runbooks/*. |
| **P0** | **Fiscal-sequence break runbook explicitly refuses recovery.** Line 110: "Freeze caisse de la branche + escalade L4 NF525 immédiate; aucune tentative de patch séquence." Owner has no L4 NF525 contact configured. If the sequence breaks mid-service, owner has **no path forward** and must shut down. | runbook line 110-115. |
| **P1** | No "lost orders" runbook covering "kiosk submitted, server never received, customer paid in cash" reconciliation. | grep -L "perdu\|lost\|orphan" reports/runbooks/. |

---

## 8. Restart / reboot recovery

| Severity | Finding | Evidence |
|---|---|---|
| **P1** | **In-flight payments not explicitly idempotent across restart.** `PendingPaymentConfirmation` model exists (CLAUDE.md §9), but no documented "on boot, scan pending payment confirmations older than X and reconcile" command. | grep `pending_payment_confirmation` Kernel.php → not scheduled. |
| **P1** | After restart, the scheduled `outbox:rescue` will pick up stuck events within 60s — good. But no `php artisan up` automation: if put in maintenance (`php artisan down`) and host reboots, application stays down silently. | grep "artisan up\|artisan down" bin/ scripts/. |
| **P2** | `installed` sentinel file (`storage/installed`) lazy-checked by middleware — if it disappears on a restore-from-backup, the app redirects to `/install` and dies. Mentioned in `playwright.yml:112` as a known gotcha. | playwright.yml:112. |

---

## 9. Logs

### What exists
- Structured channels by domain (`fiscal` 400d, `security` 90d, `observability` 90d JSON, `hardware` 30d).
- `App\Logging\JsonFormatter` for SIEM-friendly output (`config/logging.php:154, 168`).
- Default channel = `stack` → `single` driver → unrotated `laravel.log` (`config/logging.php:54-64`) — preflight warns about this (`PreflightProductionCommand.php:174-182`).

### Gaps
| Severity | Finding | Evidence |
|---|---|---|
| **P1** | **No PII redaction layer.** No `Log::filter`, no `pii_scrubber`, no `Monolog\Processor`. Customer phone numbers, emails, addresses pass through `Log::info` calls unchanged. NF525 + RGPD risk. | grep -r "PiiProcessor\|RedactionProcessor" app/Logging/ → not found. |
| **P1** | `LOG_LEVEL` default in `config/logging.php:63` = `debug`. In production this floods disk and exposes PII. Preflight warns (`PreflightProductionCommand.php:144-151`) but does not block. | config/logging.php:63. |
| **P2** | No log shipper — see §4 above. | — |

---

## 10. Health checks

| Severity | Finding | Evidence |
|---|---|---|
| **OK** | `/health`, `/health/live`, `/health/ready` are wired (`routes/api.php:138-140`) with proper depth (DB, Redis, queue size, broadcast config, outbox staleness). | HealthController.php:13-213. |
| **P1** | `/health/live` is just `return response('OK', 200)` (line 36) — always 200 even if PHP-FPM is in a half-dead state. Load balancer probes will not detect a wedged process. Should at least verify Laravel boot. | line 34-37. |
| **P2** | Health endpoint not on a separate route group → counts against `throttle:api` per-minute limits. Under a spike, probes themselves get 429'd. | routes/api.php:138. |

---

## 11. Incident response (owner persona check)

The owner is a non-senior-dev. Walk through "kiosk shows error 500 at 19:30 Friday":

1. **How would owner know?** No pager, no Slack alert. They'd see customer complaining or staff yelling. **Failed.**
2. **What runbook would they open?** None of the 10 are signed; all say "escalate L4". Owner has no L4. **Failed.**
3. **What commands could they run?** `php artisan app:preflight-production` would tell them config status. `tail -f storage/logs/laravel.log` if they can SSH in. That's it. **Partial.**
4. **Could they restart safely?** Yes IF they know the right sequence (php-fpm, queue:work daemon, scheduler). Nothing in repo documents the boot order. **Failed.**
5. **Could they roll back?** No deploy log, no symlink convention, no `RELEASE-` git tags. **Failed.**

**Verdict: a non-senior-dev cannot operate this system unaided.** They need a part-time DevOps contact on retainer or this becomes a 2 AM phone call to the developer every week.

---

## 12. Documentation for ops

| Severity | Finding | Evidence |
|---|---|---|
| **P0** | Runbooks are paper layer (see §7). | reports/runbooks/*. |
| **P1** | `docs/DEPLOYMENT_GUIDE_V1.md` exists but does not cover: queue worker daemonization, supervisor config, log rotation, swap config, mysqld my.cnf hardening, firewall, backup cron, certificate renewal, kiosk machine pairing recovery. | docs/DEPLOYMENT_GUIDE_V1.md. |
| **P2** | Multiple competing audit docs (`AGENTS.md`, `CLAUDE.md`, `MEMORY.md`, `PROJECT_BRAIN.md`, dozens of `plans/MASTER_*` files) — owner cannot tell which is current. | `ls *.md`. |

---

## Top findings ranked by severity

| # | Severity | Finding | Why opening day fails |
|---|---|---|---|
| F-01 | **P0** | Leaked AWS keys (commit `a4a88df06`, `.env:36-37` `AKIAYJOT77SIZHDXNYOZ`) **not rotated**. Attacker can already access S3 / SES / SQS. | Pre-existing breach. Day 0 already compromised. |
| F-02 | **P0** | `phpoffice/phpspreadsheet 1.30.0` (composer.lock:4611) — known RCE CVE. Any Excel import path = remote code execution. | Single CVE, classic exploit. |
| F-03 | **P0** | No automated backup, no tested restore, no off-host copy. `storage/backups/` are manual pre-mutation snapshots. | Disk failure or accidental DROP = total loss of 6-year fiscal data → NF525 violation = legal exposure. |
| F-04 | **P0** | All 10 runbooks `DRAFT_SKELETON_NOT_SIGNED` with placeholder "aucune commande dédiée" steps. Non-senior-dev owner cannot execute. | First production incident = service halt with no recovery path. |
| F-05 | **P0** | No deploy/rollback script. No CD pipeline. SSH-and-paste deploys. | Bad deploy at Friday 18:00 → cannot revert → service down Friday night. |
| F-06 | **P0** | No SAST/secret-scan/dependency-review in CI. Leaked AWS key and pinned-CVE phpspreadsheet both visible to any scanner. | Future regressions will land the same way. |
| F-07 | **P0** | POS has zero offline mode (`public/js/pos-wizard.js` no `navigator.onLine`). Internet drop = restaurant cannot take orders. | Common scenario, no fallback. |
| F-08 | **P0** | Alerts are `Log::error` only — no pager, no Slack webhook wired (`LOG_SLACK_WEBHOOK_URL` empty), no external uptime monitor. | Outage at 20:00 Saturday detected by customer Sunday morning. |
| F-09 | **P1** | Playwright E2E opt-in via PR label (`playwright.yml:37-40`). Most merges have zero browser validation. Comment admits the gate was disabled because it kept failing. | Regressions land silently. |
| F-10 | **P1** | NF525 fiscal archives written to local disk only. `FiscalArchiveCommand` runs daily but no S3-with-object-lock push. | 6-year retention rule violated on first disk failure. |
| F-11 | **P1** | No PII redaction in logs. Customer PII flows through default `Log::info`. RGPD risk. | French data-protection exposure on first audit. |
| F-12 | **P2** | Node version drift (18 vs 20 across workflows). PHP `^8.1.0` vs CI 8.2. | Subtle "works on staging" bugs. |

---

## Must-do checklist before Le Cayenne opens

| # | Item | Severity | Time est. |
|---|---|---|---|
| 1 | Rotate AWS access keys; revoke `AKIAYJOT77SIZHDXNYOZ` in IAM console; document rotation cadence (90d). | P0 | 1h |
| 2 | Bump `phpoffice/phpspreadsheet` to ≥ 1.30.5 or 2.x patched; rerun composer audit. | P0 | 30min |
| 3 | Write `bin/backup.sh`: nightly `mysqldump --single-transaction` + `tar` of `storage/app` + GPG encrypt + rsync to off-host (S3 with object-lock or rsync.net). | P0 | 4h |
| 4 | Write `bin/restore.sh`: tested in staging end-to-end. Document RTO + RPO in `docs/RESTORE_RUNBOOK.md`. | P0 | 4h |
| 5 | Sign at least 4 runbooks (FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY). Replace "Observation; aucune commande dédiée" with copy-pasteable `php artisan` commands. | P0 | 6h |
| 6 | Wire Slack/Discord webhook to `slack` log channel; verify alert on `MonitorOutboxStaleness` failure. | P0 | 1h |
| 7 | Provision UptimeRobot (free tier) or equivalent pinging `/health/live` every 1min; route alert to owner phone. | P0 | 30min |
| 8 | Write `bin/deploy.sh`: pull, `composer install --no-dev`, `npm ci && npm run prod`, `php artisan migrate --force`, `php artisan optimize`, atomic symlink flip, reload php-fpm. | P0 | 3h |
| 9 | Write `bin/rollback.sh`: flip symlink back, optionally `php artisan migrate:rollback`. Test on staging. | P0 | 2h |
| 10 | Add SAST job: GitHub `dependency-review-action` + `gitleaks` for secret scan + PHPStan level 5 on touched files. | P0 | 2h |
| 11 | Add `composer.json` line `phpstan analyse` script + CI job that fails on critical-zone errors. | P1 | 2h |
| 12 | Enable Playwright on every PR (remove `e2e-required` label gate at `playwright.yml:37-40`). Fix the underlying flakes first. | P1 | 1d |
| 13 | Set production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `BROADCAST_DRIVER=pusher`, `LOG_LEVEL=warning`, `LOG_CHANNEL=production_json`. Run `app:preflight-production --strict` → must exit 0. | P0 | 30min |
| 14 | Provision a supervisor / systemd unit for `php artisan queue:work --queue=high,default --tries=3 --backoff=10`. Commit unit file under `bin/systemd/`. | P0 | 1h |
| 15 | Provision a supervisor unit for `php artisan schedule:work` (or system cron entry `* * * * * cd /var/www/foodking-web && php artisan schedule:run >> /dev/null 2>&1`). | P0 | 30min |
| 16 | Add S3-with-object-lock push at end of `FiscalArchiveCommand` for 6-year retention compliance (NF525). | P0 | 3h |
| 17 | Implement PII scrubber Monolog processor (mask phone, email, address, card) and wire on `single` + `daily` + `production_json`. | P1 | 3h |
| 18 | Lock Node version everywhere to 20 LTS (or 22). Update `playwright.yml:82`. | P2 | 15min |
| 19 | Document boot order: php-fpm → mysqld → redis → queue:work → scheduler → nginx. Put in `docs/BOOT_ORDER.md`. | P1 | 1h |
| 20 | Add `php artisan up` to deploy script tail; explicit detection if app is stuck in maintenance after a reboot. | P1 | 30min |
| 21 | Document POS-down fallback: "When the network is down or server unreachable, switch to paper order pad + manual cash count + reconciliation at reopening." Train owner. | P0 | 2h |
| 22 | Add `composer audit` to CI (free, ships with Composer 2.4+). Fails build on known CVE. | P0 | 30min |
| 23 | Configure log rotation via logrotate.d (Laravel `single` channel = unrotated). Rotate `laravel.log` daily, keep 14. | P1 | 30min |
| 24 | Set up daily `php artisan queue:retry all` + `queue:failed` notification cron (or move to Horizon dashboard + auth). | P1 | 1h |
| 25 | Set `LOG_SLACK_WEBHOOK_URL` and route `LOG_LEVEL=critical` to slack channel. | P0 | 15min |
| 26 | Build a 1-page laminated owner cheatsheet: "what to do if X" (5 most likely incidents, with the 2-3 commands each). Tape to the back of the POS tablet. | P0 | 2h |
| 27 | Run a **disaster-recovery drill** in staging: drop the orders table, restore from last backup, replay outbox, verify Z report still closes correctly. Document time-to-recovery. | P0 | 4h |
| 28 | Set production `health_ips_allowed` so `/health` full report is not internet-accessible. | P1 | 15min |
| 29 | Pin firewall: only 80/443 + 22 from owner IP. UFW or AWS SG. | P0 | 30min |
| 30 | Verify the kiosk offline queue replay path end-to-end (kill server, place order at kiosk, restart server, confirm order reaches KDS and gets a fiscal sequence). | P0 | 2h |

**Total time estimate: ~58 hours of focused ops work** before a real restaurant can rely on this.

---

## Top 5 recommendations

1. **Treat Le Cayenne opening day as a soft launch, not a hard cutover.** Use lunch service (low volume, weekday) for week 1. Run the disaster-recovery drill BEFORE opening; do not skip it.
2. **Put a senior-dev on retainer for the first 90 days.** The current "non-senior-dev can operate alone" goal is incompatible with the current state of runbooks, alerting, and deploy automation. Buy time to bridge the gap.
3. **Sign 4 runbooks this week. Sign the rest before week 4.** A signed runbook with concrete commands is 100× more valuable than 10 unsigned skeletons. Start with FISCAL_SEQUENCE_BREAK (legal exposure) and KIOSK_NETWORK_LOSS (most common).
4. **Buy off-the-shelf monitoring (BetterUptime + Sentry) for the cost of one dinner.** Stop deferring the alerting layer. The system already emits the right signals (`MonitorOutboxStaleness`, `/health/ready` 503s, `Log::channel('fiscal')`) — it just needs a paid pager wired up.
5. **Rotate the leaked AWS keys today. Not tomorrow. Today.** Every hour those keys stay live is a security incident in progress, regardless of whether SOC sees it yet. Then add `gitleaks` to CI so this cannot recur.

---

## Appendix — files inspected (file:line citations used)

- `.github/workflows/phpunit.yml:33-172`
- `.github/workflows/vitest.yml:11-34`
- `.github/workflows/playwright.yml:37-40, 82, 6-11, 112`
- `.github/workflows/ci-sync-rupture-harness.yml:30-46, 119`
- `.github/workflows/legacy-guards.yml`
- `bin/` (only `graphiti-ingest.sh`, `graphiti-p0-long-drain.sh`)
- `bootstrap/app.php` (no scheduler override — defers to Console Kernel)
- `app/Console/Kernel.php:21-154` (full schedule)
- `app/Console/Commands/PreflightProductionCommand.php:30-303`
- `app/Console/Commands/MonitorOutboxStaleness.php:29-86`
- `app/Console/Commands/OutboxRescueCommand.php:9-31`
- `app/Console/Commands/` (listed: 28 commands)
- `app/Jobs/` (4 jobs: DispatchDomainEventsJob, CleanupStalePendingKioskOrders, SendFcmNotificationJob, Observability/SloEvaluatorJob)
- `app/Listeners/` (37 listeners — heavy outbox + notification fan-out)
- `app/Http/Controllers/HealthController.php:13-213`
- `config/queue.php:16, 87-91`
- `config/logging.php:54-188`
- `config/horizon.php` (exists, not inspected in depth)
- `composer.json:11, 18, 33` + `composer.lock:4610-4619` (phpspreadsheet 1.30.0)
- `package.json:50` (Node config across workflows)
- `routes/api.php:138-140`
- `reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md` (DRAFT)
- `reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md` (DRAFT)
- `reports/runbooks/` (10 runbooks, all DRAFT_SKELETON_NOT_SIGNED)
- `docs/runbooks/` (8 operational notes, separate scope from `reports/runbooks/`)
- `docs/DEPLOYMENT_GUIDE_V1.md` (manual SSH deploy)
- `storage/backups/` (5 manual pre-mutation directories, no automation)
- `.env:2, 4-6, 18, 20, 36-37, 46` (current local env)
- `.env.example:APP_ENV=local, APP_DEBUG=true` defaults
- Git commit `a4a88df06` (leaked AWS key origin, .env.backup-pre-round2 present in tree)
- Git commit `1e0611aeb` (.env now gitignored — but local copy retains leaked keys)

**End of report.**
