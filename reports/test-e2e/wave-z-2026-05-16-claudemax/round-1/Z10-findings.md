# Z10 — Cash drawer UI + TPE rates (Round 1 Wave Z findings)

**Auditor**: Z10 sub-agent (read-only, adversarial RED-team)
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD**: `c3ba89863`
**Verdict**: **GO-CONDITIONAL** — sister-verdict P0s F-1 / F-2 / F-3 / F-6 fully healed; **F-4 / F-5 / F-8 / F-9** also healed (commit `9024a1050` despite its misleading "i18n only" message). **F-7 / F-10 / F-11 / F-12** remain partially or fully open. Three NEW issues introduced by heals (EN i18n parity, A11y on dialog, UI/backend threshold mismatch).

---

## Summary

Sprint 1A / 1B / 1C / 1D delivered **far more** than their commit messages claim:

| Sprint | Commit | Real scope |
|--------|--------|------------|
| 1A | `76d641135` | Vue dialog `PosCashDrawerSessionDialog.vue` + Vuex `cashDrawer` module + axios service + button in `PosComponent.vue` + i18n FR |
| 1A-fu | `9024a1050` | **NOT just i18n** — adds `config/cash.php`, `CashVarianceRequiresApprovalException`, full F-4 variance gate, F-5 SQLite DELETE trigger, F-8 AuditLogService binding in `CashDrawerService`, 3 new test classes (`CashAuditLogChainTest`, `CashMovementsDeleteForbiddenTest`, `CashVarianceGateTest`), Spatie permission `cash.reconcile.variance.override` |
| 1B | `2e3635d64` | `assertCashDrawerSessionOpenIfCashInvolved()` in `PosController` + `recordCashOrderMovement` promoted public with `$strict`/`$amountOverride` + `SplitPaymentService` injects `CashDrawerService` and writes one movement per CASH tranche + `CashDrawerSessionNotOpenException` + lang FR/EN `cash_no_open_session_blocks_sale` |
| 1B-fu | `d4efc1f29` | Update 6 legacy test setUp() to seed an open session (POS CASH guard fail-fast caused regression) |
| 1C | `f36aa544e` | `payment_terminals` table + FK + `terminal_id` on `order_payments` (nullOnDelete) + `PaymentTerminal` model + admin CRUD controller + `ZReportCashEnrichmentService::aggregateByTerminal()` + `by_terminal`/`fees_total`/`net_after_fees` in `enrich()` + 16 tests |
| 1D | `852905a09` | Neutralize variance gate in legacy `CashDrawerEndpointsTest` |

Frozen-zone diff confirmed zero on `public/js/pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php`, `Kiosk*.vue`, `FiscalSequenceService.php`, `ZReportService.php`, `AuditLogService.php`, `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php`, `OrderStateMachine.php`. **Frozen-zone discipline respected.**

---

## P0 findings (file:line)

### P0-Z10-01 — F-7 hardware drawer pop never writes `cash_movements TYPE_DRAWER_OPEN`
**File**: `app/Http/Controllers/Admin/Pos/CashDrawerController.php:19-33`
```php
public function open(Request $request): JsonResponse
{
    ...
    $result = $this->printerService->openDrawer($printerId, $branchId);
    $status = ($result['success'] ?? false) ? 200 : 422;
    return response()->json($result, $status);
}
```
No call to `CashDrawerService::recordMovement(type=TYPE_DRAWER_OPEN, …)`. Sister-verdict F-7 explicitly required: « Hardware drawer pop n'écrit aucun `cash_movements TYPE_DRAWER_OPEN` ». Constant `CashMovement::TYPE_DRAWER_OPEN` exists and is whitelisted in `CashDrawerService::recordMovement` (`app/Services/Cash/CashDrawerService.php:338`) but no producer wires the controller to it. NF525 audit chain therefore lacks evidence of every physical drawer opening (no-sale events) — owner cannot reconcile manual drawer-kick fraud against fiscal reports.
**Severity P0** because NF525 requires every cash-handling event to be traceable; a silent hardware-only opening defeats the audit purpose. Severity could be downgraded to P1 if owner declares "no-sale event audit is optional in V1", but the current state matches the sister-verdict P1 wording verbatim and remains open.
**Fix scope**: insert one `app(CashDrawerService::class)->recordMovement(sessionId: $session->id, type: CashMovement::TYPE_DRAWER_OPEN, amount: 0, direction: CashMovement::DIRECTION_IN, orderId: null, notes: 'hardware drawer pop', strict: false)` after the printer call, fetching the OPEN session via `findOpenSessionForUser($branchId, Auth::id())`. ≤15 LOC, scope-minimal.

