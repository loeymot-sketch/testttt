# Adversarial RED — POS — Wave 1

> **Mission**: hostile probe of the POS Caisse system on `v1-0-1-hardening-2026-05-17`.
> **HEAD verified**: branch tip is `068461ffc` (plan documents `6908edbde`; both contain the surfaces audited).
> **Stance**: codebase guilty until proven innocent. file:line citations strict.
> **Reference**: `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md` §2 Zone 2.
> **Scope discipline**: LOCAL only. No cloud/Phase D findings. Zero frozen-zone edits proposed.

---

## 1. Findings

### POS-RED-01 — `Order::fillable` lists `branch_id` (latent mass-assignment) — **P1** — security / hidden

**Evidence**
`app/Models/Order.php:26` includes `branch_id` in `$fillable`. Empirical exploit search:

```bash
grep -rn "Order::create\|->update(\$request->all\|->fill(\$request" app/Http app/Services
```

Returned 3 hit sites:
- `app/Services/OrderService.php:313` — uses curated `$validated` array, not raw `$request->all()`.
- `app/Services/OrderService.php:626` — uses `$validated = $request->validated()` then `unset($validated['total'],...)` (line 607). `branch_id` is checked against `$authUser->branch_id` at lines 616-624 — a **server-side guard** rejects cross-branch creation with 403.
- `app/Services/Order/RefundWithCounterEntryService.php:95` — hardcoded `branch_id => (int) $parent->branch_id`.

**Verdict**: no live exploit path. **Latent risk**: a future contributor calling `Order::create($request->all())` or `$order->fill($request->all())` would silently honor wire-supplied `branch_id`.

**Owner mitigation (scope-minimal)**: add `Order::saving` observer in `boot()` (`Order.php:89`) — `if ($this->isDirty('branch_id') && ! $authIsGlobalAdmin) abort(403)`. ~10 LOC, single test.

---

### POS-RED-02 — Z-report close vs in-flight order creation race — **P1** — direct attack / hidden

**Evidence**
`ZReportService::close` acquires `Cache::lock("zreport_close_b{branch}")` at line 195 and locks the `ZReport` row at `lockForUpdate()` line 207 — *but never blocks new order INSERTs*. The aggregate window is `(opened_at, closedAt]` (line 231).

Meanwhile, `OrderService::posOrderStore` allocates `fiscal_sequence_no` (`app/Services/Fiscal/FiscalSequenceService.php` via `next($branchId)`) inside its own `DB::transaction` (e.g. `RefundWithCounterEntryService.php:90`) **without checking** whether a `ZReport::STATUS_CLOSING` row exists.

**Race window**
1. T=0.000 — Cashier A clicks "Close Z" → ZReportService enters lock, status flips to `CLOSING` (referenced at line 155).
2. T=0.001 — Cashier B's POST `/api/admin/pos/order` enters its own DB tx, calls `FiscalSequenceService::next()`. The sequence increments and returns N+1.
3. T=0.050 — ZReportService closes the Z. Order from B has `created_at` ≤ `closedAt` and `fiscal_sequence_no = N+1`, **but** it commits AFTER the aggregate snapshot was already taken.
4. Result: the order is *missing from the closed Z aggregate* but its fiscal seq is in the closed window → fiscal gap when the next Z opens (next seq = N+2, not N+1).

The orphan-warn path at `ZReportService.php:229` only catches kiosk-paid orders missing `fiscal_sequence_no` (the `fiscal_alloc_error_at` retry path) — it does **not** catch orders allocated mid-close.

**Reproduction**
```
# 2 terminals
T1: POST /api/admin/pos/z-report/close
T2: (within 100ms) POST /api/admin/pos/order  ← fiscal_sequence_no allocated
# Verify: SELECT fiscal_sequence_no, created_at, deleted_at FROM orders
#         WHERE branch_id=1 AND fiscal_sequence_no = (latest);
#         SELECT opened_at, closed_at FROM z_reports WHERE id=(latest);
# Order's created_at inside (opened_at, closed_at], but order is not in z_report total.
```

