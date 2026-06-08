# DELIVERY RUNBOOK — owner gates to take this branch to production
**Branch:** `heal/pre-cloud-exec-2026-06-05` · **HEAD:** `10ae85554` · **Date:** 2026-06-08
**Status:** autonomous hardening EXHAUSTED — every confirmed P0/P1 across 5 specialized waves healed, suite green, 0 frozen-zone. Delivery is now **human-owner-only** (CLAUDE.md §10 + safety rules: push / deploy / credentials / hardware cannot be crossed by the agent).

> This is the bridge from "hardened branch" to "live in production." Each step is an owner action. The agent has prepared and verified everything up to (but not including) these steps. Detailed scripts for the legal/AWS/frozen-merge gates live in `reports/test-e2e/goal-100pct-2026-06-07/OWNER_GATE_RUNBOOK.md` (still valid); this doc adds the CURRENT branch state + the gates this campaign surfaced.

## What is being delivered (campaign delta on this branch)
Hardening waves since the last runbook (all non-frozen, tested, committed — NOT pushed):
- **Security/GDPR:** loyalty PII-enumeration class closed across all 3 siblings (`register`/`check`/`scan` IDOR), SYNC-01 shared-channel teardown.
- **Money/NF525:** POS-1-01 (CARD/TR refund no longer phantom-debits the drawer), KIOSK-3 (reconcile cross-order tx dedup), ADMIN-9 (EOD channel split nets refunds).
- **Felt-product / UI-UX:** dashboard FR localization, KDS chime+timer, OSS spinner+flash-storm, POS FR locale, kiosk dead-button recovery (KIOSK-5), KDS double-render/flicker.
- Full prior felt-product W1–W7 + falsification + sync-security waves.

Evidence: `FINAL_ABUSE_CONVERGENCE_WAVE.md`, `FALSIFICATION_SWEEP_REPORT.md`, `sync-abuse/SYNC_ABUSE_WAVE_VERDICT.md`, `ui-ux-audit/*`, BRAIN §2.

## GATE SEQUENCE (owner — in order)

### G-PUSH — push the branch  *(agent forbidden: §10 protected-branch push)*
```
# from a clean local checkout of the repo (NOT the agent worktree)
git fetch && git checkout heal/pre-cloud-exec-2026-06-05 && git pull
# ⚠ SEC-SECRET-01 FIRST: an AWS key is in git history (commit 9b1e741f4 per BRAIN).
#   Rotate it (see OWNER_GATE_RUNBOOK §AWS) BEFORE pushing to any public remote.
git push origin heal/pre-cloud-exec-2026-06-05
```

### G-DEPLOY — deploy to the live OVH box  *(agent forbidden: server access / build on box)*
On `ssh lecayenne` (per memory `project_cloud_deploy_ovh_lecayenne`):
```
cd <app-root>
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run prod          # app.js is gitignored → MUST rebuild from source
php artisan storage:link        # symlink is gitignored — required for kiosk /storage images
php artisan migrate --force     # this campaign added: orders.cash_movement_skipped_at + others
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan up
```
⚠ Do NOT `config:cache` if any NF525 `env()` runtime read is unresolved (BRAIN PR-07 / AuditLogService) — verify first.

### G-WORKER — realtime + outbox drain  *(agent forbidden: supervisor/daemon config)*
The browser-receipt realtime leg + retries depend on these RUNNING in prod:
```
# queue worker MUST include the `high` queue (DispatchDomainEventsJob is onQueue('high'))
php artisan queue:work redis --queue=high,default --tries=3   # under supervisor
# scheduler (drives foodking:outbox:rescue + cleanup crons)
* * * * * cd <app-root> && php artisan schedule:run >> /dev/null 2>&1
```
⚠ Per BRAIN PR-01: starting the scheduler will run `CleanupStalePendingKioskOrders` — triage any stale PENDING kiosk orders + confirm mail/SMS/push transports are no-op FIRST.

### G-ENV — locale config  *(agent forbidden: edit live .env on shared box)*
```
# 24h time display (code default is now H:i; live still shows AM/PM until this flips)
TIME_FORMAT="H:i"     # in the box .env, then a SINGLE config refresh
```

### G-LEGAL / G-TVA / G-PRINT-IP / G-FROZEN-MERGES (G5/G7) / G-TPE
Unchanged from `OWNER_GATE_RUNBOOK.md` — legal footer + `foodking:set-branch-legal`, 5,5% VAT item assignment, printer IP, the staged frozen-zone merges (G5 print-saga `feat/pos-printer-saga-autoprint`, G7 LOCK PricingService NULL-tax countersign), and real TPE hardware activation (per `project_tpe_hardware_activation`).

## POST-DEPLOY SMOKE (owner, ~5 min on the live box)
1. Admin login OK, dashboard 0 JS error, toasts in French, times 24h.
2. One kiosk order → KDS card appears (proves worker+soketi); bump → board doesn't collapse.
3. One POS cash sale → receipt has NF525 block (SIRET/TVA/operator); Z close `php artisan fiscal:verify-chain --all` → CHAIN OK.
4. A CARD counter sale refund → drawer reconciliation shows NO phantom variance (POS-1-01).

## ⇒ The agent cannot perform any G-* step. They are the actual "ready for production" gate. Once the owner crosses them, the project is delivered.
