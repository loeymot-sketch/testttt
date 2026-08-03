# GO-LIVE RUNBOOK — FoodKing V1 LOCAL Le Cayenne

> **Purpose.** The single ordered cutover checklist to take the box from
> *development state* (synthetic data, `APP_ENV=local`, dormant scheduler,
> hand-launched daemons, leaked secrets in git history) to *production-live*
> for the real Le Cayenne restaurant on the real Mac box.
>
> **Authored** 2026-06-04 (OPS-3 supervisor campaign). Sourced from the
> deep-review cutover sequence. Deploy/docs artifact only — executing the
> steps below is an **owner decision**, gated step-by-step.
>
> ⚠️ **READ THIS FIRST.** Two steps are **one-way doors** (irreversible):
> **Step 6** (`migrate:fresh --seed`) and the **git-history scrub inside
> Step 4**. Everything else is reversible. Do not run the steps out of order
> — Step 6 in particular is *the* point of no return and **must be
> coordinated with the active parallel session** (it wipes the shared DB).

---

## Legend

| Tag | Meaning |
|-----|---------|
| 👤 **PHYSICAL** | Owner must do this on-site / by hand (judgement, hardware, or destructive coordination). |
| 🤖 **AUTOMATABLE** | A script / artifact in this repo can perform it (still owner-triggered). |
| ↩️ **REVERSIBLE** | Can be undone with a documented inverse command. |
| 🚫 **ONE-WAY DOOR** | Irreversible. No undo. Coordinate before running. |

---

## Pre-flight (do NOT skip)

- Confirm **no live service traffic** (restaurant closed / pre-open window).
- Confirm the **active parallel session** (encaissement) is paused/aware — Steps 5
  and **6 share the DB** with it.
- Confirm the real acquirer / TPE contract status (SG Sogemonétique / Worldline /
  Viva). Step 2's production boot guard depends on `POS_SIMULATION_HARDWARE=false`,
  which is only safe once a **real terminal** is wired. If the TPE is still
  simulated, **go-live to `APP_ENV=production` is blocked** (see Step 2 precondition).

---

## Step 1 — Backup + verify-restore  ↩️ REVERSIBLE  ·  🤖 AUTOMATABLE

Capture a known-good full DB snapshot and **prove it restores** before any
destructive step touches data. NF525 requires backups be *proven* restorable, not
just created.

```bash
# Take a fresh full backup (all retention tiers, NF525 triggers preserved):
/opt/homebrew/opt/php@8.2/bin/php artisan foodking:backup-daily

# Verify-restore into a scratch DB + re-walk the fiscal HMAC chain
# (skeleton: scripts/deploy/CRONTAB_PROD.md §6.2 lecayenne-restore-drill):
LATEST=$(ls -1t storage/backups/db-daily/*.sql.gz | head -n1)
mysql -u root -e "CREATE DATABASE lecayenne_golive_drill;"
gunzip -c "$LATEST" | mysql -u root lecayenne_golive_drill
DB_DATABASE=lecayenne_golive_drill /opt/homebrew/opt/php@8.2/bin/php artisan fiscal:verify-chain --branch=1
mysql -u root -e "DROP DATABASE lecayenne_golive_drill;"   # scratch only
```

- **Gate:** `verify-chain` must print `CHAIN OK`. If not → **STOP**, do not proceed.
- **Reversible because:** read + dump only; nothing on the live DB is mutated. The
  scratch DB is dropped at the end.

---

## Step 2 — Flip `APP_ENV=production` + `config:cache`  ↩️ REVERSIBLE  ·  👤 PHYSICAL (env edit)

Switch the app out of dev mode and cache the compiled config.

> 🛑 **HARD PRECONDITION — this step bricks boot if skipped.**
> `app/Providers/AppServiceProvider.php` **REFUSES TO BOOT in production** unless
> ALL of these hold (NF525 boot guards):
> - `POS_SIMULATION_HARDWARE=false`  (currently simulated — needs **real TPE wired** first)
> - `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`
> - `APP_DEBUG=false`  (already false ✔)
> - `APP_URL` set to the **real domain** (currently `http://localhost:8000` — must change)
> - `CACHE_DRIVER` not in `array`/`null`  (currently `redis` ✔)
>
> If the real terminal is not yet wired, **do not flip to production** — the box
> will throw `RuntimeException` at boot. This is by design, not a bug.

