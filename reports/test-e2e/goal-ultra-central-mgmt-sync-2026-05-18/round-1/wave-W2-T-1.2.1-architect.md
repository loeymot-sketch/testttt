# T-1.2.1 NF525 Chain — ARCHITECT Audit Report — Round 1

## Verdict (one line): GO-CONDITIONAL

Architecture is sound and well defended in-depth (cache lock + DB lockForUpdate + UNIQUE(branch_id, prev_hash) + retry-once + orphan-retry cron). Existing PASS tests cover correctness, but the **30-min × 10 concurrent × 3-branch acceptance harness does NOT exist** — `tests/load/RushMidiSimulationTest.php` is sequential (SQLite RefreshDatabase, lockForUpdate no-op, comment lines 47-52), and `foodking:e2e:stress` is HTTP-driven and not time-bounded. Three architectural risks remain that load conditions amplify: (a) lock-release-on-throw can lose chain coverage between FiscalSequenceService.next() and Order.save(), (b) audit chain genesis row has no protection against fork by design, (c) orphan-retry cron loops with no DLQ ceiling. None are P0 blockers in normal traffic but together they break the gap-free acceptance under pathological cache outage.

## Top findings

### [P1] app/Services/FiscalSequenceService.php:76-94 — Sequence number leaks if outer transaction rolls back AFTER lock release

trigger:
  load_mode: "≥10 concurrent kiosk + POS orders, one of them throws between FiscalSequenceService::next() (returns N+1) and the parent Order::save() in OrderService.php:922-923 / FrontendOrderService.php:1130-1136 (e.g. PricingService recompute mismatch, validation throw, idempotency conflict). The inner FiscalSequenceService transaction is a SAVEPOINT inside the outer DB::transaction, so MAX() in a concurrent caller already advanced. When the outer rolls back, the lock has been released (line 95-103 finally{}) and the next caller computes N+1 again — but the in-flight neighbour also held N+1."
  failure_mode: "Two parallel orders end up with the same fiscal_sequence_no. The orders_branch_fiscal_seq_unique DB constraint catches it (the docblock line 21-22 names this), but the caller sees a QueryException and the user-visible order create fails — fiscal monotonicity is preserved at the cost of a checkout failure under bursty load. ZERO data corruption, but order_count under load is < N (some failed). The acceptance criterion 'count = exactly (N orders × 2-3 audit rows each), sequential fiscal_sequence_no with 0 gap' is satisfied for the COMMITTED orders only — silently dropping failed orders means an external observer cannot detect 'we lost an order to a race' from the DB alone."

v2_saas_impact:
  blocks: "Multi-tenant burst (V2 SaaS = N tenants × concurrent kiosks) amplifies the collision-then-failure surface. Without per-tenant queueing the user-visible 422 rate climbs with branch count."
  enables: "Switching from MAX()+1 to a sequence table (NEXT VALUE FOR fiscal_seq_b{N}) eliminates the race entirely. V2 architectural prerequisite."

cost_of_delay_if_v1_ships:
  customer: "Rare 422 during peak rush ≈ 1 failed checkout per 1000 under SQLite-realistic load. Under MySQL + Redis production cache, expected closer to 1 per 50k."
  fiscal: "None — DB UNIQUE catches every fork, no silent gap, no DGFiP risk."
  business: "Operator-visible checkout retry needed; receipt printer doesn't fire on the failed order."

recommendation:
  scope: "Acceptance-grade fix is to add an integration test (NOT a unit test) using MySQL + Redis: forked PHP processes, 10 concurrent next() calls, observe collision rate at the QueryException level. If observed collision > 0.1%, switch FiscalSequenceService::next() to a row-level monotonic counter table (`fiscal_counters(branch_id PK, next_value)` with `SELECT ... FOR UPDATE; UPDATE +1`) — eliminates the SAVEPOINT-release race."
  rollback: "Counter table is additive — fallback path keeps current MAX()+1 logic behind FISCAL_USE_COUNTER_TABLE flag."
  owner_gate: "Y — FiscalSequenceService is frozen per CLAUDE.md §7."

### [P1] app/Services/FrontendOrderService.php:1130-1196 — Kiosk auto-allocation orphan-retry has no DLQ ceiling

trigger:
  load_mode: "Cache backend (Redis) unhealthy for >5 min during peak. `FiscalSequenceService::next()` throws RuntimeException 'could not acquire lock...' on every attempt (line 70-73 of FiscalSequenceService). `finalizePaidKioskOrder` catches the throw at line 1154-1188, flags `fiscal_alloc_error_at = now()`, returns without promoting. `RetryFiscalAllocCommand` (cron every minute, withoutOverlapping(5) + onOneServer per docblock line 36-37) refires `finalizePaidKioskOrder` — which fails the same way and re-stamps `fiscal_alloc_error_at`. Orders accumulate indefinitely on `payment_status=PAID + fiscal_sequence_no=NULL + fiscal_alloc_error_at IS NOT NULL`."
  failure_mode: "ZReportService.warnOnOrphanedPaidOrders() (line 586-616 of ZReportService) emits a fiscal-channel WARN, but does NOT block the Z close (line 612-615 'best-effort observability — never let a count() crash break a Z close'). Z aggregates exclude these orders (line 340 `whereNotNull('fiscal_sequence_no')`). NF525 invariant 'every paid receipt in exactly one Z' is *not* satisfied during the outage window — the orders are in legal limbo until cache recovers. No retry count column on the order, no DLQ table — an operator only knows from the fiscal log channel."

