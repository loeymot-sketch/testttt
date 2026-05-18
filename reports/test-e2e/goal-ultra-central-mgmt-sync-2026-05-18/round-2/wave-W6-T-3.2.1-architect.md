# Wave W6 — T-3.2.1 — ARCHITECT specialist read-only audit
**OrderStateMachine fan-out coherence (paid → preparing → ready → served)**
Date: 2026-05-18 — Round 2 — Read-only — Architect lens only.
Anchors verified live (`grep`/`find`/`Read`) 2026-05-18.

---

## VERDICT

**GREEN-with-2-conditions** for V1 (Le Cayenne, single branch).
**YELLOW** for V2 multi-operator / multi-tenant.

The state machine itself is well-defended at the read-modify-write boundary: iter15 P0-12 wrapped `apply()` in `DB::transaction + lockForUpdate` (verified by source-introspection test `OrderStateMachineLockForUpdateTest.php:38-106`); the legacy callsites in `OrderService::changeStatus` / `FrontendOrderService::changeStatus` / `KitchenDisplaySystemOrderService::changeStatus` / `PaymentService::cancelCounterPayment` re-implement the same pattern (lock + reread + guard + write + audit + after-commit dispatch). `DispatchableAfterCommit` on the 4 order events (`OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `OrderTableChanged`, `OrderPaymentStatusChanged`) closes the rollback-orphan hole that Round 1 F-T311-ARCH validated end-to-end for the outbox.

What remains soft is architectural: (a) the **admin escape hatch** at `OrderStateMachine.php:63-71` lets an Admin transition out of CANCELED / REJECTED / RETURNED to any target (including DELIVERED) with NO `requiresReason` check and NO `SealedOrderGuard::assertMutable` defense, side-stepping the layered fiscal envelope; (b) `apply()` is the SSOT for state mutation + audit but NOT for event dispatch — every caller must remember to fire `OrderStatusChanged::dispatch(...)` after the closure returns, and `apply()` does not enforce it, leaving a latent forget-to-broadcast hole every future adoption must navigate; (c) the broadcast subscription matrix is asymmetric — `OrderTableChanged` is broadcast on the branch-wide channel but only KDS subscribes; `OrderPaymentStatusChanged` is broadcast but has zero JS subscribers anywhere.

---

## TOP FINDINGS

### F-T321-ARCH-01 — Admin escape hatch from terminal states bypasses fiscal envelope
```yaml
severity: P1
category: state-graph-defense-in-depth
confidence: high
evidence:
  - app/Domain/Order/OrderStateMachine.php:63-71 — `case CANCELED / REJECTED /
    RETURNED:` returns `true` unconditionally if `$user->hasRole('Admin')`,
    regardless of target. allows(CANCELED, DELIVERED, $admin) === true.
  - app/Domain/Order/OrderStateMachine.php:260-267 — requiresReason() only
    covers transitions TO {CANCELED, REJECTED, RETURNED}; transitions FROM
    those states (the admin override) carry no reason guarantee.
  - app/Services/OrderService.php:1754-1777 — SealedOrderGuard::assertMutable
    fires ONLY on `changeStatus → RETURNED`. No equivalent guard on the
    inverse RETURNED→ANY admin override path.
  - app/Services/OrderService.php:1947-1948 — second SealedOrderGuard site is
    `changePaymentStatus → REFUNDED`. Confirmed by grep: no other call sites
    exist in app/Services or app/Domain.
  - app/Services/Order/SealedOrderGuard.php:34 — assertMutable() refuses if
    order is contained in a closed Z window; the admin terminal-state escape
    skips this entirely because it never reaches the guard.
reasoning: >
  The state machine claims to be "the SSOT for allowed transitions" (file
  docblock L15). For V1 fiscal compliance the layered defense is: (1)
  state machine guard, (2) reason enforcement, (3) sealed-Z guard, (4)
  audit log row, (5) NF525 chain. The admin override at L66-68 collapses
  layers (1)+(2)+(3) to a single `hasRole('Admin')` check — an admin user
  account compromised or misused can `apply(order, DELIVERED, $admin)`
  from RETURNED, leaving the original RETURNED audit row, the
  counter-entry refund in `audit_logs`, AND a DELIVERED row in
  `order_status_transitions`, with NO reason and the order silently
  resurrected. Fiscal verifyChain() does not break because audit_logs is
  append-only; the corruption is only visible if someone reads the
  transition sequence. NF525 doesn't require this guard explicitly but
  the cost of NOT having it is invisible silent resurrection of refunded
  orders — exactly the surface fiscal auditors investigate.
fix_direction: >
  Two minimal additions, scope-localized to OrderStateMachine.php:
  (i) Tighten allows() — Admin from a terminal state should NOT reach
  DELIVERED / PREPARED / OUT_FOR_DELIVERY. Restrict admin override target
  set to `[PENDING, ACCEPT, REJECTED, CANCELED, RETURNED]` (i.e. allow
  admin to UN-cancel back to an early state requiring re-acceptance, never
  jump straight to a fulfilled state).
  (ii) Extend `requiresReason()` to FROM-terminal admin transitions: any
  transition where `$from ∈ {CANCELED, REJECTED, RETURNED}` MUST carry a
  non-empty reason. The check is already in the `apply()` body at L228-232
  — just widen the predicate.
  Optionally bolt SealedOrderGuard::assertMutable into apply() when
  `OrderStateMachine::requiresReason($next) || from-is-terminal` — the
  guard is idempotent and side-effect-free, so the cost is negligible.
load_at_100x5min: >
  Zero throughput impact — the admin override is a manual operator action,
  not part of the kiosk/POS hot path. Currently used <1× per week per
  branch per ops history. Latent risk only.
v2_saas_impact: >
  P0 at V2: multi-tenant admin accounts increase the attack surface and
  the audit-trail divergence between tenants becomes much more visible to
  external NF525 inspection.
```

### F-T321-ARCH-02 — `apply()` is SSOT for state+audit but NOT for fan-out (silent-broadcast hole)
```yaml
severity: P2
category: architectural-debt-contract-asymmetry
confidence: high
evidence:
  - app/Domain/Order/OrderStateMachine.php:179-254 — apply() body wraps
    DB::transaction(lockForUpdate + reread + allows + write + recordTransition)
    but does NOT dispatch OrderStatusChanged at the end.
  - app/Jobs/CleanupStalePendingKioskOrders.php:60-79 — the ONLY current
    apply()-using callsite. AFTER apply() returns, the job manually
    dispatches OrderStatusChanged at L79 and OrderCanceled at L82.
  - app/Services/OrderService.php:1632 / 1707 / 1853 — legacy
    `lockForUpdate + status= + save() + recordTransition + OrderStatusChanged::dispatch`
    pattern. Each callsite repeats the dispatch by hand.
  - app/Services/FrontendOrderService.php:737 / 1232 — same pattern.
  - app/Services/PaymentService.php:463 — same pattern.
  - app/Services/KitchenDisplaySystemOrderService.php:224 — uses
    DispatchKdsTicket dispatcher (additional kitchen-release gate) but
    same caller-owned dispatch.
  - File docblock L20-23 says: "Existing OrderService / FrontendOrderService
    call sites keep their historical pattern (`$order->status = $next;
    save(); recordTransition(...)`) to honour the frozen zone V1 rule.
    The `apply()` method is the path forward."
reasoning: >
  apply() promises "atomic guard + mutate + audit" — but the consumer
  contract is: state SSOT inside the transaction, event SSOT outside the
  transaction. A future code path that adopts apply() (the documented
  V1.0.1 / V2 direction) MUST remember to dispatch OrderStatusChanged
  afterward. Today only `CleanupStalePendingKioskOrders` knows this. A
  next developer reading the docblock at L19 ("Use this from NEW call
  sites") will see "atomic guard + mutate + audit" and reasonably
  conclude the broadcast is included. It isn't. The result is a silent
  loss of KDS/POS/OSS sync the day someone migrates a legacy callsite to
  apply() without grepping for the dispatch.
fix_direction: >
  Two non-mutually-exclusive options:
  (A) Best — fold the dispatch into apply(): after recordTransition() at
  L245, call `DB::afterCommit(fn() => OrderStatusChanged::dispatch(
  $order, $from, $next))`. DispatchableAfterCommit already protects
  against double-fire if apply() is nested inside a parent transaction.
  Single source of truth = state + audit + broadcast.
  (B) Document-and-test — keep apply() narrow but add a phpunit feature
  test asserting that every apply()-using callsite (grep -rn
  "OrderStateMachine::apply" app/) is paired with a dispatch within ±20
  LOC. Brittle but cheap.
  Option A breaks the frozen-zone rule for legacy callsites (since they
  ALSO call dispatch manually → double-fire). Phase: ship A only for
  apply()-using callsites, leave the legacy pattern alone until
  V1.0.1 mass-migration.
load_at_100x5min: >
  None — this is a latent correctness hole, not a throughput defect.
  Today CleanupStalePendingKioskOrders is the only callsite and it does
  the right thing.
v2_saas_impact: >
  Increases with adoption. Each new state-changing endpoint added under
  the V1.0.1 hardening or V2 SaaS migration MUST be paired with a
  dispatch site review.
```

### F-T321-ARCH-03 — Broadcast subscription matrix is asymmetric across surfaces
```yaml
severity: P2 (V1) / P1 (V2 multi-operator)
category: cross-surface-coherence
confidence: high
evidence:
  - app/Listeners/PersistOrderTableChangedToOutbox.php:75 — channel
    `'private-branch.' . $order->branch_id` — broadcast is BRANCH-WIDE.
  - resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1775 —
    `broadcastAs: 'OrderTableChanged'` — KDS subscribes.
  - grep -rn "OrderTableChanged" resources/js/components/admin/pos
    resources/js/components/admin/orderStatusScreen → empty. POS and OSS
    do NOT subscribe.
  - app/Events/OrderPaymentStatusChanged.php — has Persist*ToOutbox
    listener (EventServiceProvider.php:156) and goes through the full
    outbox + DispatchDomainEventsJob pipeline.
  - grep -rn "OrderPaymentStatusChanged" resources/js → ZERO matches.
    No JS surface subscribes to this event at all.
  - app/Services/OrderService.php:2024 — ONLY dispatch site for
    OrderPaymentStatusChanged. Confirmed alone-dispatched (does NOT
    co-occur with OrderStatusChanged in the same closure).
  - Mitigation already present: PersistOrderStatusChangedToOutbox.php:46
    embeds `payment_status` into the OrderStatusChanged payload, so when
    BOTH events fire (e.g. POS counter payment), JS gets payment_status
    via OrderStatusChanged. Pure payment-status flips remain silent.
reasoning: >
  Backend dutifully broadcasts every domain event over the branch-wide
  private channel. The cross-surface contract is "each surface
  subscribes to the events it cares about". Two asymmetries break this:
  (1) `OrderTableChanged` (floorplan transfer + occupy) is heard by KDS
  only. A POS operator at terminal B looking at the floorplan does NOT
  see terminal A's transfer until a refetch. OSS does not display the
  table label moving in the order header. For V1 single-cashier Le
  Cayenne this is invisible (one operator at a time); for V2
  multi-operator it's a visible UX defect.
  (2) `OrderPaymentStatusChanged` is a dead broadcast at the JS layer.
  A customer-portal pay-online flow (UNPAID→PAID without status moving)
  would silently update the DB but leave KDS/POS unaware. Mitigated
  today because the kiosk/POS payment flows fire OrderStatusChanged
  alongside (status moves to ACCEPT), so the gap is dormant. Becomes
  P0 if a "pay-later" portal or table-side QR payment shipping in
  V1.0.1+ uses pure payment-status flip.
fix_direction: >
  (1) Subscribe POS (PosOrdersTrackerComponent.vue ~L540, PosComponent.vue
  ~L2157) and OSS (PreparingAndReadyComponent.vue ~L254) to
  `OrderTableChanged` — refetch on receipt (parity with the existing
  `OrderStatusChanged` handlers).
  (2) Either subscribe at least one surface to `OrderPaymentStatusChanged`
  (recommendation: POS PosOrdersTrackerComponent to refresh the payment
  indicator badge), OR delete the dead broadcast (remove
  PersistOrderPaymentStatusChangedToOutbox from EventServiceProvider) and
  let the data flow through the existing OrderStatusChanged payload
  (Round 1 F-T311-ARCH-01 already flagged the contract drift for SETTINGS
  / availability events; this is a sibling case).
  Pick (2A) before V1.0.1 ships any "pay later" / "split bill" UI.
load_at_100x5min: >
  Zero — payload sizes unchanged, just adds JS subscription. POS and
  OSS already debounce refreshes on OrderStatusChanged (KDS line 1762
  uses `_debouncedRefresh()`), same pattern for OrderTableChanged is
  cheap.
v2_saas_impact: >
  P1: multi-tenant + multi-operator surfaces the gap. Add as V2 entry
  criterion: "no broadcast event without at least one JS subscriber".
```

---

## COVERAGE MAP

### State graph (9 states, 11 legal non-identity transitions — verified via `OrderStateMachine::legalTransitions()` + unit test L196-200)

```
PENDING (1) ─→ ACCEPT (4) ─→ PREPARING (7) ─→ PREPARED (8) ─→ OUT_FOR_DELIVERY (10) ─→ DELIVERED (13) ─→ RETURNED (22)
   │              │              │                  │
   │              │              │                  └─→ DELIVERED (13)         (PREPARED skips OUT_FOR_DELIVERY for non-delivery types)
   │              │              └─→ CANCELED (16)                              [requiresReason]
   │              └─→ CANCELED (16)                                              [requiresReason]
   │              │
   │              └─→ DELIVERED (13)   IF user.hasPermissionTo('pos')  ←── POS shortcut (L41-43, L48-50)
   │
   ├─→ CANCELED (16)        [requiresReason]
   └─→ REJECTED (19)        [requiresReason]
```

**No deadlocks. No cycles in the happy path.** The only re-entry from terminal states is via the admin escape hatch at L63-71 (F-T321-ARCH-01). Identity transitions (`$from === $to`) always return `true` (L32-34) — used for idempotent re-application.

### Authz matrix (who can do what)

| From          | To              | Allowed for                                | Gate stack                                                                  |
|---------------|-----------------|--------------------------------------------|-----------------------------------------------------------------------------|
| PENDING       | ACCEPT          | Any authed user (admin / kiosk / system)   | allows() only                                                                |
| PENDING       | CANCELED/REJECTED | Any authed user (+reason required)       | allows() + requiresReason()                                                  |
| ACCEPT        | PREPARING       | Chef (KDS endpoint) / POS operator         | allows() + (KDS) KitchenReleaseRule::canTransition (KitchenDisplaySystemOrderService.php:191-193 — layered defense, chef CANNOT bypass via KDS even if allows() permits more) |
| ACCEPT        | DELIVERED       | POS operator only (`hasPermissionTo('pos')`) | allows() L41-43 — shortcut for fast-counter POS                              |
| ACCEPT        | CANCELED        | Any authed user (+reason)                  | allows() + requiresReason()                                                  |
| PREPARING     | PREPARED        | Chef (KDS endpoint)                        | allows() + KitchenReleaseRule::canTransition                                 |
| PREPARING     | DELIVERED       | POS operator only                          | allows() L48-50                                                              |
| PREPARING     | CANCELED        | Any authed user (+reason)                  | allows() + requiresReason()                                                  |
| PREPARED      | OUT_FOR_DELIVERY | Delivery dispatch (admin)                  | allows()                                                                     |
| PREPARED      | DELIVERED       | POS operator / direct hand-off             | allows()                                                                     |
| OUT_FOR_DELIVERY | DELIVERED   | Delivery boy (with ownership check)        | allows() + OrderService::deliveryBoyOrderChangeStatus owner-check L1488-1495 |
| DELIVERED     | RETURNED        | Admin / POS supervisor (+reason +SealedOrderGuard) | allows() + requiresReason() + SealedOrderGuard::assertMutable (OrderService.php:1754-1777) |
| any terminal  | **ANY**         | **Admin role only** (no reason, no Z guard) | allows() L63-71 — **F-T321-ARCH-01 hole**                                  |

### Mutation vs dispatch ordering (Question #3 — verified)

State mutates INSIDE `DB::transaction` (`apply()` OR legacy lockForUpdate pattern). Event uses `DispatchableAfterCommit` (verified for `OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `OrderTableChanged`, `OrderPaymentStatusChanged`). The trait defers dispatch to `DB::afterCommit`, so:
- if the transaction rolls back, the event is **dropped entirely** (no orphan broadcast);
- listeners receive the event AFTER commit, write the outbox row in a NEW transaction, and `DB::afterCommit` chains the queue dispatch;
- the broadcast itself happens via `DispatchDomainEventsJob` (Round 1 F-T311 verified — claim-and-lock + retry/backoff cover Pusher failure modes).

Result: mutation is the source of truth; broadcast is best-effort with at-least-once + dedupe (idempotency_key). **Listener failure does not roll back state** — outbox row is durable, retry cron rescues. This is the textbook correct decoupling for an outbox pattern.

### Race conditions in state transitions (Question #4 — verified)

`OrderStateMachine::apply()` at L208-253 wraps the lockForUpdate read + guard + write in `DB::transaction`. Two concurrent `apply($order, DELIVERED)` calls now serialize on the InnoDB row lock; the second tx reads `from === DELIVERED` and bails out via the idempotent early-return at L215-220 (no double audit row). Verified by `OrderStateMachineLockForUpdateTest.php:108-147` (behavioural) + source-introspection L38-106 (lockForUpdate present inside transaction).

Legacy callsites (`OrderService.php:1525`, `:1670`, `:1894`; `FrontendOrderService.php:1192`; `PaymentService.php:413`; `KitchenDisplaySystemOrderService.php:159`) all use the same lockForUpdate + idempotent-early-return shape. **No race remains.** Round 1 F-T311-ARCH-03 noted that SQLite tests don't truly exercise FOR UPDATE; this is unchanged tech debt — MySQL InnoDB behaves correctly in production but is unproven by suite.

### Stale state recovery on disconnect (Question #6)

KDS reconnect path uses `KdsSyncService::sync()` (anchor read fully). `since` stamp + `whereIn(status, [ACCEPT, PREPARING, PREPARED])` returns deltas; `deleted_ids` covers orders that left the active window. `version = computeOrderVersion()` is currently `updated_at` unix — Eloquent updates `updated_at` on every `save()` so status-only writes ARE visible. The TODO at L130-136 about adding `status_changed_at` is the right direction but not a V1 blocker — every existing mutation path saves the model, refreshing the timestamp.

Re-sync is a 5-second cached Cache::remember (L49) — bounded fan-in protection. **No correctness gap.**

### Cross-reference Round 1

Round 1 F-T311-ARCH-01 (EventContract REQUIRED_PAYLOAD_KEYS drift) and F-T311-ARCH-02 (Pusher size guard absent) are upstream of the state-machine fan-out: an `OrderStatusChanged` envelope that drifts at the payload level or oversizes silently terminates at the broadcast layer, even though state + audit committed correctly. **F-T321-ARCH-01/02/03 are independent of Round 1 — they cover the state machine + dispatch contract; Round 1 covered the outbox pipeline.** Composed correctness: state lockForUpdate (T-3.2.1) + outbox claim-lock (T-3.1.1) = both races covered.

---

## OPEN QUESTIONS

1. **`OrderPaymentStatusChanged` is a dead broadcast** — verified zero JS subscribers. F-T321-ARCH-03 covers the cross-surface gap, but the deeper architectural question is: should the outbox + JS BROADCAST_MAP enforce a 1-to-≥1 publisher/subscriber rule via a unit test that fails CI when an event ships with no subscribers? Sibling to Round 1 F-T311-ARCH-01 (BROADCAST_MAP drift).

2. **`OrderStateMachine::isKitchenReleaseTransition()` duplicates `KitchenReleaseRule::canTransition()`** — same predicate, two locations (OrderStateMachine.php:101-109 + KitchenReleaseRule.php:41-49). Drift risk if one is edited without the other. Recommend consolidation: KitchenReleaseRule is the KDS-specific gate, OrderStateMachine should defer to it (or vice versa). P3 polish.

3. **`KitchenDisplaySystemOrderService::changeStatus` expects `expected_status`** (L156, L171) but no other state-change endpoint enforces the same optimistic-concurrency contract. POS / mobile / kiosk cancel paths rely solely on lockForUpdate + idempotent-early-return. Question: is the KDS-only `expected_status` 409 path a deliberate UX choice (chef sees stale card → refresh prompt) or an architectural inconsistency to roll back? V1 leave-as-is, V2 normalize.

4. **`OrderTableChanged.action = 'occupy' | 'transfer'`** — the event union is documented in `Events/OrderTableChanged.php:33`, but `EventContract::REQUIRED_PAYLOAD_KEYS` (Round 1 F-T311-ARCH-01) does not enumerate the allowed values. JS consumer (`KitchenDisplaySystemComponent.vue:2204`) doesn't validate — silent rendering bug if a future emitter uses `'release'` or `'reassign'`. P2 contract polish.

5. **`OrderStateMachine::recordTransition()` swallows failures with `Log::warning`** (L156-158) — by design (audit best-effort, no NF525 dependency). Question: at what failure rate does silent best-effort become a P0? Today no metric counts dropped audit rows. Add a structured `transition_audit_dropped` counter to SyncMetricsRecorder. P3 observability.

---

## What fails first at 100 orders × 5 min?

**Nothing in the state machine itself.** State-change endpoints are O(1) per request (single row lock + 1 INSERT for audit). Even at 20 evt/sec sustained, MySQL InnoDB row contention on a single order row is bounded by the rate of state changes per order (~3-5 over a typical 10-min lifecycle). No global locks, no cross-order contention. The PoS shortcut `ACCEPT → DELIVERED` (L41-43) skips intermediate transitions, reducing the per-order row-lock acquisition count for fast-counter orders.

**At 10× scale (200 evt/sec sustained):** still no state-machine throughput defect. First-failing surface remains the outbox/Pusher fan-out (Round 1 F-T311-OQ#1 — Horizon `maxProcesses=8` ceiling at ~160 evt/sec broadcast). State machine writes succeed at DB speed; broadcasts queue behind the Pusher ack rate.

**Pusher 5-min restart incident:** state transitions continue to mutate + audit correctly. Outbox rows accumulate, `DispatchDomainEventsJob` retries with backoff (1s, 5s, 15s, 60s, 300s), `outbox:monitor` pages at 30s staleness (Round 1 verified). KDS/POS subscribers experience a 5-min visibility delay but eventually see all transitions in order (outbox FIFO via aggregate_id + correlation_id ordering on the JS side). **No data loss.**

**The only V1-relevant fragility** is F-T321-ARCH-01 (admin terminal-state escape) — orthogonal to throughput; it's a manual-action defense gap, not a load-time failure.
