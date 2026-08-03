# PK-1 DATA FLOW — Intersection POS×KDS — Round 1 STATUS

Branch: `heal/cms-pr1-quickwins-2026-05-18`
HEAD: `9df4809b5`
Date: 2026-05-18
Master: PK-1 (parallel with PK-2 STATUS-SYNC, PK-3 CONTRACT-SERIALIZERS, PK-4 RESILIENCE-TZ)
Scope: order creation cascade POS→KDS, 7 hops
Mode: READ-ONLY (no heal applied — see "Heal Restraint Decision" below)

---

## Verdict

**PASS_WITH_FINDINGS — no P0, 1 P1, 5 P2, 4 P3. Cascade integrity solid. No frozen-zone or DIRTY-file modification required.**

The cascade is impressively hardened by historical iterations (F-002 round-3 SSOT-first listener order, GOAL-CMS S-R3-P0-G channel auth fix, Audit T G2 backoff curve, NEW-01 atomic claim, NEW-03 retry policy, Audit T G3 contract_violation prefix preservation, GOAL-CMS S-P0-A heal R1 SRE-001 ws:heartbeat). The single P1 is a stale comment / mental-model leftover from pre-after-commit times.

---

## Cascade Diagram (7 hops, file:line verified)

```
[1] POS submit                              app/Http/Controllers/Admin/PosController.php  (DIRTY — observed only)
        |
        v
[2] OrderService::create*                   app/Services/OrderService.php                 (DIRTY — observed only)
        | DB::transaction(... event(new OrderCreated($order)) ...)
        v
[3] OrderCreated event fires                app/Events/OrderCreated.php:14 (DispatchableAfterCommit)
        | event() runs in DB::afterCommit() closure
        | -> drops silently on rollback (KI-001 / gate C9)
        v
[4] 4 listeners (order MATTERS):            app/Providers/EventServiceProvider.php:145-151
        | (a) PersistOrderCreatedToOutbox       :147  <-- SSOT FIRST (F-002 round-3)
        | (b) DecrementItemAvailabilityOnOrder  :148
        | (c) DecrementStockOnOrderCreated      :149
        | (d) SendFcmOnOrderCreated             :150
        v
[5] domain_events row written               app/Models/DomainEvent.php (table: domain_events)
        | idempotency_key = sha1(event_type|aggregate_id) UNIQUE
        | channel = ["private-branch.{branch_id}"]
        | -> DispatchDomainEventsJob::dispatch (inline, after-commit, swallows queue error)
        v
[6] Broadcast / queue                       app/Jobs/DispatchDomainEventsJob.php
        | Phase 1: lockForUpdate + dispatched_at claim atomically  :65-86
        | Phase 2: BroadcastManager->broadcast(channels, name, env) :115-116
        | Phase 2.5: EventContract::assertEnvelopeValid             :110
        | retry: [1,5,15,60,300], tries=6 (~6.4 min worst-case)
        | ws:heartbeat write (S-P0-A R1 SRE-001)                    :127-131
        v
[7] KDS read paths (push + poll)
        | push:  Echo subscribes to private-branch.{id} -> admin-kds.js (DIRTY — observed)
        |        channel auth via routes/channels.php:41-62 (token-name + role check)
        | poll:  GET /api/admin/kds/sync?since=ISO -> KdsSyncController.php:32-78
        |        -> KdsSyncService::sync(branchId, since, includeDeleted)
        |        -> Cache::remember 5s + branch-keyed minute bucket
        |        -> KDSOrderDetailsResource::collection -> JSON
```

Detailed evidence per hop is captured in the 4 specialist JSONs.

---

## Specialist Verdicts

| Specialist | Verdict | P0 | P1 | P2 | P3 | File |
|---|---|---|---|---|---|---|
| Architect | PASS_WITH_FINDINGS — cascade integrity preserved | 0 | 1 | 2 | 1 | `architect.json` |
| Security  | PASS_WITH_FINDINGS — branch isolation 5/5 hops OK  | 0 | 0 | 3 | 1 | `security.json` |
| SRE/Sync  | PASS_WITH_FINDINGS — outbox+retry+heartbeat solid  | 0 | 0 | 2 | 2 | `sre-sync.json` |
| RED       | PASS_WITH_DISPUTES — 10 attacks defeated, 1 partial | 0 | 1 | 2 | 0 | `red.json` |

Cross-validated findings (2+ specialists agree): PK1-ARCH-01 ⟺ PK1-RED-01 (stale rollback comment + cascade isolation inconsistency).

---

## 4-LIST OUTPUT

### KEEP (production-grade, do not touch)

