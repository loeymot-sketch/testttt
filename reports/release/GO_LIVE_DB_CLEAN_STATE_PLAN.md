# GO-LIVE DB CLEAN-STATE PLAN (GATE-DATA-1)
2026-06-09. Owner chose "prepare the reset plan" — **I do NOT run any destructive step; you execute it.**

## The problem
The operating `foodking` DB carries **~200 soak-kiosk test orders + ~2200 total test orders + 146 aged SLA alerts + ~20 soak/stress staff accounts**. Go-live needs a clean operational state (real menu, 0 fake orders, accurate dashboards).

## ⚠️ The NF525 constraint (why you can't just "DELETE the test orders")
The fiscal chain (`audit_logs` HMAC chain + `z_reports`) is **append-only, legally retained 6 years, and DB-trigger-protected against DELETE**. The test orders have fiscal entries woven into that chain. **You cannot delete the orders without either orphaning the chain or violating NF525.** So a "clean-state" is NOT a DELETE of the dev data — it's one of:

### ✅ RECOMMENDED — Option A: FRESH production database (clean fiscal chain from sequence 1)
The NF525-correct go-live: the real business starts a **NEW fiscal chain**. Don't clean the dev DB — deploy a fresh one.
1. On the production box, create a NEW empty DB (e.g. `foodking_prod`).
2. Run migrations + seed ONLY: real menu (45 Le Cayenne items), categories, branch legal data (E.DELICE SAS — SIRET 10417050100019 / TVA FR19104170501), real staff accounts (reset the ~20 soak/stress test accounts to real employees), tax/settings.
3. **Do NOT import any orders / audit_logs / z_reports** — the production fiscal chain starts at `fiscal_sequence_no = 1` with a fresh genesis hash. This is the legally-correct "première mise en service".
4. Point `.env` `DB_DATABASE=foodking_prod`. Keep the old `foodking` (dev/soak) as an archived dump for reference (NOT served).
5. Verify: `php artisan fiscal:verify-chain --all` GREEN on the fresh chain; dashboard shows 0 orders / CA 0,00 €; catalogue 45 items.

### Option B: keep `foodking`, archive + filter (NOT recommended — messier, NF525-fragile)
Only if you must preserve the existing DB. Mark test orders with a flag + filter them from dashboards/reports. Does NOT remove them from the fiscal chain (they remain, legally). Dashboards need a `WHERE is_test = 0` everywhere — error-prone. Avoid unless there's a hard reason.

## What I prepared (ready for you to run on the production box)
- **The seed command exists:** `php artisan foodking:set-branch-legal` (sets SIRET/TVA — verified in prior cloud cutover). Real-menu seeders exist (`MenuHealLight*`).
- **Staff reset:** the ~20 soak/stress accounts (`soak-cashier-*@soak.local`, `stress-cashier-*@stress.local`) must be replaced with real employees — a manual data step (you provide the real staff list).
- **`.env` for production** already needs the go-live values (incl. the `TIME_FORMAT=H:i` one-liner from this session's FR-locale work, `APP_ENV=production`, `POS_SIMULATION_HARDWARE=false` once the TPE is wired).

## The destructive/authorize step that is YOURS (CLAUDE.md §10)
- Creating/switching the production DB + deciding the archive disposition of the current `foodking` data = **your call** (production data lifecycle + NF525 retention). I will **execute the non-destructive prep** (fresh DB migrate + seed real menu + legal + settings on a target you name) on your go-ahead, but I will **not** drop/overwrite the operating DB.

## One-line recommendation
**Go-live on a FRESH `foodking_prod` DB (Option A) — clean fiscal chain from sequence 1, real menu + legal + staff, archive the soak DB. NF525-correct, and the dashboards are accurate by construction.**