```bash
# Edit .env (👤 by hand): APP_ENV=production, APP_URL=https://<real-domain>,
#                          POS_SIMULATION_HARDWARE=false, IDEMPOTENCY_MIDDLEWARE_ENABLED=true
/opt/homebrew/opt/php@8.2/bin/php artisan config:cache
/opt/homebrew/opt/php@8.2/bin/php artisan route:cache
/opt/homebrew/opt/php@8.2/bin/php artisan view:cache
```

- **Reversible because:** `php artisan config:clear && route:clear && view:clear`
  drops the caches; reverting `APP_ENV=local` in `.env` restores dev mode.

---

## Step 3 — Install scheduler cron + daemon auto-restart  ↩️ REVERSIBLE  ·  🤖 AUTOMATABLE

Wake the 22 dormant scheduler lanes and make the 4 daemons self-healing. Full
instructions in `deploy/local/README.md`.

```bash
# Daemons (launchd) — STOP the manual daemons first (see README §1):
deploy/local/dev-stack.sh install
deploy/local/dev-stack.sh start
deploy/local/dev-stack.sh status        # expect serve :8000, soketi :6001, redis :6379

# Scheduler (user crontab — the one line that wakes Kernel.php):
touch storage/logs/schedule.log
( crontab -l 2>/dev/null; echo '* * * * * cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && /opt/homebrew/opt/php@8.2/bin/php artisan schedule:run >> storage/logs/schedule.log 2>&1' ) | crontab -
crontab -l                               # confirm the line
/opt/homebrew/opt/php@8.2/bin/php artisan schedule:list   # expect 22 lanes
```

- **Reversible because:** `dev-stack.sh stop` unloads daemons; `crontab -e` (delete
  the line) or `crontab -r` removes the scheduler. No data touched.

---

## Step 4 — Rotate leaked secrets + scrub `.env` from git history  ⚠️ MIXED  ·  👤 PHYSICAL · 🤝 COORDINATE

Two distinct sub-actions with **different reversibility** — do not conflate them.

**4a. Rotate leaked secrets** — ↩️ forward-only/repeatable (safe to redo).
The deep-review + memory flag a past incident: `.env` with live AWS keys was
committed. Any credential that ever touched git is **burned** — rotate it at the
provider, not just in `.env`:
```bash
# Rotate at the source (AWS console, Stripe dashboard, Pusher/soketi app secret,
# DB password, APP_KEY if exposed), then update .env, then:
/opt/homebrew/opt/php@8.2/bin/php artisan config:cache
```

**4b. Scrub `.env` (and any secret blob) from git history** — 🚫 **ONE-WAY DOOR.**
History rewrite (`git filter-repo` / BFG) **rewrites every commit hash**, which
**breaks every existing clone and the parallel session's worktree**. There is no
undo once force-pushed.
```bash
# 🤝 COORDINATE FIRST — every collaborator/session must re-clone afterward.
# Example (git-filter-repo):  git filter-repo --path .env --invert-paths
# Then force-update remote (owner-gated; CLAUDE.md §3quater push discipline).
```

- **Gate:** 4a (rotation) is mandatory and safe to run anytime. 4b (scrub) is the
  one-way half — **schedule it with the parallel-session owner**; coordinate the
  re-clone window. Rotation (4a) makes the leaked keys harmless even if the scrub
  (4b) is deferred, so **4a is the security floor; 4b is hygiene**.

---

## Step 5 — Ansible REVOKE on `audit_logs` / `z_reports`  ↩️ REVERSIBLE  ·  🤖 AUTOMATABLE

Lock the NF525 fiscal tables at the DB-grant level so even a `TRUNCATE`/`DROP` is
refused (defence-in-depth above the `BEFORE DELETE` triggers). The exact REVOKE
statements already exist as `deploy/ansible/site.yml` task **CVP0-1** (covers
`audit_logs`, `z_reports`, `cash_movements`, `cash_drawer_sessions`,
`order_payments`, `domain_events`, `webhook_events`).

```sql
-- Run as the DB superuser against the production DB (mirror of site.yml CVP0-1):
REVOKE DROP, ALTER ON `<db>`.`audit_logs` FROM `<app_user>`@`%`;
REVOKE DROP, ALTER ON `<db>`.`z_reports`  FROM `<app_user>`@`%`;
-- (+ the other 5 fiscal tables — see deploy/ansible/site.yml task CVP0-1)
FLUSH PRIVILEGES;
```

- **Reversible because:** a `GRANT DROP, ALTER ON … TO …` restores the privilege if
  ever needed. No rows touched.

---

## Step 6 — `migrate:fresh --seed`  🚫 **ONE-WAY DOOR**  ·  👤 PHYSICAL · 🤝 MUST COORDINATE

