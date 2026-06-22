# A08 — Z Report + X Report + Aggregate Audit (parallel POS audit)

**Date** : 2026-05-11
**HEAD** : `a220b9bd8`
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Role** : Sub-agent A08 — Z Report close logic + X Report intraday + aggregate window + SoftDeletes interplay (NF525 critical).
**Method** : READ-ONLY. file:line verified directly. No tests run.

---

## §1. Scope read

- `app/Services/Fiscal/ZReportService.php` (~727 lines, **modified 2026-05-10**)
- `app/Services/Fiscal/XReportService.php` (82 lines)
- `app/Services/Fiscal/ZReportCashEnrichmentService.php` (143 lines)
- `app/Models/Order.php:11+17` (SoftDeletes trait usage)
- `app/Models/OrderItem.php:8+13` (SoftDeletes trait usage)
- `app/Models/ZReport.php` (67 lines, **no BranchScope, no SoftDeletes**)
- `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`
- `tests/Feature/Fiscal/ZReport*Test.php` (9 files)
- `app/Console/Commands/FiscalArchiveCommand.php` (skim)
- `app/Http/Controllers/Admin/Fiscal/{X,Z}ReportController.php`
- `routes/api.php:1035-1044`

---

## §2. Reframing verification — past P0-01/02 (SoftDeletes + aggregate scope)

**CLAIM (past audit)** : `ZReportService::aggregate` preserved `SoftDeletingScope`, so post-allocation soft-deleted orders disappear from Z totals → NF525 gap.

**VERIFICATION** :
- `Order.php:11,17` — `use Illuminate\Database\Eloquent\SoftDeletes;` + `use SoftDeletes;` ✅ confirmed.
- `ZReportService.php:337-341` — current aggregate base query:
  ```php
  $baseQuery = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
      ->withTrashed() // [P0-FIX-1/2] include soft-deleted post-allocation orders for NF525 fiscal continuity
      ->where('branch_id', $branchId)
      ->whereNotNull('fiscal_sequence_no')
      ->where('payment_status', '!=', PaymentStatus::UNPAID);
  ```
- `tests/Feature/Fiscal/ZReportAggregateFilterTest.php:101-141` — `test_soft_deleted_post_allocation_orders_are_counted` asserts soft-deleted PAID orders enter the Z aggregate (`order_count == 2`, `total_ttc == 1009.00`).
- `tests/Feature/Fiscal/ZReportAggregateFilterTest.php:149-182` — companion test asserts CANCELED+soft-deleted orders STAY excluded (terminal-status whitelist still rules).

**VERDICT P0-01/02** : **CLOSED**. iter15 owner G0-A Option A landed (commit ref in code comment). The fix uses `withTrashed()` at the base query, and the post-Z adjustment query at `:387-402` clones the same `$baseQuery` so adjustments also include soft-deleted rows. Boundary tests (`ZReportBoundaryTest.php`) and tax-breakdown tests cover the half-open `(from, to]` semantics correctly.

**Subtle correctness note** : `$baseQuery` also bypasses `BranchScope` — required because admin (branch_id=0) closing Z for a specific branch would otherwise fight the scope. Explicit `->where('branch_id', $branchId)` re-pins it. Acceptable.

---

## §3. Findings

### P1-A08-01 — `warnOnOrphanedPaidOrders` does NOT see soft-deleted orphans (NF525 observability gap)

**Severity** : P1 — observability, not correctness.
**File** : `app/Services/Fiscal/ZReportService.php:586-616`.

The aggregate at `:337` uses `->withTrashed()` to include soft-deleted post-allocation orders. The **pre-close warn helper** at `:589-599` does NOT:

```php
$query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
    ->where('branch_id', $branchId)
    ->where('payment_status', PaymentStatus::PAID)
    ->whereNull('fiscal_sequence_no')
    ->where('created_at', '<=', $to);
```

