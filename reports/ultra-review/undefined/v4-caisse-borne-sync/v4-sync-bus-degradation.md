# V4 — Sync Bus & Degradation (outbox → queue → soketi)

Target: real-time synchronization bus. Posture: GREEN = hypothesis to refute.
Env: LIVE 127.0.0.1:8766 (foodking_e2e), worker `queue:work --queue=high,default` active, soketi up. HEAD 61e9ea7b7 + working tree.
Discipline: read-only (no DB/file writes except this report). All findings reproduced live.

## Verdict: BROKEN (1×P2, 1×P3) — core bus held green, degradation/alerting safety-net has a live defect

The event bus *core* is well-built and survived every correctness/concurrency/security attack
(see "Held green"). The break is on the **degradation-observability axis** the mission asked me
to attack: the outbox staleness monitor — the single alarm meant to detect "sync fully down" —
currently returns **FAILURE / "queue worker may be down" every minute while the pipeline is
perfectly healthy**, because legacy undispatched rows are stuck in an immortal gap between the
recovery lanes. This defeats the very safety-net that makes "la synchro est robuste" true.

---

## The 37 (now 39) pending domain_events — explained (mission question)

`SELECT ... WHERE dispatched_at IS NULL` → 37 rows at audit start (39 after live traffic added 2 fresh):

| class | count | attempts | last_error | root cause |
|---|---|---|---|---|
| `order.created` (valid) | 20 | 0 | — | Legacy (2026-06-17). Contract-VALID (verified via `EventContract::assertEnvelopeValid` read-only → ENVELOPE_OK, 0 missing keys). Never picked up by a worker; `outbox:rescue` (attempts<5, created_at old) *would* re-drive them but has not converged them in this env → **scheduler not effectively running here** (ops/env artifact, not a code bug). |
| `loyalty.balance_changed` | 16 | 2–3 | `contract_violation: type must be one of order.created\|...` | Legacy producer, now **removed** (`grep -r 'balance_changed' app/` → 0 producers). Event type not in `EventType::all()` → permanent contract violation → `$this->fail()`. |
| `order.created` (invalid) | 1 (id 9689) | 4 | `contract_violation: payload missing required keys: payment_method` | Legacy. Current `PersistOrderCreatedToOutbox` always emits `payment_method` → not reproducible with current code. |

Fresh live events (id 10245–10248, created 15:38:55) dispatched at 15:39:24–28 (~30s, within
worker/backoff cycle) → **current producers + pipeline are healthy end-to-end**.

---

## P2 — Live false-positive "queue worker may be down" alert (immortal-orphan gap)

**Files:** `app/Console/Commands/MonitorOutboxStaleness.php:48-58`,
`app/Console/Commands/PruneOutboxCommand.php:56-60`,
`app/Console/Commands/OutboxRescueCommand.php:45`,
`app/Console/Commands/OutboxRetryFailedCommand.php:75-76`.

**Live repro (read-only):**
```
$ php artisan foodking:outbox:monitor --threshold=10 --stale-after=30
[OUTBOX STALE] 37 undispatched events older than 30s (threshold: 10) + 1 crash-claimed orphans.
  ... queue worker may be down — verify `php artisan queue:work --queue=high` is running ...
EXIT=1
```
…emitted **while the worker is provably alive** (ids 10245-10248 dispatched seconds earlier).
`Log::error` + non-zero exit fire on every scheduled run (`app/Console/Kernel.php:50`, everyMinute).

**Root cause — the immortal gap between the three recovery lanes:**
- `outbox:rescue` re-drives `attempts < 5` (no time bound). A contract-violation row climbs
  1→2→…→5 and then rescue **excludes it forever** (`attempts < 5` false).
- `outbox:retry-failed` re-drives `attempts >= 4 AND attempts < 12` **but only `created_at >= now()-24h`**.
  After 24h the row is excluded → it can never climb from 5 to the ≥6 needed by prune.
- `outbox:prune` deletes `dispatched_at IS NOT NULL` **or** `attempts >= 6`. A row frozen at
  attempts ≤5 with `dispatched_at = NULL` matches **neither clause → never pruned**.