v2_saas_impact:
  blocks: "Multi-tenant: a single tenant's cache instance failing turns its kiosk into orphan-only. No per-tenant alerting hook today."
  enables: "Adding `fiscal_alloc_retry_count` column + DLQ-on-N-fail unblocks SLA dashboards for V2."

cost_of_delay_if_v1_ships:
  customer: "Kiosk customer received receipt + food; KDS may or may not have the order (status stays PENDING under M-08-B legacy unless POS manually collects). User-visible impact = inconsistent KDS only during cache outage."
  fiscal: "DGFiP attestation 'every paid order in exactly one Z' is technically violated during the outage. Mitigation: the retry recovers automatically, so steady-state is compliant. The window must be documented as `≤ retry_interval × max_retries` (current = 1min × ∞)."
  business: "Single-resto Le Cayenne: acceptable. SaaS: silent SLA drift."

recommendation:
  scope: "Add `fiscal_alloc_retry_count INT DEFAULT 0` to orders table; increment in finalizePaidKioskOrder catch block; once `>= config('fiscal.alloc_max_retries', 30)`, write an audit_log row `action='fiscal.alloc.dlq'` + emit Log::critical + skip future retries unless operator clears the flag. Add observability ping to /healthz."
  rollback: "Column nullable additive — feature flag fiscal.alloc_dlq_enabled gates the new branch, off-by-default."
  owner_gate: "Y for frozen-zone? Audit catch block lives in FrontendOrderService::finalizePaidKioskOrder (NOT frozen). LOCK not needed; superpower-gstack 7-step OK."

### [P2] app/Services/Fiscal/AuditLogService.php:60-66 + tests/Feature/Fiscal/AuditLogConcurrencyTest.php:56-59 — Audit chain GENESIS row has no fork protection by design

trigger:
  load_mode: "Per-branch *first ever* audit_logs write. Cache::lock + DB UNIQUE(branch_id, prev_hash) — but SQL UNIQUE treats NULLs as distinct (documented in `AuditLogConcurrencyTest.php:56-59`). If two writers slip past `audit_chain_b{N}` lock simultaneously on a *fresh* branch with empty chain, both compute prev_hash=NULL, both INSERT, and UNIQUE rejects neither."
  failure_mode: "Fork at chain root. `verifyChain()` (AuditLogService.php:199-231) walks ASC by id and the second row's prev_hash=NULL ≠ first row's current_hash → returns the second row's id as 'corrupted'. Operationally, only a cache-split-brain *during the first audit write of a branch lifetime* triggers it. Once a non-NULL row exists, UNIQUE protects."

v2_saas_impact:
  blocks: "V2 SaaS tenant provisioning — each new tenant has a fresh branch starting from empty audit_logs."
  enables: "Genesis-row protection unlocks 'cold-start' guarantees."

cost_of_delay_if_v1_ships:
  customer: "None directly."
  fiscal: "False-positive tampering alert in fiscal channel; the verifier blocks Z.open() via FiscalChainValidator.assertChainIntegrity (ZReportService.php:88-95) — operator-blocking event."
  business: "Manual recovery = surgical DELETE of one of the two genesis rows under owner gate (but DELETE is forbidden by trigger — recovery = clone-rebuild branch)."

recommendation:
  scope: "Seed a synthetic 'branch.created' audit row in BranchFactory + BranchObserver::created so prev_hash=NULL is never the live tail. 30 lines. Includes a unit test that verifies the genesis row exists for every branch_id appearing in orders."
  rollback: "Pure additive seed; no schema change."
  owner_gate: "N — Observer is not frozen; AuditLogService not modified."

## Coverage map

Call paths traced (8 ingestion points to fiscal_sequence_no + audit_logs):
- POS create order: OrderService.php:922 `next()` → save inside `saveOrderWithQueueNumber` → optional `AuditLogService::write('order.discount_applied')` at line 980 → `OrderCreated::dispatch` (AfterCommit) at line 548
- Kiosk paid finalize: FrontendOrderService.php:1130-1196 `next()` → save → `OrderStateMachine::recordTransition` → `OrderCreated::dispatch` at line 1226
- Counter payment: PaymentService.php:207 `next()` → audit `order.counter_payment_confirmed` at line 231 → `OrderPaidAtCounter::dispatch`
- Cash back / refund: PaymentService.php:123 audit `payment.cash_back_issued` (no new sequence — refunds inherit)
- Status change: OrderService.php:2006 audit `order.payment_status_changed`
- Refund post-Z: RefundWithCounterEntryService (creates NEW audit row + counter-entry order with NEW fiscal_sequence_no)
- Z close: ZReportService.php:203-273 verifies chain → aggregates → signs → updates z_reports row
- Retry cron: RetryFiscalAllocCommand → re-enters FrontendOrderService::finalizePaidKioskOrder (single SSOT path, line 25-27 docblock)

