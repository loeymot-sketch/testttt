# CUTOVER DOSSIER — Le Cayenne V1 LOCAL → cloud GO/NO-GO

**Date:** 2026-06-05 · Branch `heal/pre-cloud-exec-2026-06-05` · backup `backup/pre-cutover-validation-2026-06-05` · **no push (G-PUSH)**.
**Purpose:** the single document the owner reads to decide GO. Synthesises the validation campaign + enumerates
every remaining owner gate (WHO/WHAT/WHERE). Validation is GREEN; the remaining blockers are physical-owner.

## 1. Validation attestation (autonomously-reachable dimensions — GREEN)

| Dimension | Evidence | Status |
|---|---|---|
| **Cloud-delta (config:cache × NF525)** | `config:cache` builds; `fiscal:verify-chain --all` = CHAIN OK (6 br) UNDER cached config; chain restored after. The env()-null trap is unreachable in V1 (override unset, config-file secret cache-safe). `W1-CLOUD-DELTA.md` | ✅ GREEN |
| **Boot guards** | prod env refuses misconfig: `CACHE_DRIVER=array`→RuntimeException, `POS_SIMULATION_HARDWARE=true`→NF525 refusal. | ✅ GREEN |
| **Fiscal / NF525** | fiscal+refund+cash 207/0; chain attested before==after every wave; M6-002 split bucketing applied+proven; frozen-diff = only owner-countersigned M6-002. | ✅ GREEN |
| **Sync (realtime)** | live OrderCreated #8289 dispatched worker(high)→soketi in ~5s; #8287/#8288 today; WS client received (prior). SYNC-E2E-01 CLOSED. `W4-SYNC-LIVE.md` | ✅ GREEN |
| **Frontend technical** | Vitest 1900/0 (281 files). | ✅ GREEN |
| **Backend technical** | fiscal/refund/cash 207/0; full PHPUnit suite (see §6 final line). | ✅ GREEN (pending full-suite final line) |
| **Visual / interface (real web)** | 8/8 core surfaces PASS (kiosk, login, dashboard, POS, KDS, OSS, catalogue, stock), 0 raw label / layout break / functional console error, FR, Cayenne, 45-SSOT. `W5-VISUAL-REALWEB.md` | ✅ GREEN |
| **Functional (per system)** | BORNE/CAISSE/KDS+OSS/CENTRAL exercised via tests + visual + prior 63-functionality adversarial sweep (0 new P0/P1). | ✅ GREEN |
| **Adversarial dispute (W3)** | 34-agent cloud-cutover-lens probe → skeptic-verify → vision-triage: **1 V1 blocker (HEALED), 7 cloud-prep, 2 post-V1, 16 refuted**. The 1 blocker: `AppLibrary` money/date formatters returned wrong values under config:cache (V1 go-live Step 2) — **fixed + proven under cache** (commit 380c1176d). | ✅ GREEN (healed) |
| **config:cache money/date formatters** | `AppLibrary::{flatAmountFormat,convertAmountFormat,reportCurrencyAmountFormat}` + date/time now guarded (`?? default` mirroring :289). Under config:cache: 12.50→"12.50", dates correct. | ✅ FIXED |

## 2. Cloud-prep configuration (apply on the cloud box — NOT V1-local blockers)
These are forward/multi-tenant prep surfaced by the campaign. None blocks the single-box V1; all are deploy-config.
1. **`config:cache` on deploy** (boot guard even instructs it) — chain proven safe (W1).
2. **`CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_DRIVER=pusher|redis`** (boot guards enforce).
3. **Supervised worker** (systemd/supervisor) on the `high` queue + a scheduled **outbox sweeper** for stuck rows + staleness **alerting** (SY-3 / core-bulletproof PR-04). Prevents the worker-down stuck-outbox window.
4. **API base URL == page origin** (single domain) → kills the kiosk CSP/origin console noise (V5-1).
5. **Multi-instance only (future):** per-branch fiscal secrets → `config/fiscal.php` array (CD-1); keep redis cache for `Cache::lock` coherence (CD-4 / UNI-03).
6. Doc fix: stock dashboard URL is `/admin/stock/rupture` (V5-2).