**Owner mitigation**: `FiscalSequenceService::next` should check that no `ZReport::STATUS_CLOSING|STATUS_OPEN→CLOSING-transition` is in progress for the branch — acquire the same `Cache::lock("zreport_close_b{branch}")` or read a "closing barrier" flag. Alternative: in `ZReportService::close`, after lock acquire, also lock all `orders` rows for the branch with `created_at >= opened_at` via `lockForUpdate`. Service-level fence, no frozen-zone edit (FiscalSequenceService is frozen; the lock can be acquired in the OrderService callers instead).

---

### POS-RED-03 — `cash_movements` has NO actor column (forensic gap) — **P2** — hidden / NF525-adjacent

**Evidence**
Brief vector #11 claims "F-10 Sprint H2 added actor_user_id" on cash movement insertions. **False**.

- `database/migrations/2026_05_17_100000_add_actor_columns_to_cash_drawer_sessions.php` adds `closed_by_user_id` + `reconciled_by_user_id` to **`cash_drawer_sessions` only**.
- `database/migrations/2026_05_08_140100_create_cash_movements_table.php:28-51` defines `cash_movements` with: `cash_drawer_session_id`, `branch_id`, `order_id` (nullable), `type`, `amount`, `direction`, `notes` — **no actor column**.
- `app/Services/Cash/CashDrawerService.php:446-454` (`recordMovement`) does NOT write any actor column.

The actor is **only** recoverable via the linked `audit_logs` row (`CashDrawerService::writeAuditLog` line 459-466) which carries `user_id`. If the audit chain is degraded (allowed by the `try/catch` at line 534-547: best-effort, never blocks the movement), an attacker who fraudulently records movements gets a row in `cash_movements` with no actor pointer.

**Reproduction (forensic gap, not auth bypass)**
```sql
SELECT id, type, amount, direction FROM cash_movements WHERE id = (latest);
-- No way to identify the actor without joining audit_logs by (session_id, type, created_at) heuristic.
```

**Owner mitigation**: migration adds nullable `recorded_by_user_id BIGINT UNSIGNED NULL` on `cash_movements`, backfill from joined `audit_logs.user_id` (NF525 chain), update `CashDrawerService::recordMovement` to write `Auth::id()`. Defensive — does not touch any frozen-zone file.

---

### POS-RED-04 — Routine cash-drawer-close manager-gate is config-gated, default-OFF — **P1** — visible / hidden

**Evidence**
`app/Services/Cash/CashDrawerService.php:151-160`: the H2 manager gate fires **only** if `config('cash.manager_gate_routine_close', false)` is true. Default: `false`. Brief vector #4 implies the gate is enforced; it is not — it is opt-in.

For Le Cayenne single-resto single-cashier this is the documented intended UX (line 142-150 comment). But brief vector #4 explicitly asks to verify the routine-close gate — multi-cashier scenario is genuinely vulnerable: cashier B can `POST /api/admin/pos/cash-drawer/sessions/{A_session}/close` if they hit `assertSessionVisibleToUser` and are on the same branch (line 226-240 only checks branch isolation, not session ownership).

**Reproduction**
```bash
# Cashier A opens session 42 on branch 1
# Cashier B (also branch 1, role: POS Operator, permission:pos) sends:
curl -X POST /api/admin/pos/cash-drawer/sessions/42/close \
     -d '{"closing_amount": 0}'  ← B closes A's drawer with closing_amount=0
# Variance becomes catastrophic; cash physically present in drawer is now "missing"
# at reconciliation → blame falls on A.
```

**Owner mitigation**: in `CashDrawerSessionController::close` (and reconcile/movements), add `abort_if((int)$session->opened_by_user_id !== (int)$user->id && ! $user->hasRole('Admin'|'Branch Manager'), 403)`. Roughly 5 LOC, non-frozen. Independent of the config flag.

---

### POS-RED-05 — `reorderItems` exposes any order's cart structure to a branch_id=0 Admin without permission check — **P2** — indirect

**Evidence**
`app/Http/Controllers/Admin/PosOrderController.php:197` (`reorderItems`):
- Route binding `Order $order` → BranchScope filters for non-admin staff.
- For `branch_id=0` (global Admin), `BranchScope::apply` returns early at `app/Models/Scopes/BranchScope.php:33-36` → ALL orders visible.
- `permission:pos-orders` middleware is set at `PosOrderController.php:28-37`, including reorder-items. So an Admin with `pos-orders` permission can read any branch's cart payload — including `composition_snapshot` allergen data, customer notes, item variations.

