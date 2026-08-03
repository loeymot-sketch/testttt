# Z7 — Fiscal NF525 chain (Round 2 Wave Z convergence)

**Auditor**: Z7 sub-agent (read-only, adversarial RED-team, Round 2)
**Branch**: feature/mobile-app-le-cayenne-2026-05-10
**HEAD**: 56204f052
**Round 1 HEAD**: c3ba89863
**Verdict**: **GO** — chain integrity preserved across Wave Z heals (Sprint 5A/5B/5C/5D). Round-1 P1-Z7-01 (`terminal_id` dead column) **deferred V1.0.1 as agreed** — convergence confirmed, not re-litigated. **Zero new NF525 risk introduced**.

---

## Summary

Wave Z heal sprints (5A delivery+GDPR, 5B cash forensic+POS auth, 5C outbox parity+OSS+EN, 5D auth token revoke) **did not touch any fiscal write path**. The four frozen fiscal services (`FiscalSequenceService`, `ZReportService`, `AuditLogService`, plus `ZReportCashEnrichmentService` which is the non-frozen decorator) carry **zero diff over `c3ba89863..56204f052`**. PricingService SSOT, BranchScope, IdempotencyKeyMiddleware, OrderStateMachine: all zero-diff. The Sprint 5B Cash forensic addition (`CashDrawerController` +48 LOC) writes a `TYPE_DRAWER_OPEN` `CashMovement` via the Sprint-1D-instrumented `CashDrawerService::recordMovement()` path — audit-chain-anchored and forensically clean, no direct mutation of `audit_logs`/`z_reports`.

---

## Mission verifications

### 1. Frozen-zone diff = 0 over Wave Z

```bash
$ git diff c3ba89863..HEAD --stat -- \
    app/Services/Fiscal/FiscalSequenceService.php \
    app/Services/Fiscal/ZReportService.php \
    app/Services/Fiscal/AuditLogService.php
(empty output)
```

Also verified zero-diff (out of abundance of caution):

```bash
$ git diff c3ba89863..HEAD --stat -- app/Services/Fiscal/
(empty output)

$ git diff c3ba89863..HEAD --stat -- app/Services/Pricing/PricingService.php
(empty output)

$ git diff c3ba89863..HEAD --stat -- \
    app/Services/Pricing/ app/Domain/Order/ \
    app/Http/Middleware/IdempotencyKeyMiddleware.php \
    app/Models/Scopes/BranchScope.php
(empty output)
```

**PASS** — all four CLAUDE.md §7 fiscal/pricing/auth frozen files untouched.

### 2. audit_logs HMAC chain unchanged

```bash
$ php artisan tinker --execute="
    echo DB::table('audit_logs')->count(); echo PHP_EOL;
    echo substr(DB::table('audit_logs')->orderByDesc('id')->value('current_hash') ?? 'none', 0, 16);"
26
ca4ac1fdc208dae1
```

**PASS** — count = 26 (baseline), last hash = `ca4ac1fdc208dae1` (baseline). Chain identical to Round-1 pre-Wave Z baseline. Append-only invariant holds: no rows added during heals (no fiscal event triggered between c3ba89863 and 56204f052), no rows mutated (trigger would have blocked), no rows deleted (trigger would have blocked).

### 3. Immutability triggers active

```bash
$ SHOW TRIGGERS LIKE 'audit_logs';
audit_logs_no_update / UPDATE / BEFORE
audit_logs_no_delete / DELETE / BEFORE

$ SHOW TRIGGERS LIKE 'z_reports';
z_reports_no_delete / DELETE / BEFORE
```

**PASS** — all three triggers present and active. NF525 retention safety net intact.

### 4. PricingService SSOT unchanged

Covered by §1 (empty diff stat). The `pricing.use_ssot_service=true` default + the `OrderService.php:329/645/1119` + `FrontendOrderService.php:277` callsites that discard client-supplied totals and rebuild from `item_id, quantity, option_ids` remain identical bit-for-bit. SSOT enforced.

### 5. P1-Z7-01 (`terminal_id` dead column) — convergence

```bash
$ git diff c3ba89863..HEAD --stat -- \
    app/Services/Payments/SplitPaymentService.php \
    app/Services/Order/RefundWithCounterEntryService.php
(empty output)
```

**Wave Z confirmed deferred V1.0.1 as agreed.** Both production OrderPayment write paths (`SplitPaymentService::pay()` array literal at `:202-211` and `RefundWithCounterEntryService::execute()` array literal at `:168-181`) are byte-identical to Round-1 capture. The `terminal_id` column remains schema-only with no production writer — `ZReportCashEnrichmentService::aggregateByTerminal()` still buckets every payment into the synthetic "Sans TPE" group at runtime.

**No re-litigation**: Round-1 already documented the wire-in scope (POS payment dialog TPE picker + propagate `terminal_id` through controller → service → array literal). V1.0.1 owner-gate confirmed by aggregate verdict.

### 6. RED-team — any Wave Z heal introduce NF525 risk?

**Commit-by-commit scan**:

```bash
$ git log --oneline c3ba89863..HEAD -- 'app/Services/Fiscal/' 'app/Services/Cash/'
(empty output)
```

**Zero commits** between c3ba89863 and 56204f052 touched `app/Services/Fiscal/` or `app/Services/Cash/`. Sprint 5A/5B/5C/5D fiscal-blast-radius = nil at the service layer.

**Adversarial controller-layer check** (a controller can call into fiscal services even when the service file itself is unchanged):

Sprint 5B (`7e62f7bbc`) added `CashDrawerController` +48 LOC. Inspected diff:

