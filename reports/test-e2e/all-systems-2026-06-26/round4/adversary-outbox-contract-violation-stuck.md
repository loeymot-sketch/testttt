# [P3] Outbox contract_violation events stuck forever — never pruned + permanent monitor FAILURE

**Verdict: REAL — CONFIRMED P3 (V1-LOCAL ops/monitoring quality; non-frozen heal available)**

## file:line
- `app/Jobs/DispatchDomainEventsJob.php:161-187` — on `PayloadMismatchException`, releases claim (`dispatched_at=NULL`, `last_error='contract_violation: ...'`) then `$this->fail($e)` (terminal, no further retry).
- `app/Console/Commands/PruneOutboxCommand.php:50-60` — safe-set = (A) `dispatched_at NOT NULL` OR (B) `attempts>=6`. A contract-violation row matches NEITHER.
- `app/Console/Commands/MonitorOutboxStaleness.php:44-47` — `staleCount = whereNull(dispatched_at) AND created_at<cutoff` → counts these rows forever; `crashClaimedCount` (l.72-77) requires `dispatched_at NOT NULL` so it does NOT cover them.
- `app/Console/Kernel.php:50` — `foodking:outbox:monitor --threshold=10` scheduled (everyMinute per l.263 comment).
- Tries config: `DispatchDomainEventsJob.php:40-42` — `$backoff=[1,5,15,60,300]`, `$tries=6`.

## Repro (live foodking_e2e)
```
SELECT COUNT(*),MIN(attempts),MAX(attempts) FROM domain_events
 WHERE dispatched_at IS NULL AND last_error LIKE 'contract_violation%';
-> 17 rows, attempts MIN=2 MAX=4 (NEVER >=6)

GROUP BY event_type:
  loyalty.balance_changed  16  oldest 2026-06-12 18:28:56  max_attempts 3
  order.created             1  2026-06-17 14:39:02         attempts 4

staleCount (whereNull(dispatched_at) AND created_at<NOW()-30s) = 231
  -> breakdown: 17 contract_violation (permanent) + 214 other (oldest 2026-06-17, separate transient).
```
17 (contract_violation alone) > threshold 10 → `MonitorOutboxStaleness` returns `self::FAILURE` permanently, independent of queue-worker health.

## Why it's real (root cause)
`$this->fail()` short-circuits BEFORE `attempts` reaches `$tries=6` (intentional, per F-3-SYNC P1: avoid wasting 6 queue messages on a malformed payload). Consequence: the row is left `dispatched_at=NULL, attempts<6` permanently.
- Prune clause A needs `dispatched_at NOT NULL` → fail. Clause B needs `attempts>=6` → fail. ⇒ never pruned (unbounded, though tiny: 17 rows / 2 weeks).
- Monitor `staleCount` keys on `whereNull(dispatched_at)` ⇒ counts them as "queue worker down" forever. `crashClaimedCount` keys on `dispatched_at NOT NULL` ⇒ blind to them.
- `outbox:retry-failed`/`outbox:rescue` only re-fail or re-queue (scopeFailed/lane-B) — they cannot clear a terminal contract violation.

Net: a permanent false-positive on the "queue is down entirely" alarm. When the queue ACTUALLY goes down, the owner cannot distinguish the new failure from the standing 17 — the real signal is masked.

## Not already guarded
`PayloadMismatchFailOnceSentinelTest` only asserts fail-once semantics; neither prune nor monitor excludes/handles the resulting terminal rows. No existing clause covers `last_error LIKE 'contract_violation%'`.

## Severity rationale (V1-LOCAL)
NOT P0/P1: no order loss (KDS/sync fall back to DB poll), no money, no NF525 (`domain_events` is an operational outbox, never fiscal), no security/data-leak. Impact = monitoring/ops quality: a permanently-red defense-in-depth health probe + negligible unbounded growth. On a mono-poste local with no wired pager, practical impact is low → **P3** (cloud-prep / ops-hardening). Candidate severity confirmed.

## Lens
Twin/systemic: the terminal-marker convention (`fail()` leaves `dispatched_at=NULL, attempts<tries`) is out of sync with BOTH downstream consumers (prune safe-set AND staleness signal). Any future `PayloadMismatchException` (the current 17 are a removed `loyalty.balance_changed` feature, but the path is live for every contract-non-conforming payload) reproduces it.

## Reco (non-frozen; Job/Commands only)
TDD heal, pick one consistent terminal convention:
1. `MonitorOutboxStaleness:44-47` — exclude terminal contract violations from `staleCount` (the "queue-down" signal) and surface them as a SEPARATE dimension (`contractViolationCount`) so they are reported, not silently ignored. Sentinel: a `contract_violation` row must NEVER be counted in the queue-down `staleCount` indefinitely.
2. `PruneOutboxCommand:50-60` — add safe-set clause (C) `last_error LIKE 'contract_violation%' AND created_at < cutoff` so terminal contract violations are pruned after the 90d retention window (bounds growth).
3. Sentinel test asserting (1)+(2): a stuck `contract_violation` row is excluded from the queue-down alarm and is prune-eligible past retention.

Avoid the naive `dispatched_at=now()` marker: a contract violation occurring at `attempts>=5` would then match `crashClaimedCount` (l.72-77) and re-introduce a false orphan alarm after the 10-min cutoff.

## Frozen
NO. `DispatchDomainEventsJob.php`, `PruneOutboxCommand.php`, `MonitorOutboxStaleness.php` are all non-frozen. Heal safe.