This matches the codebase's "Admin omniscience" pattern (BranchScope:33). **Not a vulnerability per se**, but the endpoint is also covered by `permission:pos-orders|pos` at line 37 (only for index/show, NOT for reorderItems). Reorder is gated by `permission:pos-orders` (line 34), which is a permission a Branch Manager has — yet a Branch Manager on branch=2 can `GET /api/admin/pos-order/reorder-items/{branch_5_order_id}` if they discover an ID? **No** — BranchScope re-applies on the route-bind, so a branch_5 order vanishes for a branch_2 user (404 from the binding). So this is constrained to Admins. Documenting as P2 — disclosure of composition_snapshot is fine for Admin per design.

**Owner mitigation (optional)**: P3 — add an audit log entry on each `reorderItems` hit, for forensic trail. Otherwise: accept.

---

### POS-RED-06 — `cashBack` does NOT lock the `Transaction` row before idempotent check (race) — **P2** — hidden

**Evidence**
`app/Services/PaymentService.php:90-98`: `cashBack` checks `Transaction::where(...)->first()` for an existing `cash_back` row, **without** `lockForUpdate`. Two simultaneous `cashBack($order)` calls (e.g. customer self-cancel + admin RETURN status flip in the same window) could both pass the existing-check and write two `cash_back` transactions + credit the customer balance twice (line 113-117: `$user->balance += $order->total` × 2).

The wrapping context is `OrderService::changeStatus` which DOES `Order::lockForUpdate` at line 1670 — so as long as cashBack is *only* called from changeStatus, the parent order lock serializes them. BUT `FrontendOrderService.php:701` also dispatches cashBack via its own path, and those locks are independent (different tables/rows).

**Reproduction (theoretical, frontend/POS dual-path)**
```
Tx1 (mobile self-cancel): OrderService::changeStatus → locks Order row → cashBack
Tx2 (admin POS refund mid-action on same order, parallel ms): if dispatched before Tx1 commits, blocks on order lock — OK.
Tx2 (cross-controller: FrontendOrder path on a parent that mirrors): if it hits before Tx1 commits, also order-lock serializes.
```

The exploit window is narrow because the parent Order lock is held in both POS and frontend paths. **Downgraded to P2** — file the fix as defense-in-depth.

**Owner mitigation**: add `Transaction::lockForUpdate` inside `cashBack` after the existence check OR (safer) add a UNIQUE composite index `(order_id, type)` on `transactions` table for `type IN ('cash_back')` — DB-tier idempotency guarantee.

---

### POS-RED-07 — `assertSessionVisibleToUser` is branch-scoped only, allowing cross-cashier same-branch session probe — **P2** — direct (subsumed by RED-04)

**Evidence**
`app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:226-240`: `assertSessionVisibleToUser` 404s if session not found, 403s if cross-branch — but DOES NOT 403 if `session.opened_by_user_id != user.id` while same branch. Movements endpoint (line 172) leaks all cash movements of a colleague's session.

This is the same surface as RED-04. Disclosure of colleague's cash flow is less severe than the close attack, hence P2 here.

**Owner mitigation**: same fix as RED-04.

---

## 2. Cross-validation needed before P0 promotion

| ID | Why second opinion |
|---|---|
| **POS-RED-02** | Race window is sub-second; needs concurrency proof via real timing test on MySQL InnoDB (SQLite test would not reproduce). DBA review of `FiscalSequenceService::next` lock semantics is mandatory before P0 / heal. If the `Cache::lock("audit_chain_b{n}")` indirectly serializes (since Z close calls verifyChain which calls audit chain), the race may already be closed. **Frozen-zone**: FiscalSequenceService is in the frozen list — fix must be in callers or a LOCK plan. |
| **POS-RED-04** | Multi-cashier scenario is not part of Le Cayenne single-resto V1 NORTH STAR per CLAUDE.md §3. May be V1.0.2 backlog if owner confirms single-cashier deploy. If single-cashier stays the contract, downgrade to P2. |
| **POS-RED-01** | Without a found exploit path, the finding is theoretical. Validator: confirm no contributor PR review hook flags `Order::create($request->all())` patterns. |

