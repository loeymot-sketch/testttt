# RED-TEAM — Outbox delivery guarantees (at-least-once, ordering, dedup, fallback)

READ-ONLY adversarial audit. HEAD branch `pos/category-first-caisse-2026-06-23`, date 2026-08-04.
Domain: `DomainEvent` outbox → `DispatchDomainEventsJob` → soketi `private-branch.{id}` (+ polling fallback per surface).
Every finding grounded `file:line` with a reproducible scenario. Findings without a code-anchored repro are marked REFUTED.

---

## ROOT INSIGHT (drives P2-1 + P2-2)

`domain_events.dispatched_at` is written in **Phase 1 (claim), BEFORE the broadcast** — it is a
**CLAIM marker, not a DELIVERY marker**:

- `DispatchDomainEventsJob.php:80-83` — inside the `lockForUpdate` transaction the job sets
  `dispatched_at = now()` and `attempts++` **before** the broadcast, which only happens later at
  `DispatchDomainEventsJob.php:116` (Phase 2, outside the transaction).
- Phase 1 **never touches `last_error`**. `last_error` is set ONLY by a caught throw in Phase 3b
  (`:161-166`) and cleared ONLY by a successful Phase 3a (`:153-155`).

Consequence: a worker killed (SIGKILL / OOM / reboot / deploy `kill -9`) in the Phase-2 window leaves
a row with `dispatched_at != NULL` and `last_error` unchanged — **indistinguishable from a genuine
success** to every downstream lane that keys on `dispatched_at`. The only compensating control is
rescue lane-B, which is bounded (`attempts < 5`) and non-alerting.

---

## [P2] P2-1 — Crashed-mid-broadcast orphan with `last_error IS NULL`: unreachable by every re-drive lane, INVISIBLE to the staleness monitor, silently pruned at 90 d as a "success"

**file:line**
- `app/Jobs/DispatchDomainEventsJob.php:80-83` (claim sets `dispatched_at`+`attempts` before broadcast `:116`; `last_error` untouched in Phase 1)
- `app/Console/Commands/OutboxRescueCommand.php:47` — crash-recovery lane-B is gated `->where('attempts', '<', 5)`
- `app/Console/Commands/MonitorOutboxStaleness.php:79-84` — `crashClaimedCount` requires `->whereNotNull('last_error')`
- `app/Models/DomainEvent.php:45-49` + `:34-37` — `scopeFailed()` → `scopePending()` → `whereNull('dispatched_at')` (so `outbox:retry-failed` only sees `dispatched_at IS NULL` rows)
- `app/Console/Commands/PruneOutboxCommand.php:65-66` — prune lane (A) deletes any `dispatched_at IS NOT NULL AND dispatched_at < cutoff(90d)`

**Scenario (reproducible)**
1. Event row born `dispatched_at=NULL, attempts=0, last_error=NULL`.
2. Job attempt: Phase 1 → `attempts=1, dispatched_at=T1`. Worker is **SIGKILLed during the Phase-2 broadcast** (`:116`) — the slowest window (network I/O to soketi), so the likeliest place for an OOM/reboot to land. Row stays `attempts=1, dispatched_at=T1, last_error=NULL`.
   (Redis `retry_after` re-runs the same job, but Phase 1 sees `dispatched_at != null` → `skip` at `:75-78` → clean ack, never re-broadcast, `attempts` NOT bumped. So only rescue helps.)
3. `outbox:rescue` lane-B (`:42-45,70-72`) nulls `dispatched_at` and re-dispatches a fresh job (`attempts<5` still true). Under a memory-pressure / reboot loop the crash repeats. After 4 rescues the 5th crash pins **`attempts=5, dispatched_at=T5, last_error=NULL`**.
4. Now the row falls through EVERYTHING:
   - `outbox:rescue` — `attempts<5` false → **skip**.
   - `outbox:retry-failed` — `failed(5)` = `pending()` = `whereNull('dispatched_at')`; row has `dispatched_at=T5` → **not matched**.
   - `outbox:monitor` `staleCount` (`:50-54`, `whereNull('dispatched_at')`) → not matched; `deadLetterCount` (`:91-95`, `whereNull('dispatched_at')`) → not matched; **`crashClaimedCount` (`:79-84`) requires `last_error IS NOT NULL` → NOT counted.** No lane pages the operator.
   - `/api/health/ready` `checkQueueWorker` (`HealthController.php:225-233`, `whereNull('dispatched_at')`) → not counted.
   - `outbox:prune` lane (A) (`PruneOutboxCommand.php:65-66`) matches `dispatched_at IS NOT NULL` at 90 d → **row silently DELETED as if delivered.**