---

## P1 findings (file:line)

### P1-Z10-02 — F-10 `closed_by_user_id` / `reconciled_by_user_id` columns never added
**File**: `database/migrations/2026_05_08_140000_create_cash_drawer_sessions_table.php:30-53`
```php
$table->unsignedBigInteger('opened_by_user_id');  // line 34
// No closed_by_user_id, no reconciled_by_user_id
```
Sister-verdict F-10 listed both as required. Migration schema only carries `opened_by_user_id`. `CashDrawerService::closeSession` (`app/Services/Cash/CashDrawerService.php:126-170`) and `reconcileSession` (`:190-315`) both have access to `Auth::user()` / passed-in `$actor` but never persist who closed or reconciled. Audit log captures the actor via `writeAuditLog(..., $actor?->id)` (`:307`), giving partial traceability through the HMAC chain, but the **direct relational column** allowing dashboards / Z-drilldowns to display "session reconciled by Alice on 2026-05-16 18:42" is missing. This degrades operational accountability for V1 (no UI surface, no Z-report join).
**Fix**: new migration `add_closed_reconciled_by_to_cash_drawer_sessions` adding two nullable FK columns + service writes them at the respective save points. ≤25 LOC migration + 4 LOC service.

### P1-Z10-03 — F-11 partial: only variance branch is gated; routine close has no manager-gate
**File**: `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:26-32`
```php
public function __construct(private readonly CashDrawerService $service)
{
    parent::__construct();
    $this->middleware(['permission:pos']);
}
```
The constructor applies `permission:pos` to **every** route on the controller — including `close($session)` and `reconcile($session)`. A POS Operator can therefore close + reconcile their own session whenever |variance| ≤ 2€. The variance permission `cash.reconcile.variance.override` (seeded in `database/seeders/PermissionTableSeeder.php:651` and assigned to Branch Manager only at `RolePermissionTableSeeder.php:78`) gates **only** the over-threshold reason path, not the routine close itself. Sister-verdict F-11 said: « POS Operator peut clôturer sa propre session sans manager-gate ». Within-threshold close remains unguarded.
**Severity P1** — partial mitigation lowers from P0: a cashier cooking the books with |variance|>2€ now hits the gate. But systematic <2€ skim per close is still possible without manager review.
**Fix**: split route middleware — `open/current/movements` keep `permission:pos`; `close/reconcile` move to `permission:cash.session.close` (new) granted to Branch Manager + Admin only. Owner-gate required.

### P1-Z10-04 — F-12 frozen Vanilla JS `pos-wizard.js` has no proactive cash-session block
**File**: `public/js/pos-wizard.js` (frozen — diff-check only)
```bash
$ grep -n "cash_no_open_session\|CASH_NO_OPEN_SESSION\|cash_drawer_session\|cash drawer" public/js/pos-wizard.js
# (0 matches)
```
The Vanilla JS wizard is frozen-zone (CLAUDE.md §7) so it remains untouched. Backend guard fires correctly: `PosController::store` → `assertCashDrawerSessionOpenIfCashInvolved` → 422 with `code='CASH_NO_OPEN_SESSION'` + i18n message (`app/Http/Controllers/Admin/PosController.php:50-67, 82-128`). The Vue wrapper `PosComponent.vue` reads `cashDrawer/session` and surfaces the "Caisse" CTA, but the wizard popup itself does not pre-emptively disable the CASH tile when no session is open. Cashier sees: open wizard → pick CASH → click checkout → 422 toast. Friction acceptable in V1 (owner has the parent "Caisse" button visible permanently) but UX is reactive not proactive. Frozen-zone protects this file → no fix without owner LOCK.
**Severity P1** (UX friction only — backend gate is hard, no fiscal leak).
**Recommendation**: surface the OPEN-session status in `admin-pos-v4.blade.php` header banner (also frozen — LOCK needed) or accept as V1 limitation and document in `docs/POS_OPERATOR_RUNBOOK.md`.

### P1-Z10-05 — i18n EN parity: 22 `cash_session_*` keys present FR-only
**Files**:
- `lang/fr/all.php:129-150` — 22 keys under `label.cash_session_*`
- `lang/en/all.php` — **0 matches** (verified `grep -c "cash_session" lang/en/all.php` → 0)
- `resources/js/languages/fr.json:407-` — 21 keys
- `resources/js/languages/en.json` — **0 matches**

