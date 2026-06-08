# W5 — Synchronisation deep-validation VERDICT
**Date:** 2026-06-08 · GOAL_WIZARD_DYNAMIC §4 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push)
**Method:** empirical — core pipeline read (`DispatchDomainEventsJob`, `EventContract`) + executed Outbox/Sync suites (sqlite :memory:) + deploy-config inspection.

## VERDICT: Core PROVEN-SOUND (exactly-once, commit-before-broadcast, contract-validated, crash-recoverable, escalated). The ONE real open item is operational, not code: the `high`-lane worker + scheduler must be running in prod (G-SYNC-1).

---

## Pipeline (verified)
emit (`HasDomainEvents`) → `domain_events` row in the SAME tx → `DispatchableAfterCommit` dispatches `DispatchDomainEventsJob` `onQueue('high')` → atomic claim → `EventContract` validate → soketi broadcast `private-branch.{id}` → KDS/OSS/POS consumers + polling fallback.

## Invariants PROVEN (code + 64 green tests)
- **Exactly-once** — `DispatchDomainEventsJob.php:54-88`: Phase-1 claim under `lockForUpdate` + `dispatched_at` guard in one committed unit; the losing concurrent worker observes `dispatched_at != null` and returns silently → broadcaster never fires twice. (`OutboxConcurrentWorkerDedupeTest`, `OutboxConcurrentRetryLockTest`)
- **Commit-before-broadcast** — `OrderCreated` etc. are `DispatchableAfterCommit`; the job runs post-commit. (`OrderCreatedDispatchPlacementSentinelTest`)
- **Contract-validate-before-broadcast** — `:107-110` `buildEnvelope` → `assertEnvelopeValid` BEFORE `broadcaster->broadcast`, outside any DB tx. (`EventContract` + envelope tests)
- **Crash-recovery / no lost events** — Phase-3b `:162` releases the claim (`dispatched_at => null`) on transient failure so the retry curve re-attempts; stale claimed rows rescued by `outbox:rescue`. (`OutboxRescueStaleClaimedRowsTest`, `OutboxMonitorCrashClaimedSentinelTest`)
- **Fail-once on malformed contract** — a structurally-bad event is not retried 6× (`:177`), avoiding 'high'-lane waste.
- **Terminal-failure escalation** — after `$tries` exhausted, pager-worthy log; `OutboxBroadcastSwallowedEvent` → `EscalateOutboxBroadcastSwallowed`. (`OutboxBroadcastSwallowedListenerTest`)
- **Retry curve** — `$tries=6`, `$backoff=[1,5,15,60,300]` (`:40-42`).

## Findings re-graded HONESTLY
- **`--tries` divergence (workflow P2) — REAL config inconsistency, NOT a behaviour bug.** Job `$tries=6` (`:42`) vs `deploy/local/fr.lecayenne.queue-high.plist --tries=1` vs `deploy/ansible/.../supervisor-foodking.conf.j2 --tries=3`. In Laravel the per-job `$tries` property is authoritative and overrides the worker `--tries` CLI flag → behaviour is **always 6 attempts** regardless of the deploy flag. ⇒ ops-clarity nit (align the templates' comments), not a correctness defect. **Not healed** (no behaviour change to make; editing deploy templates here adds churn for zero runtime effect).
- **Fallback polling (SYNC-WS-01, memory)** — when the browser WS (`ws:6001`) fails, KDS/POS consumers degrade to abort-guarded polling; the backend pipeline is unaffected (producer-side fan-out is independent of browser delivery). Known, designed.
- **KDS version-gate status-only skip (workflow P2)** — `KdsSyncService` `computeOrderVersion` = `updated_at` unix; a status change that doesn't bump `updated_at` could gate a card. View-layer, backend-idempotent; deferred (V1.0.x view-layer hardening, no money/NF525 impact).

## G-SYNC-1 (OWNER/OPS GATE — the real open item)
Every guarantee above **depends on the runtime actually running**: `php artisan queue:work redis --queue=high` AND `schedule:run` (for `foodking:outbox:rescue`). The launchd plists + ansible supervisor template exist but are **not proven-live** on the target box. This is the headline operational gate (matches the standing "supervisor/workers" TODO from the OVH deploy). **WHO:** owner/ops. **WHAT:** `supervisorctl status` / launchd `list` showing the high-lane worker + scheduler up. **WHERE:** deploy report + BRAIN §2. Until then, sync degrades to polling (functional, higher latency) — not a data-loss risk (outbox rows persist and drain when the worker returns).

## Evidence (executed)
`tests/Feature/Outbox` (12) + `tests/Feature/Sync` (6) + `OutboxTest` + `OutboxRescueTest` = **64/64, 218 assertions**. No frozen touched. No code change (validation-only).
