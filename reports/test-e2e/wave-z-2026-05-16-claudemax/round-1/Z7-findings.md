# Z7 — Fiscal NF525 chain (Round 1 Wave Z findings)

**Auditor**: Z7 sub-agent (read-only, adversarial RED-team)
**Branch**: feature/mobile-app-le-cayenne-2026-05-10
**HEAD**: c3ba89863
**Verdict**: **GO-CONDITIONAL** — chain integrity intact, frozen-zone untouched, but **1 P1** wiring gap (Sprint 1C `terminal_id` is column-only — never written by production code, so Z TPE breakdown is functionally dead) + 2 P2 (sequence gap forensic visibility, lock TTL stress tooling) + 2 P3 (doc/observability).

---

## Summary

Wave Z heal sprint 1C ("TPE rates — `payment_terminals` table + Z-report breakdown") added the `payment_terminals` table, the `terminal_id` FK on `order_payments` (`nullOnDelete`), the `PaymentTerminal` model, the `ZReportCashEnrichmentService::aggregateByTerminal()` decorator method, and `ZReportTerminalBreakdownTest` — **without** touching any of the four frozen fiscal services. Discipline respected.

Verified:
- **Frozen-zone diff = 0** over `76d641135~1..c3ba89863` for `FiscalSequenceService.php` / `ZReportService.php` / `AuditLogService.php` (empty `--stat` output) — only `ZReportCashEnrichmentService.php` (+126/-2) which is **not** in the frozen list per kickoff §58-78.
- **Audit-log chain integrity** : `audit_logs` count = 26 (matches baseline), last `current_hash` = `ca4ac1fdc208dae1...` (matches baseline). Linear walk over the 26 rows confirms `prev_hash[N+1] == current_hash[N]` for every row (no break); first row has `prev_hash = NULL` as expected.
- **Immutability triggers** active: `audit_logs_no_update` (BEFORE UPDATE), `audit_logs_no_delete` (BEFORE DELETE), `z_reports_no_delete` (BEFORE DELETE). Z report has DELETE-only guard (UPDATE allowed for additive cash columns persisted post-close by `ZReportCashEnrichmentService::persistForClosedReport()` per documented decorator pattern, `ZReportCashEnrichmentService.php:228-265`).
- **PricingService SSOT** wired via `pricing.use_ssot_service` flag (`config/pricing.php:9`, default `true`). `OrderService.php:329, 645, 1119` + `FrontendOrderService.php:277` all call `PricingService::calculateOrder()` and **discard** any client-supplied total — backend rebuilds totals from `item_id, quantity, option_ids` only. The `else` branch (line 676+) is the legacy non-SSOT path gated off by default.
- **composition_snapshot immutability** : 5 write sites total (`OrderService.php:455, 810, 1266`, `PricingService.php:291`, `FrontendOrderService.php:441`, `RefundWithCounterEntryService.php:135` — the last is a refund-mirror copy, not an overwrite). Zero UPDATE statements on `composition_snapshot` anywhere. `OrderItem.php:44, 71` lists it as `fillable` + cast `array` but no service touches it post-insert.
- **6-year retention safe** : zero `truncate audit_logs` / `delete from z_reports` / `delete from audit_logs` matches in `app/`, `database/`, or anywhere else.
- **payment_terminals FK safety** : `payment_terminals.branch_id` has `cascadeOnDelete()` (migration line 60) — fine, branches lifecycle is upstream. `order_payments.terminal_id` → `payment_terminals.id` uses `nullOnDelete` (migration line 41) — **safe** : archiving a TPE will null the FK on historical rows but **not** delete order_payments; signed Z reports already aggregated the row at close, so post-archive nulling does not retroactively alter Z totals. No FK from `payment_terminals` to `z_reports` exists — no cascading delete risk to the chain.

Open verification: gaps in `fiscal_sequence_no` (max=334 vs count=165 in dev DB, 169 missing) — see P2-Z7-01. Most likely cause = DB::transaction rollbacks during dev/test, **not** a service bug per FiscalSequenceService comment (`FiscalSequenceService.php:18-26`), but no instrumentation exists to confirm in prod.

---

## P0 findings (file:line)

**None.** Chain HMAC intact, triggers active, frozen zones untouched, no retention violation, no signature mutation, no FK that could cascade-delete the chain.

