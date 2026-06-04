# PROPOSAL — ZReportService::warnOnOrphanedPaidOrders silently misses soft-deleted paid orphans (NF525 observability gap)

- **Scope**: `app/Services/Fiscal/ZReportService.php` (Phase B.5 proposal #001)
- **Frozen-zone**: YES — `CLAUDE.md §7` lists `app/Services/Fiscal/ZReportService.php` as NF525-critical
- **LOCK status**: NONE exists for this file
- **Severity proposed**: **P2 observability** (NOT a correctness break — the aggregate path is consistent ; this is a missing operator signal)
- **Read-only**: this document only. ZERO file edits.
- **Owner gate required**: YES (frozen-zone + NF525 — any patch needs a `LOCK_*.md` per §7)

---

## 1. Finding (root cause + evidence)

`ZReportService::warnOnOrphanedPaidOrders()` (`app/Services/Fiscal/ZReportService.php:586-616`)
exists to warn operators at close time when paid orders in the window are
missing their `fiscal_sequence_no` (kiosk retry cron not yet caught up). Its
predicate is:

```php
$query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
    ->where('branch_id', $branchId)
    ->where('payment_status', PaymentStatus::PAID)
    ->whereNull('fiscal_sequence_no')
    ->where('created_at', '<=', $to);
```

It deliberately drops `BranchScope` but **does not** add `->withTrashed()`.

By contrast, the canonical aggregate at line 337-341 explicitly *does* add
`->withTrashed()` after the iter15 owner G0-A decision (POS-9-H.2.5 /
F-B5 / P0-FIX-1-2):

```php
$baseQuery = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
    ->withTrashed() // [P0-FIX-1/2] include soft-deleted post-allocation orders ...
    ->where('branch_id', $branchId)
    ->whereNotNull('fiscal_sequence_no')
    ->where('payment_status', '!=', PaymentStatus::UNPAID);
```

The Z6-P1-WGS hardening on `FiscalSequenceService.php:97-101` similarly notes
that the canonical pattern is "singular bypass + `->withTrashed()` makes both
intents explicit (mirrors `ZReportService:337-338` canonical pattern)".

**Drift consequence**:

A paid kiosk order that:

1. allocated PAID payment_status
2. raised an alloc-error → `fiscal_sequence_no IS NULL`,
   `fiscal_alloc_error_at IS NOT NULL`
3. was then soft-deleted (admin archive, retention janitor, partner sync etc.)

…**is invisible to the orphan warning** (`Order::withTrashed()` not applied),
but the canonical aggregate query, the retry cron
(`RetryFiscalAllocCommand.php:65` uses `FrontendOrder` not `Order`, but that's
a separate model with its own SoftDeletes), and **future audits via
`fiscal_alloc_error_at IS NOT NULL` scans** still see it. The operator
therefore receives a clean "close OK, no orphans" signal while
soft-deleted-but-paid-and-unsealed rows exist in the window.

This is **not** a fiscal-totals correctness break — the aggregate at line 337
correctly *excludes* the row (because `fiscal_sequence_no IS NULL`), so no
double-count or gap occurs in the signed totals. It is a **regression of the
operator-facing safety net** that iter14 SPECIALIST-3 explicitly introduced
to "give ops a chance to delay the close until the retry succeeds"
(comment, line 222-224).

In practice the impact is small **today** because:

- the only documented soft-delete flow on `orders` (Order has SoftDeletes —
  see `withTrashed()` usage at line 338, plus `FiscalSequenceService.php:90`
  "Order::restoring throws — soft delete is one-way audit") happens
  *after* fiscal seq allocation, so `fiscal_sequence_no` would be NOT NULL ;
- but the iter6 Q2=B "archive-then-delete recoverable" workflow and any
  future cron that soft-deletes errored kiosk orders **could** create
  this shape.

The asymmetry is a latent observability cliff, not a current production
bleed.

## 2. Why this slipped past B3.6 attestation

B3.6 attested:

- HMAC chain-signed (verified — `sign()` line 618-628, `signature` column)
- Append-only enforced (verified — DELETE trigger at
  `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`)
- No UPDATE bypass possible (PARTIALLY — see Proposal #002 below: UPDATE
  on z_reports is **explicitly allowed** by the same migration ; the
  enforcement is application-layer `verifySignature()` only)

B3.6 did NOT cover:

- the **operator-warning path** which is "best-effort observability, not
  correctness" (line 581-583 says exactly that) — so a divergence there
  doesn't crash anything ;
- the parity between `aggregate()` and `warnOnOrphanedPaidOrders()` query
  shapes ;
- the soft-delete interaction in the orphan-detection branch.

## 3. Verification observations (read-only — no edits)

Confirmed by reading:

- `ZReportService.php` lines 586-616 (orphan warn): NO `withTrashed()`
- `ZReportService.php` lines 337-341 (aggregate): HAS `withTrashed()` with
  explicit comment citing P0-FIX-1/2
- `FiscalSequenceService.php` lines 88-101: cites
  `ZReportService:337-338 canonical pattern` as the rule to mirror

The asymmetry is a clear violation of the "canonical pattern" the codebase
itself declares.

## 4. Proposed remediation sketch (NOT applied — for owner / future LOCK plan)

Minimal scope-true patch (must go through `LOCK_*.md` per §7 since file is
frozen):

```php
$query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
    ->withTrashed() // [PROPOSAL-001] parity with aggregate() L337 — keep
                    // orphan-warn coverage on the same row set as the
                    // signed-aggregate scan
    ->where('branch_id', $branchId)
    ->where('payment_status', PaymentStatus::PAID)
    ->whereNull('fiscal_sequence_no')
    ->where('created_at', '<=', $to);
```

**Risk of patch**: ~ zero. The branch returns a `count()` for a log warning,
no totals are affected, no HMAC re-sign needed.

**Test coverage required if applied**:

- existing `ZReportCloseTest.php` does NOT cover the orphan-warn branch
  (verified — no `warnOnOrphanedPaidOrders` reference in the file).
  A new regression test asserting "soft-deleted paid orphan triggers
  fiscal.log warn at close" would be needed.

## 5. Verdict

- **Severity**: P2 — observability gap, NOT a fiscal-correctness break.
- **Recommended action**: deferred to V1.0.x backlog (no operator pain
  reported today, asymmetry is silent). A LOCK plan is needed if the
  owner chooses to ship the parity fix.
- **Do NOT auto-patch** — frozen-zone discipline (`CLAUDE.md §7`) requires
  human gate for any edit on `ZReportService.php`.

---

## 6. Additional findings surfaced during this audit
(documented here for traceability — each could become its own proposal if
the owner wants them split)

### F-EXTRA-A — `assertNoPendingClose()` is dead code today (line 147-174)

The method guards on
`defined(ZReport::class . '::STATUS_CLOSING')` ; `ZReport::class` (`app/Models/ZReport.php`)
**does not define `STATUS_CLOSING`** (only `STATUS_OPEN` and `STATUS_CLOSED`).
The comment at line 144-146 says "STATUS_CLOSING is reserved for a future
plan (write path not yet activated). This method is a no-op until then."

That's accepted as planned — but the implication is: **if a close() crashes
mid-write, the `OPEN` row stays untouched** (DB transaction rolled back),
and the next `open()` attempt would just see the same OPEN and refuse with
"already has an OPEN Z report". The system is correctly self-protecting,
but the *intended* crash-recovery hint (15s stuck CLOSING detection) is
never armed. Severity P3 — informational, no actual risk vector.

### F-EXTRA-B — `prev_hash` lookup uses `sequence_no` ordering ; `verifyChain` walks by `id` (lines 233-237 vs 480-484)

```php
// close() — picks predecessor:
$prevHash = (string) (ZReport::query()
    ->where('branch_id', $branchId)
    ->where('status', ZReport::STATUS_CLOSED)
    ->orderByDesc('sequence_no')
    ->value('signature') ?? '');

// verifyChain() — walks history:
$zReports = ZReport::query()
    ->where('branch_id', $branchId)
    ->where('status', ZReport::STATUS_CLOSED)
    ->orderBy('id', 'asc')
    ->get();
```

Today both converge because `sequence_no` is monotonic per branch and
auto-allocated via `MAX(sequence_no)+1` inside the same `z_report_b{N}`
cache lock, so insertion order in `id` matches monotonic `sequence_no`
order. **But there is no DB CHECK constraint** that pins
`sequence_no = rank_over(id partition by branch_id)`. A future data-fix
migration that renumbers `sequence_no` or backfills historical Zs in
wrong `id` order would silently mis-link the chain (close picks the
wrong predecessor, verifyChain reports a chain_break the next day).
Severity P3 — latent fragility, not actively reachable.

**Recommendation**: add a `BranchScopeCoverageSentinel`-style regression
test in `ZReportSchemaTest.php` that creates 3 Zs out-of-order in `id`
vs `sequence_no` and asserts the close→verifyChain pair stays
consistent. Read-only and safe (no production change).

### F-EXTRA-C — No daily-cardinality enforcement (architectural intent ≠ invariant)

The PROPOSAL mission says "only one close per day per branch". The
**actual code** says one OPEN at a time per branch, no day-bound
constraint. The migration comment
(`2026_04_22_000003_create_z_reports_table.php:13`) is explicit:
"actually per open/close cycle, because a branch may manually Z a second
time if the first was triggered too early."

So the mission's framing of "one close per day" is an **architectural
guideline**, NOT an invariant — the code intentionally allows N closes
per day per branch (a branch can correct a too-early close). The NF525
invariant is sequence_no monotonic + gap-free + chain HMAC, NOT
day-cardinality.

This is a **clarification** for the audit log: B3.6 attested fiscal
correctness ; that attestation should record that "1 close / day" is
not codified, by design.

### F-EXTRA-D — UPDATE on `z_reports` is explicitly allowed by DB-level trigger doc, no row-checksum at storage layer

`2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:19-21`
explicitly states:

> "UPDATE is INTENTIONALLY allowed because z_reports has a legitimate
> state machine: open → closed (sign + lock totals) → archived
> (FiscalArchiveCommand sets archived_at). Cash enrichment also UPDATEs
> post-close."

So the only defense against a raw-SQL UPDATE on signed columns (e.g.
`total_ttc`, `total_by_method`, `signature`) is the application-layer
`verifySignature()` recompute — same as `ZReportCloseTest::test_signature_verifies_and_detects_tampering()`.

This is **by design** — the cash enrichment decorator
(`ZReportCashEnrichmentService::persistForClosedReport`, line 267)
writes additive non-signed columns. No B3.6 regression.

**However**: a future schema change that adds a CHECK constraint of the
form `signature IS NULL OR (signature_locked_at IS NOT NULL AND ...)`
or a row-level UPDATE trigger that whitelists only the cash-enrichment
columns (cash_*) when status=closed would harden the trail. That's a
V1.0.x heal candidate, not a regression.

---

## 7. Files referenced

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/ZReportService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalSealingService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalChainValidator.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalSequenceService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/ZReportCashEnrichmentService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/ZReport.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/Fiscal/ZReportController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_22_000003_create_z_reports_table.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_05_08_140200_alter_z_reports_add_cash_columns.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/fiscal.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Fiscal/ZReportCloseTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Fiscal/ZReportBoundaryTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Fiscal/ZReportSchemaTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/RetryFiscalAllocCommand.php`

(end of proposal)
