# RED-Z5 — NF525 Fiscal Audit
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z5 · **Branch**: `v1-0-1-hardening-2026-05-17`
**Scope**: FiscalSequenceService race, HMAC chains (audit_logs + z_reports), Z-report open/close, DELETE/UPDATE triggers, archive, retry cron, refund counter-entry.

## A. Anchors verified (file:line)
- `app/Services/Fiscal/FiscalSequenceService.php:57-104` — `next()`: `Cache::lock('fiscal_seq_b{N}',5)` + `$lock->block(3)` then `DB::transaction` with `Order::withoutGlobalScopes()->where('branch_id')->lockForUpdate()->max('fiscal_sequence_no')` returns `$max+1`. **Reservation-only — does NOT INSERT**; caller persists.
- `app/Services/Fiscal/AuditLogService.php:70-192` — `write()`: `Cache::lock('audit_chain_b{N}',10)` + `block(5)`; INSERT inside `DB::transaction` (l. 112); HMAC = `hash_hmac('sha256', $prevHash.'|'.canonical(action,payload), branchSecret)` (l. 237-243); single retry on UNIQUE collision (l. 185-189).
- `app/Services/Fiscal/AuditLogService.php:88-97` — rejects `branch_id === null` (forces explicit branch_id=0 for system writes).
- `app/Services/Fiscal/AuditLogService.php:269-292, 303-327` — secret resolution + prod sentinel/min-32-char guards; throws on missing.
- `app/Services/Fiscal/AuditLogService.php:199-231` — `verifyChain()`: full ordered `cursor()` walk per branch; returns first broken id or null.
- `app/Services/Fiscal/ZReportService.php:71-137` — `open()`: `Cache::lock('z_report_b{N}',10)` + `block(4)`; pre-flight `verifyChain($branchId)` (l. 88) + `chainValidator()->assertChainIntegrity` (l. 95); rejects existing OPEN; seq = `MAX(sequence_no)+1` inside transaction.
- `app/Services/Fiscal/ZReportService.php:180-286` — `close()`: pre-flight `verifyChain`; `lockForUpdate` on STATUS_OPEN row; signs HMAC + atomic save in transaction.
- `app/Services/Fiscal/ZReportService.php:297-435` — `aggregate()`: `withoutGlobalScope(BranchScope)` + **`withTrashed()`** (l. 338, P0-FIX-1/2), `whereNotNull('fiscal_sequence_no')`, half-open `(from, to]`.
- `app/Services/Fiscal/ZReportService.php:463-572` — Z-chain `verifyChain()`: 3 error kinds (`chain_break`, `sequence_gap`, `signature_mismatch`); strict=throw, non-strict=log+return.
- `app/Services/Fiscal/FiscalSealingService.php:11-38` — Z HMAC pre-image `{branch_id, sequence_no, closed_at UTC ISO-8601, aggregates}`.
- `app/Services/Fiscal/FiscalChainValidator.php:55-107` — bounded audit-chain tail (default 500, `fiscal.audit_chain_tail_window`) post Z-chain strict.
- `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:37-40` — UNIQUE `(branch_id, fiscal_sequence_no)` named `orders_branch_fiscal_seq_unique`.
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php:97-134` — BEFORE UPDATE+BEFORE DELETE triggers (MySQL/MariaDB SIGNAL 45000; SQLite `RAISE(ABORT,…)`). pgsql/sqlsrv = no triggers.
- `database/migrations/2026_04_22_000002_…php:62-83` — `down()` throws in prod (6y NF525 retention).
- `database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php:34-40` — UNIQUE `(branch_id, prev_hash)`. NULLs treated as distinct per MySQL+SQLite≥3.9.
- `database/migrations/2026_04_22_000003_create_z_reports_table.php:62` — UNIQUE `(branch_id, sequence_no)`.
- `database/migrations/2026_05_09_160000_…php:50-58` — BEFORE DELETE trigger on z_reports (MySQL only). UPDATE intentionally allowed (state machine).
- `database/migrations/2026_05_10_010000_…php:107-141` — BEFORE DELETE on cash_movements/cash_drawer_sessions/order_payments + RESTRICT FKs.
- `database/migrations/2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php:51-57` — nullable retry flag column.
- `app/Console/Commands/FiscalVerifyChainCommand.php:65-188` — dual-chain CLI; exit codes 0/1/2/3; `--branch=0` refused; `--all` sweeps via `Kernel::activeBranchIds()`.
- `app/Console/Commands/RetryFiscalAllocCommand.php:39-132` — everyMinute; predicate `PAID + seq IS NULL + alloc_error_at IS NOT NULL`; limit=50.
- `app/Console/Commands/FiscalArchiveCommand.php:71-155` — acquires SAME `z_report_b{N}` lock (TTL=600s, wait=30s) for TOCTOU closure; aborts on `verifyChain valid=false`.
- `app/Console/Kernel.php:143-147, 174-208, 216-246` — schedules: retry-alloc everyMinute · chain-monitor daily 03:30 all branches · archive daily 02:00 J-1.
- `app/Services/OrderService.php:881, 946-947` — POS alloc inside `saveOrderWithQueueNumber`, called from outer DB transaction.
- `app/Services/PaymentService.php:184-247` — counter-payment alloc under `lockForUpdate` inside transaction.
- `app/Services/FrontendOrderService.php:1086, 1130-1190` — kiosk auto-allocate in transaction; throw → flag `fiscal_alloc_error_at = now(); save(); return`.
- `app/Services/Order/RefundWithCounterEntryService.php:54-91, 88-251` — sealed-Z required; mirror seq via `next()`; atomic with audit row.
- `app/Services/Order/SealedOrderGuard.php:46-65, 84-114` — predicate identical to `aggregate()` half-open window; toggleable `fiscal.sealed_z_guard_enabled`.
- `tests/Feature/Fiscal/FiscalSequenceTest.php:29-49` — `test_atomic_per_branch_no_gaps` covers 1..10 sequential.
- `tests/Feature/Fiscal/AuditTruncateProtectionDeployDocTest.php` + `deploy/ansible/site.yml:59-71` — REVOKE DROP/ALTER on 7 fiscal tables (sentinel-locked).

## B. Findings P0 → P3 (fiscal = LEGAL; any P0 blocks V1)

**P0 — none confirmed.**

### P1
- **F-Z5-P1-A** — `AuditLogService::verifyChain()` (l. 199-231) walks ALL rows for branch with no LIMIT. `ZReportService::open()` calls it (l. 88) BEFORE the bounded-tail FiscalChainValidator. Under the 4s `z_report_b{N}` cache lock, a 6-year audit chain becomes O(N) under-lock work. Year-1 single-resto = fine. Year-3+ = lock-TTL-exceeded cliff. **Recommend**: bound or scope to last N Z reports.
- **F-Z5-P1-B** — UNIQUE `(branch_id, prev_hash)` (`2026_04_22_100000` l. 22-24) treats each NULL distinct (MySQL/SQLite semantics). The **genesis row** (first audit_log per branch, prev_hash=NULL) is therefore NOT defended by the index. Cache::lock+transaction at READ-COMMITTED should serialize, but if cache driver degrades (database fallback, Redis split-brain) two genesis writes could both succeed → forked chain at row 1 only. Mitigated in practice; flagged for prod cache driver confirmation.
- **F-Z5-P1-C** — `FrontendOrderService.php:1174-1175` writes `fiscal_alloc_error_at = now(); $locked->save();` from inside the catch block, which is inside the parent `DB::transaction` (l. 1086). If THAT save() itself throws (rare: trigger, FK, DB hiccup), the whole transaction rolls back including the flag write. Caller sees `promoted=false`, no exception, no flag, no retry candidate. Row stays PAID+PENDING+seq=NULL+flag=NULL = pre-iter14 orphan reproduced. Narrow edge case; **Recommend**: write flag via separate raw `DB::update` outside the transaction or in a finally.
- **F-Z5-P1-D** — `orders` table is the source-of-truth for Z aggregation but is **NOT** in the Ansible REVOKE DROP/ALTER list (only 7 fiscal sibling tables are). Hard-delete on `orders` is not blocked by a DB-level trigger. CLAUDE.md §8 lists audit_logs+z_reports retention only — intent or oversight? If an admin's DB credential were compromised, `orders` could be wiped and the Z-signature would still verify (Z stores aggregated totals, not row IDs). Owner gate.
- **F-Z5-P1-E** — `FiscalSequenceService::next()` MAX query (l. 88-91) does NOT call `withTrashed()`. `Order` uses SoftDeletes (`Order.php:11-17`). A soft-deleted order with `fiscal_sequence_no=42` is invisible to MAX → next allocation returns 42 → UNIQUE composite throws 1062 on save. No gap (UNIQUE catches it), but legitimate POS sale fails visibly. **Recommend**: add `withTrashed()` to the MAX query.

### P2
- **F-Z5-P2-A** — `FiscalSequenceService::next()` reservation has a small TOCTOU window between MAX and the caller's actual INSERT. On `Cache::lock=database` driver under contention, UNIQUE composite is the ultimate gate (no silent gap), but raw POS sales surface confusing 1062 errors instead of graceful retry. Owner gate: confirm prod cache=Redis.
- **F-Z5-P2-B** — `FiscalArchiveCommand --no-verify` (l. 54, 108) ships a bundle with `z_chain_verified=false` in manifest. No `AuditLogService::write` recording WHO bypassed verification. NF525 evidence bundle without chain proof should require a forensic trail.
- **F-Z5-P2-C** — `RetryFiscalAllocCommand` has no per-order retry cap. Structurally-broken row retries every minute forever. Operators only see log signal (`kiosk.fiscal_alloc_retry.crashed`). Bound only by `--limit=50`/tick.
- **F-Z5-P2-D** — `applyOrderToTotals` (l. 630-638) falls back to `'unknown'` bucket in `total_by_method` if both `pos_payment_method` and `payment_method` are blank. NF525 requires per-tender breakdown; bucket exists but is not flagged in close logs.

### P3
- **F-Z5-P3-A** — Daily chain-monitor cron (Kernel:179-188) collapses exit codes 1/2/3 into a single `Log::error`. TAMPER (1), INVALID (2), EXEC ERROR (3) all share the same alert lane — no severity routing.
- **F-Z5-P3-B** — `assertNoPendingClose()` (l. 147-174) is dead code: `STATUS_CLOSING` constant is never defined. Documented as "no-op until then." Cleanup or implement.
- **F-Z5-P3-C** — `audit_logs` UPDATE/DELETE protection driver-conditional. MySQL+SQLite covered; pgsql/sqlsrv silently drop protection (l. 138-140). Confirmed prod = MySQL via `config/database.php`.

## C. Hard questions for owner (16)
1. **Cache driver in production**: Redis HA, or database fallback? F-Z5-P1-B + F-Z5-P2-A both hinge on this.
2. **`orders` table DROP/ALTER protection** — not in Ansible REVOKE list. F-Z5-P1-D. Intentional or backlog?
3. **`FiscalSequenceService::next()` does NOT `withTrashed()`** — soft-delete + new order = 1062. Documented or bug? F-Z5-P1-E.
4. **Audit-chain genesis NULL race** — any test/guarantee that exactly one prev_hash=NULL row exists per branch?
5. **`STATUS_CLOSING`** placeholder — when will it be activated, or remove the dead `assertNoPendingClose` call?
6. **`fiscal:archive --no-verify`** — should it require an audit_log entry naming the bypassing operator?
7. **Unbounded `verifyChain()` at every Z open** (F-Z5-P1-A) — projected audit_logs row count at year-3? Plan to bound the sweep?
8. **`activeBranchIds()` predicate** = status IN (5, 1). What about a future status=4 (compliance hold)? Skipped from monitoring.
9. **Refund counter-entry boundary**: refund issued at exact `closed_at` instant — falls outside any sealed Z window (predicate is strict `<` then inclusive `<=`). Documented?
10. **Multi-branch admin ZReportController.open/close authz** — Spatie permission check that an admin cannot close a different branch's Z? (Adjacent Z4 lane, surfaced here.)
11. **`FISCAL_AUDIT_SECRET` rotation runbook** in `docs/FISCAL_SECRETS.md` — tested end-to-end with chain re-verify?
12. **HMAC pre-image** does NOT include branch_id when shared single-secret config is used — cross-branch replay theoretical risk if secret rotation lags.
13. **`tax_rate` canonicalisation** (`number_format(...,2)` + rtrim zeros) — verified for 5.5/8.5/20/10 — any other taxonomy?
14. **Allocation consumed by failed Order** — verified `next()` returns N, caller TX rolls back, next next() returns same N (no gap) via `OrderService.php:881-948` and `FiscalSequenceTest`. Confirm understanding.
15. **`composition_snapshot` NOT in HMAC** — intentional (signature covers totals + method + tax_rate buckets only). Confirm.
16. **TRUNCATE deploy gate**: Ansible playbook applied to prod at first deploy? Recovery if DROP/ALTER grants accidentally restored?

## D. Sync invariants verified GREEN
- UNIQUE composites: `(branch_id, fiscal_sequence_no)` on orders + `(branch_id, sequence_no)` on z_reports + `(branch_id, prev_hash)` on audit_logs (genesis NULL caveat).
- BEFORE UPDATE+BEFORE DELETE triggers on `audit_logs` (MySQL+SQLite); BEFORE DELETE on `z_reports` + `cash_movements` + `cash_drawer_sessions` + `order_payments` (MySQL only).
- Migration `down()` blocks rollback in production (RuntimeException).
- HMAC chains audit_logs + z_reports with per-branch secret overrides; production sentinel/<32-char guards on first sign attempt.
- Triple defense fiscal sequence: `Cache::lock` + `lockForUpdate` + UNIQUE composite.
- Retry cron `foodking:fiscal:retry-alloc` everyMinute + onOneServer + withoutOverlapping(5).
- Daily chain monitor `fiscal-chain-monitor-all-branches` 03:30 sweeps every active branch.
- Daily archive `foodking-fiscal-archive-daily` 02:00 sweeps every active branch.
- Archive command acquires same `z_report_b{N}` cache lock as open/close (TOCTOU closed).
- Sealed-Z guard predicate identical across destroy / changeStatus / aggregate / RefundWithCounterEntry.
- Audit-chain requires explicit branch_id (rejects null).
- TRUNCATE/DROP/ALTER blocked via Ansible REVOKE on 7 fiscal tables + sentinel `AuditTruncateProtectionDeployDocTest`.
- Kiosk alloc failure flags `fiscal_alloc_error_at` (post-iter14 fix verified).
- `FiscalVerifyChainCommand` distinct exit codes 0/1/2/3 + `--all` sweep semantics.
- POS allocation inside outer transaction (`OrderService.php:881-948`); refund mirror reuses `next()` atomically.

## E. Out-of-scope / unverifiable this session
- Live production cache driver (Redis vs database) — owner-gate.
- `docs/FISCAL_SECRETS.md` rotation runbook content — not opened.
- ZReportController authz cross-branch (adjacent Z4 lane).
- `tests/Feature/Fiscal/AuditLogConcurrencyTest.php` + `NF525ComplianceE2ETest.php` full content — referenced, not opened.
- Live prod chain state (CHAIN OK per session-handoff, not re-executed here).
- pgsql / sqlsrv parity — out per `config/database.php` target.

## F. RED verdict
**Score: 8.4 / 10.** Defense-in-depth is substantial. Triple-lock allocation is correct, HMAC chains have correct canonicalisation + secret guards, immutability triggers cover prod (MySQL) and tests (SQLite), retry cron + daily chain monitor + daily archive form coherent observation. **No P0 confirmed — chain integrity, gap-free invariant, retention block all hold.**

**Top 3 residual risks** (none ship-blocking V1 LOCAL):
1. **Unbounded `verifyChain()` at every `Z::open()`** (F-Z5-P1-A) — O(N) full-walk under 4s lock. Year-1 fine; year-3+ cliff. Bound it.
2. **Cache driver assumption** (F-Z5-P1-B + F-Z5-P2-A) — sequence + audit-chain genesis lean on Redis single-leader. Database fallback creates 1062 user-visible failures (no silent gap). Confirm Redis prod.
3. **Soft-delete shadowing MAX** (F-Z5-P1-E) + **flag-save inside parent transaction** (F-Z5-P1-C) — narrow edge cases. `withTrashed()` on MAX + flag write via separate connection close them.

**Shippable V1 LOCAL Le Cayenne?** **YES — GO** for fiscal lane.
- All NF525 invariants documented in CLAUDE.md §8 are enforced by code paths verified.
- TRUNCATE/DROP/ALTER blocked at deploy layer with sentinel.
- Per-branch chain monitor daily + on-demand `fiscal:verify-chain --all`.
- 6y retention blocked at migration `down()` + DB triggers + Ansible REVOKE.
- Allocation failures flagged + everyMinute retry cron.
- Refund counter-entry sealed-Z-aware + atomic fresh-sequence mint.

**Owner gates before V2 SaaS multi-branch (not V1):** per-branch secret rotation test, `orders` table DROP/ALTER revoke, bounded audit-chain verify at Z-open, move verify off the critical lock.
