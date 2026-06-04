# GStack Fiscal Auditor — NF525 — Wave 1

Branch `v1-0-1-hardening-2026-05-17` HEAD `6908edbde`. LOCAL Le Cayenne. Read-only; zero edits. Frozen
services (FiscalSequenceService, AuditLogService, ZReportService, PricingService) audited untouched. Cloud
topics out of scope per owner mandate.

## 1. Chain integrity verification

### 1.1 audit_logs
Schema: `database/migrations/2026_04_22_000002_create_audit_logs_table.php:34-56` — `branch_id`, `user_id`,
`action`, `resource`, `resource_id`, `payload` (json), `prev_hash` CHAR(64), `current_hash` CHAR(64), `ip`,
`user_agent`, `session_id`, `created_at`.

DB-level triggers (`installImmutabilityTriggers` lines 89-141): MySQL/MariaDB `audit_logs_no_update` +
`audit_logs_no_delete` via SIGNAL SQLSTATE '45000'; SQLite uses `RAISE(ABORT, ...)`. `down()` (lines 62-83)
**throws RuntimeException when APP_ENV=production** — accidental rollback blocked. UNIQUE(branch_id,
prev_hash) at `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` — DB-level fork rejection.

### 1.2 AuditLogService::write
`app/Services/Fiscal/AuditLogService.php:70-132`:
- Per-branch cache lock `audit_chain_b{n}` TTL=10s, WAIT=5s (lines 100-109).
- Null branch_id rejected (lines 93-98); `branch_id=0` is the system/CLI chain.
- DB::transaction wraps `performInsert` (line 112) — atomic tail-read + INSERT.
- UNIQUE-violation retry exactly once with recomputed prev_hash (lines 184-189).
- Secret via `secretFor()` (lines 269-292) — RuntimeException if missing (line 288). Production-safe
  assertion (lines 303-327) blocks dev sentinels and secrets <32 chars when APP_ENV=production.
- Hash: `hash_hmac('sha256', prev_hash || canonical($action,$payload), $secret)` (line 242); canonical JSON
  recursively sorts keys (lines 358-374) — stable across PHP/DB drivers.

### 1.3 verifyChain
Lines 199-231: re-walks ordered by id, optional branch scope, returns first tampered row id (constant-time
`hash_equals` line 223) or null.

Bounded-tail extension: `app/Services/Fiscal/FiscalChainValidator.php:118-183` (window default 500 via
`fiscal.audit_chain_tail_window`) used at Z `open()` to avoid full O(N) walk under z_report_b{N} lock.

### 1.4 z_reports
Schema `database/migrations/2026_04_22_000003_create_z_reports_table.php:27-65` — UNIQUE(branch_id,
sequence_no) line 62. DELETE trigger at `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:50-58`
(MySQL only); UPDATE allowed by design (open → closed → archived; cash enrichment).

`ZReportService::close` signs via `FiscalSealingService::signZReport` (`app/Services/Fiscal/ZReportService.php:618-628`),
prev_hash chained from previous CLOSED Z (lines 233-237). `verifyChain` (lines 463-572) re-walks link +
sequence gap + recomputed signature; throws strict mode (line 561). Z `open()` line 88 verifies chain
first — chain rot blocks new opens.

### 1.5 Concurrency on Z chain
`Cache::lock('z_report_b{n}')` TTL=10s, ACQUIRE=4s
(`app/Services/Fiscal/ZReportService.php:33-34,78-83,191-196`). Inside the transaction, `lockForUpdate()` on
the open row (line 207). `assertNoPendingClose` (lines 147-174) detects STATUS_CLOSING stuck >15s.

### 1.6 Cross-service DELETE protection
`database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php`:
- `cash_movements` / `cash_drawer_sessions` / `order_payments` → BEFORE DELETE trigger SQLSTATE 45000
  (lines 108-141, MySQL only).
- `cash_movements.cash_drawer_session_id` + `order_payments.order_id` FKs: `cascadeOnDelete` →
  `restrictOnDelete()` (lines 65-96).

### 1.7 CLI `fiscal:verify-chain` — **NOT FOUND** (GAP)
Comprehensive grep of `app/Console/Commands/*.php`: no matching `protected $signature`. What exists:
- `foodking:fiscal:archive` (`FiscalArchiveCommand.php:50`) — verifies Z chain pre-bundle (lines 96-109)
  when `fiscal.verify_chain_before_archive=true` (default).
- `foodking:fiscal:retry-alloc` (`RetryFiscalAllocCommand.php:41`) — kiosk orphan recovery only.
- `PreflightProductionCommand::checkFiscalVerifyChain` (lines 230-238) only checks the flag, does NOT run
  the chain.