Backend `cash_no_open_session_blocks_sale` IS translated FR+EN (`lang/en/all.php:134`, `lang/fr/all.php:154`) but the entire `cash_session_*` family used by `PosCashDrawerSessionDialog.vue` (header, buttons, intro, variance, reason, empty state, stats) is FR-only. EN locale falls back to raw `label.cash_session_open` literals — visual mandate breach (CLAUDE.md §6 ¶5 "pas de raw label `Label.X`").
**Severity P1** (raw-label leak in EN locale is a customer-facing defect that ships).
**Fix**: copy all 22 keys to `lang/en/all.php` + 21 to `en.json` with English translations. Scope-minimal ≤44 LOC.

---

## P2 findings

### P2-Z10-06 — A11y: dialog has no Escape-key close, no focus trap, no autofocus on open
**File**: `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue`
- Line 10 — only `@click.self="onBackdrop"` closes the modal; no `@keydown.esc`/keyboard handler on the root `<div role="dialog">`.
- Line 83 — `ref="openingInput"` is declared but never `.focus()`-ed in `mounted()` or `resolveMode()` (`:444-454`). Keyboard users land on the document body, must Tab through 4+ chips before reaching the amount input.
- No focus trap — Tab can escape the modal back to the underlying POS surface, breaking the modal contract.

WCAG 2.1 SC 2.1.1 (Keyboard) + SC 2.4.3 (Focus Order). For a cashier station the lack of Escape close is mild (mouse-driven) but for an A11y audit this is a blocker.
**Fix**: add `@keydown.esc.prevent="emitClose"` on root `<div>`, `this.$nextTick(() => this.$refs.openingInput?.focus())` in `resolveMode()` when `mode==='open'`, plus a roving-tabindex focus trap (≤30 LOC). Reuse pattern from `KsA11ySettings.vue` if present.

### P2-Z10-07 — UI/backend variance threshold mismatch
**File**: `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue:389-396`
```js
varianceRequiresReason() {
    return this.mode === 'close' && Math.abs(this.liveVariance) > 0.005;
},
varianceReasonMissing() {
    if (!this.varianceRequiresReason) return false;
    const trimmed = (this.varianceReason || '').trim();
    return trimmed.length < 3;
},
```
UI demands `variance_reason` for **any** non-zero variance (>0.5 cents). Backend gate only fires above `config('cash.variance_threshold_eur', 2.00)` (`app/Services/Cash/CashDrawerService.php:231`). Result: cashier with a 0.5€ variance must type a 3-char reason in the UI even though backend would happily accept an empty `variance_reason`. Inconsistency is harmless (more strict in UI than in backend) but confuses the cashier and contradicts the configurable threshold.
**Fix**: read the threshold from a frontend config endpoint (or hardcode 2.00 to match the default) and compare `Math.abs(this.liveVariance) > threshold` instead of `> 0.005`. Also align `trimmed.length < 3` with backend's `mb_strlen($trimmedReason) > $maxReasonLength` check.

### P2-Z10-08 — `recordMovement` lacks `lockForUpdate` on session row
**File**: `app/Services/Cash/CashDrawerService.php:370-388`
```php
$session = CashDrawerSession::query()->find($sessionId);   // line 370 — no lockForUpdate
if (! $session) { ... }
if ($session->status !== CashDrawerSession::STATUS_OPEN) { ... }
$movement = CashMovement::create([...]);
```
Sister-verdict F-9 was about "recordMovement race without lockForUpdate". Sprint 1D added `lockForUpdate` to `openSession` (`:84`), `closeSession` (`:136`), `reconcileSession` (`:202`) — but **not** to `recordMovement` itself. Race window: a `close` transaction can transition status OPEN→CLOSED between the `find()` on line 370 and the `create()` on line 389, allowing a movement to be created on a just-closed session. Likelihood is low (close requires explicit cashier action) and immutability triggers prevent corruption after the fact, but the invariant is theoretically violable.
**Severity P2** (theoretical race, low likelihood, no fiscal leak because the parent operation transactions wrap the call).
**Fix**: change line 370 to `CashDrawerSession::query()->whereKey($sessionId)->lockForUpdate()->first()` and wrap the `create()` in a `DB::transaction()` block. ≤8 LOC.