A PAID kiosk order that crashed pre-fiscal-alloc and was later soft-deleted by an ops janitor will not appear in the warn, even though it remains a NF525 fiscal gap candidate the retry cron should pick up. Cron `RetryFiscalAllocCommand` itself may or may not handle soft-deleted orphans — separate audit.

**Fix** : add `->withTrashed()` to the warn query for parity with `aggregate()`. Single line.

**Confidence** : HIGH (file:line + direct comparison with the aggregate fix).

---

### P1-A08-02 — GATE-FZH-ALLOC pre-Z-close is warn-only — NF525 orphan is "discoverable" not "blocked"

**Severity** : P1 — past audit ack'd, still unfixed.
**File** : `app/Services/Fiscal/ZReportService.php:586-616`.

Method `warnOnOrphanedPaidOrders` runs `Log::channel('fiscal')->warning(...)` and **returns** — no exception, no abort. Caller at `:229` ignores the return value. A Z can close with N orphan PAID rows whose fiscal sequence has not yet been allocated (retry cron in-flight or alloc backend degraded). These orders are correctly excluded from the signed totals, but the close proceeds.

**Why this is P1, not P0** :
- The aggregate stays gap-free (excluded rows have `fiscal_sequence_no = null`, so they never claim a sequence).
- The retry cron will eventually allocate them, but they will then sit in a **later** Z than where they were paid → fiscal date drift.
- NF525 audit by a CGI inspector would surface the date-drift as "encaissement non-rattaché au Z du jour" — a finding, not a violation.

**Fix recommendation** : add a config flag `fiscal.block_z_close_on_orphan = true` (default in `production`, false in dev) that throws `RuntimeException` when orphan_count > 0. Reuse the same warn payload as the exception message so ops have a fix path.

**Confidence** : HIGH — past audit P1-02 not addressed in HEAD.

---

### P1-A08-03 — `z_reports` table allows UPDATE; `verifySignature` is the ONLY backstop

**Severity** : P1 — past audit ack'd, design tradeoff.
**File** : `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:18-21`.

The trigger only blocks DELETE. UPDATE is **intentionally permitted** because:
- `archived_at` is set by `FiscalArchiveCommand` (legitimate state machine).
- `ZReportCashEnrichmentService::persistForClosedReport` UPDATEs cash columns post-close (additive, not signed).

But: `tests/Feature/Fiscal/ZReportCloseTest.php:131-138` directly demonstrates the attack vector:
```php
$closed->total_ttc = 999999.99;
$closed->saveQuietly();
$this->assertFalse($this->service->verifySignature($closed->refresh()), ...);
```

So a DB-level UPDATE can silently flip `total_ttc` until someone calls `verifySignature`. The chain validation (`verifyChain` at `:463`) is invoked **inside** `open()` and `close()`, so a tampered closed Z between two opens would surface — but a tampered FINAL Z (no further opens) stays undetected until manual audit.

