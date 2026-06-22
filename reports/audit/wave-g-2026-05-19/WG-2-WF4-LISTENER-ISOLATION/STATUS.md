# WG-2 — Unified failure-isolation policy across 4 `OrderCreated` listeners

**Wave** : wave-g-2026-05-19
**Task ID** : WG-2 / WF-4 / PK1-ARCH-01 (cross-validated)
**Severity** : P1 (cross-validated by WF-4 + PK1-ARCH-01 + RED-team)
**Status** : GREEN — heal landed
**Discipline** : TDD-first (RED 4 failing → GREEN 5 passing)

---

## 1. Root cause (verbatim)

`OrderCreated` event uses `DispatchableAfterCommit` — listeners run AFTER the
outer DB transaction commits. The pre-WG-2 `DecrementStockOnOrderCreated`
re-threw on **both** `StockUnavailableException` (domain-typed) AND
`Throwable` (generic catch-all), with a stale comment claiming "let the
transaction roll back upstream / Pre-iter12 behavior preserved".

That rationale was load-bearing-wrong because :
- the transaction has already committed by the time the listener runs (afterCommit semantics),
- the re-throw rolls back NOTHING,
- the re-throw **skips sibling listeners** registered in `EventServiceProvider:145-151` :
  - `PersistOrderCreatedToOutbox` (SSOT for KDS/Kiosk/POS sync)
  - `DecrementItemAvailabilityOnOrder`
  - `SendFcmOnOrderCreated`
- the re-throw surfaces a 500 to the POS HTTP client for an order that EXISTS in DB → cashier confusion + dup-create attempt.

A second drift was identified by RED-team / advisor : `DecrementItemAvailabilityOnOrder`
had **zero try/catch**. Pre-WG-2 it was protected from breaking the SSOT only by
EventServiceProvider registration order (Outbox first) — that's coincidence,
not contract. A future reorder silently breaks the invariant.

`PersistOrderCreatedToOutbox` already wraps its inner `DB::afterCommit` broadcast
dispatch in try/catch (line 67-78) — verified, no work needed.
`SendFcmOnOrderCreated` already isolates per-dispatch via `safeDispatch`
(F-002 round-3 2026-05-10) — verified, no work needed.

## 2. Files touched

| File | Type | LOC | Notes |
|------|------|-----|-------|
| `app/Listeners/DecrementStockOnOrderCreated.php` | MODIFY | ~60 | Drop `StockUnavailableException` special-case + `throw $e` ; dispatch `StockDecrementFailedEvent` ; stale comment removed |
| `app/Listeners/DecrementItemAvailabilityOnOrder.php` | MODIFY | ~70 | Add `try { ... } catch (Throwable $e) { log+event }` mirror of stock policy |
| `app/Events/StockDecrementFailedEvent.php` | NEW | 35 | Lightweight observability event, intentionally unwired (no listener) |
| `tests/Feature/Listeners/OrderCreatedFailureIsolationSentinelTest.php` | NEW | ~230 | 5 cases : 2 real-dispatch + 1 direct-invocation + 2 structural sentinels |
| `reports/audit/wave-g-2026-05-19/WG-2-WF4-LISTENER-ISOLATION/STATUS.md` | NEW | this file | Status report |

**Frozen-zone diff** : 0 lines. No `app/Services/Fiscal/`, no `BranchScope`, no `Pricing`, no kiosk wizard Vue, no `pos-wizard.js`.

## 3. Sentinel coverage (5 cases)