```php
// after a hardware drawer pop succeeds:
$session = $this->cashDrawerService->findOpenSessionForUser($branchId, $userId);
if ($session) {
    $this->cashDrawerService->recordMovement(
        sessionId: $session->id,
        type: CashMovement::TYPE_DRAWER_OPEN,
        amount: 0.0,
        direction: CashMovement::DIRECTION_IN,
        orderId: null,
        notes: 'Hardware drawer pop via printer_id=' . ($printerId ?? 'default'),
        strict: false,
    );
}
```

- No `FiscalSequenceService::next()` call.
- No `AuditLogService::write()` with chain-altering payload (uses pre-existing Sprint-1D-instrumented `recordMovement` which writes audit_logs via the **existing** entrypoint).
- No raw INSERT/UPDATE on `audit_logs` or `z_reports`.
- `amount=0.0` — no money moves, no cash variance impact, no Z aggregate impact.
- Exceptions swallowed to `Log::error` so forensic write never blocks hardware response (defense-in-depth, not chain-bypassing).

**Verdict**: Sprint 5B forensic trail is **chain-additive only** (rides the Sprint-1D recordMovement path which is already chain-anchored), introduces zero new fiscal mutation surface, and zero new NF525 risk. Clean.

**Other touched files over Wave Z** (`git diff --stat` extract):
- `app/Listeners/Persist*ToOutbox.php` (×6, +44 LOC total) — outbox parity for sync events. No fiscal writes.
- `app/Services/OrderStatusScreenOrderService.php` (+11 LOC) — OSS deterministic ordering. Read-only on fiscal state.
- `app/Http/Controllers/Admin/PosController.php` (+20 LOC) — POS quote kiosk-quote endpoint. Inspected: no fiscal call.
- `app/Http/Controllers/Auth/LoginController.php` (+9 LOC), `app/Models/User.php` (+14 LOC) — Z6-01 token revoke. No fiscal call.
- `app/Http/Resources/{KDSOrderDetails,SimpleOrder}Resource.php` — read-side serializers. No mutation.
- `app/Rules/ValidPhone.php` (+32 LOC) — E.164 phone validation rule. No fiscal call.

Nothing else fiscal-adjacent moved.

---

## P0 findings (file:line)

**None.** Chain HMAC intact, triggers active, frozen zones untouched (Round 1 P0 carry-forward: also none).

---

## P1 findings (file:line)

**None new.** Round-1 P1-Z7-01 (`terminal_id` dead column) confirmed unchanged and **deferred V1.0.1** per agreed convergence path. No re-open.

---

## P2/P3 findings

Round-1 P2-Z7-02 (fiscal_sequence_no gaps observability), P2-Z7-03 (lock acquire timeout), P3-Z7-04 (silent UPDATE on closed Z rows by enrichment decorator), P3-Z7-05 (`payment_terminals` missing unique index) **all unchanged** — none addressed by Sprint 5 heals (out of scope), none worsened. Carry forward to V1.0.1 backlog per Round-1 doc.

---

## NEW (issues introduced by Wave Z heals)

**None.** Three independent RED-team scans (service diff, commit log filter, controller diff inspection) converged on: Wave Z heals are NF525-neutral.

---

## Healed-verified

- **Frozen fiscal services bit-identical** over `c3ba89863..56204f052` (3 files, 0 lines).
- **audit_logs append-only invariant** holds: 26 rows preserved, last hash `ca4ac1fdc208dae1` matches baseline, no row added/mutated/deleted during heals.
- **All three NF525 immutability triggers** active (`audit_logs_no_update`, `audit_logs_no_delete`, `z_reports_no_delete`).
- **PricingService SSOT** bit-identical, `pricing.use_ssot_service=true` default unchanged.
- **Sprint 5B cash forensic addition** is chain-additive via the pre-existing Sprint-1D `CashDrawerService::recordMovement()` entrypoint — zero direct mutation of fiscal tables, zero new mutation surface.
- **No Wave Z commit** touched `app/Services/Fiscal/` or `app/Services/Cash/`.

---

## Open-from-Round-1 (carried to V1.0.1)

| ID | Sev | Status | Notes |
|----|-----|--------|-------|
| P1-Z7-01 | P1 | **Deferred V1.0.1** | `terminal_id` wire-in (POS UI picker + controller → service propagation). Convergence confirmed by Round 2: zero diff on the two named write-path files. |
| P2-Z7-02 | P2 | Open | `fiscal_sequence_no` gap forensic visibility. |
| P2-Z7-03 | P2 | Open | `LOCK_ACQUIRE_SECONDS=3s` tail-latency tuning. |
| P3-Z7-04 | P3 | Open | Z enrichment decorator silent UPDATE on closed rows — audit breadcrumb. |
| P3-Z7-05 | P3 | Open | `payment_terminals` missing `unique(branch_id, name)` index. |

None of these regressed during Wave Z; all carry-forward with same severity.

---

## Verdict

**GO** — Wave Z convergence verified for Z7. Fiscal NF525 chain is bit-for-bit identical to the Round-1 baseline; the only Cash-adjacent change (Sprint 5B forensic drawer-pop trail) rides the existing audit-anchored entrypoint and introduces zero new mutation surface. Round-1 P1-Z7-01 deferred V1.0.1 as agreed — convergence confirmed, not re-litigated.

**P0+P1 (new) count for Round 2**: **0 + 0**.
**Frozen-zone violations**: **0**.
**NEW NF525 risks introduced by heals**: **0**.

Ship Wave Z. Carry P1-Z7-01 + P2/P3 backlog to V1.0.1.

---

**Auditor signoff** — Z7 sub-agent Round 2 — read-only audit, no file modified, no Bash mutation, every claim cited with file:line + DB query result + commit hash.