### P2-Z10-09 — `payment_terminals` FK `branch_id` cascadeOnDelete vs Z-report join lifetime
**File**: `database/migrations/2026_05_16_120000_create_payment_terminals_table.php:57-60`
```php
$table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
```
If a branch is hard-deleted (admin error, test teardown leak), all `payment_terminals` are deleted; the next `terminal_id` FK on `order_payments` is `nullOnDelete()` (`2026_05_16_120001_*.php:38-41`), so existing order_payments lose their terminal linkage. Combined with the `STATUS_ARCHIVED=5` soft-delete convention (which preserves the row), the existence of `cascadeOnDelete` on `branches` is a contradictory escape hatch — Z-reports drilling into past fees would show "Sans TPE" rows for previously well-attributed payments. Branches **should not** be hard-deletable in NF525 territory anyway, but the migration encodes a destructive path inconsistent with the rest of the audit-chain ethos.
**Severity P2** (defensive coding gap, not an active fault).
**Fix**: change `cascadeOnDelete()` to `restrictOnDelete()` on the FK so branch hard-delete fails fast when terminals exist. ≤3 LOC.

---

## P3 findings

### P3-Z10-10 — Commit message vs commit content drift on `9024a1050`
**Commit**: `9024a1050596bd85dfef86818d597eff516564a2`
**Title**: `i18n(pos-cash): add cash_session_* PHP keys missed by Sprint 1A commit`
**Reality**: +1096 LOC across 16 files including `CashDrawerService.php` (+234), `CashVarianceRequiresApprovalException.php` (+77), `config/cash.php` (+65), `CashAuditLogChainTest.php` (+156), `CashMovementsDeleteForbiddenTest.php` (+130), `CashVarianceGateTest.php` (+228), the SQLite DELETE trigger migration (+85).
The commit body's "linter race avec les sprints parallèles avait stripé ces clés" is misleading; the commit is in fact the **entire Sprint 1D / F-4 / F-5 / F-8 deliverable**. Auditability concern: future bisect / git-log readers will skip this commit thinking it's an i18n fix-up.
**Severity P3** (process / transparency only, no code defect).
**Fix**: cannot rewrite history on a shared branch. Document the discrepancy in `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md` and Graphiti note.

---

## Healed-verified (sister-verdict closed by these heals)

| Sister ID | Heal commits | Verification |
|-----------|--------------|--------------|
| **F-1** (UI fond de caisse absent) | `76d641135`, `9024a1050` | `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` exists (917 LOC, 4 modes); wired into `resources/js/components/admin/pos/PosComponent.vue:835, :1060, :1088`; Vuex `cashDrawer` module registered (`resources/js/store/index.js:91, :218`); 9 Vitest tests in `tests/js/PosCashDrawerSessionDialog.spec.js`; default `openingAmount: 50` (`PosCashDrawerSessionDialog.vue:356`) ✅ |
| **F-2** (TPE rates feature missing) | `f36aa544e` | Migration `2026_05_16_120000_create_payment_terminals_table.php` creates the table with branch_id/name/gateway_type/fee_percent(5,3)/fee_fixed(8,2)/serial_number/status; FK `branch_id`→`branches` cascadeOnDelete (caveat P2-Z10-09); model `app/Models/PaymentTerminal.php` with BranchScope + scopeActive; admin CRUD controller `app/Http/Controllers/Admin/PaymentTerminalController.php` gated by `permission:settings`; companion migration `2026_05_16_120001_add_terminal_id_to_order_payments_table.php` adds nullable `terminal_id` with `nullOnDelete()` FK ✅ |
| **F-3** (Cash sans session = invisible) | `2e3635d64`, `d4efc1f29` | `app/Services/PaymentService.php:261-329` `recordCashOrderMovement` now throws `CashDrawerSessionNotOpenException` when `$strict=true`; called with strict=true from `OrderService::posOrderStore` (inside the parent DB transaction); double-defense in `app/Http/Controllers/Admin/PosController.php:50-128` `assertCashDrawerSessionOpenIfCashInvolved`; `SplitPaymentService` per-tranche guard (commit message); 6 tests in `tests/Feature/Pos/PosCashTrailTest.php` cover happy path, 422 rollback, split tranches, kiosk legacy ✅ |
| **F-4** (variance gate absent) | `9024a1050` | `app/Services/Cash/CashDrawerService.php:230-277` enforces `|variance| > threshold` → `CashVarianceRequiresApprovalException`; `variance_reason` required + actor must hold `cash.reconcile.variance.override`; defaults: threshold 2.00€, approval_required=true (`config/cash.php:31, :43`); permission seeded + assigned to Branch Manager (`PermissionTableSeeder.php:651`, `RolePermissionTableSeeder.php:78`); 9 test cases in `tests/Feature/Cash/CashVarianceGateTest.php` ✅ |
| **F-5** (DELETE cascade breaks NF525 retention) | `9024a1050` + pre-existing `2026_05_10_010000` | `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:65-141` already drops the cascade FK + recreates as `restrictOnDelete()` + installs MySQL BEFORE-DELETE triggers `cash_movements_no_delete`, `cash_drawer_sessions_no_delete`, `order_payments_no_delete` on prod. Sprint 1D commit `9024a1050` adds the SQLite parity migration `2026_05_16_130000_add_cash_movements_delete_trigger_sqlite.php` so PHPUnit can verify the invariant. 3 tests in `tests/Feature/Cash/CashMovementsDeleteForbiddenTest.php`. MySQL prod is unchanged but already protected. ✅ |
| **F-6** (Z report no TPE breakdown / net-after-fees) | `f36aa544e` | `app/Services/Fiscal/ZReportCashEnrichmentService.php:106-121` `enrich()` merges `by_terminal[]`, `fees_total`, `net_after_fees`; `aggregateByTerminal()` (:151-222) groups order_payments by terminal_id, computes `fees_total = (cash+card) * fee_percent / 100 + count * fee_fixed`, legacy null bucket "Sans TPE" with 0 fees; 6 tests in `tests/Feature/Fiscal/ZReportTerminalBreakdownTest.php` cover formula, branch isolation, window filter, read-only (no z_reports mutation) ✅ |
| **F-8** (CashDrawerService no audit_logs) | `9024a1050` | `app/Services/Cash/CashDrawerService.php:111-117, :159-167, :297-308, :399-407, :456-488` — `writeAuditLog()` posts `cash.session.opened`, `cash.session.closed` (only on transition), `cash.session.reconciled` (with variance payload + over_threshold flag), `cash.movement.recorded` to `audit_logs` via `AuditLogService::write()` (the frozen HMAC writer is unchanged); 5 tests in `tests/Feature/Cash/CashAuditLogChainTest.php`. Best-effort error policy: log warning + continue if chain unavailable (DB triggers remain SSOT) ✅ |
| **F-9** (recordMovement race) | `9024a1050` (parent ops only) | `lockForUpdate()` confirmed at `app/Services/Cash/CashDrawerService.php:84` (openSession), `:136` (closeSession), `:202` (reconcileSession). **NOT** added to `recordMovement` itself → see P2-Z10-08 above. Heal is 80% complete. |

