# WJ-4 / WI-5 / OBS-OUTBOX-01 — Outbox swallow alarm

**Severity:** P1
**Date:** 2026-05-19
**Branch:** `v1-0-1-hardening-2026-05-17`
**Status:** GREEN — sentinel + regression converged.

---

## Problem (observability gap)

3 outbox persist listeners caught `Throwable` from
`DispatchDomainEventsJob::dispatch(...)` inside their `DB::afterCommit`
hook and emitted only `Log::warning`:

| Listener | Pre-WJ-4 site |
| --- | --- |
| `app/Listeners/PersistOrderCreatedToOutbox.php` | lines 69-78 |
| `app/Listeners/PersistOrderStatusChangedToOutbox.php` | lines 76-85 |
| `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` | lines 81-90 |

The DomainEvent row was still persisted in DB, so the cron path
`outbox:retry-failed` (hourly) would retry. **BUT** if the queue worker
*and* the cron scheduler were simultaneously down (deploy window, infra
incident, dropped systemd unit), the `Log::warning` tier was the only
signal — and production alerting tiers (Sentry default level filter,
Datadog `log_pipeline_minimum_severity=error`) typically *do not* watch
warning. Effectively silent broadcast loss.

---

## Fix (mirror WG-2 `StockDecrementFailedEvent` pattern)

1. **NEW** `app/Events/OutboxBroadcastSwallowedEvent.php` —
   `final` observability event carrying `domainEventId`, `eventType`,
   `aggregateId`, `branchId`, `listener`, `errorMessage`, `failedAt`.
   Intentionally unwired (no `EventServiceProvider` entry) — ops alerting
   can subscribe later.
2. **3 listener updates** — at the swallow site:
   - Escalate `Log::warning` → `Log::error` (catch production alerting),
   - Update gate string from `test-e2e-fix-E-001-round-3` →
     `WJ-4-WI5-OBSOUTBOX01`,
   - Dispatch `OutboxBroadcastSwallowedEvent` with full context,
   - Wrap the event dispatch in a nested try/catch (defense-in-depth
     — observability MUST NOT itself re-break the cascade; mirrors
     `DecrementStockOnOrderCreated` lines 53-60).

---

## Files modified

```
A  app/Events/OutboxBroadcastSwallowedEvent.php              (+ NEW, 45 lines)
M  app/Listeners/PersistOrderCreatedToOutbox.php             (+30 / -6)
M  app/Listeners/PersistOrderStatusChangedToOutbox.php       (+30 / -6)
M  app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php (+30 / -6)
A  tests/Feature/Sentinels/OutboxBroadcastSwallowAlarmSentinelTest.php (+ NEW, 248 lines)
A  reports/audit/wave-j-2026-05-19/WJ-4-WI5-OBSOUTBOX01-SWALLOW-ALARM/STATUS.md (+ NEW)
```

---

## TDD evidence

### RED (sentinel before fix)
```
Tests:  4 failed, 1 passed
Time:   0.81s
```
The 4 structural assertions failed (no `OutboxBroadcastSwallowedEvent`
reference, no `Log::error` in the 3 listeners). The contract-shape
test passed because the event class file existed by then.

### GREEN (sentinel after fix)
```
PASS  Tests\Feature\Sentinels\OutboxBroadcastSwallowAlarmSentinelTest
  persist order created dispatches swallow event on broadcast failure
  persist order created source has swallow alarm
  persist order status changed source has swallow alarm
  persist order payment status changed source has swallow alarm
  outbox broadcast swallowed event contract shape
Tests:  5 passed
Time:   0.83s
```

### Regression — Outbox + Persist*Outbox suite

```
php artisan test --filter "Outbox|Persist.*Outbox"
Tests:  2 skipped, 104 passed
Time:   13.96s
```
The 2 skipped are `OutboxPipelineHealthSentinelTest` (harness-gated by
`CI_WEBSOCKETS_HARNESS=1`, expected non-regression).

### Regression — broad sentinel sweep

```
php artisan test --filter "Sentinel"
Tests:  2 skipped, 339 passed
Time:   37.90s
```

### Regression — related (OrderCreated isolation + EventContract + AfterCommit)

```
php artisan test --filter "OrderCreated.*Sentinel|AfterCommitDispatch|EventContract"
Tests:  40 passed
Time:   2.40s
```

---

## Sentinel shape (5 tests)

1. **Runtime sentinel** — bind a throwing `Bus\Dispatcher` for
   `DispatchDomainEventsJob`, fire real `OrderCreated::dispatch($order)`,
   assert `OutboxBroadcastSwallowedEvent` dispatched with the expected
   payload (domainEventId > 0, eventType, aggregateId, branchId,
   listener = `PersistOrderCreatedToOutbox::class`,
   errorMessage contains crash signature).
2. **Structural** — `PersistOrderCreatedToOutbox.php` contains
   `OutboxBroadcastSwallowedEvent` + `Log::error` + the
   `catch (\Throwable $broadcastException)` regex (regression lock).
3. **Structural** — same on `PersistOrderStatusChangedToOutbox.php`.
4. **Structural** — same on
   `PersistOrderPaymentStatusChangedToOutbox.php`.
5. **Structural — event contract** — `OutboxBroadcastSwallowedEvent`
   is `final class` and carries the 7 required typed fields.

The mix follows `OrderCreatedFailureIsolationSentinelTest` precedent:
1 runtime + N structural, where N = number of listener files sharing
the policy.

---

## Constraints respected

| Constraint | Status |
| --- | --- |
| 0 frozen-zone touch | OK — `git diff --name-only` matches none of `Fiscal/BranchScope/IdempotencyKey/Pricing/OrderStateMachine/pos-wizard/KioskWizard/KioskApp/KioskUpsell/admin-pos-v4`. |
| 0 DIRTY file touch | OK — none of the 3 listeners nor `OutboxReplayAuditTest.php` (dirty from Wave 3 fiscal work) was on my edit path. |
| Commit format | `fix(outbox-WJ-4-P1): alarm on swallow via OutboxBroadcastSwallowedEvent (OBS-OUTBOX-01)` |
| Wall-clock | ~35 min recon + write + RED + heal + GREEN + regression + status |

---

## What this does NOT fix

- It does **not** wire a listener to react to
  `OutboxBroadcastSwallowedEvent` (e.g. fire a Sentry breadcrumb or a
  pager). That is intentional — same pattern as
  `StockDecrementFailedEvent`. Ops will subscribe when the alerting
  pipeline is in scope.
- It does **not** replay the broadcast inline — the cron
  `outbox:retry-failed` path is already in place for that and is
  unchanged. This patch is observability-only.

---

## References

- `app/Listeners/DecrementStockOnOrderCreated.php` — WG-2 mirror pattern
- `tests/Feature/Listeners/OrderCreatedFailureIsolationSentinelTest.php`
  — sentinel-shape precedent (structural + runtime mix)
- `app/Events/StockDecrementFailedEvent.php` — twin observability event