---

## P1 findings (file:line)

### P1-Z7-01 — `terminal_id` is a dead column in production (Sprint 1C Z TPE breakdown will always show "Sans TPE")

**Files**:
- `database/migrations/2026_05_16_120001_add_terminal_id_to_order_payments_table.php:34` — column added.
- `app/Models/OrderPayment.php:36, 47` — `terminal_id` in `$fillable` + cast.
- `app/Models/PaymentTerminal.php:79` — `hasMany(OrderPayment::class, 'terminal_id')`.
- `app/Services/Fiscal/ZReportCashEnrichmentService.php:153-222` — reads `terminal_id` for breakdown.
- `tests/Feature/Fiscal/ZReportTerminalBreakdownTest.php:297` — test seeds `terminal_id` directly.

**Gap**: Production code paths that create `OrderPayment` rows do **NOT** include `terminal_id` :
- `app/Services/Payments/SplitPaymentService.php:202-211` — array keys: `order_id, branch_id, mode, amount, tendered, change_amount, reference, paid_at`. **No `terminal_id`.**
- `app/Services/Order/RefundWithCounterEntryService.php:168-181` — array keys: same set as above, no `terminal_id`.
- No other `OrderPayment::create` / `new OrderPayment` / `->orderPayments()->create` callsites exist anywhere in `app/`.

Consequence: `ZReportCashEnrichmentService::aggregateByTerminal()` groups by `terminal_id` and falls into the "NULL bucket" for every row, returning a single synthetic `{terminal_id: null, name: "Sans TPE", gateway_type: "unknown", fees_total: 0}`. The Sprint 1C commit `f36aa544e` "Z-report breakdown" delivers **schema + UI plumbing** but never produces a real breakdown — `net_after_fees == gross_total` in 100% of cases because `fees_total = 0` for the NULL bucket (enrichment service line 196 only computes fees when `$terminal instanceof PaymentTerminal`).

**Sister verdict mapping**: Wave F F-2 ("TPE rates missing") declared healed by Sprint 1C — **partially false**. The plumbing exists but the wire-in step (caller passes `terminal_id` to OrderPayment::create) was missed.

**Fix scope** (sub-30 LOC, non-frozen files):
1. Add `terminal_id` (nullable int) to `SplitPaymentService::pay()` tranche schema + propagate from `posPaymentForm` → controller → service.
2. Add `terminal_id` to `RefundWithCounterEntryService::execute()` to mirror parent payment's `terminal_id` on refund rows.
3. UI: expose a TPE picker on the POS payment dialog (`PosCheckoutDialog*.vue` or wizard) — falls back to "Sans TPE" when no TPE registered for the branch.

Until fixed, Sprint 1C convergence claim for F-2 should downgrade from "closed" to "schema-only, runtime gap".

---

## P2 findings (file:line)

### P2-Z7-02 — `fiscal_sequence_no` has 169 gaps in dev DB, no `fiscal_alloc_error_at` set, no observability proof these are benign

**Evidence** :
- `SELECT branch_id=1: MIN=1, MAX=334, COUNT=165` → 169 numbers consumed but unallocated.
- `SELECT COUNT(*) WHERE fiscal_alloc_error_at IS NOT NULL` → 0.
- Total orders branch 1 = 222, fiscal-allocated = 165 → 57 orders without `fiscal_sequence_no`.
- 12 gap-jumps; biggest = 25 numbers between fiscal_seq_no 189 → 215; first gap at seq 6 → 7 (single missing).

**Analysis** : `FiscalSequenceService::next()` is called inside `DB::transaction` blocks (`OrderService.php:922`, `PaymentService.php:179`, `FrontendOrderService.php:1136`) and the service comment (`FiscalSequenceService.php:18-26`) asserts that a transaction rollback **does not consume** a sequence number, because the next call sees the same `MAX(fiscal_sequence_no)` again. But the actual DB state shows gaps — meaning **either** (a) some allocations happened in transactions that rolled back **after** `next()` returned but **before** the order row was persisted with the new `fiscal_sequence_no` (which would re-emit the same MAX next time — but here MAX moved forward, so this hypothesis fails), **or** (b) the `lockForUpdate()` race-condition mitigation (line 88-91) handled a real concurrent write that later rolled back its outer transaction. In MySQL with row locks, `SELECT MAX(...) FOR UPDATE` reads the **committed** max — if writer A allocates 100, writer B sees 100 → 101, writer A's outer txn rolls back, writer B has 101 → gap at 100.