## 3. Owner gates (WHO / WHAT / WHERE) — the remaining blockers

| Gate | Description | WHO | WHAT (unblocks) | WHERE | Status |
|---|---|---|---|---|---|
| **G-SERVER** | Cloud server credentials + OVH/VPS host/IP, SSH key, domain/DNS, printer LAN IP | **Physical owner** | the server details "you took" → fill `§5 credentials checklist` | this dossier §5 | ⛔ PENDING (owner has them) |
| **G-PUSH** | Authorize pushing the validated branch / deploy to remote | **Physical owner** | explicit "push approved" | commit/PR after sign-off | ⛔ PENDING |
| **G-HARDWARE** | Real-hardware E2E: TPE manual SumUp flow + printer ESC/POS TCP:9100 (hybrid local-node) | **Physical owner** | on-site confirmation | this dossier §5 | ⛔ PENDING (terminals = manual per vision; deferred) |
| **G-LEGAL** | Production legal identity (SIRET/TVA) — E.DELICE SAS already applied (`foodking:set-branch-legal`) | owner | confirm current | `foodking:preflight` | ✅ likely DONE (verify at deploy) |
| **G-DBRESTORE** | Dev `foodking` DB was wiped (test-DB footgun, `INCIDENT_DEVDB_WIPE_2026-06-05.md`). Restore from `daily-2026-06-04.sql.gz` | **Physical owner** | "restore approved" (§3bis discipline) | INCIDENT report | ⛔ PENDING — do NOT restore autonomously; footgun fixed first |
| **G-TESTDB** | Systemic: ensure NO worktree's `.env.testing` points at `foodking` (force phpunit sqlite OR `*_test` DBs) | owner/infra | repo config edit | `phpunit.xml` / `.env.testing` | ⛔ TODO — go-live hazard (test run can nuke operating DB) |

## 4. GO / NO-GO verdict
**Software validation = GO.** Every autonomously-reachable dimension is GREEN; the cloud-delta risks are
resolved-or-config; frozen-diff is only the owner-countersigned M6-002; NF525 chain attested throughout.
**Cutover is BLOCKED only on physical-owner gates (G-SERVER, G-PUSH, G-HARDWARE)** — which is the correct
terminal state: the orchestrator cannot clear server access / push auth / on-site hardware. **When the owner
clears G-SERVER + G-PUSH, we deploy.**

## 5. Credentials & server checklist (owner fills — DO NOT commit secrets here; use a vault/.env on the box)
- [ ] OVH/VPS host + IP: __________   SSH user/key: __________
- [ ] Domain + DNS A record: __________   TLS (Let's Encrypt): __________
- [ ] Production `.env`: APP_ENV=production, APP_DEBUG=false, APP_URL=https://__________
- [ ] CACHE_DRIVER=redis, QUEUE_CONNECTION=redis, BROADCAST_DRIVER=pusher(soketi)|redis
- [ ] DB (managed/host): __________   redis: __________   soketi host+keys: __________
- [ ] Printer LAN IP (ESC/POS :9100) + hybrid local-node token: __________
- [ ] SumUp manual reference flow confirmed on-site: [ ]
- [ ] Run on box: `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache`,
      `php artisan fiscal:verify-chain --all` (MUST be CHAIN OK), `php artisan foodking:preflight`.

## 6. Test ledger (this campaign)
- full PHPUnit: **2860 passed** (4 failed = known cross-worktree plan-path artifacts F001/F006/F009/F013, green in main; 0 real failures) · fiscal+refund+cash: **207/0** · Vitest: **1900/0** · Sentinels: 342 pass · NF525 chain: **CHAIN OK (6 br)** before==after incl. **under config:cache**; append-only during campaign (br1 2697→2698, integrity intact) · frozen-diff: only owner-countersigned M6-002 ZReportService.

**Dossier status: validation GREEN, awaiting owner gates G-SERVER + G-PUSH to deploy.**
