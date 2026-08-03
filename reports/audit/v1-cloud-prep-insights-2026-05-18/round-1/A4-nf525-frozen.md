# A4 — NF525 + Frozen-zone Integrity Audit (V1 Cloud-Prep)

**Audited range**: `d16d4ac48..HEAD (1235e3e1a)` + uncommitted working tree
**Branch**: `v1-0-1-hardening-2026-05-17`
**Methodology**: Read-only, file:line strict, NF525 invariants = highest stake
**Auditor**: RED-team A4 (axis: NF525 + Frozen-zone)
**Date**: 2026-05-18

---

## Verdict Matrix

| # | Invariant | Status |
|---|---|---|
| 1 | Frozen-zone committed diff (13 files) | PASS |
| 2 | Frozen-zone working-tree diff (13 files) | PASS |
| 3 | NF525 chain HMAC stable vs baseline | PASS |
| 3b | DB triggers (audit_logs + z_reports) active | PASS |
| 4 | `composition_snapshot` immutability (parent rows) | PASS |
| 5 | `fiscal_sequence_no` monotonic SSOT (all writes via FiscalSequenceService) | PASS |
| 5b | Simulation flag impact on fiscal sequence allocation | PASS |
| 6 | Cash drawer audit log bindings (Sprint 1D F-8) | PASS |
| 7 | Prune commands NF525-safe (operational tables only) | PASS |
| 7b | CashDrawerService DELETE/cascade risk | PASS |
| 8 | PricingService SSOT preserved (diff=0) | PASS |
| 9 | `simulation_hardware` production sentinel | **FAIL** |
| 10 | `verifyChain` in backup restore script | PASS |

**Global**: 11/12 PASS, 1 FAIL — see Finding F-A4-001 (P0 sentinel gap, deferrable to deploy-doc).

---

## 1. Frozen-zone diff over V1 Cloud-Prep range (committed) — PASS

Command:
```
git diff --stat d16d4ac48..HEAD -- <13 frozen files>
```

Output: **empty**. Zero line modified across all 13 frozen-zone files in the V1 Cloud-Prep commit range (7 commits: 72b078682..1235e3e1a).