No on-demand "verify chain" CLI for ops or NF525 inspection. See §10 R1.

## 2. composition_snapshot immutability — 5 write sites

`composition_snapshot` JSON nullable added at
`database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php:12`. Eloquent cast `array`
(`app/Models/OrderItem.php:71`). Fillable line 44.

| # | Surface | File:line | Note |
|---|---------|-----------|------|
| 1 | PricingService SSOT (3 surfaces) | `PricingService.php:291` | Bulk `OrderItem::insert` after `CompositionSnapshotBuilder::build` (line 270). All 3 surfaces when `pricing.use_ssot_service=true` (default). |
| 2 | OrderService::store (legacy web) | `OrderService.php:455` | Insert path inside DB transaction. |
| 3 | OrderService::posOrderStore legacy | `OrderService.php:810` | When `use_ssot_service=false`. |
| 4 | OrderService::tableOrderStore legacy | `OrderService.php:1266` | Same fallback. |
| 5 | FrontendOrderService legacy | `FrontendOrderService.php:441` | Kiosk legacy fallback. |

All five `json_encode` because bulk `insert()` bypasses the Eloquent cast.

**Refund mirror is INSERT, not UPDATE**: `RefundWithCounterEntryService.php:136` copies
`composition_snapshot` byte-for-byte onto a new OrderItem row on the mirror order. Parent never mutated.
Same for `allergens_snapshot` line 141.

**No UPDATE site found**: project-wide grep on `composition_snapshot` outside `insert()` returned zero
hits. No `OrderItem::query()->update`, no `DB::table('order_items')->update` touching it, no Eloquent
`save()` overwrite. `BackfillAllergensSnapshotCommand:93` updates **only** `allergens_snapshot` rows where
NULL — composition untouched. Historical migration
`2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot` allergens-only.

**Read sites (no mutation)**: KDS hash builder (`KitchenDisplaySystemOrderService.php:272`), PosOrder
display (`PosOrderController.php:230,262`), resources (`OrderItemResource.php:36`), stock release
(`StockService.php:280`).

## 3. fiscal_sequence_no monotonicity

`FiscalSequenceService::next` `app/Services/Fiscal/FiscalSequenceService.php:57-104`:
- Positive branch_id enforced (lines 59-63).
- Cache::lock `fiscal_seq_b{n}` TTL=5s, ACQUIRE=3s (lines 65-74).
- `DB::transaction` (line 76) → `SELECT MAX(fiscal_sequence_no)+1` with `lockForUpdate()` (lines 88-91).
  SQLite no-op via BEGIN IMMEDIATE (comment line 86).
- Caller persists `$order->fiscal_sequence_no` inside the **same outer transaction** — outer rollback
  leaves no observable consumption.

### Allocation triggers (4 paths verified)
| # | Caller | File:line | Trigger |
|---|--------|-----------|---------|
| 1 | POS close | `OrderService.php:922-923` | New POS order, inside `saveOrderWithQueueNumber` 'pos' context. |
| 2 | Counter deferred pay | `PaymentService.php:206-208` | Counter order paid, seq still null. |
| 3 | Kiosk auto-allocate on PAID | `FrontendOrderService.php:1130-1153` | Same DB::transaction as PAID status promotion. Flag `fiscal.kiosk_auto_allocate_sequence` default true. |
| 4 | Refund counter-entry mirror | `RefundWithCounterEntryService.php:90` | Negative mirror order gets own seq — refund is fiscal event. |

### Gap-free invariant
- DB UNIQUE `orders_branch_fiscal_seq_unique` (branch_id, fiscal_sequence_no) at
  `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:38` — final correctness gate.
- Allocate-then-crash safety: lockForUpdate + outer transaction rollback ⇒ next call sees unchanged MAX.
- Kiosk failure path (FrontendOrderService:1154-…): `fiscal_alloc_error_at` flag persisted, cron
  `foodking:fiscal:retry-alloc` (everyMinute, `Kernel.php:142`) recovers. Order stays PAID+PENDING (KDS
  skips, Z excludes) — no silent gap, no silent orphan.

## 4. Pricing SSOT — 3 consumer surfaces

`PricingService::calculateOrder` (`app/Services/Pricing/PricingService.php:36-…`) single authoritative
path. Backend re-reads `Item::price` from DB (lines 57-61, `$dbItem->price` line 134) — frontend `price`
fields **never trusted**.

