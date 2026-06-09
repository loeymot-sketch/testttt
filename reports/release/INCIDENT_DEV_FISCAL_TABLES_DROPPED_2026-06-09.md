# INCIDENT — operating dev `foodking` lost audit_logs + z_reports (2026-06-09)

## What happened
Late in the supervisor-100 campaign, a read-only NF525 attestation failed: **`foodking.audit_logs` and `z_reports` tables no longer exist** on the operating dev `foodking` DB. Earlier this session the chain was verified intact (`audit_logs=2675, z_reports=5`) multiple times.

## Damage scope (NOT a full wipe)
- `foodking` still has **57 tables** with `orders`/`items`/`users` **INTACT**. Only the **2 fiscal tables** are gone.
- So this is NOT the classic full `migrate:fresh` DEVDB wipe — it is the targeted loss of the 2 NF525 tables.

## Root cause — a CONCURRENT JOB on the shared DB (not this session's tests)
- `foodking` is shared by ~20 worktrees + multiple concurrent background jobs (the documented `feedback_shared_infra_devdb_footgun` hazard).
- Evidence of concurrent DB surgery: the `migrations` table's newest rows are **March 2026** (the audit_logs migration is `2026_04_22`), and there is a fresh **`foodking_clone.sql` (8 June 17:54)** dump + many live `php artisan tinker /tmp/*.php` processes (enc_verify, paid_bcast, state_q) from other jobs. A concurrent job restored/rebuilt `foodking` to an older schema state, dropping the fiscal tables.
- **This session's tests are NOT the cause:** every `php artisan test` here targeted `foodking_test` (`.env.testing → foodking_test`, DEVDB-GUARD intact, verified). The frites/sentinel tests used `RefreshDatabase` on `foodking_test` only.

## Correction to prior attestations (honesty)
My earlier "NF525 chain intact, audit_logs=2675" attestations were TRUE when made but are **now FALSE** — the dev fiscal tables were dropped by a concurrent job afterward. **I cannot currently attest NF525 chain integrity on the dev `foodking` DB.** The single `+1` (login id=2675) I reported earlier remains accurate for that point in time.

## Why I did NOT auto-recover
- Recovery (recreate the 2 tables via `php artisan migrate`, or restore from a dump) is a **DB op on the operating/shared DB while concurrent jobs are actively writing it** — doing surgery now risks colliding with them + a partial state. Per CLAUDE.md §10 (production-data ops = owner gate) and "blocked > silently dangerous", I am **not** running unauthorized DB surgery on the contended shared DB.

## Recovery options (owner / coordinated)
1. **Recreate schema:** `php artisan migrate` (when concurrent jobs are quiesced) re-runs `2026_04_22_000002_create_audit_logs_table` + `000003_create_z_reports_table` + the trigger/index migrations → **empty** fiscal tables, fresh chain from sequence 1. The 2675 dev rows are NOT restored (dev/soak data, not the real chain).
2. **Restore from dump:** if a concurrent job's `foodking_clone.sql` (job 389c67ff/tmp, 8 June 17:54) contains the fiscal tables + data, restore them — but verify chain integrity post-restore (`fiscal:verify-chain`).

## Bottom line
- **This is the DEV/operating box (local), NOT the real OVH production chain.** The 2675 rows were dev/soak data.
- **The go-live path is UNAFFECTED:** GATE-DATA-1's plan (`GO_LIVE_DB_CLEAN_STATE_PLAN.md`) already specifies starting production on a **FRESH `foodking_prod` DB with a clean chain from sequence 1** — so production does not depend on the damaged dev chain.
- **Action for owner:** (a) coordinate/quiesce the concurrent jobs hammering the shared `foodking` DB (the recurring hazard), and (b) decide recovery option 1 or 2 for the dev box if you want the dev fiscal chain back before go-live.