---

## Open-from-sister (NOT fully healed)

- **F-7** — P0-Z10-01 (hardware drawer pop never writes TYPE_DRAWER_OPEN).
- **F-9** — partial via P2-Z10-08 (recordMovement still lacks lockForUpdate on session row).
- **F-10** — P1-Z10-02 (closed_by_user_id / reconciled_by_user_id columns missing).
- **F-11** — P1-Z10-03 (only variance branch gated; routine close has no manager-gate).
- **F-12** — P1-Z10-04 (frozen wizard cannot proactively disable CASH tile; backend gate hard but UX reactive).

---

## NEW (introduced by heals — not in sister verdict)

- **P1-Z10-05** — EN i18n parity broken: 22 `cash_session_*` keys present FR-only in both `lang/en/all.php` and `resources/js/languages/en.json`. Cashier on EN locale sees raw `label.cash_session_open` literals → visual-mandate breach.
- **P2-Z10-06** — `PosCashDrawerSessionDialog.vue` has no Escape key handler, no focus trap, no autofocus on the opening-amount input. WCAG 2.1 SC 2.1.1 + 2.4.3 violations on the cashier's most-used dialog.
- **P2-Z10-07** — UI demands variance reason for >0.005€ while backend threshold is 2.00€. Stricter-than-backend client check confuses cashiers and contradicts the configurable threshold.
- **P2-Z10-09** — `payment_terminals.branch_id` FK uses `cascadeOnDelete()`, contradicting the NF525 / soft-delete (STATUS_ARCHIVED=5) intent.
- **P3-Z10-10** — Commit `9024a1050` message describes "i18n keys" but actually delivers 1096 LOC of F-4/F-5/F-8 work. Bisect/audit transparency concern.

---

## Convergence verdict for Z10

**P0**: 1 (F-7 / P0-Z10-01).
**P1**: 4 (P1-Z10-02 F-10, P1-Z10-03 F-11, P1-Z10-04 F-12, P1-Z10-05 EN i18n).
**P2**: 4. **P3**: 1.

Z10 does **NOT** converge in Round 1. Round 2 requires healing P0-Z10-01 + the 4 P1s before two consecutive rounds with P0+P1=0 can be claimed. Healing scope is small (≤80 LOC total), inline-eligible per CLAUDE.md §10. F-12 needs owner-LOCK on `admin-pos-v4.blade.php` if a banner is preferred; otherwise document V1 limitation in operator runbook.

---

**Audit completed read-only — no Edit/Write/Bash mutation performed. All assertions cited file:line.**