| # | Test name | Type | What it locks |
|---|-----------|------|---------------|
| 1 | `test_decrement_stock_failure_does_not_block_outbox_persistence` | runtime (real dispatch) | Stock listener throws → `domain_events` row still exists |
| 2 | `test_decrement_item_availability_source_absorbs_throwable` | structural | Mirror policy on availability listener (final class — can't mock at runtime) |
| 3 | `test_send_fcm_listener_isolates_per_dispatch` | runtime + structural | FCM `safeDispatch` retained + outbox row persists |
| 4 | `test_decrement_stock_listener_invoked_directly_never_rethrows` | direct invocation (advisor : order-independent discriminator) | Stock listener absorbs Throwable when called outside the dispatcher |
| 5 | `test_decrement_stock_source_has_no_unconditional_rethrow` | structural | Locks the `throw $e;` regression at source level |

## 4. Test results

### TDD RED → GREEN
- **RED (pre-fix)** : 4 failed, 1 passed (FCM-only)
- **GREEN (post-fix)** : 5 passed

```
PASS  Tests\Feature\Listeners\OrderCreatedFailureIsolationSentinelTest
  ✓ decrement stock failure does not block outbox persistence
  ✓ decrement item availability source absorbs throwable
  ✓ send fcm listener isolates per dispatch
  ✓ decrement stock listener invoked directly never rethrows
  ✓ decrement stock source has no unconditional rethrow

Tests:  5 passed
```

### Regression sweep
- `--filter "OrderCreated|DecrementStock|PersistOrder"` → **10 passed**
- `--filter "Stock|Outbox|EventContract|Listener|OrderCreated|Availability"` → **302 passed, 8 skipped**
- `--filter Stock` → **82 passed, 5 skipped** (baseline 79 + 3 new sentinel cases referencing stock listener)
- `--filter "Sentinel"` → **292 passed, 2 skipped**
- `--filter "Fiscal|AuditChain|ZReport"` → **221 passed, 2 incomplete, 3 skipped** (no NF525 regression)

## 5. Invariants preserved

- **NF525 fiscal chain** : untouched. Listener cascade is not part of fiscal chain.
- **Outbox SSOT** : strictly STRONGER — was protected by registration order only, now protected by contract (sibling Throwable absorbed at source).
- **Stock drift visibility** : preserved via `Log::error` + `StockDecrementFailedEvent` (structured ops hook, intentionally unwired for ops to subscribe later).
- **`StockUnavailableException` contract** : `StockService::decrementForOrder` still throws it ; only the listener wrap changed. All existing tests that assert against the service directly remain green.
- **HTTP behavior** : POS client no longer receives a 500 for an order that exists in DB after-commit. (This restoration was the headline P1 from WF-4 + PK1-ARCH-01.)

## 6. Decisions / drift notes

### A — Removed `StockUnavailableException` special-case re-throw
Advisor flagged this as the bug, not a feature to preserve. Pre-WG-2 the listener treated it as "domain-expected — let it bubble" but the bubble has no recipient (transaction committed, HTTP client gets 500). Post-WG-2 unified policy : both domain-typed AND generic `Throwable` are absorbed identically.

### B — Why structural sentinel for `DecrementItemAvailabilityOnOrder` instead of runtime
`AvailabilityService` is `final` and cannot be replaced via Mockery without invasive test infrastructure changes. The structural sentinel locks the WG-2 policy at the source level (`catch (Throwable $`, `Log::`, no `throw $e;`). A future refactor that introduces a runtime fault-injection path can layer a runtime test on top — the structural sentinel will remain as a tripwire.

### C — `StockDecrementFailedEvent` intentionally unwired
Per advisor : "Create it as a no-listener observability hook. Don't add a listener now — future ops can subscribe. Note in the class docblock that it's intentionally unwired." Done. The event class carries `branchId`, `orderId`, `listener`, `errorMessage`, `failedAt` — enough for Sentry/Datadog wiring later without rewriting the dispatch sites.

### D — Defense-in-depth around event dispatch itself
Even the observability event dispatch is wrapped in try/catch with `Log::warning` absorb. Defense-in-depth : the failure-isolation policy must not itself become a new failure source.

## 7. Commit

```
fix(listeners-WF-4-PK1-P1): unified failure isolation 4 OrderCreated listeners
```

## 8. Backlog / follow-ups (not in scope)

- Wire `StockDecrementFailedEvent` to Sentry / Datadog ops alert listener (V1.0.2 ops observability).
- Consider unfinaling `AvailabilityService` for testability — currently structural-sentinel-only.
- Mirror the same isolation policy across other event cascades (OrderStatusChanged, OrderPaymentStatusChanged) — separate audit task.