| Surface | File:line |
|---------|-----------|
| Kiosk | `FrontendOrderService.php:277` |
| POS  | `OrderService.php:645` |
| Table | `OrderService.php:1119` |
| Web   | `OrderService.php:329` |
| Preview (read-only, no persist) | `OrderQuoteService.php`, `Kiosk/PricingPreviewService.php` |

Feature flag `pricing.use_ssot_service` (`config/pricing.php:9`, `PRICING_USE_SSOT`, default true). Legacy
fallback paths still server-recompute from DB and re-encode `composition_snapshot` — NF525-safe even when
SSOT off. Frontend `price` trust grep across `app/Http/`: zero hits in order paths.

### Stripe cents — Insights P0-#2 verified
`app/Http/PaymentGateways/Gateways/Stripe.php:68`: `'amount' => (int) round((float) $order->total * 100)`.
Round-before-cast — €9.99 → 999 cents (not 900). Comment lines 50-56 cites P0-6 CTO audit 2026-05-16.
Metadata `order_id` injected (lines 71-74) for webhook correlation.

## 5. Retention + DELETE protection (LOCAL)

### Pruners verified safe per CLAUDE.md §8
- `PruneOutboxCommand` (`app/Console/Commands/PruneOutboxCommand.php`) touches **only** `domain_events`
  (lines 50-86). Comment lines 25-27: `audit_logs + z_reports (6y retention) are NEVER touched`.
- `PruneWebhookEventsCommand` (`app/Console/Commands/PruneWebhookEventsCommand.php`) touches **only**
  `webhook_events` processed/duplicate >180d (PCI dispute window). Comment lines 35-37 reiterates.

Scheduled (`app/Console/Kernel.php`): `outbox:prune --older-than-days=90` (line 100),
`webhook:prune --older-than-days=180` (line 116), `fiscal:retry-alloc` (line 142). `fiscal:archive` is
manual/ops only (not scheduled). **No pruning command can erase audit_logs or z_reports.**

### Local DELETE protection
| Table | Protection | Source |
|-------|-----------|--------|
| audit_logs | Trigger BEFORE UPDATE+DELETE (MySQL+SQLite) | 2026_04_22_000002 lines 96-136 |
| z_reports | Trigger BEFORE DELETE (MySQL only) | 2026_05_09_160000 lines 50-58 |
| cash_movements | Trigger BEFORE DELETE (MySQL only) + FK restrictOnDelete | 2026_05_10_010000 lines 73-79, 108-117 |
| cash_drawer_sessions | Trigger BEFORE DELETE (MySQL only) | 2026_05_10_010000 lines 119-129 |
| order_payments | Trigger BEFORE DELETE (MySQL only) + FK restrictOnDelete | 2026_05_10_010000 lines 88-95, 131-141 |

LOCAL note: only `audit_logs` has SQLite triggers — z_reports/cash/payment DDL is MySQL-only. LOCAL Le
Cayenne MUST run on MySQL/MariaDB for full DELETE protection. TRUNCATE bypasses MySQL triggers; mitigation
is `GRANT` revocation on prod DB user (deploy doc reference at 2026_05_09_160000 lines 30-34, 2026_05_10_010000
lines 47-50) — owner-physique task, not code.

## 6. Receipt NF525 compliance

`app/Services/Receipt/ReceiptDataService.php:13-28` exposes: `fiscal_sequence_no` (line 20),
`pos_register_id` (line 21), `pos_siret` (22), `pos_vat_intra` (23), `pos_legal_footer` (24),
`operator_name` (25), `created_at` (26).

Reprint marking: `PosReceiptPrintController::increment` `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php:35-75`.
Atomic `receipt_print_count = COALESCE(...,0)+1` (line 47). `is_duplicata = count >= 2` (line 61). Audit
action `pos.receipt.reprint` vs `pos.receipt.print` (line 83). Best-effort chain emission — failure does
NOT block paper delivery (operational continuity, lines 22-27); UI gets `audit_emitted=false` (line 73);
fiscal channel logs the failure. Per-rate TVA breakdown shares SSOT with Z (`ZReportService::taxBreakdownForOrders`
lines 645-668, source `order_items.tax_rate + tax_amount`) — no drift surface.

Frontend component `ReceiptDuplicataMarker.vue` referenced in controller docstring line 19 (Article
286-I-3 bis CGI requirement).

## 7. Weak spots (deviations from CLAUDE.md §8)

1. **W1 — Missing `fiscal:verify-chain` CLI** (P1). CLAUDE.md §8 cites it; not present. Ops cannot run
   on-demand chain integrity without spinning a heavier archive run or hitting Z `open()`.