**Proof the monitor's own assumption is wrong**: `MonitorOutboxStaleness.php:56-76` comment asserts a crash "leaves `dispatched_at != NULL` **WITH `last_error` set** (from a prior attempt)". That holds only if a prior attempt *threw*; a **crash never sets `last_error`**, and a first-attempt (or all-crash) orphan therefore has `last_error=NULL` and is invisible. The `whereNotNull('last_error')` clause encodes that faulty assumption.

**Impact / severity calibration**: at-least-once + observability are genuinely broken for that event and it is GC'd with no trace. It is **P2, not P1**, because every consumer independently re-reads `orders` on poll (KDS drift 60 s, OSS 5 s, POS coalesced poll) so the *state* self-heals — the loss is bounded to latency + a silent unpaged alarm. It becomes **P1 for any push-only consumer** → see P2-2. Probability is low (needs ≥5 consecutive mid-broadcast kills with no intervening throw); a crash *after* a normal throw carries a non-null `last_error` and IS caught by `crashClaimedCount`.

**Fix direction (owner)**: separate the claim marker from the delivery marker (e.g. `broadcast_at` set only in Phase 3a), OR drop the `whereNotNull('last_error')` clause at `MonitorOutboxStaleness.php:81` and raise the rescue `attempts<5` ceiling for the crash-claimed lane so `dispatched_at`-stuck rows are always visible+recoverable regardless of `last_error`.

---

## [P2] P2-2 — `KdsOrderRecalled` is PUSH-ONLY: no poll rehydration, so a lost/late recall broadcast is never recovered on other KDS screens (and never alerted)