NF525 article L.84-D (gap-free monotonic sequence) treats `fiscal_sequence_no` gaps as fiscal-event signals for inspection — auditors expect each gap to map to a documented reason (cancellation log, sealed-then-deleted row, etc.). The current dev DB has zero reason rows.

**Fix V1.x** :
- Add a `fiscal_sequence_gaps` audit table populated by a scheduled `php artisan fiscal:detect-gaps` command that runs after each Z close. Each row : `{branch_id, from_seq, to_seq, detected_at, audit_log_id}`.
- Or : tighten the allocation path so `next()` happens **after** the order INSERT (post-commit hook + retry on UNIQUE violation, since the unique key `orders_branch_fiscal_seq_unique` enforces no duplication).
- Or : document explicitly in `docs/NF525_INVARIANTS.md` that dev DB gaps are expected post-rollback and don't constitute prod violations.

**Severity rationale** : dev only today. In prod with non-rollback flows (kiosk M-08 happy path), gaps would still occur on payment-gateway timeouts → ZReport aggregate excludes orphans (line 300-310 `ZReportService.php` comment) — but the inspector still sees the gap. P2, not P1, because the chain itself is intact and audit auditors expect *some* gaps documented per the law's intent — what's missing is the documentation/observability layer.

### P2-Z7-03 — `FiscalSequenceService::LOCK_ACQUIRE_SECONDS = 3s` may be insufficient under sustained rush

**File**: `app/Services/Fiscal/FiscalSequenceService.php:42-43, 65-74`

```php
private const LOCK_TTL_SECONDS      = 5;
private const LOCK_ACQUIRE_SECONDS  = 3;
```

The service comment (`FiscalSequenceService.php:39-41`) describes 5-6 concurrent POS checkouts as the design target. Wave Y rush tests (`tests/load/RushMidiSimulationTest.php`) exercise 10+ concurrent allocations. Under realistic 4-cashier POS + kiosk fleet (3-5 borne), a burst of 8-10 simultaneous payments hits the same `fiscal_seq_b1` lock — each call holds it for one `lockForUpdate()` + `SELECT MAX` (~5-15ms typical, but spikes to 50-100ms under MySQL load). 3s acquire timeout = 30-50 concurrent waiters tolerated, but on a degraded DB (network blip, lock-wait-timeout=50 default), the 3s could expire and throw `RuntimeException`. The caller (`PaymentService::collectCounter`, `OrderService::posStore`) **does not catch** that exception → the order fails to seal → user-visible 500.

**Fix V1.x** :
- Bump `LOCK_ACQUIRE_SECONDS` to 5-8s for production.
- Add a retry wrapper at caller layer with exponential backoff (≤2 retries).
- Add a Prometheus / log metric `fiscal_seq_lock_acquire_ms` so prod can validate the tail latency before tuning.

**Severity rationale** : no measured failure in dev, no rush test against the lock-acquire timeout. P2 because the failure mode is loud (RuntimeException → caller fails fast, no chain corruption), not silent.

---

## P3 findings

### P3-Z7-04 — `ZReportCashEnrichmentService::persistForClosedReport` writes to closed Z reports via raw `update()` — no audit log of the mutation

**File**: `app/Services/Fiscal/ZReportCashEnrichmentService.php:259-265`

```php
ZReport::query()->whereKey($report->id)->update($cash);
```

The decorator pattern is sound — additive columns (`cash_opening_amount`, `cash_closing_amount`, `cash_variance`, `cash_movements_count`) are hors-signature per the doc-comment (line 22, 95-101). But the UPDATE is silent : no audit_logs entry, no log, no event. If a future regression accidentally updated a *signed* column via the same path, the chain would silently desync and only `php artisan fiscal:verify-chain` would catch it (assuming the command exists — `FiscalArchiveVerifyChainTest.php` implies yes).

**Fix V1.x**: wrap the `update()` in an `AuditLogService::write(['action' => 'z_report.cash_columns_persisted', ...])` to leave a forensic trail, even though the chain itself is unaffected.