## 3. Vectors probed but NOT vulnerable (negative-space documentation)

| Brief vector | Verdict | Citation |
|---|---|---|
| #1 Cross-branch IDOR on destroy/changeStatus/changePaymentStatus/selectDeliveryBoy | NOT VULNERABLE. Route-model binding applies BranchScope → cross-branch returns 404 (consistent with non-existent). `OrderService::destroy:2185-2188`, `changeStatus:1731-1736`, `selectDeliveryBoy:2104-2127` all have explicit branch checks beyond the scope. `refundWithCounterEntry` has explicit check at `PosOrderController.php:57-61`. |
| #1 Cross-branch IDOR on parked-order endpoints | NOT VULNERABLE. `PosParkedOrderService::recall:72-80` filters by BOTH `user_id` AND `branch_id`. `ParkedOrderController:93-98` enforces `branch_id > 0` (Admin must operate from branch login). |
| #2 Mass-assignment via POS endpoints | NOT VULNERABLE in current call sites — see RED-01. Latent in fillable list only. |
| #3 Phantom CARD with NULL/empty terminal_id | NOT VULNERABLE. `SplitPaymentService::validateBreakdown:117-137` requires `terminal_id > 0`, queries `PaymentTerminal` WITHOUT global scopes but explicitly filters on `branch_id = $order->branch_id` AND `status = ACTIVE`. Sentinel `tests/Feature/Sentinels/PosSplitPaymentPhantomCardSentinelTest.php` confirms. |
| #5 Refund counter-entry double-fire | NOT VULNERABLE. `RefundWithCounterEntryService::execute:73-78` blocks if parent `status === RETURNED`. `SealedOrderGuard::assertSealed` at line 70-71 forces pre-Z orders down the `changeStatus → RETURNED` path. The two paths are mutually exclusive by Z-window membership. |
| #5 Refund without manager permission | NOT VULNERABLE. `PosOrderController.php:36` declares `permission:pos-orders` on `refundWithCounterEntry`. Branch check at line 58-61. |
| #7 POS_SIMULATION_HARDWARE bypass production | NOT VULNERABLE. `app/Providers/AppServiceProvider.php:78-91` throws RuntimeException at boot if `app()->environment('production')` and `pos.simulation_hardware === true`. Boot path is `AppServiceProvider::boot()` which runs on every artisan + web + queue worker + scheduler bootstrap (Laravel's standard provider boot lifecycle). |
| #8 Walk-in customer PII in receipt | OUT OF SCOPE FOR THIS WAVE. Receipt generation is `PosReceiptPrintController`; PII printed is cashier-controlled. No cross-surface leak detected. |
| #9 NFC lookup IDOR | NOT VULNERABLE. `CustomerNfcLookupController.php:30-34` filters by `branch_id = auth.user.branch_id`. Admin (branch_id=0) gets no customer back since customers have `branch_id > 0` — graceful 404. PII returned (`phone`) is by design for POS cashier UX and gated by `permission:pos`. |
| #10 Parked order pickup wrong-cashier | NOT VULNERABLE (already confirmed in #1 row above). |
| #12 RefundCreated double-dispatch listener idempotency | NOT VULNERABLE. `PaymentService::cashBack:96-98` idempotent early-return on existing `cash_back` Transaction. `ReleaseStockOnRefundCreated` + `ReleaseAvailabilityOnRefundCreated` both idempotent via `released_qty` ledger (`AvailabilityService::releaseForOrderItems`). Even double-dispatch (cashBack + counter-entry mirror) is safe by listener design. |

---

## Summary

- **3 P1 findings**: latent mass-assignment (RED-01), Z-close race (RED-02), multi-cashier drawer-close gap (RED-04).
- **3 P2 findings**: cash_movements actor gap (RED-03), reorderItems Admin disclosure (RED-05), cashBack non-locked existence check (RED-06), session-movements disclosure (RED-07).
- **Zero P0**: no production-breaking exploit confirmed; the most critical claim (POS_SIMULATION_HARDWARE bypass) is properly guarded.
- **Brief vector #11 disputed**: `cash_movements` table has no actor column despite the brief's claim. Documented under RED-03.
- **All proposed mitigations are scope-minimal, non-frozen-zone, LOCAL-only**.