**Fix recommendation** : either (a) a column-list UPDATE trigger that rejects writes to the 8 signed columns (`total_*`, `*_count`, `signature`, `prev_hash`), permitting only `archived_at` + cash_*` + `status`, or (b) a scheduled `foodking:fiscal:verify-chain` cron that runs `verifySignature` on every closed Z weekly + alerts.

**Confidence** : HIGH — design decision documented at `:18-21`, but the mitigation (verifySignature only when chain re-read) is incomplete for stale tails.

---

### P2-A08-04 — `ZReport` model lacks `BranchScope` — implicit `where('branch_id')` discipline required everywhere

**Severity** : P2 — latent multi-tenant risk.
**File** : `app/Models/ZReport.php` (no `boot()` method).

`Order`, `OrderItem`, `OrderPayment`, `KioskMachine` etc. (11 models per CLAUDE.md §9) all use `BranchScope`. `ZReport` does not. Today the only callers are:
- `ZReportController::index` at `:28-32` — explicit `->where('branch_id', $branchId)`. ✅
- `ZReportController::show/pdf` at `:62/:77` — Route-model-bound + explicit `abort_if((int) $zReport->branch_id !== $branchId)`. ✅
- `ZReportService` internal queries always pin `branch_id`. ✅
- `FiscalArchiveCommand` — scoped by argument. ✅

A future controller that does `ZReport::orderByDesc('closed_at')->limit(10)->get()` would leak cross-branch fiscal data. **Not exploitable today**, but absence of defense-in-depth is documented as a latent gap.

**Fix recommendation** : add `BranchScope` to `ZReport::boot()` with admin (branch_id=0) bypass. Compatible with all existing call sites (each already pins branch_id).

**Confidence** : HIGH — verified absence in model + ad-hoc grep of usages.

---

### P2-A08-05 — `ZReport::status` enum has no `STATUS_CLOSING` — `assertNoPendingClose` is dead code

**Severity** : P2 — code-cleanliness, future-plan refs reality drift.
**File** : `app/Services/Fiscal/ZReportService.php:147-174` + `app/Models/ZReport.php:15-16`.

`ZReport.php` declares only `STATUS_OPEN = 'open'` and `STATUS_CLOSED = 'closed'`. `ZReportService::assertNoPendingClose` at `:147` short-circuits at `:149` with `if (!defined(ZReport::class . '::STATUS_CLOSING')) return;` — so the entire method is no-op. It is called from `open()` at `:98`, harmless but dead. Comments at `:144` claim it is "reserved for a future plan".

**Fix recommendation** : either (a) remove the call + method until the future plan lands, or (b) ship STATUS_CLOSING + the two-phase commit it implies. Leaving dead defensive code in a fiscal-critical service is a future-bug invitation (someone will assume it works).

**Confidence** : HIGH — direct code read.

---

### P2-A08-06 — `XReportService::defaultFrom` uses `closed_at` DESC but does not constrain to the closed Z whose window CONTAINS `to`

**Severity** : P2 — edge case ambiguity, no daily-life impact.
**File** : `app/Services/Fiscal/XReportService.php:58-80`.

`defaultFrom` resolves the lower bound as "last CLOSED Z's `closed_at`". This works when X is called between two Z windows. Two edge cases:
1. **X consulted BEFORE the first Z** of the day, when `to=null` (default `now()`): falls back to the currently OPEN Z's `opened_at` (correct).
2. **X consulted DURING a closed window** (e.g. `to = 2026-04-22 18:00` after Z closed at 23:59): `defaultFrom` returns 23:59 of 2026-04-22 → `from > to` → aggregate window is empty/inverted. The aggregate at `ZReportService.php:343-347` would silently produce zeros (no error).

Today the controller `XReportController.php:32-33` only passes `from/to` from query string, so callers usually let both default. The empty-window case is a latent reporting bug for back-dated X consults.

**Fix recommendation** : if `to` is passed AND last-closed `closed_at > to`, walk the Z table to find the Z whose `(opened_at, closed_at]` brackets `to`. Or throw `InvalidArgumentException` when `from > to`.

**Confidence** : MEDIUM — empirically derivable from code, no test covers back-dated X.

---

### P2-A08-07 — `ZReportCashEnrichmentService::persistForClosedReport` does NOT pin a branch in the `previousClosedAt` lookup

**Severity** : P2 — multi-branch boundary concern, not exploit today.
**File** : `app/Services/Fiscal/ZReportCashEnrichmentService.php:125-131`.

```php
$previousClosedAt = ZReport::query()
    ->where('branch_id', $report->branch_id)  // OK
    ->where('status', ZReport::STATUS_CLOSED)
    ->where('id', '!=', $report->id)
    ->where('closed_at', '<=', $closedAt)
    ->orderByDesc('closed_at')
    ->value('closed_at');