Files verified (all 0 lines changed):
- `public/js/pos-wizard.js` / `public/css/pos-wizard.css` / `resources/views/admin-pos-v4.blade.php`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` / `KioskAppComponent.vue` / `KioskUpsellComponent.vue`
- `app/Services/Fiscal/FiscalSequenceService.php` / `ZReportService.php` / `AuditLogService.php`
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `app/Services/Pricing/PricingService.php`
- `app/Domain/Order/OrderStateMachine.php`

## 2. Frozen-zone diff over WORKING TREE — PASS

Command: `git diff -- <13 frozen files>` returns empty.

Working-tree modifications confirmed non-frozen (status -short):
- `app/Http/Controllers/Admin/PosController.php` — non-frozen (Wave 5F simulation_hardware + Sprint 1B cash guard)
- `app/Services/PaymentService.php` — non-frozen
- `app/Services/Payments/SplitPaymentService.php` — non-frozen
- `app/Http/PaymentGateways/Gateways/Stripe.php` — non-frozen
- `app/Http/Controllers/Auth/LoginController.php` — non-frozen (already listed in V1 Cloud-Prep commit diff)

None of the 13 frozen files appear in working-tree mods. Discipline intact.

## 3. NF525 chain HMAC vs baseline — PASS

```
$ php artisan tinker --execute='...audit_logs->count()|substr(orderByDesc(id).current_hash,0,16)'
26|ca4ac1fdc208dae1
```

**Identical to pre-V1.0.1 baseline `26|ca4ac1fdc208dae1`.** No new audit_logs row inserted by V1 Cloud-Prep activity (expected — none of the executed waves write audit events at code-modification time; only runtime POS/cash flows would). HMAC chain integrity preserved.

### 3b. Triggers

```
SHOW TRIGGERS LIKE 'audit_logs'  → 2 triggers (audit_logs_no_update / UPDATE, audit_logs_no_delete / DELETE)
SHOW TRIGGERS LIKE 'z_reports'    → 1 trigger  (z_reports_no_delete / DELETE)
```

All 3 NF525 trigger gates active. No DROP TRIGGER detected in migrations between `d16d4ac48..HEAD`.

## 4. `composition_snapshot` immutability — PASS

Complete write-site census across `app/`:

| File:Line | Operation | Verdict |
|---|---|---|
| `app/Services/Pricing/PricingService.php:291` | INSERT on order creation | OK (SSOT write at creation) |
| `app/Services/OrderService.php:455 / :810 / :1266` | INSERT on order creation | OK (creation paths) |
| `app/Services/FrontendOrderService.php:441` | INSERT on kiosk order creation | OK |
| `app/Services/Order/RefundWithCounterEntryService.php:136` | INSERT on **mirror** row (read-copy from parent) | **OK — parent untouched** |

Refund mirror code path inspected (lines 121-142): iterates `$parent->orderItems`, creates NEW negated `OrderItem` rows on the mirror order, and copies `composition_snapshot` from parent verbatim. Parent rows are NEVER mutated. NF525 evidence preserved.

`app/Services/PaymentService.php` (modified Wave 5F + uncommitted) — grep returns zero `composition_snapshot` references. No risk.

## 5. `fiscal_sequence_no` monotonic discipline — PASS

Write-site census (assignment = `=`, not arrow comparison):

| File:Line | Source of value | Verdict |
|---|---|---|
| `app/Services/OrderService.php:922` | `FiscalSequenceService::class->next($branchId)` | OK (POS path, inside transaction) |
| `app/Services/PaymentService.php:188-189` | `FiscalSequenceService::class->next($locked->branch_id)` | OK (delayed alloc on payment) |
| `app/Services/FrontendOrderService.php:1136` | `$newSeq = FiscalSequenceService::class->next(...)` | OK (kiosk M-08 auto-alloc) |
| `app/Services/Order/RefundWithCounterEntryService.php:115` | `$mirrorSeq` (computed via `FiscalSequenceService::next` earlier in method) | OK |

**Zero writes bypass `FiscalSequenceService::next()`.** Cache::lock + DB FOR UPDATE wrapping intact (verified in FiscalSequenceService — file is frozen, diff=0).

### 5b. simulation_hardware impact on fiscal allocation — PASS

The `POS_SIMULATION_HARDWARE=true` flag introduced in working tree gates ONLY:
- `PosController.php:95-97` — skip cash-drawer-session precondition before order creation (controller defense-in-depth only)
- `PaymentService.php:280-282` — downgrade `recordCashOrderMovement` strict→soft (skips `CashDrawerSessionNotOpenException` throw; still tries to write cash_movement if session exists)
- `SplitPaymentService.php:206` — skip cash-session lookup; `cashSession === null` propagates downstream (CASH tranche OrderPayment + audit log preserved, only `cash_movements` row skipped)

**Critical confirmation**: fiscal sequence allocation (`$order->fiscal_sequence_no = FiscalSequenceService::next(...)`) at `OrderService.php:922` happens **INSIDE the DB::transaction** AFTER controller pre-checks. Simulation flag does NOT touch this code path. Comment at `PosController.php:93-94` confirms intent: "NF525 invariants (sequence, audit chain, composition_snapshot) remain enforced."

Verdict: simulation flag is a cash-drawer-session bypass only. NF525 sequence/chain/snapshot invariants are untouched.

## 6. Cash drawer audit log bindings preserved — PASS

Sprint 1D F-8 binding traceability in `app/Services/Cash/CashDrawerService.php`:

| Event | Line | Trigger |
|---|---|---|
| `cash.session.opened` | :111-117 | `open()` after persisted |
| `cash.session.closed` | :194-205 | `close()` only on real transition |
| `cash.session.reconciled` | :339-345 | `reconcile()` after variance ack |
| `cash.movement.recorded` | :459-466 | `recordMovement()` IN/OUT |

All 4 paths route to private `writeAuditLog()` (:516-526) → `app(AuditLogService::class)->write(...)`. `CashDrawerService.php` was NOT modified in V1 Cloud-Prep range (`git log d16d4ac48..HEAD -- app/Services/Cash/CashDrawerService.php` returns empty). Sprint 1D bindings intact since `83eb52ea5..08854ad34` (pre-V1.0.1).

## 7. 6-year retention discipline — PASS

### 7a. `PruneOutboxCommand.php`

Target table verified: `DB::table('domain_events')` only (lines 67, 84 of command).
- Predicate: `dispatched_at < cutoff` OR `attempts >= 6 AND created_at < cutoff` — both columns are operational outbox semantics, NOT fiscal.
- Header docstring (line 28-29): *"NF525 invariant: domain_events is an OPERATIONAL outbox, NOT a fiscal audit table. audit_logs + z_reports (6y retention) are NEVER touched by this command."*
- Zero references to `audit_logs` or `z_reports` in file.

### 7b. `PruneWebhookEventsCommand.php`

Target table verified: `DB::table('webhook_events')` only (lines 62, 80).
- Safe-status filter: `IN ('processed', 'duplicate')` excludes `pending`+`failed` (DLQ-safe).
- Header docstring (lines 28-30): *"NF525 invariant: webhook_events is an OPERATIONAL ledger, NOT a fiscal audit table. Fiscal payment evidence lives on order_payments + audit_logs (6y retention) — NEVER touched here."*
- Zero references to `audit_logs` or `z_reports` in file.

### 7c. CashDrawerService DELETE/cascade risk

`grep -n "DELETE\|->delete()\|::destroy\|::truncate" app/Services/Cash/CashDrawerService.php` returns **empty**. No DELETE statements on `cash_drawer_sessions` or `cash_movements`. NF525 evidence integrity preserved.

## 8. PricingService SSOT — PASS

```
git diff d16d4ac48..HEAD -- app/Services/Pricing/PricingService.php
→ 0 lines changed
```

PricingService is frozen-zone; diff confirms zero modification across V1 Cloud-Prep. No new caller path discovered that bypasses PricingService (no new `Order::create` outside `OrderService` / `FrontendOrderService` / `PaymentService` review). SSOT pricing path intact.

## 9. **FAIL — Hardware simulation flag NF525 risk** (F-A4-001)

### Finding F-A4-001 — `POS_SIMULATION_HARDWARE` lacks deploy-time sentinel (P0/deferrable)

**Location**: `config/pos.php:37` + `.env`

**Risk model**:
- If `POS_SIMULATION_HARDWARE=true` is left in production `.env` post-launch:
  - `PosController:95-97` skips OPEN-session precondition
  - `PaymentService:280-282` downgrades strict to soft (no exception)
  - `SplitPaymentService:206-207` skips cash-session lookup
- **Consequence**: A cashier sells CASH without an OPEN `CashDrawerSession`. `cash_movements` row is NOT written (no session_id). Z-report aggregation reads `order_payments` (still positive) — variance gap appears at close: declared cash ≠ system cash, since system cash from movements is short by the unrecorded transactions. NF525 audit chain still hashes the audit_log rows that WERE written (open/close/reconcile/movement-when-session-exists), but **operational truth is falsified** vs declared.
- The HMAC chain itself remains valid (no tampering); the falsification is *upstream* of the chain.

**Current mitigations**:
1. `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:112` documents `POS_SIMULATION_HARDWARE=false`.
2. Comment in `config/pos.php:6-8` describes the flag's purpose.
3. Owner-physique checklist (per task description) mentions "flip to false on prod day" — HUMAN DISCIPLINE only.

**Gap**: No automated sentinel enforces `POS_SIMULATION_HARDWARE=false` when `APP_ENV=production`.

- `PreflightProductionCommand.php` (308 lines): grep returns ZERO matches for `simulation_hardware`. The very command designed to gate deployment (signature `app:preflight-production`, exit 1 = block deploy) does NOT check this flag.
- `app/Providers/` boot guards: grep returns ZERO matches for `simulation_hardware`. AppServiceProvider does NOT abort boot in production-with-simulation-on.
- `tests/Feature/Sentinels/`: zero coverage asserting the flag must be false in prod.

**Recommendation** (post-launch, deferrable to V1.0.2):
1. Add to `PreflightProductionCommand::handle()` a CRITICAL check: when `APP_ENV=production`, assert `config('pos.simulation_hardware') === false`. Exit 1 if violated.
2. Add a sentinel test `tests/Feature/Sentinels/SimulationHardwareProductionGuardTest.php` that asserts the preflight command exits 1 with `APP_ENV=production` + `POS_SIMULATION_HARDWARE=true`.
3. (Defense-in-depth) Add boot guard in `AppServiceProvider::boot()`: in production, throw on `simulation_hardware=true`.

**Severity**: P0 in terms of NF525 risk model (operational truth falsification), P1 in terms of practical exposure (owner-physique gate + deploy doc + dedicated env template already document the rule). Deferrable to V1.0.2 IF owner-physique checklist verbalizes the gate at switchover.

## 10. Verifychain in restore script — PASS

`scripts/restore-foodking-from-backup.sh` lines 5, 62-72:

```
$audit = app(\App\Services\Fiscal\AuditLogService::class)->verifyChain(1);
$z     = app(\App\Services\Fiscal\ZReportService::class)->verifyChain(1);
echo "audit_logs.verifyChain: " . ($auditOk ? "OK" : "FAIL@id=$audit") . PHP_EOL;
echo "z_reports.verifyChain:  " . ($zOk    ? "OK" : "FAIL=" . json_encode($z["errors"] ?? [])) . PHP_EOL;
```

Trace verified:
- `AuditLogService::verifyChain` exists at `app/Services/Fiscal/AuditLogService.php:199` (frozen-zone, diff=0)
- `ZReportService::verifyChain` exists at `app/Services/Fiscal/ZReportService.php:463` (frozen-zone, diff=0)

Both methods invoked post-restore. **Caveat**: hard-coded `branch_id=1`. Multi-branch restore would need extension — flagged as V1.0.2 future-work (not P0 since V1 = single restaurant Le Cayenne).

---

## Summary

NF525 + frozen-zone discipline across V1 Cloud-Prep is **clean** with exactly ONE non-blocking finding:

- **Frozen-zone**: zero line touched in 13 protected files (committed + working tree). Verified.
- **NF525 chain**: HMAC stable at baseline `26|ca4ac1fdc208dae1`. Triggers active.
- **Composition snapshot**: writes only at order creation + refund mirror copy. Parent rows immutable.
- **Fiscal sequence**: all 4 write sites route through `FiscalSequenceService::next()`. Simulation flag does NOT bypass allocation.
- **Audit log bindings**: Sprint 1D F-8 (cash open/close/reconcile/movement) preserved — CashDrawerService unmodified.
- **Pruning**: domain_events + webhook_events only. Zero `audit_logs`/`z_reports` reference. 6y retention safe.
- **PricingService SSOT**: diff=0.
- **Verifychain**: present and wired in restore script.

**Single FAIL**: F-A4-001 — `POS_SIMULATION_HARDWARE` flag has no automated sentinel asserting `false` in production. Currently relies on human discipline at deploy + documented `.env` template. Recommendation: add preflight check + sentinel test, deferrable to V1.0.2.

**Merge gate (A4 axis)**: **CONDITIONAL GO** — owner-physique must verbally confirm `POS_SIMULATION_HARDWARE=false` at prod switchover. Hardening (sentinel) deferred to V1.0.2.
