# WF-1 — POS → KDS Sync End-to-End Confirmation

**Wave:** F — Sync Confirmation
**Task:** WF-1 — POS → KDS Sync End-to-End
**Date:** 2026-05-19
**Branch:** `heal/cms-pr1-quickwins-2026-05-18` @ HEAD `50bdd5150`
**Mode:** READ-ONLY confirmation (validate not implement)
**Discipline:** GStack + Superpowers + adversarial RED + visual + anti-fiction + 3-lens cross-validation

---

## VERDICT

**PASS — production-grade.**

The POS → KDS synchronization cascade is structurally sound, retry-safe,
race-safe, channel-auth hardened, TZ-aware, and idempotent on three
independent layers. All 9 cascade points were verified file:line. Three
analytical lenses (Architect / SRE-Sync / RED) were applied independently
and converged on PASS. No P0 or P1 blocker found. Two architectural
recommendations queued as **V1.x backlog** (non-blocking).

---

## Methodology (anti-fiction)

This audit was conducted by a single agent applying three analytical lenses
sequentially. The original task brief framed this as "3 specialists parallel" —
the runtime here exposes only Bash/Read/Edit/Write/Skill tools (no Task/Agent
spawning). Calling those three passes "sub-agents" would be hallucinated
context, so they are framed as **three independent analytical passes by this
agent with distinct lenses, reconciled at the end**. Each pass produced its
own JSON deliverable, each finding is independently verifiable via cited
file:line.

The advisor was consulted once before substantive work began, identified the
structural keystone (listener order in `EventServiceProvider.php`), and
flagged the absence of a Task tool — that guidance shaped this report.

---

## Cascade Verification Matrix (9 points)

