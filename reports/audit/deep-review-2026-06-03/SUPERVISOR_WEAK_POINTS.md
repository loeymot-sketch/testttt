# Deep Ultra-Review — Supervisor Weak-Points Synthesis (V1 LOCAL Le Cayenne)

**Date:** 2026-06-03 · **HEAD:** `d6487f716` · **Method:** 13 parallel adversarial reviewers (read-only, file:line/DB-verified, bucket-tagged) → supervisor synthesis.
**Lens (owner's brief):** weak points *selon le but & vision* = V1 is the owner's PERSONAL single-box tool to run Le Cayenne day-to-day. Ranked by **impact on the owner physically using/shipping the software**, NOT raw severity. Cloud/SaaS/scale items are labelled `[V2-SCALE]` and are explicitly **not** V1 weaknesses.

---

## SUPERVISOR VERDICT

**The proven flows are not the problem.** POS pricing/fiscal/composition, kiosk Plan-B routing, KDS bump/recall, stock/86, delivery fee, money net-realized semantics, branch isolation, RBAC — all came back **solid and grounded**. V1 *works*.

**The weak points cluster in 4 places — none of them "the software is broken", all of them "the software isn't yet an operable, observable, owner-run shop appliance":**

1. **Operability & observability of the single unattended box** (the dominant theme — 4 reviewers converged here independently). The resilience *logic* is above its weight class; the *operability* is hollow. The linchpin: **the box runs `APP_ENV=local` and the scheduler cron is not installed** — which cascades into half the findings below.
2. **The daily fiscal-close cockpit is missing** — opening/reading(X)/closing the fiscal day runs on invisible cron safety-nets; the owner has no UI to *see and run his own day*, and the physical cash-count is decoupled from the fiscal seal.
3. **A handful of real daily data/cash traps** — HIST-04 (66 orders vanish from a filter), drawer-trail holes on the primary collection path, immortal abandoned kiosk orders.
4. **Go-live cutover hygiene** — synthetic DB must be wiped, secrets scrubbed, boot-guards activated, monitoring wired.

> **Meta-insight that reframes ~40% of the list:** much of the `[GO-LIVE]` cluster IS the already-documented physical go-live sequence (G5–G8: `.env` flip, Ansible REVOKE, `migrate:fresh --seed`, on-site walk) that was always owner-action. The reviewers *verified why each step matters* and found **4 gaps the documented sequence does NOT yet cover**: disk/log-rotation, the pager-wiring no-op, the secret-in-history scrub, and the bundle↔source drift. Those 4 are the genuinely-new go-live findings.

---

## [DAILY-BLOCK] — bites the owner during daily operation (ranked, top = bites hardest)

| # | Weak point | Evidence | Fix shape |
|---|---|---|---|
| **DB-1** | **No owner-facing "close/read my fiscal day" UI.** Z open(00:01)/close(23:59) are cron *safety-nets* ("until the optional UI button ships" — Kernel's own words); X-report (mid-day read) + Z open/close have backend/CLI only, **zero Vue trigger**. Cash-drawer close (manual UI, with variance) is a *separate* act with no link to the fiscal seal. | `Kernel.php:383-432` (L422-424 comment); `grep x-report resources/js`=0; `PosCashDrawerSessionDialog.vue:161-236` has no z-report ref; `CashDrawerSessionController::close:128-147` no ZReportService call | Build an owner cockpit: "Voir le Z d'aujourd'hui (X)", "Clôturer ma journée", couple drawer-count ⇄ Z-close. **The headline vision gap.** |
| **DB-2** | **soketi/worker death is SILENT on the box.** KDS "polling mode" banner is suppressed when `APP_ENV=local` (which the box runs); every alarm `Log::error`s into a file nobody tails; the pager cron curls `/api/health` which returns **200 unconditionally even when degraded**. | `KitchenDisplaySystemComponent.vue:40,1314-1321`; `.env:2 APP_ENV=local`; `HealthController::full:13-31`; `CRONTAB_PROD.md:279-286`; `MonitorOutboxStaleness.php:129` | Flip `APP_ENV=production`; point pager at `/api/health/ready` (503 on worker-death) or make `full()` 503 on degraded; wire `LOG_CHANNEL`→slack/webhook. |
| **DB-3** | **Disk exhaustion recurs on the one box.** No log rotation (default `stack→single` unbounded `laravel.log`), `storage/debugbar` = **391M / 7,939 files** never purged, **no cleanup cron**. Box hit 100% mid-session today, killing the server + a test. | `config/logging.php:54-64`; `du storage/debugbar`=391M; `Kernel.php` grep log/debugbar cleanup = none | Scheduled log+debugbar purge; default channel→`daily`; disable debugbar in prod; disk-monitor. |
| **DB-4** | **HIST-04: "En ligne" filter silently drops 66 legacy online orders.** Badge labels NULL-surface rows "En ligne" but the filter sends `source_surface='web'` (`LIKE '%web%'`) → never matches NULL. **DB-repro: 96 NULL-surface, 0 'web', 66 badged "En ligne" but invisible under the filter.** | `HistoriqueListComponent.vue:308-311,337`; `OrderService.php:2806`; DB query | Make `online` filter match `source_surface IN(web,app,mobile) OR (NULL AND source IN(WEB,APP))` to mirror the badge. |
| **DB-5** | **Cash-drawer trail holes on the PRIMARY collection path.** Counter-collect CASH (now the main path for kiosk Plan-B + walk-in) records cash movement `strict=false` *after* the tx — **no open-session precondition** → order PAID + fiscal-seq allocated but **no `cash_movement`**. Same for cash-refund with drawer closed. End-of-day reconciliation under-counts with unexplained variance. | `PaymentService.php:442-444` + route `routes/api.php:837-845` (only `can('pos')`); `RefundWithCounterEntryService.php:262-312` | Require/auto-open a drawer session on counter-collect, or surface the skipped-movement to the cashier (not just a cron log). |
| **DB-6** | **Abandoned kiosk orders are immortal + offline orders strand the customer.** Walk-away borne taps stay `ACCEPT+PENDING_COUNTER` forever — the cleanup job is **dead code** (keyed on `PENDING`, which auto-accept pre-empts) → they pile on the cashier queue AND KDS every lunch. Offline order shows the customer `#—` to "pay at counter" where nothing exists. | `FrontendOrderService.php:206-208,266`; `CleanupStalePendingKioskOrders.php:36-37`; `kioskCart.js:762-800`, `KioskCashInstructionComponent.vue:19-20` | Fix the cleanup gate to match `PENDING_COUNTER`+age; give the cashier an abandoned-order surface; reconcile offline `#—` on sync. |
| **DB-7** | **OSS public wall is NOT live — 60s poll, zero push.** The unauth wall never subscribes to the private branch channel (`subscribeEcho` early-returns for `branchId<=0`) and uses `intervalMsWhenConnected:60_000`. Customer sees PRÉPARATION→PRÊT up to ~1 min late. | `OssSyncService.js:9`; `PreparingAndReadyComponent.vue:262-263`; `routes/channels.php:43` | Drop the unauth wall to a 2-5s cadence, or add a public presence channel. |

---

## [GO-LIVE] — must be done at production cutover (the linchpin sequence + 4 new gaps)

| # | Item | Evidence | Note |
|---|---|---|---|
| **GL-1** | **`migrate:fresh --seed` to wipe 3,443 synthetic orders + 1,254 stale PENDING_COUNTER** (`€43,366` phantom). **NOT a hand-DELETE** — 1,998 orders carry fiscal sequences; DELETE breaks the NF525 chain. | DB: `orders=3443`, stale kiosk PENDING_COUNTER=1254; seeders order-free (`DatabaseSeeder.php:115-122` commented out) — **path verified clean** | Single most concrete go-live action. Also clears the F-W5-01 "new borne order at #1255 invisible in 200-cap queue" symptom (root-caused here). |
| **GL-2** | **Install `schedule:run` cron + daemon auto-restart (launchd/supervisor).** Verified: **host has NO `schedule:run`**, only `litellm`+`neo4j` launchd jobs; **latest backup is 5 days stale**. Without it: backups, prune, fiscal close/open, log-cleanup all **dormant**; dead daemons don't recover. | `crontab -l`=none; `launchctl list`; `supervisor-*.j2` is a template for a different (Hetzner) box | The well-built scheduler/supervisor is decorative until installed on the actual machine. |
| **GL-3** | **Flip `APP_ENV=production` + `config:cache`.** All 7 production boot guards are inside `if (environment('production'))` — **they do NOT fire on the `local` box.** Also un-suppresses the KDS soketi-down banner. | `AppServiceProvider.php:158`; `.env APP_ENV=local` | One switch activates the whole guard suite (G5 in the documented sequence). |
| **GL-4 (NEW)** | **Secrets in git history.** Live `AWS_ACCESS_KEY_ID=AKIA…` + `AWS_SECRET`, `PUSHER_APP_SECRET`, `FISCAL_AUDIT_SECRET`, `FISCAL_Z_REPORT_SECRET`, `KIOSK_MACHINE_PASSWORD` retrievable at `9b1e741f4:.env`. HEAD is clean (gitignored). | `git cat-file -e 9b1e741f4:.env`=exists | **Rotate AWS + fiscal secrets now; `git filter-repo --path .env --invert-paths` before ANY remote push.** Fiscal-secret exposure is the NF525 sting. |
| **GL-5 (NEW)** | **Delivery charge enters the signed Z at 0% TVA**, and there is **no delivery feature-flag** to gate it. Inert today (0 fiscal delivery orders) but under-declares TVA on the first paid delivery. Menu is now 10% VAT. | `ZReportService.php:435-444,661-668`; `PricingService.php:351-353` | Confirm delivery VAT rate (likely 10% accessory-follows-principal); fix breakdown OR hard-gate delivery off until decided. |
| **GL-6 (NEW)** | **Pager/health wiring is a no-op + no alert sink** (see DB-2). Plus `/healthz` websocket+queue probes are fake (`BROADCAST_DRIVER!=null`; reads `jobs` table while driver=redis). | `HealthController.php:13-31`; `HealthzController.php:120-186` | Without this, every other safety net is silent. |
| **GL-7 (NEW)** | **Bundle↔source drift (root-caused).** Committed `admin-shell.js` is byte-different from its HEAD blob (6,512,777 vs 6,512,600) yet `git status` reports **clean** (index stat-cache). "What's committed isn't what ships" for JS. No CI gate rebuilds+diffs; Playwright CI rebuilds fresh so it never tests the committed artifacts. | `cmp` vs HEAD blob; `playwright.yml:114-117`; `scan-bundle-legacy.sh` | Add CI `npm run prod && git diff --exit-code public/js public/mix-manifest.json`, OR stop committing bundles + build-on-deploy. |
| **GL-8** | **`PreflightProductionCommand` is blind to the 2 things that actually broke** (disk space, scheduler-installed) and contains a **stale WARNING** claiming manual-discount/F1 is unfixed (it's fixed + enabled since 2026-05-31). | `PreflightProductionCommand.php:51-55,135-145` | Add disk + cron-installed assertions; fix the stale F1 warning. |
| **GL-9** | **ZRPT-SEM-01 owner countersign pending** (governance, low runtime risk — fix is active + sentinel green this session). | `LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING_2026-06-01.md:73-78` | Just ratify §6; Zs already close correctly. |
| **GL-10** | **6-year retention only enforced if the scheduler runs** (see GL-2) — immutable DB triggers exist, but `backup-daily`/`fiscal:archive` need the cron. | trigger migrations; `Kernel.php:144`; `site.yml:188` | Subsumed by GL-2; confirm `crontab -l` shows the lane. |

---

## [V2-SCALE] — explicitly NOT V1 weaknesses (label only, do not action for V1)

KDS active-feed cap 50 (cap-banner mitigates; 50+ concurrent unrealistic single-shop) · coupon/cash `max_uses_global` TOCTOU (single-cashier non-event) · BranchScope 10-model exemptions (single `branch_id=1` ⇒ mathematically moot) · cache UNI-03 file/db-pass-guard (running `.env`=redis, single-box-safe) · unpaginated Excel export `get()` (256ms@3443, UI paginates) · Sanctum 8h TTL (bounded by 2h proactive refresh + relogin-revoke) · static browser-shipped API key (coarse gate, real authz is Sanctum+Spatie) · Playwright `workers:1` throttle coupling · delivery-boy cash sessions IF no in-house fleet (confirm or mark dormant like dine-in).

---

## [POLISH] — confidence/cleanliness (no daily/go-live bite)

Catalogue prices render without `€` (3 surfaces, the one the owner edits most) · `flatAmountFormat` truncates cents to whole € if `CURRENCY_DECIMAL_POINT` env ever unset (latent, `.env`=2 today) · stale Kernel close/open comments (23:55 vs actual 23:59) · CLAUDE.md FormRequest-sentinel doc stale (code is tight at 66, would catch new `return true;`) · CI doesn't run phpunit/vitest on working-branch pushes (only main/PR) · e2e/visual/abuse tier opt-in (not auto-CI) + **16 abuse-e2e specs + catalogue-stock-read.spec.js UNCOMMITTED** (one `git clean` from loss) · parked-order recall non-atomic (delete-then-recreate) · reprint reachability unconfirmed (plumbing exists) · `SiteController` dead `env('DEMO')` branch.

---

## What is SOLID (calibration — so the list isn't read as "everything's broken")

- **Stock/availability + Livreur**: entirely solid — 86-toggle live-prune + commit-gate on all 4 paths, ledger integrity, delivery fee rule exact on branch 1, delivery-boy cash includes the fee.
- **POS core**: pricing/fiscal-seq/composition_snapshot **not bypassable** (simulation_hardware bypasses only drawer/TPE); discount cap properly tiered; split-payment + counter-collect race verified.
- **Money correctness**: net-realized-revenue semantic genuinely lock-stepped across dashboard/sales/items/Z for **cumulative** totals; refund netting sound both pre-Z and post-Z. (One real per-day retroactivity divergence noted as DB-adjacent.)
- **Security auth**: 0 genuinely-unguarded sensitive FormRequests (all backstopped by `permission:` middleware); RBAC sidebar filtering proven; cross-branch 403 proven; secret-scan sentinel + pre-commit hook defend HEAD.
- **NF525**: chain CHAIN OK, gap-free 1..1994, signed netting, refund counter-entry, z-membership detector — all converged.
- **KDS**: bump/recall sound, overflow chip mitigates the slice(0,8) gap.

---

## Reconcile question for the owner
Which of these do you want turned into a **heal `/goal`**? Suggested grouping:
- **(A) Daily-traps heal** (non-frozen code): HIST-04 filter, kiosk abandoned-order cleanup, drawer-trail surfacing, OSS wall live-push. ← biggest daily-quality win, low risk.
- **(B) Operability/go-live hardening**: disk/log-rotation + cleanup cron, pager/health wiring + alert sink, preflight disk/cron checks, APP_ENV guard. ← makes the box safe to run unattended.
- **(C) Daily fiscal cockpit** (new UI): X-read + close-my-day + cash⇄Z coupling. ← largest build, highest vision value.
- **(D) Go-live cutover checklist** (mostly owner-physical): migrate:fresh, secret-scrub+rotate, APP_ENV flip, scheduler install, delivery-TVA decision, bundle-drift CI gate, ZRPT countersign.