**THE point of no return.** This **drops every table and re-creates it empty**, then
seeds the canonical V1 catalog. It **permanently wipes all current order data**:
measured **3443 orders** on this box right now (`Order::withoutGlobalScopes()->count()`
@ 2026-06-04). The deep-review describes these as ~synthetic + ~phantom test orders
(the often-cited "3430 synthetic + 1252 phantom" split is the deep-review's
characterisation — **not independently re-verified here**; the only number this
runbook attests is the measured total **3443**).

```bash
# 🛑 IRREVERSIBLE. Re-confirm Step 1 backup exists + verified-restorable FIRST.
# 🤝 The active parallel session shares this DB — it MUST be paused and the owner
#    MUST confirm coordination before this runs. Running it mid-session corrupts
#    the other session's in-flight work.
/opt/homebrew/opt/php@8.2/bin/php artisan migrate:fresh --seed
```

- **One-way because:** there is **no rollback** — once tables are dropped, the only
  recovery is restoring the Step 1 backup (which itself loses anything written after
  the backup). Treat the Step 1 snapshot as the *sole* safety net.
- **Coordination is mandatory**, not advisory: this is the step the brief calls out
  for explicit parallel-session sign-off.

---

## Step 7 — On-site walk: real signed Z + verify-chain + printed-vs-screen  ↩️ N/A (read-only)  ·  👤 PHYSICAL

Final human acceptance on the live box. Exercise one **real** end-to-day cycle and
prove the fiscal chain is genuine and the printed ticket matches the system.

```bash
# 1. Open a Z (or rely on the 00:05 z-open safety-net lane), run a real test sale,
#    take a real payment on the real TPE, then close the day:
/opt/homebrew/opt/php@8.2/bin/php artisan fiscal:close-all-active-branches
# 2. Re-walk the signed chain end-to-end:
/opt/homebrew/opt/php@8.2/bin/php artisan fiscal:verify-chain --branch=1   # expect CHAIN OK
```
- 👤 **Print the Z report and compare it line-by-line to the on-screen Z** (totals,
  fiscal sequence number, HMAC/signature footer). Mismatch → **STOP, do not open to
  customers**, escalate.
- 👤 Walk every surface live: Kiosk order → Caisse encaissement → KDS bump → OSS
  status. Confirm realtime sync (soketi) propagates within ~1s.
- **Read-only:** this step verifies; it does not mutate config. The test sale itself
  is real fiscal data and stays in the chain (by design — it is a genuine Z).

---

## One-way-door summary (memorise these two)

| Step | Action | Why irreversible | Mitigation |
|------|--------|------------------|------------|
| **6** | `migrate:fresh --seed` | Drops + recreates every table; 3443 orders gone | Step 1 verified backup is the *only* recovery; coordinate with parallel session |
| **4b** | git-history `.env` scrub | Rewrites all commit hashes; breaks every clone | Coordinate re-clone window; do 4a (rotate) first so leaked keys are already dead |

Everything else (Steps 1, 2, 3, 4a, 5, 7) is reversible or read-only.

---

## Reversibility / ownership matrix

| Step | What | Owner-physical | Automatable | Reversible |
|------|------|:-:|:-:|:-:|
| 1 | Backup + verify-restore | | ✅ | ✅ |
| 2 | Flip APP_ENV=production + config:cache | ✅ (env edit) | partial | ✅ |
| 3 | Scheduler cron + daemon auto-restart | | ✅ | ✅ |
| 4a | Rotate leaked secrets | ✅ | partial | ✅ (forward-only) |
| 4b | Scrub `.env` from git history | ✅ | partial | 🚫 **one-way** |
| 5 | Ansible REVOKE fiscal tables | | ✅ | ✅ (GRANT) |
| 6 | `migrate:fresh --seed` | ✅ | ✅ | 🚫 **one-way** |
| 7 | On-site Z + verify-chain + printed-vs-screen | ✅ | partial | n/a (read-only) |

---

*Cross-refs: `deploy/local/README.md` (Steps 2–3 detail), `deploy/ansible/site.yml`
task CVP0-1 (Step 5 REVOKE), `scripts/deploy/CRONTAB_PROD.md` §6 (Step 1 restore
drill skeleton), `app/Providers/AppServiceProvider.php` (Step 2 boot guards),
`app/Console/Kernel.php` (the 22 scheduler lanes Step 3 wakes). No app code, no
Kernel edit, no frozen-zone touch in this artifact.*