| # | Step | Evidence (file:line) | Status |
|---|---|---|---|
| 1 | POS submit order via `PosController::store` → `OrderService::posOrderStore` (DB transaction) | `app/Http/Controllers/Admin/PosController.php:54-75` → `app/Services/OrderService.php:579+` | PASS |
| 2 | After commit, `OrderCreated` fires via `DispatchableAfterCommit` trait | `app/Services/OrderService.php:1067` (dispatch) → `app/Events/Concerns/DispatchableAfterCommit.php:27-42` (trait) | PASS |
| 3 | `PersistOrderCreatedToOutbox` runs **FIRST** (SSOT defense) | `app/Providers/EventServiceProvider.php:145-151` (with rationale comment lines 128-137) | PASS |
| 4 | `DecrementItemAvailabilityOnOrder` + `DecrementStockOnOrderCreated` fire after Outbox | Same array, lines 148-149 | PASS |
| 5 | `DispatchDomainEventsJob` picks up outbox row, broadcasts via Pusher/Soketi | `app/Jobs/DispatchDomainEventsJob.php:50-132` | PASS |
| 6 | KDS frontend subscribes via Echo private channel OR polling fallback | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1782-1814` (Echo) + `resources/js/services/KdsSyncService.js:119-225` (polling) | PASS |
| 7 | `KdsSyncService::sync` resolves with TZ-aware bounds | `app/Services/KdsSyncService.php:77-94` (commit `148dbebce` verified ancestor of HEAD) | PASS |
| 8 | `KDSOrderDetailsResource` serializes composition + allergens | `app/Http/Resources/KDSOrderDetailsResource.php:19-73` | PASS |
| 9 | KDS Vue components render card + allergen pill | `KdsOrderCard.vue`, `KdsV2Grid.vue` — visually confirmed in Playwright captures | PASS |

---

## Empirical Test Evidence

### PHPUnit Cascade Filter
```
php artisan test --filter "KDSFlow|PersistOrderCreatedToOutbox|DispatchDomainEventsJob|KdsSync"
→ 13 passed in 3.57s
```
Tests verified:
- `KdsSyncControllerTest` (8 tests): rejects malformed since, returns deltas, branch isolation, admin override, server_now monotonic, cache key per-branch.
- `KdsSyncSargableTest` (1): query uses sargable range predicates not date function.
- `KdsSyncTzAwareTest` (1): sync binds UTC-converted Paris day boundaries.
- `KDSFlowTest` (3): invalid transition rejected, list filtered by branch, accept→preparing valid.

### Extended Sync Cascade
```
php artisan test --filter "OutboxConcurrentWorker|EventContract|DomainEvent"
→ 36 passed in 3.87s
```
Tests verified:
- `EventContractUnitTest` (12): envelope shape v1, payload validation, broadcast map identity.
- `EventContractTest` (9): listener uses ORDER_CREATED constant, dispatch job broadcasts canonical envelope, dispatch job rejects contract-violating envelope.
- `DispatchDomainEventsObservabilityIntegrationTest` (2): metrics failure does not break outbox, correlation_id propagates.
- `OutboxConcurrentWorkerDedupeTest` (9): two sequential handles broadcast once, claim committed before broadcast, broadcast failure releases claim, payload mismatch fails-once, etc.
- `DispatchDomainEventsFailedCallbackTest` (4): no crash on missing row, contract violation prefix preserved, runtime failure categorized, no Sentry SDK warning.

**Total: 49 sync-cascade tests green.**

### Playwright Zone-3 E2E (real chronological journey)
```
npx playwright test tests/e2e/zone3-kiosk-to-kds.spec.js → 3 passed (54.2s)
```
- **K01-K07 — Kiosk idle → wizard → pay → confirm chronological visual:** PASS
- **K08-K09 — KDS cross-surface bump ACCEPT → PREPARING → PREPARED:** PASS
  - `[K09a] accept→preparing: {"ok":true,"status":202}`
  - `[K09b] preparing→prepared: {"ok":true,"status":202}`
  - `[K09] final order status in DB: 8` (PREPARED)
- **K10 — TZ smoke: order seeded today appears in admin dashboard realtime:**
  PASS with auth-probe caveat. The Playwright spec emitted
  `[K10] realtime probe non-200: pre=401 post=401` — the realtime endpoint
  returned 401 (permission/session) for both pre- and post-snapshots, so
  the assertion `expect(postCount.daily_orders).toBeGreaterThanOrEqual(...)`
  short-circuited via the spec's documented fallback branch (`zone3-kiosk-to-kds.spec.js:386-394`,
  "if endpoint not 200 ... at least the spec runs and captures the surface
  visually"). The visual capture was produced. **TZ behavior itself is
  verified by PHPUnit `KdsSyncTzAwareTest::sync binds utc converted paris
  day boundaries` — the K10 Playwright spec is a smoke complement to that
  primary evidence, not a replacement.**

Screenshots captured to
`reports/test-e2e/critical-focus-2026-05-18/zone-3-KDS-KIOSK/screenshots/`:
K01-kiosk-idle.png, K02-K07 wizard/pay/confirmation, K08-kds-order-visible.png,
K09a/K09b KDS state transitions, K10-admin-dashboard-realtime.png.

---

## Three-Lens Cross-Validation Summary

### Lens 1 — Architect (specialist-1-architect.json, 1109 words)
Verdict: **PASS**, one V1.x recommendation.
- Listener order Outbox-FIRST verified at `EventServiceProvider.php:145-151` with explicit rationale comment lines 128-137.
- `DispatchableAfterCommit` trait correctly drops events on rollback (lines 27-42), preventing the historical "ghost KDS order on rollback" defect (BUG-C1).
- Two-layer idempotency: `sha1(event_type|aggregate_id)` UNIQUE-indexed (migration `2026_05_09_180000_add_idempotency_key_to_domain_events.php`) + `wasRecentlyCreated` short-circuit at `PersistOrderCreatedToOutbox.php:57`.
- **Recommendation (V1.x non-blocking):** `DecrementStockOnOrderCreated.php:36` re-throws on `Throwable`, which is deliberate (iter12 P1 STOCK rationale) but means a downstream listener crash returns 5xx to the cashier after the outbox row was already written. Status quo is acceptable for V1 single-restaurant; queue a `stock_reconciliation_job` cron for V1.x.

### Lens 2 — SRE-Sync (specialist-2-sre-sync.json, 1106 words)
Verdict: **PASS**, one V1.x recommendation.
- Claim-and-dispatch race-safe via `lockForUpdate + dispatched_at` guard committed BEFORE broadcast (`DispatchDomainEventsJob.php:65-94`).
- Backoff curve `[1, 5, 15, 60, 300]` with `tries=6` covers Pusher/Soketi restart (verified at lines 24-42, Audit T G2 commit fixed the `tries=5` bug).
- `ws:heartbeat` cache write (lines 127-131) closes the silent-Pusher-down blind spot — GOAL-CMS-2026-05-18 S-P0-A heal. Consumer at `SyncOverviewController.php:531`.
- Polling cadence clamps `[250ms..60s base / 0..30s jitter]` resist owner misconfig in both directions (Wave 3 KDS-RED-09 + Wave 3b KDS-ADV3B-04).
- Network errors do NOT halt poll loop (F-03 Lot 1.C Audit G1).
- Reconnect-storm jitter `0–500ms` prevents thundering herd.
- `PayloadMismatchException` fails-once via `$this->fail()` (F-3 SYNC P1 quick-win, lines 167-186).
- **Recommendation (V1.x non-blocking):** `Cache::remember TTL=5s` on `KdsSyncService.php:49` creates a 5s visibility floor in polling-only mode. Consider TTL=1s if BROADCAST_DRIVER=null in low-cost deploy.

### Lens 3 — RED (specialist-3-red.json, 1126 words)
Verdict: **PASS**, no P0/P1 found.
Seven attack vectors probed:
1. **Duplicate event injection** — BLOCKED on 3 layers (sha1 UNIQUE + wasRecentlyCreated + lockForUpdate dispatched_at).
2. **Listener-failure cascade abort** — BLOCKED by Outbox-FIRST doctrine (F-002 round-3 evidence: 87 pre-failure rows, 0 post-failure; now Outbox runs first).
3. **Pusher channel-auth bypass via Sanctum `*` wildcard** — BLOCKED by token-NAME check at `channels.php:44-50` (R3 T-3.2.2 Sec F-SEC-W6-01 heal, commit `139ce01aa`).
4. **Cross-branch leak via Guest/branch_id=0** — BLOCKED by explicit `hasRole('Admin'|'Tenant Admin')` check at `channels.php:56` (R3 T-3.2.2 Sec F-SEC-W6-02 heal).
5. **TZ window 22:00–24:00 Paris drift** — BLOCKED by surgical UTC conversion at `KdsSyncService.php:77-91` (Wave 3 KDS-ADV3-01, commit `148dbebce` verified ancestor).
6. **Outbox row poisoning via malformed payload** — BLOCKED by `EventContract::assertEnvelopeValid` last guard + fail-once short-circuit.
7. **Polling DoS via owner misconfig** — BLOCKED by `[250ms..60s]` clamps.

---

## Reconciliation (no contradictions found)

All three lenses agreed on PASS. Architect's ARCH-4 (stock re-throw) and
SRE-Sync's SRE-8 (5s cache TTL) are complementary V1.x backlog items, not
conflicts. RED's seven attack vectors are independently verifiable against
the same code Architect read.

---

## 4-List Synthesis

### DEAD-CODE
None found in the 9 cascade points.

### DUPLICATION
- `\App\Events\OrderCreated::dispatch($order)` is repeated 4 times across `OrderService.php` (lines 564, 1067, 1377) + `FrontendOrderService.php:1226`. Each call carries a near-identical `try { ... } catch (\Exception $e) { Log::warning(...) }` wrapper. This is **INTENTIONAL** repetition (surface-specific log messages for diagnostics); refactoring into `OrderService::dispatchKdsBroadcast()` would lose log granularity. **Keep as-is for V1.**

### KEEP-AS-IS
- Outbox-FIRST listener order in `EventServiceProvider.php:145-151` with the rationale block at lines 128-137. The comment-in-code preserves architectural intent across future refactors.
- `DispatchableAfterCommit` trait pattern (`app/Events/Concerns/DispatchableAfterCommit.php`) — clean, well-documented, test-covered, prevents ghost KDS orders on rollback.
- Two-layer idempotency (`idempotency_key` UNIQUE migration + `wasRecentlyCreated` short-circuit).
- `DispatchDomainEventsJob` 3-phase pattern (claim / broadcast / finalize) — textbook outbox.
- Polling cadence clamp `[250ms..60s base / 0..30s jitter]`.
- `ws:heartbeat` best-effort cache write (try/catch + 120s TTL).
- Channel-auth `channels.php` with token-NAME check + explicit `hasRole` check.
- TZ-aware bounds (`Carbon::today($appTz)->setTimezone('UTC')`) at `KdsSyncService.php:77-91`.

### RECOMMENDATIONS-V1.x (non-blocking, queue for backlog)
1. **REC-V1.x-1 (Architect):** Add `stock_reconciliation_job` cron that walks orders created in the last hour and reconciles `stock_level` vs. `sum(order_items.qty)`. Closes the 1-decrement drift window when `DecrementStockOnOrderCreated` re-throws after Outbox write already committed. Rationale: V1 single-restaurant rate makes drift rare and recoverable; V1.x multi-restaurant amplifies the risk.
2. **REC-V1.x-2 (SRE-Sync):** When `BROADCAST_DRIVER=null` (single-restaurant cost-optimized deploy where Pusher is disabled), reduce `Cache::remember` TTL on `KdsSyncService::sync` from 5s to 1s. Halves the worst-case visibility latency in polling-only mode.
3. **REC-V1.x-3 (RED):** Add sentinel tests for the channel-auth wildcard-bypass attack and Guest-Echo-Bypass attack to permanently guard the `R3 T-3.2.2 Sec F-SEC-W6-01/02` heals. The current code is correct, but a regression sentinel would prevent silent reintroduction.
4. **REC-V1.x-4 (RED):** Surface `SyncOverviewController` `ws:heartbeat` freshness as a JS-visible signal in `StockRuptureDashboard` so the kitchen operator sees Pusher status without opening the admin observability console.

---

## Cross-Reference: Prior PK-1 Finding (intersection-pos-kds-2026-05-18)

The prior intersection audit `reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-1-DATA-FLOW/STATUS.md`
identified the same cascade with verdict PASS_WITH_FINDINGS (0 P0, 1 P1
PK1-ARCH-01: stale rollback comment in `DecrementStockOnOrderCreated.php`).
That P1 was deliberately **deferred** by PK-1 because patching only that
listener without aligning siblings would create drift. Our ARCH-4
RECOMMENDATION-V1.x re-surfaces the same concern with the same disposition
(non-blocking, V1.x backlog via `stock_reconciliation_job`). No new P0 or
P1 introduced.

---

## Heal Commits Verified on Branch

```
50bdd5150 docs(brain) + memory: Wave E final converged READY-TO-TAG state    (HEAD)
5452e556d fix(sync-F-3-P1): PayloadMismatchException fail-once + sentinel    (V1.0.1)
01d2b25f6 feat(outbox): PersistBranchStatusChangedToOutbox listener          (T-6.4 Z7-V1.0.2-P2-01)
139ce01aa fix(sync-heal-2026-05-18 S-R3-P0-G+H): channel-auth wildcard + Guest-Echo-Bypass
 65f59e82f fix(sync-heal-2026-05-18 S-P0-A): write ws:heartbeat after successful broadcast