2. **W2 — `composition_snapshot` in $fillable** (P2). `OrderItem.php:44` includes it. No model `updating`
   guard. A future contributor could call `->update(['composition_snapshot' => ...])` without DB-level
   rejection. Current code: zero such call. Future risk: latent.
3. **W3 — Receipt reprint counter increments before audit emission** (P3).
   `PosReceiptPrintController:67` increments BEFORE `emitAudit` (lines 80-93). Failure → counter advanced,
   audit row missing. Acceptable per operational-continuity doctrine but SIEM alert + manager dashboard
   warranted.
4. **W4 — Local SQLite z_reports/cash/payment DELETE unprotected** (P3 LOCAL only).
   Migrations 2026_05_09_160000 and 2026_05_10_010000 short-circuit non-MySQL. Acceptable for test runner;
   risk only if LOCAL ever runs SQLite (it should not).
5. **W5 — No DB-level immutability trigger on `order_items.composition_snapshot`** (P2). Unlike
   audit_logs/z_reports/cash_movements, no `BEFORE UPDATE` trigger rejecting snapshot mutation. Defense
   relies entirely on application discipline.
6. **W6 — Z chain validation feature-flagged** (P3). `FiscalChainValidator::isEnabled` lines 185-188
   reads `fiscal.chain_validation_enabled` default true. Flag-off path skips bounded audit-chain tail
   walk; Z chain unconditional still runs. Document override + re-enable procedure.

## 8. Existing test coverage

| Test | Coverage |
|------|----------|
| `tests/Feature/Fiscal/FiscalSequenceTest.php` | 1..N gap-free monotonicity, per-branch isolation, positive branch_id, MAX continuation |
| `tests/Feature/Fiscal/AuditLogHashChainTest.php` | Pristine verify, payload tamper, forged row, canonical-order invariance, secret guard |
| `tests/Feature/Fiscal/AuditLogConcurrencyTest.php` | UNIQUE(branch_id, prev_hash) fork rejection |
| `tests/Feature/Fiscal/AuditLogBranchRequiredTest.php` | Null branch_id rejection |
| `tests/Feature/Fiscal/ZReportCloseTest.php` + `ZReportBoundaryTest.php` + `ZReportAggregateFilterTest.php` | Close path, half-open (from,to] window, fiscal_sequence_no + withTrashed + terminal-status filter |
| `tests/Feature/Fiscal/ZReportTaxBreakdownTest.php` + `ZReportTerminalBreakdownTest.php` | total_by_tax_rate + per-terminal split |
| `tests/Feature/Fiscal/ZReportSchemaTest.php` + `ZReportDeleteTriggerMysqlOnlyTest.php` + `ZOpenChainVerifiedTest.php` | Schema, DELETE trigger SQLSTATE 45000, open invokes verifyChain |
| `tests/Feature/Fiscal/FiscalArchiveVerifyChainTest.php` | Archive CLI verifies Z chain pre-bundle |
| `tests/Feature/Fiscal/NF525ComplianceE2ETest.php` | Full NF525 contract |
| `tests/Feature/Fiscal/OrderFiscalSequenceSchemaTest.php` | Unique index check |
| `tests/Feature/Fiscal/FiscalAllocOrphanRetryTest.php` | Kiosk retry cron |
| `tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest.php` + `FiscalCashAtCounterLifecycleTest.php` + `FiscalHardeningMinorTest.php` | Audit call sites + counter cash + hardening |
| `tests/Unit/Services/Fiscal/FiscalChainValidatorTest.php` | Bounded tail walk |
| `tests/Feature/Cash/CashAuditLogChainTest.php` + `ZReportCashEnrichmentTest.php` | Cash session chain + post-Z enrichment |
| `tests/Feature/PosReceiptFiscalExposureTest.php` | VAT/SIRET/register_id/sequence in receipt |
| `tests/Feature/OrderItemCompositionSnapshotTest.php` + `KDS/KdsSnapshotImmutableTest.php` + `Sentinels/PosReorderHistoricalPricingSentinelTest.php` | Snapshot written at create; KDS reads snapshot; reorder uses snapshot price |
| `tests/Feature/Sentinels/F001KioskFiscalSequenceInvariantSentinelTest.php` + `BypassPaymentInvariantsTest.php` | Kiosk invariant + bypass authz |
| `tests/Feature/CancelAuditTrailTest.php` + `PosManualDiscountAuditTest.php` | Cancel + manual discount audit |
| `tests/Feature/Stock/StockReleaseOnRefundTest.php` | Stock release walks snapshot.addons |
| `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` + `ItemWizardStepVersionImmutabilityTest.php` | Profile rollover safety |