Files Read:
- app/Services/Fiscal/FiscalSequenceService.php (full)
- app/Services/Fiscal/AuditLogService.php (full)
- app/Services/Fiscal/FiscalSealingService.php (full)
- app/Services/Fiscal/FiscalChainValidator.php (full)
- app/Services/Fiscal/ZReportService.php (full)
- app/Domain/Order/OrderStateMachine.php (full)
- app/Services/OrderService.php (lines 540-560, 900-1052, 2000-2050)
- app/Services/FrontendOrderService.php (lines 1080-1237)
- app/Services/PaymentService.php (lines 100-265)
- app/Console/Commands/RetryFiscalAllocCommand.php (full)
- database/migrations/2026_05_09_180000_add_idempotency_key_to_domain_events.php (full)
- database/migrations/2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php (full)
- database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php (full)
- tests/Feature/Fiscal/AuditLogConcurrencyTest.php (full)
- tests/Feature/Fiscal/NF525ComplianceE2ETest.php (header)
- tests/load/RushMidiSimulationTest.php (header)

Test coverage gaps identified:
- **No actual 30-min × 10c × 3-branch test.** `RushMidiSimulationTest` is SQLite-serial (docblock lines 47-52 are explicit). `E2EStressCommand` is HTTP-driven but not time-bounded — needs a `--duration=30m` flag or external runner. Acceptance criterion of GOAL plan T-1.2.1 lines 158-163 cannot be attested with current tooling.
- No DLQ test for orphan-retry: `FiscalAllocOrphanRetryTest` exists but verifies success path, not infinite-retry containment.
- No genesis-fork test on virgin chain — `AuditLogConcurrencyTest` starts post-first-row.
- No "Cache::lock blocks indefinitely" simulation — would expose orphan-loop behaviour.
- No cross-branch isolation under load — current tests use sequential single-branch.

## Open questions for cross-agent synthesis

For **DBA agent**:
- Is there an index on `(payment_status, fiscal_sequence_no, fiscal_alloc_error_at)`? Without one, RetryFiscalAllocCommand `whereNotNull('fiscal_alloc_error_at')->orderBy('fiscal_alloc_error_at')` does a full-table scan once orders > 100k. Confirm execution plan.
- The `orders_branch_fiscal_seq_unique` index is named in FiscalSequenceService.php:22 — does the migration use `UNIQUE` or `UNIQUE NULLS NOT DISTINCT`? On PostgreSQL semantics matter; here MySQL/SQLite both treat NULL distinct so sparse fiscal_sequence_no rows can multiply (acceptable since pre-alloc fiscal_sequence_no=NULL is per-design).

For **Security agent**:
- `FISCAL_AUDIT_SECRET_BRANCH_{id}` env lookups (AuditLogService.php:273) — if a malicious admin manipulates env before chain validation, can they rewrite stored current_hash to validate? Confirm secret rotation procedure preserves verifyChain.
- The orphan-retry path bypasses the `assertCounterDeferredOrder` guard (PaymentService.php:197) on the second pass — verify no privilege escalation possible from inside RetryFiscalAllocCommand.

For **Fiscal agent**:
- Confirm DGFiP tolerance for the "orphan window" — how long is a paid-but-unallocated order legally acceptable before becoming a §1840-J-bis violation? Current architecture allows unbounded retry; need a regulatory ceiling.
- Z close warns on orphans but does NOT block — is this aligned with NF525 §1.4 "every receipt in exactly one Z"? My read: it's defensible because the orphan will be in the *next* Z once the retry succeeds, but DGFiP audit would see a partial-day Z with N+1 orders for the next day's aggregate. Confirm.

For **SRE agent**:
- Redis split-brain detection: is there a `Cache::has('healthcheck')` ping in /healthz that would alert ops before fiscal allocation degrades? Required upstream control for P1#2 above.
- Telemetry: `Log::channel('fiscal')->info('[FISCAL_TIMING]', ...)` (AuditLogService.php:128) emits duration but not p95/p99 aggregated metric. Need a counter+histogram (Prometheus) for the GOAL acceptance.

For **Tester agent**:
- The acceptance test `SELECT COUNT(*), MAX(current_hash) FROM audit_logs` does not validate *which* actions are present — a load that writes only `order.created` would pass count but miss the `order.payment_status_changed` rows expected for paid orders. Recommend asserting also `SELECT action, COUNT(*) FROM audit_logs GROUP BY action` matches expected per-action counts.
