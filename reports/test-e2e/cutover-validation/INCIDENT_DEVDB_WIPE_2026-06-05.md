# INCIDENT — dev `foodking` DB wiped by a RefreshDatabase run via `.env.testing` footgun

**Date:** 2026-06-05 · Severity: dev-data loss (NOT production — V1 not live, no real fiscal/customer data) · Status: **CONTAINED, restore pending owner gate**

## What happened
During the cutover-validation campaign, the operating dev DB `foodking` was emptied: `branches/audit_logs/orders = 0/0/0`, with all **88 tables present but empty** — the signature of `php artisan migrate:fresh` (i.e. Laravel `RefreshDatabase`). At W0 it held audit_logs=2697; the live server :8000 dashboard showed 3483 orders at W5 (~18:14); it was found empty during the AppLibrary-fix verification (~later).

## Root cause (structural — attribution not conclusively provable)
`.env.testing` set **`DB_DATABASE=foodking`** (the real operating DB), overriding `phpunit.xml`'s safe `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`. So a `php artisan test` run (RefreshDatabase) ran `migrate:fresh` against `foodking` and wiped it. This MySQL is **shared across worktrees** (this session's `pre-cloud-exec` + a sibling `massive-e2e-0604` server, pid 84294), so the destructive run could be **this session's full-suite run OR a concurrent sibling job** — unprovable from here. Note: the W5 dashboard showing 3483 orders *while* this session's suite (PID 58406) was running argues against "this session's suite wiped it at suite-start" cleanly — consistent with a sibling/other timing. **The structural cause is certain; the specific actor is not.** This is the documented `.env.testing` hazard ([[project memory]]: ".env.testing manquant" / "migrate:fresh reverts catalog").

## Containment done (in-autonomy, non-destructive)
- **Footgun fixed (this worktree):** `.env.testing` `DB_DATABASE=foodking` → **`foodking_test`** (gitignored, local). Future test runs in this worktree target a disposable DB, never the operating one.
- No more `config:cache`/`migrate`/`restore` on the shared box without owner go.
- Verified: `SHOW PROCESSLIST` = nothing actively writing (only idle event_scheduler) → safe, no concurrent corruption.

## Recovery available — but it is an OWNER GATE (do NOT restore autonomously)
Backup exists: **`storage/backups/db-daily/daily-2026-06-04.sql.gz`** (2026-06-04, 1.6 MB). Restoring is gated by CLAUDE.md §3bis (restore discipline = explicit owner confirmation) + §10 (shared-data restoration). **Order matters: fix the footgun on ALL worktrees first, THEN restore** — restoring into a DB a sibling job will re-`migrate:fresh` is wasted/risky.
- The 06-04 backup will NOT contain today's accrued dev data (the ~2698 audit_logs / today's orders) — those are lost; the chain attestations in this campaign were *true when run* (CHAIN OK on the then-present rows); the chain is not "corrupted", the dev DB is simply empty now.

## Impact on the validation campaign — NONE on the conclusions
- The full PHPUnit suite result (2860/0) is unaffected (tests are self-contained; whatever DB they used, they passed). Vitest 1900/0, the AppLibrary fix (proven under config:cache), 8/8 visual, sync cascade, cloud-delta — all stand. The W5 dashboard data was real at capture time.
- **The incident is itself cutover intelligence:** "running the test suite can nuke the operating DB" is a real go-live hazard → see dossier.

## RESOLUTION 2026-06-05 (owner approved: "fix footgun everywhere, THEN restore")
1. **Footgun fixed everywhere:**
   - Tracked **DEVDB-GUARD** added (`tests/CreatesApplication.php`, commit ce15…/this branch): aborts any test run at app-boot (before `migrate:fresh`) unless the DB is `:memory:` sqlite or a `*test*` name. **Empirically proven: BLOCKS `foodking`, ALLOWS `foodking_test`.** Bypass `ALLOW_NON_TEST_DB=1`.
   - All 4 worktrees that pointed `.env.testing` at `foodking` repointed → `foodking_test` (parent repo, `massive-e2e-0604`, `printer-saga-pos`, `pre-cloud-exec`).
2. **Restored** `foodking` from `daily-2026-06-04.sql.gz` (exit 0): **branches=1, orders=3443, audit_logs=2556, items=59**. **NF525 chain re-attested: CHAIN OK (branch 1).** Server :8000 healthy (db/redis/queue ok).
3. **Forward-migrated:** the 06-04 backup predated `add_cash_movement_skipped_at_to_orders` (06-05). Ran `php artisan migrate --force` (additive, NOT migrate:fresh) → column added, **data intact (orders=3443, audit_logs=2556), chain still CHAIN OK**.
4. **Visual re-validation:** logged in on restored data → dashboard renders clean: **Total ventes 31 773,90 €, commandes 3429, articles menu 45 (canonical SSOT)**. (Earlier "59 active items" was a raw `status=5` count including addon/variation sub-items — NOT a stale catalogue; the user-facing menu metric is the correct 45.)
5. **Residual:** only the ~14h of orders/audit between the 06-04 00:05 backup and the wipe are unrecoverable (expected for a daily backup). No corruption, no follow-up catalogue work needed.

## Systemic fix recommended (owner — applies to ALL worktrees / the repo)
1. Make `phpunit.xml`'s sqlite override authoritative: `<env name="DB_CONNECTION" value="sqlite" force="true"/>` + `:memory:` force — so no `.env.testing` can repoint tests at a real DB. (Verify the suite passes on sqlite first; mysql-only tests already skip.)
2. OR ensure EVERY worktree's `.env.testing` points at a `*_test` DB, never `foodking`.
3. Add a guard: refuse `migrate:fresh`/RefreshDatabase when `DB_DATABASE` lacks a `_test` suffix in non-production.
