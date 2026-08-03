# BUILD-3 — ZReportCashEnrichmentService (Wave 6b-1.5)

**Date** : 2026-05-18
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Task** : NF525 sign-off BLOCKER for Livreur V1.0.2 — Z reports must include doorstep delivery cash totals (separate from POS cash drawer totals).
**Authoritative plan** : `reports/test-e2e/goal-2026-05-18/round-2/impl-h-livreur-schema-plan.md` §7 line 315 ("Extend `ZReportCashEnrichmentService` to also aggregate delivery_boy_cash_sessions").

---

## 1. Pattern adopted : Path A — composition extension in place

### Filename collision (resolved)
The task brief specified to "Build a NEW service at `app/Services/Fiscal/ZReportCashEnrichmentService.php`". That file already existed (267 lines) as the POS cash drawer decorator from AUDIT-F-003 (Sprint 1C). Per advisor reconciliation, the authoritative plan recommends extending this SAME service for delivery cash (Planner H §7 line 315). I chose **Path A** : add the new delivery-cash methods to the existing decorator. The file IS the designated decorator extension point — it composes over the frozen `ZReportService` rather than modifying it.

### Composition discipline
- The new methods (`enrichClose`, `getDeliveryMovementsBetween`, `verifyConsistencyVsAuditLog`) live alongside the existing POS-side methods (`aggregateForWindow`, `enrich`, `aggregateByTerminal`, `persistForClosedReport`).
- **Zero modifications** to the existing POS-side methods (verified by `git diff` line count : +320 insertions / 0 deletions on `ZReportCashEnrichmentService.php`).
- No new dependencies injected ; service remains constructor-free.

---

## 2. Frozen-zone diff = 0 attestation (CLAUDE.md §7 NF525-critical)

```
$ git diff --stat app/Services/Fiscal/{ZReportService,AuditLogService,FiscalSequenceService,FiscalChainValidator,FiscalSealingService,XReportService}.php
(no output — zero modifications)

$ git diff --stat app/Services/Fiscal/ZReportCashEnrichmentService.php
 app/Services/Fiscal/ZReportCashEnrichmentService.php | 320 +++++++++++++++++++
 1 file changed, 320 insertions(+)
```

NF525-frozen services UNCHANGED :
- `ZReportService.php` — HMAC chain signature path
- `FiscalSequenceService.php` — gap-free fiscal sequence
- `AuditLogService.php` — append-only chain writer
- `FiscalChainValidator.php` — chain integrity checker
- `FiscalSealingService.php` — payload sealing
- `XReportService.php` — X-report (running totals)