**file:line**
- `app/Services/KitchenDisplaySystemOrderService.php:428-529` — `recall()` **asserts `orders.status` MUST stay `PREPARED`** (`:498-505`); it only inserts an `OrderStatusTransition(reason=kitchen_recall)` (`:486-496`) and broadcasts `KdsOrderRecalled` (`:519-526`). No board-visible column is mutated.
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:2483-2501` — the recall is applied ONLY from the Echo handler (`recallActiveMap`, 60 s window). The poll (`/api/admin/kds-order/sync`) fetches board-visible statuses; a `PREPARED` (bumped) order is off the active board, so **nothing in a poll re-surfaces a recall.**

**Scenario (reproducible)**
A chef recalls a bumped ticket on screen A. The `KdsOrderRecalled` broadcast is the ONLY path to screen B. If that broadcast is lost — WS down during the 60 s window, soketi split-brain (`SO_REUSEPORT`, PROJECT_BRAIN 07-29), or the outbox row lost per **P2-1** — screen B **never** shows the RAPPELÉ card. There is no re-fetch that recovers it and no staleness signal (the row, if it broadcasts at all, looks delivered).

**Impact**: LOW for strict single-screen V1 Le Cayenne (the initiating screen updates locally; the broadcast serves only *other* screens). Real for a 2-screen kitchen (prep + expo) and for V2 multi-station. This is the surface where P2-1's silent loss becomes user-visible — hence the pairing. **P2.**

---

## [REFUTED] Vector 2 (ordering) & Vector 3 (double-delivery) on KDS / POS / OSS — self-healing by design

The order events are consumed as a **"something changed → re-fetch full state from `orders`" trigger**, not applied from the payload:
- KDS: `KitchenDisplaySystemComponent.vue:2457-2461` → `_debouncedRefresh()` (full re-fetch); the payload's `new_status` is used only to *decide whether* to refresh (`_statusChangeAffectsKds`, `:1830-1841`), never written to the board.
- POS tracker: `PosOrdersTrackerComponent.vue:563-586` → coalesced `fetchOrders` on every Echo event + a poll watchdog.
- OSS: poll-only wall (`PreparingAndReadyComponent.vue:262-270`), `list()` re-fetch.

Therefore:
- **Ordering**: even the documented 2-worker race (`scripts/deploy/supervisor.conf.template` `numprocs=2`, and no per-order/per-branch serialization — `HasDomainEvents.php:55-60` dispatches one independent job per event, claim is per-row only) that CAN broadcast `PREPARING`/`PREPARED` out of order does **not** corrupt these boards — the next re-fetch reflects the DB truth. No `file:line` produces a persisted out-of-order state on an in-repo surface. **REFUTED.**
- **Double-delivery**: (a) the per-row `lockForUpdate` claim (`DispatchDomainEventsJob.php:65-86`) makes a 2nd concurrent worker see `dispatched_at != null` → `skip`, so the SAME event is not double-dispatched; (b) the rescue-vs-straggler double-broadcast that IS possible by design (`OutboxRescueCommand.php:62-72` "worst case is one extra broadcast") collapses into an idempotent re-fetch. No double-effect (no counter increment, no duplicated tile) reachable. **REFUTED.**

---

## [P2 / VERIFY — not a confirmed defect in this repo] `private-customer.{id}` fan-out has no in-repo subscriber

`PersistOrderStatusChangedToOutbox.php:153-163` fans `OrderStatusChanged` out to `private-customer.{user_id}`
for `source_surface==='web'`, and `routes/channels.php` authorizes `customer.{customerId}`. But **no client in
this repo subscribes to it** — every Echo consumer (KDS, POS, OSS, KioskWaiting, KioskApp) binds `branch.{id}`.
The **customer web account is a SEPARATE standalone deploy** (CLAUDE.md §3bis, `lecayenne-web-deploy`) not in this
tree, so the subscriber MAY live there. Per anti-hallucination discipline this is **not** scored as a defect here.
Owner action: verify the standalone web repo actually subscribes to `private-customer.{id}`; if it does not, the
documented real-time "prête" UX is non-functional (customer relies solely on polling `/api/frontend/order/show` —
no data loss, degraded latency + wasted broadcasts per web status change).

---

## [COVERED — residual known gap] Vector 6 — scheduler down

Largely mitigated in production: `HealthzCheckCommand.php:47` writes `scheduler:last_tick` every 5 min; `/api/health/ready`
`checkScheduler` (`HealthController.php:168-182`) → **503 when the tick is >10 min stale**, gating in `production` only
(`:68-70`) so the external UptimeRobot probe pages. Backlog is caught up on scheduler return (rescue re-queues
`attempts<5`) — **except the P2-1 orphan class**. Residual (documented, not novel — SYNC_CONTRACT §7): if the scheduler
is down, `MonitorOutboxStaleness` itself doesn't run, so `/ready` is the *only* worker-down signal; and the monitor
alerts via `Log::error` only (no mail/SMS). Normal dispatch is unaffected by scheduler death (jobs are enqueued inline
from `HasDomainEvents::bootHasDomainEvents` `afterCommit`, not by cron).

---

## Vector 1 detail — the NORMAL failure path terminates VISIBLY (contrast with P2-1)

A broadcast *throw* (soketi returns error, not a crash): Phase 3b sets `last_error`, re-throws; redis backoff
`[1,5,15,60,300]`×`tries=6` (`DispatchDomainEventsJob.php:40-42`) exhausts → `failed()` persists `last_error`
(`:205-214`), row ends `pending, attempts~6, last_error set`. `outbox:retry-failed` re-drives it hourly while
`created ≥ now-24h` and `attempts<12` (`OutboxRetryFailedCommand.php:103-104`); `deadLetterCount`
(`MonitorOutboxStaleness.php:91-95`) counts it (visible/paged). After 24 h it is no longer auto-retried but remains
visible in `deadLetterCount` until pruned. So the throw path is **at-least-once + observable**; only the **crash path
with `last_error=NULL`** (P2-1) is silent. The delta between the two is exactly the `last_error` column — which is why
P2-1's fix is to stop keying delivery/visibility on it.

---

## VERDICT

**Money/fiscal path: not in scope (advisory broadcasts only).** The end-to-end "surface eventually shows correct
state" invariant HOLDS for all polled surfaces (KDS/POS/OSS/kiosk) because the DB `orders` table is the SSOT and the
outbox is a latency optimization — this REFUTES the naive ordering (V2) and double-delivery (V3) attacks.

The **outbox's own at-least-once + observability guarantee is genuinely broken** in one narrow but real class:
- **P2-1** — a crashed-mid-broadcast event with `last_error=NULL` is unreachable by rescue/retry-failed, invisible to
  the staleness monitor (`whereNotNull('last_error')`) and `/ready`, then pruned at 90 d as a success. Root cause:
  `dispatched_at` is a claim marker treated everywhere as a delivery marker.
- **P2-2** — `KdsOrderRecalled` is push-only with no poll rehydration, so P2-1 (or any lost recall broadcast) becomes
  user-visible on a 2nd KDS screen, with no recovery and no alert.

Both **P2** (low probability / low V1 single-screen scope), no **P0/P1**. Recommend owner GATE on the P2-1 fix
direction (separate claim vs delivery marker, or relax the `last_error` + `attempts<5` gates) since it silently
defeats the "no data loss" invariant the whole outbox exists to guarantee. The `private-customer.{id}` item is a
VERIFY-against-standalone-repo, not a scored defect.
