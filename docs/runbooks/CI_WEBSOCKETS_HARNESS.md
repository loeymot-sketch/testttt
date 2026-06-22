# CI WebSockets Harness — Operational Runbook

> **Mission** : `CV1-CI-WEBSOCKETS-HARNESS-001`
> **Source** : RED-R3 / RED-R5 adversarial sync rupture audit (2026-05-07)
> **Audience** : Dev local, CI maintainers, QA validators
> **Date** : 2026-05-07

---

## 1. What this harness exists for

RED-R3 reproduced a silent failure mode in the FoodKing outbox pipeline:

> **Without `php artisan websockets:serve` (or soketi) UP on `127.0.0.1:6001`**,
> AND with `BROADCAST_DRIVER=pusher`, **every** `DispatchDomainEventsJob` ends
> in phase 3b (broadcast failure → release claim → retry). Result on every
> domain event:
> - `dispatched_at = NULL` permanently
> - `last_error = "cURL error 7: Couldn't connect..."`
> - `attempts` climbs to 6 then the job lands in `failed_jobs`
>
> **With `BROADCAST_DRIVER=log`** (the PHPUnit default in `phpunit.xml`),
> the broadcaster always succeeds — but **nothing reaches Pusher/soketi**.
> Any regression in the Pusher serialization, channel naming, or
> `pusher-php-server` library would pass silently through CI.

The MEGA-D 10/10 PASS suite, the R3 follow-ups, and most existing outbox
unit tests validate the **DomainEvent row creation** path. None of them
validates the **end-to-end Pusher dispatch**. This harness fixes that gap.

---

## 2. Components

| File | Role |
|---|---|
| `scripts/ci-bootstrap-websockets-harness.sh` | Detects backend (soketi or beyondcode/laravel-websockets), kills orphans, drains queue, starts the broadcaster on `127.0.0.1:6001`, TCP-probes it, writes PIDs to `storage/logs/ci-harness.pids`. |
| `scripts/ci-teardown-websockets-harness.sh` | Reads PIDs file, sends SIGTERM (then SIGKILL after 3s), sweeps stragglers, removes PIDs file. Optional `--truncate-logs`. |
| `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php` | Four tests: (1) end-to-end dispatch under harness, (2) env self-check, (3) phase 3b release-claim invariant, (4) `contract_violation:` prefix preservation. |
| `.github/workflows/ci-sync-rupture-harness.yml` | GitHub Actions: spins up MySQL service, installs soketi via npm, runs bootstrap + filter + teardown. Triggers on PRs that touch outbox/broadcasting/queue/sentinel/script files. |
| `soketi.json` | Soketi app config (already in repo): `id=app-id`, `key=app-key`, `secret=app-secret`. |

---

## 3. Activation — Local

### Prerequisites

You need ONE of the following installed on your machine:

```bash
# Option A — soketi (preferred, matches CI)
npm i -g @soketi/soketi

# Option B — beyondcode/laravel-websockets
composer require beyondcode/laravel-websockets
```

If neither is installed, the bootstrap script fails loud with an
install hint. The sentinel skips loudly when the harness is not active.

### Run

```bash
# 1. Boot soketi (or websockets:serve) on 127.0.0.1:6001
./scripts/ci-bootstrap-websockets-harness.sh

# 2. Export the harness env so the sentinel runs against the real broadcaster
#    (without these, the sentinel will skip with a loud message)
export CI_WEBSOCKETS_HARNESS=1
export BROADCAST_DRIVER=pusher
export QUEUE_CONNECTION=database

# 3. Run the sentinel only
php artisan test --filter=OutboxPipelineHealthSentinel

# 4. Tear down (idempotent, safe to call any time)
./scripts/ci-teardown-websockets-harness.sh
```

### Why must I export 3 env vars?

`phpunit.xml` hardcodes `BROADCAST_DRIVER=log` and `QUEUE_CONNECTION=sync`.
These defaults are correct for the rest of the suite (1573 tests run fast
in-process). But under those drivers the Pusher pipeline is bypassed
entirely — exactly the silent-failure mode RED-R3 caught.