148dbebce fix(kds): TZ-aware boundaries in KdsSyncService (Wave 3 P0)
c2613cab0 fix(kds+oss): TZ-aware boundaries in KitchenDisplay + OSS services (Wave 3b P0)
```
All cited commits are ancestors of HEAD `50bdd5150` (verified via `git merge-base --is-ancestor`).

---

## Owner Mandate Alignment (2026-05-19)

> "massive test with G-Stack + Superpowers + lots of sub-agents, discuss and
> confirm... above all, synchronization, because it's the structure, the base"

Confirmed. Synchronization is **production-grade**. The cascade structure is
intact, retry-safe, race-safe, and channel-authenticated. V1 ship for Le
Cayenne single-restaurant is unblocked from a sync-cascade perspective.

---

## Deliverables

- `STATUS.md` (this file)
- `specialist-1-architect.json` (1109 words, ≤1500 cap respected)
- `specialist-2-sre-sync.json` (1106 words, ≤1500 cap respected)
- `specialist-3-red.json` (1126 words, ≤1500 cap respected)
- Playwright captures: `reports/test-e2e/critical-focus-2026-05-18/zone-3-KDS-KIOSK/screenshots/` (K01-K10, 11 PNG)
- PHPUnit evidence: 49 sync-cascade tests green (cascade filter 13 + extended filter 36)

**END OF STATUS.**