## 9. Test coverage GAPS

1. **G1 — No CI-pinned chain baseline sentinel**. Insights round 1 cites count=26, last_hash=ca4ac1fdc208dae1.
   No PHPUnit asserts a deterministic chain baseline. Drift only caught at next Z open or archive.
2. **G2 — No negative test for composition_snapshot UPDATE rejection**. All tests assert it's written; none
   assert it cannot be rewritten.
3. **G3 — No test for `warnOnOrphanedPaidOrders`** (`ZReportService.php:586-616`) emitting
   `fiscal.z_report.orphan_paid_in_window` log on close.
4. **G4 — No test for fiscal_sequence_no outer-rollback non-consumption**. `next()` called inside a
   transaction that throws should leave next call returning same N — not pinned.
5. **G5 — No test asserting pruners cannot touch audit_logs/z_reports**. Comments assert; no test catches
   future refactor.
6. **G6 — Zero coverage of `fiscal:verify-chain` CLI** (does not exist — W1).
7. **G7 — No Stripe cents round-before-cast regression sentinel**. P0-6 fix lives in code comment but no
   `StripeCentsRegressionTest.php`.

## 10. Recommendations

> All Fiscal services FROZEN per CLAUDE.md §7. Each code-change inside frozen scope needs a `lock-plan`
> doc + owner countersign. New test files + new CLI = outside frozen.

### Outside frozen — implementable
- **R1 — Add `app/Console/Commands/FiscalVerifyChainCommand.php`** wrapping
  `ZReportService::verifyChain($branchId, true)` + `FiscalChainValidator::verifyAuditChainTail($branchId, $window)`.
  Args: `--branch=`, `--window=N`, `--all-branches`, `--strict`. Exit 0 intact, 1 corrupted. JSON output for
  SIEM. NEW FILE.
- **R3 — SIEM dashboard for receipt-reprint chain failure** — pure ops doc, no code (data already emitted).
- **R6 — `docs/FISCAL_CHAIN_VALIDATION.md`** documenting `fiscal.chain_validation_enabled` flag, override,
  re-enable procedure. Docs only.
- **R7 — Sentinel `tests/Feature/Sentinels/FiscalChainBaselineSentinelTest.php`**: fresh DB → write N audit
  rows → assert count + last_hash match snapshot. NEW TEST.
- **R8 — Sentinel `CompositionSnapshotUpdateRejectionSentinelTest.php`**: attempt update; assert unchanged
  (or trigger fires after R5). NEW TEST.
- **R9 — Sentinel `FiscalPrunersCannotTouchFiscalTablesSentinelTest.php`**: run both pruners on DB with
  audit_logs + z_reports rows; assert row counts unchanged. NEW TEST.
- **R10 — Sentinel `StripeCentsRoundBeforeCastSentinelTest.php`**: mock Stripe, assert €0.99 → 99 cents,
  €9.99 → 999. NEW TEST.

### Inside frozen — LOCK plan required
- **R2 — Remove `composition_snapshot` from `OrderItem::$fillable` (line 44) OR add a model
  `static::updating(...)` guard rejecting dirty composition_snapshot on existing rows**. OrderItem.php is
  not listed in CLAUDE.md §7 frozen list explicitly but the change cascades into all 5 bulk-insert sites;
  prefer the `updating` guard (single-line, reversible). LOCK plan path:
  `plans/LOCK_NF525_COMPOSITION_SNAPSHOT_GUARD_<date>.md`.
- **R4 — Add SQLite RAISE(ABORT) trigger for z_reports/cash_movements/cash_drawer_sessions/order_payments
  DELETE** (new migration). Defense-in-depth — LOCAL should run MySQL. LOCK plan:
  `plans/LOCK_NF525_SQLITE_DELETE_TRIGGERS_<date>.md`.
- **R5 — MySQL `BEFORE UPDATE` trigger on `order_items.composition_snapshot`** rejecting
  `OLD IS NOT NULL AND NEW != OLD`. Strongest; blocks any future migration touching column. LOCK plan:
  `plans/LOCK_NF525_COMPOSITION_SNAPSHOT_DB_TRIGGER_<date>.md`. Recommend after R2.

### Priority for V1 ship
- **P1 (pre-ship)**: R1, R7, R8, R9, R10.
- **P2 (V1.0.2)**: R2, R3, R6.
- **P3 (V1.1+)**: R4, R5.

---

GStack Fiscal Auditor — NF525 — Wave 1
