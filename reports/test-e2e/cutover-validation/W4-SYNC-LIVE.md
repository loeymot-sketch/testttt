# W4 — SYNC live under chaos (closes SYNC-E2E-01)

**Date:** 2026-06-05 · Worktree pre-cloud-exec · infra: soketi:6001 UP, queue:work redis --queue=high UP, redis UP.

## ⭐ Headline: the real-time sync cascade is PROVEN live (outbox → worker(high) → soketi → client)

### Live OrderCreated dispatch proof (current code, real order)
Fired `event(new OrderCreated($order))` for real order **#4215** (branch 1, queue_number A0054):
- → `domain_events #8289` created with payload keys `total,status,_origin,order_id,created_at,order_type,queue_number,payment_method,payment_status,payment_pending_counter` — **all required keys present**.
- → after ~5s: **`#8289 dispatched_at = 2026-06-05 20:07:50`** (worker consumed `high` queue, pushed to soketi). No failure.

### Corroborating evidence
- `#8287 OrderPaidAtCounter` + `#8288 OrderStatusChanged` **dispatched today 18:31:54** (live path active).
- Prior turn: a client subscribed to `private-branch.1` **received** a real `OrderStatusChanged` WS push.
- Queue alignment verified: worker `--queue=high` == `DispatchDomainEventsJob::onQueue('high')` (the PR-01 mismatch is NOT present).
- Payload-validation guard works: malformed events → `PayloadMismatchException` → failed_jobs, `--tries=1` (no retry storm, no bad data to KDS). Sentinel `PayloadMismatchFailOnceSentinelTest`.

### No-data-loss invariant — held
The 2 PENDING outbox rows (`#8283/#8285`, created 05:49) carry **order_id 99999 / 50000 = synthetic test
artifacts** (soak/stress run), NOT real orders. Their payloads predate the `queue_number/_origin/payment_method`
key schema → correctly rejected as dead-letter. **No real order was lost.** Chain attestation unchanged
(br1 audit_logs=2697 baseline) — sync re-broadcast does not touch fiscal.

## Findings (triaged per §0.3)

| # | Finding | Triage | Action |
|---|---|---|---|
| SY-1 | Live cascade OrderCreated/StatusChanged/PaidAtCounter → soketi | ✅ **PASS** | proven dispatched (#8289 5s, #8287/#8288 today) |
| SY-2 | 2 PENDING outbox rows are synthetic test dead-letter | **📋 housekeeping** | order 99999/50000 not real; payload guard correctly rejects; optional cleanup, no data loss |
| SY-3 | No auto-replay sweeper / dead-letter cleanup for genuinely-stuck rows; `MonitorOutboxStaleness` logs but does not alert | **☁️ CLOUD-PREP** | core-bulletproof PR-04. For cloud: (1) **supervised worker** (systemd/supervisor) so worker-down = seconds; (2) scheduled outbox sweeper re-dispatching PENDING > N min with dead-letter cap; (3) staleness monitor → alert. NOT a V1 blocker (poll fallback = no data loss). |
| SY-4 | Degradation (worker/soketi down) → poll fallback | ✅ **PASS (evidence+code)** | outbox persists durably (rows survived 14h worker-gap); KDS poll fallback reads orders directly (SYNC_CONTRACT §7, Phase-3 "Mode admin centralisé 60s" confirmed). **Destructive kill-drill deferred to isolated staging** — the shared dev worker/soketi serve sibling jobs; killing them here would be irresponsible. |

## SYNC-E2E-01 (prior open gate) → CLOSED
The Phase-3 finding "real-time sync not exercised (soketi was down)" is resolved: soketi is UP and a fresh
OrderCreated dispatched end-to-end live. The realtime path is validated for V1 branch 1.

**W4 STATUS: CLOSED — live cascade proven, 0 V1 blocker, 1 cloud-prep (outbox supervision/sweeper, SY-3).**