The CLI exports above override `phpunit.xml`. The sentinel asserts the
overrides took effect (test 2: `harness_environment_is_not_the_phpunit_defaults`).
If you forget one of the exports, the sentinel fails LOUD instead of
quietly green-passing.

> **DO NOT** edit `phpunit.xml` to flip these globally. That would slow
> down the rest of the suite (Pusher round-trip per event) and re-introduce
> hardware coupling for unrelated tests.

### Test against an existing local server

If you already have `php artisan serve` on `:8000` plus soketi on `:6001`
running for manual QA, the bootstrap script will detect the orphan soketi
process, kill it, and restart its own. If you want to keep your manual
soketi running, skip the bootstrap and just run the sentinel directly —
the TCP probe inside step 1 of `test_outbox_pipeline_dispatches_under_active_harness`
is implicit (the dispatch itself fails loud if soketi is unreachable).

---

## 4. Activation — CI

The workflow at `.github/workflows/ci-sync-rupture-harness.yml` runs on:

- PRs touching outbox / broadcasting / queue / sentinel / scripts paths
- Pushes to `main` and `develop`
- Manual `workflow_dispatch`

It is **independent** of `phpunit.yml` (the main 1573-test workflow).
The two should NOT be merged: their env contracts are mutually exclusive.

### What the workflow does

1. Spins up MySQL 8.0 service container
2. Installs PHP 8.2 + soketi via `npm i -g @soketi/soketi@1`
3. Exports `CI_WEBSOCKETS_HARNESS=1`, `BROADCAST_DRIVER=pusher`,
   `QUEUE_CONNECTION=database`, plus `PUSHER_*` matching `soketi.json`
4. Mirrors the same vars into `.env` (defense in depth)
5. Runs migrations
6. `./scripts/ci-bootstrap-websockets-harness.sh`
7. `vendor/bin/phpunit --filter OutboxPipelineHealthSentinel --testdox`
8. Uploads `storage/logs/websockets-ci.log` on failure
9. `./scripts/ci-teardown-websockets-harness.sh` (always, even on failure)

### Adding the workflow as a required check

> **WARNING — path-filter vs required-check trap.** The workflow has a
> `paths:` filter on `pull_request` (only triggers when outbox /
> broadcasting / queue / sentinel / scripts files change). GitHub
> branch-protection treats a non-triggered required check as
> "expected — waiting for status" **forever**, blocking merges of
> unrelated PRs.
>
> Pick ONE of the following, do NOT mix:
>
> - **Option A — Advisory (recommended initially).** Leave the workflow
>   as-is, do NOT mark it required in branch protection. Merges of PRs
>   touching outbox files will still trigger it; the check shows up red
>   if it fails and reviewers can block merge manually.
> - **Option B — Always-required.** Drop the `paths:` filter entirely
>   (workflow runs on every PR, +~3 min per run including soketi npm
>   install) AND add it to branch protection. This is the only way to
>   make a required check + path filter coexist correctly.
> - **Option C — Wrapper status job.** Add a tiny "harness-gate" job
>   that runs unconditionally and either depends on `outbox-pipeline-harness`
>   (path-matched runs) or no-ops with success (path-unmatched runs).
>   Reference that wrapper job in branch protection. More plumbing,
>   correct semantics.
>
> RED-R3 / RED-R5 risk weighting argues for Option B once the workflow
> proves stable; ship as Option A first to avoid blocking unrelated work.

---

## 5. Troubleshooting

### Bootstrap fails: "No broadcaster backend available"

Neither `soketi` (npm global) nor `beyondcode/laravel-websockets` (composer)
is installed. Install one — see Section 3.

### Bootstrap fails: "Probe timed out after 10s"

The broadcaster started but is not listening on `127.0.0.1:6001`. Check:

```bash
tail -n 50 storage/logs/websockets-ci.log
lsof -i :6001
```

Common causes:
- Another process (a forgotten soketi from a manual session) holding port 6001 — kill it.
- `soketi.json` `host` set to something other than `127.0.0.1`.
- Firewall blocking localhost (rare on dev macs/Linux).

### Sentinel test 1 fails with "dispatched_at is still NULL"

Either:
- The broadcaster crashed mid-test (check `storage/logs/websockets-ci.log`).
- `PUSHER_*` env vars don't match `soketi.json` — Pusher client fails auth.
- Network policy blocks `127.0.0.1:6001` (run `curl -v http://127.0.0.1:6001/`).

This is RED-R3's exact failure mode in production. The sentinel is doing
its job — fix the underlying broadcaster issue before touching the test.

### Sentinel test 2 fails with "broadcasting.default = log"

You ran `php artisan test` without exporting `BROADCAST_DRIVER=pusher`,
or `phpunit.xml` was modified to override the env. Re-export and retry.
**Do NOT** edit the sentinel to relax the assertion — that's the exact
bypass RED-R3 surfaced.

### Sentinel test 3 fails "Phase 3b MUST reset dispatched_at"

A code change in `DispatchDomainEventsJob::handle()` broke the release-claim
contract. Read the job's docblock (NEW-01 phase 3b comment) before
"fixing" the test. The contract is load-bearing for the queue retry
backoff curve `[1, 5, 15, 60, 300]s × 6 tries`.

### Tests pass locally but CI shows them as skipped

CI workflow probably stripped the `CI_WEBSOCKETS_HARNESS=1` export.
Check `.github/workflows/ci-sync-rupture-harness.yml` env block.
Skipped tests in CI = silently green = the same RED-R3 failure mode in a
new wrapper. Treat skipped sentinel runs as red.

### Local dev: queue:work --once finds nothing

`QUEUE_CONNECTION=sync` is still active (job ran inline at dispatch time).
Re-export `QUEUE_CONNECTION=database` AND make sure `php artisan migrate`
created the `jobs` table.

---

## 6. Invariants — DO NOT relax

The following must remain true for the harness to mean anything:

1. **`CI_WEBSOCKETS_HARNESS=1` is the harness gate.** The sentinel reads
   it from `getenv()`, not from `config()`, because PHPUnit's xml env
   loading does NOT propagate to subprocess `getenv()` consistently. Keep
   the gate at `getenv` level.
2. **Test 2 must HARD-FAIL on log+sync, not skip.** Skipping when the
   harness flag is set but env overrides are missing IS the bug.
3. **The bootstrap must FAIL loud when no backend is available.** Graceful
   degradation belongs in the sentinel (skip if flag unset), NOT in the
   bootstrap (failing CI is what protects against silent harness regressions).
4. **`phpunit.xml` defaults must remain log+sync.** Flipping them globally
   would break the rest of the suite. The harness is opt-in for a reason.
5. **The workflow is independent of `phpunit.yml`.** Do not merge them.

---

## 7. Related artifacts

- `app/Jobs/DispatchDomainEventsJob.php` — the job under test (read NEW-01 docblock)
- `app/Domain/Events/EventContract.php` — envelope validation rules (`item_id`, `status`, etc.)
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` — unit-style coverage of phase 3b with mocked broadcaster (complements this sentinel)
- `soketi.json` — Pusher-protocol app config used by both local and CI
- RED-R3 / RED-R5 audit reports under `reports/antigravity/` and `reports/review/`

---

## 8. Permission note (post-checkout)

The bootstrap and teardown scripts ship with the executable bit set in
git. If `git checkout` for some reason strips it (cross-platform clone
on Windows, archive extraction, etc.), restore manually:

```bash
chmod +x scripts/ci-bootstrap-websockets-harness.sh
chmod +x scripts/ci-teardown-websockets-harness.sh
```

The CI workflow also runs `chmod +x` defensively before invoking them, so
this only matters for local dev.