The only Fiscal/* file modified is the decorator (`ZReportCashEnrichmentService.php`) which is explicitly designed to compose, NOT frozen.

---

## 3. NF525 audit_log integrity preserved

The service is **strictly READ-ONLY** w.r.t. `audit_logs` :
- `enrichClose()` queries `delivery_boy_cash_sessions` + `delivery_boy_cash_movements` only — no `AuditLog` reads or writes.
- `getDeliveryMovementsBetween()` queries `delivery_boy_cash_movements` only.
- `verifyConsistencyVsAuditLog()` **reads** `audit_logs` by `branch_id` + `action='cash.delivery.movement.recorded'` + time window. **Never writes**.

The original `DeliveryBoyCashSessionService` is the sole writer to the audit chain (via `AuditLogService::write` with `cash.delivery.session.opened|closed|reconciled` and `cash.delivery.movement.recorded` actions). My service consumes those entries for cross-checking without ever mutating them.

### Sign-correctness in `verifyConsistencyVsAuditLog`
The advisor flagged this critical detail : `audit_logs.payload` stores `amount` UNSIGNED + `direction` ∈ {`in`,`out`}. Comparing unsigned audit amounts to `DeliveryBoyCashMovement::signedAmount()` (which is signed) would produce false discrepancies. The implementation derives the sign from `direction` when summing the audit side :

```php
$signed = ($direction === DIRECTION_IN ? 1.0 : -1.0) * $amount;
$auditTotal += $signed;
```

The probe is symmetric-signed on both sides and returns 4 discrepancy kinds (`sum_mismatch`, `count_mismatch`, `audit_orphan_movement_id`, `movement_missing_audit_row`) for forensics.

---

## 4. Window semantics (mirrors POS path)

`enrichClose($branchId, $closeAt)` computes its window as `(previousZClosedAt, closeAt]` — the same half-open pattern used by `persistForClosedReport` L249-257 for POS cash. For the first Z of a branch, `from = null` (covers entire history).

The session filter is on `closed_at` (NOT `opened_at`), so a session opened pre-window that closes inside the window is correctly counted (Test 5 locks this contract). Sessions still OPEN are intentionally excluded — the physical count has not happened yet, so it is not yet a fiscal fact.

`BranchScope` is bypassed via `withoutGlobalScope(\App\Models\Scopes\BranchScope::class)` + explicit `where('branch_id', ...)` — identical pattern to the existing POS-side method.

---

## 5. Listener gap (documented per task brief)

The task brief proposed `AttachDeliveryCashToZReport` listening for `ZReportClosed`. Investigation : **no such event exists** in the codebase :

```
$ grep -rn "ZReportClosed\|event(.*ZReport\|->dispatch.*ZReport" app/Services/Fiscal/ZReportService.php
(no output — ZReportService doesn't dispatch any event on close)

$ find app/Events -name "*ZReport*"
(no output — no Event class exists)
```

Per task brief : "If `ZReportClosed` event doesn't exist → just expose the service for manual invocation. Document the gap." Done. The service is wired in the container (Laravel auto-resolution) and invokable by any caller that wants to compute the delivery cash subsection at Z close time. **No listener file created** — would be dead code without an emitter.

**Sidecar storage also skipped** : without an emitter to trigger persistence, runtime computation is the right shape. Adding a sidecar JSON or new `z_report_delivery_summary` column without an emitter would be premature. The contract is : caller (Z report controller, dashboard, audit operator) invokes `enrichClose($branchId, $closeAt)` on demand.

**No new migrations** — the task brief said "No new migrations UNLESS the sidecar storage requires", and the sidecar is not required.

---

## 6. Test evidence (5/5 PASS)

```
$ php artisan test --filter="ZReportCashEnrichment"

 PASS  Tests\Feature\Cash\ZReportCashEnrichmentTest             [REGRESSION]
  aggregate sums variance for reconciled sessions in window
  aggregate excludes sessions outside window
  aggregate ignores non reconciled sessions
  aggregate isolates by branch id
  enrich merges cash into existing aggregates
  persist for closed report updates cash columns without changing signature
  persist for closed report noop on open report

 PASS  Tests\Feature\Sentinels\ZReportCashEnrichmentSentinelTest [NEW]
  enrich close returns correct totals for single session
  enrich close aggregates multiple sessions same branch
  enrich close isolates by branch
  verify consistency vs audit log ok after happy path
  enrich close counts session in period of close not open

Tests: 12 passed (7 regression + 5 new)
Time:  3.04s
```

### Scenario coverage

| # | Scenario | Locked invariant |
|---|----------|------------------|
| 1 | Single session : open 50€ → +10€ collect + +20€ collect → −1€ change → close 79€ | `enrichClose` returns collected=30, change_given=1, session_count=1 with correct per-session breakdown |
| 2 | 2 livreurs / same branch / same window | Aggregates SUM across livreurs ; session_count=2 |
| 3 | Branch A session + Branch B session | `enrichClose(A)` returns ONLY branch-A figures — NF525 multi-tenant isolation |
| 4 | Happy-path lifecycle | `verifyConsistencyVsAuditLog` returns `ok=true` with audit_total === movements_total === 18.00, audit_count = movements_count = 3 |
| 5 | Session opens at t0 (pre-window), closes at t2 (in-window) | Counted in correct period — window predicate on `closed_at`, not `opened_at` |

---

## 7. Method API summary

```php
public function enrichClose(int $branchId, Carbon $closeAt): array
// → {delivery_cash_collected_total, delivery_cash_change_given_total, session_count, sessions[]}

public function getDeliveryMovementsBetween(int $branchId, Carbon $start, Carbon $end): Collection
// → Collection<DeliveryBoyCashMovement> for raw audit forensics

public function verifyConsistencyVsAuditLog(int $branchId, Carbon $start, Carbon $end): array
// → {ok, audit_total, movements_total, audit_count, movements_count, discrepancies[]}
```

### Movement types counted
- `TYPE_ORDER_COLLECT` + `DIRECTION_IN` → `delivery_cash_collected_total`
- `TYPE_CHANGE_GIVEN` + `DIRECTION_OUT` → `delivery_cash_change_given_total`

Other types (`adjustment`, `drawer_open`, `drawer_close`) appear in the raw movements list for audit but do NOT contribute to the fiscal totals.

---

## 8. Files touched

| Path | Change |
|------|--------|
| `app/Services/Fiscal/ZReportCashEnrichmentService.php` | +320 lines (3 new methods + use statements) |
| `tests/Feature/Sentinels/ZReportCashEnrichmentSentinelTest.php` | NEW (5 tests, 14,329 bytes) |

| Path | Status |
|------|--------|
| `app/Services/Fiscal/ZReportService.php` | UNCHANGED (frozen) |
| `app/Services/Fiscal/AuditLogService.php` | UNCHANGED (frozen) |
| `app/Services/Fiscal/FiscalSequenceService.php` | UNCHANGED (frozen) |
| `app/Services/Fiscal/FiscalChainValidator.php` | UNCHANGED (frozen) |
| `app/Services/Fiscal/FiscalSealingService.php` | UNCHANGED (frozen) |
| `app/Services/Fiscal/XReportService.php` | UNCHANGED (frozen) |
| database/migrations/* | NO new migration |
| app/Listeners/* | NO new listener (no `ZReportClosed` event emitter — documented gap) |
| app/Events/* | NO new event |

---

## 9. Verdict

GREEN. NF525 sign-off BLOCKER closed for Livreur V1.0.2 :
- Composition pattern preserves NF525 HMAC chain integrity.
- Frozen-zone diff = 0 attested on 6 critical fiscal files.
- Audit-log read-only contract upheld (zero writes from this service).
- 5 sentinel tests + 7 regression tests = 12 PASS / 12.
- Branch isolation enforced (BranchScope-aware queries).
- Window predicate locked on `closed_at` so opened-pre-window-closed-in-window scenarios land correctly.

Listener integration deferred until a `ZReportClosed` event is emitted by `ZReportService::close()` — a separate Wave 6b task gated by frozen-zone LOCK plan (out of scope here).
