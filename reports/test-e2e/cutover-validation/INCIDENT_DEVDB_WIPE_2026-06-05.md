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

## Systemic fix recommended (owner — applies to ALL worktrees / the repo)
1. Make `phpunit.xml`'s sqlite override authoritative: `<env name="DB_CONNECTION" value="sqlite" force="true"/>` + `:memory:` force — so no `.env.testing` can repoint tests at a real DB. (Verify the suite passes on sqlite first; mysql-only tests already skip.)
2. OR ensure EVERY worktree's `.env.testing` points at a `*_test` DB, never `foodking`.
3. Add a guard: refuse `migrate:fresh`/RefreshDatabase when `DB_DATABASE` lacks a `_test` suffix in non-production.