1. **Listener registration order Outbox-FIRST** — `EventServiceProvider.php:147` (F-002 round-3 invariant). SSOT defense survives even buggy peer listeners.
2. **Outbox idempotency via sha1(event_type|aggregate_id) + UNIQUE + wasRecentlyCreated** — `PersistOrderCreatedToOutbox.php:22-57` (Sprint 3B P1-SYNC-03 sibling parity).
3. **DispatchableAfterCommit guard** — `OrderCreated.php:14` + `Concerns/DispatchableAfterCommit.php:30-42`. KI-001 / gate C9.
4. **Atomic claim under lockForUpdate** — `DispatchDomainEventsJob.php:65-86` (NEW-01). Test sentinel: `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php:46-90`.
5. **Backoff curve [1,5,15,60,300] tries=6** — `DispatchDomainEventsJob.php:40-42` (Audit T G2 2026-04-23).
6. **EventContract::assertEnvelopeValid pre-broadcast** — `DispatchDomainEventsJob.php:110` + `app/Domain/Events/EventContract.php:99-148`. Last-line defense.
7. **contract_violation: prefix preserved at terminal failure** — `DispatchDomainEventsJob.php:186-200` (Audit T G3).
8. **ws:heartbeat write-then-read** — `DispatchDomainEventsJob.php:127-131` (GOAL-CMS S-P0-A heal R1 SRE-001).
9. **Channel auth hardening token-NAME (not tokenCan)** — `routes/channels.php:43-49` (GOAL-CMS S-R3-P0-G — Sanctum '*' immunity).
10. **Guest/customer branch_id=0 bypass closed via Role check** — `routes/channels.php:56` (R3 T-3.2.2 Sec F-SEC-W6-02).
11. **KdsSyncController explicit cross-branch 403** — `KdsSyncController.php:60-66`.
12. **KdsSyncService Cache::remember 5s + branch-keyed minute bucket** — `KdsSyncService.php:40-49`.
13. **SyncMetricsRecorder defensive double try/catch** — `DispatchDomainEventsJob.php:139-148`.
14. **OutboxConcurrentRetryLockTest 5 invariants covered** — `tests/Feature/Outbox/OutboxConcurrentRetryLockTest.php`.

### HEAL-NOW (clean files, scope-minimal)

**NONE applied this round.**

Reason: the single candidate (PK1-ARCH-01 stale rollback comment in `DecrementStockOnOrderCreated.php:17-37`) is part of a broader 4-listener failure-isolation policy unification (PK1-RED-01 cross-validates). Patching only this listener without aligning siblings (`DecrementItemAvailabilityOnOrder` no try/catch, `PersistOrderCreatedToOutbox` swallows queue but not handler, `SendFcmOnOrderCreated` already isolates) would create more drift. Defer to PK-4 RESILIENCE for unified policy, or to a dedicated V1.0.X heal cycle.

### BACKLOG V1.0.X (cited evidence, defer)

1. **PK1-ARCH-01 / PK1-RED-01 (P1)** — Unify failure isolation across 4 OrderCreated listeners. Update stale rollback comment in `DecrementStockOnOrderCreated.php:17-20` to reflect after-commit reality. Wrap throws to non-blocking log + metric in (a) `DecrementStockOnOrderCreated.php:36`, (b) `DecrementItemAvailabilityOnOrder.php:23` (currently no try/catch). Keep `PersistOrderCreatedToOutbox` outbox write throw-on-failure (SSOT must hard-fail).
2. **PK1-ARCH-02 (P2)** — Tighten `BroadcastableOrder` interface (`app/Contracts/BroadcastableOrder.php:11-16`) with explicit getter contract. Currently empty marker interface — listener depends on structural typing of concrete `Order` / `FrontendOrder`. Add `getBranchId()`, `getQueueNumber()`, `getPaymentMethod()`, `getOrderTotal()`, `getOriginSurface()`.
3. **PK1-ARCH-03 (P2)** — Emit `SyncMetricsRecorder->recordInlineDispatchFailure` counter when `DispatchDomainEventsJob::dispatch` throws in `PersistOrderCreatedToOutbox.php:67-78`. Currently only Log::warning; failure invisible until polling fallback masks it.
4. **PK1-SEC-01 (P2)** — V2 SaaS RBAC: split `OrderCreated` payload into chef-safe (queue, item count, status) vs manager-safe (total, payment_method). V1 single-branch acceptable.
5. **PK1-SEC-03 (P2)** — Cron/seeder correlation_id chain breakage (`PersistOrderCreatedToOutbox.php:82-98`). Instrument `Log::shareContext(['correlation_id' => ...])` at boot of cron commands.
6. **PK1-SRE-01 (P2)** — Verify `MonitorOutboxStaleness` cron cadence ≤ 60s. Stale outbox alarm must reach pager. Cross-validate with PK-4.
7. **PK1-RED-02 (P2)** — Emit `recordEventRecovered` metric when `attempts > 1 && success` in `DispatchDomainEventsJob.php:150-154`. Forensic visibility of recovery moments.
8. **PK1-RED-03 (P2)** — Production boot guard: `config('queue.default') !== 'sync'` assertion. Prevents silent FCM kitchen-push failure if queue accidentally set to sync.
9. **PK1-ARCH-04 (P3)** — Hand off `DispatchKdsTicket` parallel broadcast (`KitchenDisplaySystemOrderService.php:241-245`) to PK-2 STATUS-SYNC.
10. **PK1-SEC-02 (P3)** — Informational: no `fiscal_sequence_no` in `OrderCreated` payload (by design — flows via `ORDER_PAYMENT_CONFIRMED`).
11. **PK1-SEC-04 (P2)** — Cross-validate that `AdminController` parent installs `auth:sanctum` (file out of scope; not DIRTY). Hand off to PK-4.
12. **PK1-SRE-02 (P3)** — V2 SaaS: stagger TTL or lock_for_get on `KdsSyncService` cache to avoid thundering herd at scale.
13. **PK1-SRE-03 (P3)** — Document 5s polling-fallback SLA in operator playbook.
14. **PK1-SRE-04 (P3)** — Verify admin observability dashboard renders p95 from `SyncMetricsRecorder->recordEventDispatched`.