### P3-Z7-05 — `payment_terminals` table has no `branch_unique(name)` index → two TPEs with same name silently coexist

**File**: `database/migrations/2026_05_16_120000_create_payment_terminals_table.php:41-56`

Indexes : `(branch_id, status)`, `gateway_type`. No `unique(branch_id, name)`. Operationally, a fleet operator could accidentally create two "TPE Cuisine" rows on the same branch, and `aggregateByTerminal()` would emit two separate rows with the same `name` field → confusing Z report UI.

**Fix V1.x**: add `$table->unique(['branch_id', 'name'])` in a new migration (additive only — fine on empty prod table today).

---

## Healed-verified (sister verdict findings confirmed closed)

- **Z-report breakdown by TPE wired** — `ZReportCashEnrichmentService::aggregateByTerminal()` exists, sums per-terminal `cash_total`, `card_total`, `transactions_count`, applies `fee_percent`/`fee_fixed` correctly (line 196-201), emits sorted output with "Sans TPE" bucket last. `ZReportTerminalBreakdownTest.php` covers T-1..T-4.
- **Net-after-fees computed** — `enrich()` returns `fees_total` and `net_after_fees` (line 116-120). Runtime-only, never persisted, never signed.
- **Decorator preserves chain** — `persistForClosedReport()` touches only additive columns; the signature column is never in the `$cash` array (line 81-86).
- **6-year retention** — no truncate/delete pathways found.
- **Frozen-zone respected** — `git diff 76d641135~1..c3ba89863 --stat -- app/Services/Fiscal/FiscalSequenceService.php app/Services/Fiscal/ZReportService.php app/Services/Fiscal/AuditLogService.php` returns empty output (0 lines changed).
- **Chain HMAC** — 26 rows, last hash `ca4ac1fdc208dae1...` matches kickoff baseline; first row `prev_hash=NULL`; linear walk no break.
- **Triggers active** — `audit_logs_no_update`, `audit_logs_no_delete`, `z_reports_no_delete` all present.

---

## Open-from-sister (issues not yet healed)

None for Z7 directly (sister verdict for fiscal chain was "intact").

Note: Wave F F-5 ("DELETE trigger NF525") is listed in the kickoff as a Sprint 4 hardening item, but `z_reports_no_delete` BEFORE DELETE is already active in dev — so the "missing" trigger F-5 likely refers to a different protection (perhaps `z_reports_no_update` for signed columns, which **does not exist** today — the current UPDATE trigger absence is exactly what enables P3-Z7-04 above to work, but also means a buggy SET signature=... wouldn't be caught at the DB layer).

---

## NEW (issues introduced by heals)

### NEW-Z7-A (P1) — `terminal_id` is column-only; production never writes it (P1-Z7-01 above)

Sprint 1C delivered the schema + read-side enrichment, but missed the write-side wire-in. Result: the Z-report TPE breakdown is functionally inert in production. The test suite (`ZReportTerminalBreakdownTest`) seeds `terminal_id` directly into `OrderPayment::create` so the tests are green, but the green tests do not exercise any production write path.

### NEW-Z7-B (P3) — `ZReportCashEnrichmentService` mutates closed Z rows silently (P3-Z7-04 above)

Decorator pattern is correct but the silent UPDATE leaves no audit breadcrumb. Sister verdict didn't catch this because the chain HMAC is by design unaffected.

---

## Verdict

**GO-CONDITIONAL** for V1 ship pending the P1-Z7-01 wire-in.

Fiscal chain itself is **rock solid**: HMAC chain verified end-to-end, frozen zones untouched, all triggers active, no retention violation, no FK that could cascade-delete the chain, PricingService SSOT enforced, composition_snapshot immutable. The Sprint 1C heal commit `f36aa544e` was disciplined (decorator pattern, runtime-only enrichment) — but its caller-side wire-in (write `terminal_id` at payment creation) was not delivered.

P0+P1 count: **0 P0, 1 P1**. Convergence blocked on P1-Z7-01 until either (a) the wire-in lands in a Sprint 1C-bis commit, or (b) the sister verdict downgrades F-2 from "closed" to "schema-only, runtime gap deferred to V1.1".

---

**Auditor signoff** : Z7 sub-agent — read-only audit, no file modified, no Bash mutation, every claim cited with file:line + DB query result.