Net: any contract-violation (or otherwise permanently-failing) domain_event that does not reach
`attempts>=6` inside its first 24h becomes **immortal** — it sits in `domain_events` forever with
`dispatched_at = NULL`, and `MonitorOutboxStaleness.staleCount` (`whereNull('dispatched_at')`,
`created_at` old) counts it forever. 17 such legacy rows already exist; today they pin
`staleCount=37 > threshold=10` → **permanent RED**.

**Impact:** the one alarm designed to catch "sync down" is desensitized to a constant false
positive → alert fatigue → a *genuine* worker-down (the catastrophic sync-loss the whole
outbox+poll architecture defends against) is masked. Reproducible every run.

Also flagged live: **1 crash-claimed orphan** (id 8194 `order.status_changed`, attempts=6,
`last_error=expired:quarantined`, dispatched_at 2026-06-12) — the monitor itself documents this
class as "UNREACHABLE by retry-failed/rescue"; a deliberately-quarantined row is surfaced as an
orphan alarm dimension (minor false positive; will prune at 90d since attempts>=6).

**Fix direction (no code changed — read-only audit):** align the lane boundaries so no gap exists,
e.g. make `outbox:prune` clause-B threshold `attempts >= 5` (match rescue's cap) OR give
`outbox:retry-failed` a terminal-quarantine transition (`dispatched_at = now()` + `last_error`
flag) for `attempts >= REPLAY_MAX_ATTEMPTS` contract-violations so staleCount stops counting them.
Contract-violations are non-retryable by definition — they should be quarantined out of the
pending set, not looped. NF525-safe (operational outbox only; `audit_logs`/`z_reports` untouched).

## P3 — 20 contract-valid order.created events undispatched for 2 weeks

**File:** live data (`domain_events` ids 9691-9710, occurred 2026-06-17, attempts=0).
These are contract-valid and would dispatch if re-driven; `outbox:rescue` is coded to catch them
(`attempts<5`, `created_at` old, verified) but has not in ~2 weeks → the rescue cron is not
effectively running in this environment. If this reflects prod, the real-time push for those
orders was silently lost. **No data loss to end-users** — KDS/OSS/POS poll the `orders` table
directly (not `domain_events`), so the poll fallback still surfaced the orders. Classified P3
because it is an env/ops artifact (scheduler cadence), not a current-code defect, but it is the
concrete evidence that the "rescue re-queues stuck events → no loss" claim depends entirely on a
cron that was silently absent here.

---

## Held green (attacks attempted and refuted)

1. **Channel wiring correctness** — server broadcasts to `["private-branch.1"]`
   (`Persist*ToOutbox` channel col); client `eventContract.js:353` does
   `Echo.private('branch.'+id)` → Pusher channel `private-branch.1`; `channel.listen('.'+broadcastAs)`
   matches `broadcast_as`. **No prefix/namespace mismatch** → real-time reaches connected clients.
2. **Double-broadcast from rescue crash-recovery lane** — refuted: client dedup keyed on
   `(correlationId, type, branchId, aggregateId)` (`eventContract.js:377`) collapses a re-broadcast;
   `OutboxRescueCommand` comment's "worst case one extra broadcast" is neutralized client-side.
3. **Concurrent double-dispatch** — `DispatchDomainEventsJob` Phase-1 `lockForUpdate` +
   `dispatched_at != null` guard + claim in one committed txn; loser returns silently. Correct.
4. **commit-before-dispatch** — `HasDomainEvents` + every `Persist*` listener dispatch via
   `DB::afterCommit` → no broadcast of an uncommitted aggregate.
5. **Malformed-payload queue flood** — `PayloadMismatchException` short-circuits via `$this->fail()`
   (no 6× `high`-lane retry storm); sentinel-backed. Correct.
6. **Current producers emit all required contract keys** — `order.created` / `order.status_changed`
   / `order.payment_confirmed` / `order.payment_status_changed` all include `payment_method` and
   `fiscal_sequence_no` (null-allowed key present) → borne Plan-B path (fiscal_sequence_no=NULL
   pre-encaissement) does **not** produce a contract violation. Verified read-only.
7. **Live pipeline health** — fresh events dispatched in ~30s with worker up; 9897 dispatched vs 39 pending.