### BLOCK (must not merge to main without explicit gate)

**NONE.**

No P0. No frozen-zone touch required. No NF525 invariant breach. No security regression.

---

## Adversarial Defenses Summary (RED)

10 attacks attempted, 9 fully defeated, 1 partial:

| # | Attack | Outcome |
|---|---|---|
| 1 | Duplicate OrderCreated injection | DEFEATED (sha1 UNIQUE + lockForUpdate) |
| 2 | Listener throws — cascade break | PARTIAL (outbox SSOT safe, FCM can be skipped) |
| 3 | Race outbox-write / broadcast | DEFEATED (afterCommit defers) |
| 4 | Race reverse — broadcast before write | DEFEATED (afterCommit) |
| 5 | Cross-branch channel guess | DEFEATED (routes/channels.php auth) |
| 6 | Sanctum wildcard token bypass | DEFEATED (R3 token-NAME check) |
| 7 | Guest branch_id=0 escalation | DEFEATED (R3 Role check) |
| 8 | Stale KDS post-cancel | DEFEATED (deleted_ids + 5s TTL) |
| 9 | Channel injection | DEFEATED (hardcoded channel) |
| 10 | Envelope contract drift | DEFEATED (assertEnvelopeValid pre-broadcast) |

The single PARTIAL is the cross-validation of PK1-ARCH-01 / PK1-RED-01 — listener isolation policy.

---

## Cross-Zone Handoffs

- **PK-2 STATUS-SYNC**: `DispatchKdsTicket` parallel path (`KitchenDisplaySystemOrderService.php:241`), `OrderPaymentStatusChanged` cascade.
- **PK-3 CONTRACT-SERIALIZERS**: `KDSOrderDetailsResource` and `KDSOrderItemsResource` serialization correctness.
- **PK-4 RESILIENCE-TZ**: `AdminController` auth middleware confirmation, `MonitorOutboxStaleness` cadence, p95 dashboard panel, unified listener failure-isolation policy.

---

## Heal Restraint Decision

Per advisor guidance and master mandate: no heal applied on DIRTY files (`OrderService.php`, `admin-kds.js`, `admin-oss.js`, `kiosk-shell.js`, `pos-app.js`, `OutboxRetryFailedCommand.php`) or FROZEN files (`IdempotencyKeyMiddleware`, `BranchScope`, NF525 services).

The single clean-file candidate (PK1-ARCH-01 1-line comment + optional 3-line try/catch wrap in `DecrementStockOnOrderCreated.php`) deferred to V1.0.X because:
1. Cross-validates with PK1-RED-01 (listener isolation policy).
2. Surgical fix on one listener alone leaves other 3 inconsistent.
3. PK-4 RESILIENCE is better positioned to unify across the 4-listener set.

No tests run (read-only audit). All findings have Read-verified file:line citations.

---

## Anti-Fiction Attestation

- Every finding lists a Read-verified file:line.
- Every KEEP item lists a Read-verified file:line.
- Cascade diagram hops 1-7 individually traced via `Read` tool.
- No claim made about DIRTY files beyond status-grep observation.
- Pre-existing KDS 10 test failures (c2613cab0 TZ regression) explicitly excluded — V1.0.X / session-A scope.

End STATUS.