```

Looks correct. But — if `ZReport` ever gets a `BranchScope` (P2-A08-04) AND this is called from an admin context (branch_id=0), the explicit `->where('branch_id', ...)` works but the scope would silently filter to branch_id=0 (zero rows) if scope precedence flips. Defensive: add `->withoutGlobalScope(BranchScope::class)` proactively.

**Confidence** : LOW today, MEDIUM if the recommended P2-A08-04 fix lands.

---

### P3-A08-08 — `ZReportCloseTest::test_signature_verifies_and_detects_tampering` uses `saveQuietly()` which masks audit observer hooks

**Severity** : P3 — test hygiene.
**File** : `tests/Feature/Fiscal/ZReportCloseTest.php:131-138`.

`saveQuietly()` bypasses model events. If/when a future audit observer is added to `ZReport` for tamper detection, this test will keep passing even if the observer fails. Prefer `DB::table('z_reports')->where('id', ...)->update([...])` to simulate raw DB tampering, which is the actual threat model.

**Confidence** : HIGH — code read, low-impact.

---

## §4. P0/P1/P2/P3 summary

| ID | Severity | Title | Confidence |
|---|---|---|---|
| P0-01/02 (past) | **CLOSED** | `aggregate` withTrashed merged | HIGH |
| P1-A08-01 | P1 | `warnOnOrphanedPaidOrders` missing `withTrashed` | HIGH |
| P1-A08-02 | P1 | GATE-FZH-ALLOC warn-only (past P1-02 unfixed) | HIGH |
| P1-A08-03 | P1 | z_reports UPDATE allowed, no column-list trigger | HIGH |
| P2-A08-04 | P2 | ZReport model has no BranchScope (latent) | HIGH |
| P2-A08-05 | P2 | STATUS_CLOSING dead code path | HIGH |
| P2-A08-06 | P2 | XReport back-dated `from > to` empty window | MEDIUM |
| P2-A08-07 | P2 | persistForClosedReport scope robustness | LOW→MED |
| P3-A08-08 | P3 | saveQuietly() in tamper test bypasses observers | HIGH |

**No P0 found in this scope after re-verification.**

---

## §5. Proposed PHPUnit scenarios (5)

1. `test_aggregate_includes_archived_orders_with_force_deleted_excluded()` — soft-delete an order, then `forceDelete()` another, assert only the soft-deleted appears in Z totals.
2. `test_close_throws_on_orphan_paid_when_block_flag_enabled()` — set `fiscal.block_z_close_on_orphan = true`, create a PAID order with `fiscal_sequence_no = null`, expect close() to throw with orphan_count in the message.
3. `test_z_reports_signed_columns_updated_via_db_are_detected_by_verify_chain()` — close 3 Zs, raw `DB::update` flips total_ttc on Z #2, assert `verifyChain($branch)` returns `valid=false` with `kind=signature_mismatch`.
4. `test_x_report_with_back_dated_to_returns_correct_window()` — close a Z today, request X with `to = yesterday`, assert window resolves to the Z whose `(opened_at, closed_at]` brackets yesterday OR throws InvalidArgumentException.
5. `test_x_report_consultation_does_not_mutate_state()` — snapshot the `z_reports` and `orders` tables, call `XReportService::snapshot` twice, assert tables byte-identical (no implicit allocation, no status flip).

---

## §6. Verdict for orchestrator

- **P0 from past audit (P0-01/02) is verifiably closed** at `ZReportService.php:337-341` + test coverage `ZReportAggregateFilterTest:101-141`. Past audit "REFRAME → P0-NEW" recommendation has landed.
- **3 P1 remain** : orphan warn `withTrashed` gap, orphan block-vs-warn gate, signed-column UPDATE trigger. None are V1 blockers individually; together they constitute "NF525 invariants are best-effort, not enforced at DB layer". Recommend bundling into a single iter17 fiscal-hardening sprint.
- **4 P2/P3 are housekeeping** : add BranchScope to ZReport (defense-in-depth), remove STATUS_CLOSING dead code, harden XReport back-dated window, swap saveQuietly→raw DB::update in tamper test.

**No new P0 surfaced. Aggregate scope correctness is intact under HEAD `a220b9bd8`.**

— A08, 2026-05-11.
