# X5 — DATA INTEGRITY (cross-cutting audit, READ-ONLY)

Date: 2026-05-17
Auditor: Claude Code (X5 sub-agent)
Scope: FK constraints, snapshot immutability, audit trail completeness, transaction boundaries, partial-write states, NF525 chain validation end-to-end.
Method: file:line evidence only. No code changes. No drift.

Score: **62 / 100** — fiscal core (audit_logs, z_reports, fiscal_sequence) is hardened; everything around it (FK coverage on hot tables, transition→audit linkage, runtime invariants, currency, branch-FK on fiscal tables) is uneven and full of "soft" guards.

---

## 0. EVIDENCE SCOPE READ

Migrations: 158 total in `database/migrations/` (latest 2026-05-16). 47 FK declarations via `constrained()`. 83 lines mention foreign/cascade/onDelete/onUpdate.

Fiscal services:
- `app/Services/Fiscal/AuditLogService.php` (376 lines, full HMAC chain + UNIQUE-prev_hash defense)
- `app/Services/Fiscal/ZReportService.php` (728 lines, Z chain HMAC + sequence gap detect)
- `app/Services/Fiscal/FiscalSequenceService.php` (107 lines, monotonic per branch)
- `app/Services/Fiscal/FiscalChainValidator.php` (audit-tail extension)

Models sampled: Order, OrderItem, FrontendOrder, AuditLog, ZReport, ItemCategory, Branch, Item, User, KioskPromo, UpsellRule.

Observers wired in `app/Providers/AppServiceProvider.php:67-72`:
```php
$audit = SoftDeleteAuditObserver::class;
Order::observe($audit);
FrontendOrder::observe($audit);
OrderItem::observe($audit);
Branch::observe($audit);
Item::observe(ItemObserver::class);
ItemCategory::observe($audit);
```

> Agent 1's claim that "only FrontendOrder is observed" is **FALSE**. Five domain models attach `SoftDeleteAuditObserver`; the observer is wired symmetrically across order aggregates.

State machine: `app/Domain/Order/OrderStateMachine.php` (313 lines) — pure guard + transition recorder.

Tests with chain coverage:
- `tests/Feature/Fiscal/AuditLogHashChainTest.php` — tamper / forge / canonicalisation
- `tests/Feature/Fiscal/AuditLogImmutabilityTest.php`, `AuditLogConcurrencyTest.php`, `AuditLogBranchRequiredTest.php`
- `tests/Feature/Fiscal/FiscalSequenceTest.php`, `OrderFiscalSequenceSchemaTest.php`
- `tests/Feature/Fiscal/NF525ComplianceE2ETest.php`
- `tests/Feature/Fiscal/FiscalArchiveVerifyChainTest.php`, `FiscalSealingHmacTest.php`

---

## 1. FINDINGS BY DIMENSION

### D1 — FK constraints coverage (score: 5/10)

Hot tables with NO foreign-key constraint on critical references:

| Table | Column | Type | FK declared? | Evidence |
|---|---|---|---|---|
| `audit_logs` | `branch_id` | unsignedBigInteger NULL | **NO** (only `index()`) | migrations/2026_04_22_000002:36 |
| `audit_logs` | `user_id` | unsignedBigInteger NULL | **NO** (only `index()`) | migrations/2026_04_22_000002:37 |
| `audit_logs` | `resource_id` | unsignedBigInteger NULL | **NO** (polymorphic by design) | migrations/2026_04_22_000002:41 |
| `z_reports` | `branch_id` | unsignedBigInteger | **NO** (only `index()`) | migrations/2026_04_22_000003:30 |
| `z_reports` | `opened_by`, `closed_by` | unsignedBigInteger NULL | **NO** | migrations/2026_04_22_000003:35-36 |
| `cash_movements` | `branch_id` | unsignedBigInteger | **NO** (only index) | migrations/2026_05_08_140100:32 |
| `cash_movements` | `order_id` | unsignedBigInteger NULL | **NO** (only index) | migrations/2026_05_08_140100:33 |
| `action_logs` | `user_id` | foreignId NULL | YES — `onDelete('set null')` | migrations/2026_03_06_182733:18 |
| `loyalty_transactions` | `user_id` | foreignId | YES — `onDelete('cascade')` | migrations/2026_03_26_075918 |
| `orders` | `branch_id`, `user_id` | foreignId | YES — `constrained()` (RESTRICT by default) | migrations/2022_11_17_110810:24-25 |
| `order_items` | `order_id`, `branch_id`, `item_id` | foreignId | YES — RESTRICT | migrations/2022_11_17_110832:18-20 |
| `order_payments` | `order_id` | YES — `restrictOnDelete()` (was cascade, hardened in P0-FIX-4) | migrations/2026_05_10_010000:88-94 |

**Why this matters**: the fiscal core tables (`audit_logs`, `z_reports`) intentionally have no FK to `branches` so a branch hard-delete cannot CASCADE-wipe 6-year retention rows. That's defensive. But the consequence is **silent orphan possibility**:
- If `branches.id=42` is force-deleted (no SoftDeletes on Branch? — actually Branch DOES use SoftDeletes, see `app/Models/Branch.php:12`), the audit_logs and z_reports with `branch_id=42` keep pointing to a now-meaningless id, and HMAC chain re-verification per branch still works (the chain is keyed by branch_id, not by branch.row).
- `cash_movements.order_id` without FK means a malformed cash_movement can reference a non-existent order id. Order rows themselves cannot be hard-deleted thanks to the `order_payments.restrictOnDelete()` trigger (P0-FIX-4), but a stray INSERT with order_id=0 or order_id=999999 will be accepted.

Mixed `onDelete` policies (audited):
- `cascade` on: permission pivots (`role_has_permissions`, `model_has_roles`), `loyalty_transactions.user_id`
- `set null` on: `action_logs.user_id`
- `restrict` on: `order_payments.order_id`, `cash_movements.cash_drawer_session_id` (post-P0-FIX-4)
- default RESTRICT (no explicit policy) on most `constrained()` calls

Inconsistency: deleting a soft-deletable User does not cascade in the SoftDeletes model layer, but `loyalty_transactions` cascadeOnDelete fires on hard-delete (`forceDelete`). Symmetrically, `action_logs.user_id` becomes NULL on hard-delete — different policies for two adjacent audit tables.

---

### D2 — Orphan-row possibility (score: 6/10)

Realistic orphan paths:

1. **`order_payments` without `order`** — IMPOSSIBLE now (restrictOnDelete + DB trigger `order_payments_no_delete` since migration 2026_05_10_010000:131-141). MySQL only; SQLite skips the FK conversion (test-runner gap).

2. **`cash_movements` without `cash_drawer_session`** — IMPOSSIBLE (`restrictOnDelete` + trigger). Same MySQL-only caveat.

3. **`cash_movements.order_id` pointing to non-existent / deleted Order** — **POSSIBLE** (no FK, no trigger). Even more so since Order is SoftDeletes — `order_id` survives soft-delete, but a manual hard-delete of an Order outside `OrderService::destroy()` would orphan the cash_movement. The order_payments restrictOnDelete protects against destroy-via-FK-cascade but not against an admin running `Order::find(123)->forceDelete()` from tinker; that's now allowed because Order has no force-delete guard at the model level (see `Order::boot()` only blocks `restoring`, not `forceDeleting`).

4. **`audit_logs.actor` (`user_id`) without `users`** — **POSSIBLE BY DESIGN**. User can be soft-deleted; audit row is INSERT-only and references an id that may later vanish from authenticatable lookups. Tradeoff: NF525 retention vs FK clean-up.

5. **`z_reports.opened_by/closed_by` without `users`** — **POSSIBLE** (no FK).

6. **`order_items` without `order`** — IMPOSSIBLE (RESTRICT FK on `order_id`) AND OrderItem uses SoftDeletes (`app/Models/OrderItem.php:13`) so even soft-delete works without orphaning.

7. **`order_status_transitions.order_id` polymorphic (`order_type` column) without FK** — **POSSIBLE**. migrations/2026_04_15_230000:17-18: `unsignedBigInteger('order_id')` + `string('order_type', 191)`, no FK because of polymorphic Order/FrontendOrder dispatch. A FrontendOrder hard-delete would orphan its transitions.

---

### D3 — composition_snapshot immutability (score: 8/10)

`composition_snapshot` is added in migrations/2026_04_22_000020 as a nullable JSON column on `order_items`.

Generation sites (immutable-at-write):
- `app/Services/Pricing/PricingService.php:266-291` — `[T07 SSOT] Build the immutable composition_snapshot at order creation`, persisted via `json_encode($compositionSnapshot)`
- `app/Services/Pricing/CompositionSnapshotBuilder.php` — dedicated builder
- `app/Services/OrderService.php:455, 810, 1266` — wires the snapshot into OrderItem inserts
- `app/Services/FrontendOrderService.php:441` — same for frontend orders
- `app/Services/Order/RefundWithCounterEntryService.php:135` — copies the snapshot verbatim onto the mirror refund order

Consumers (read-only, never overwrite):
- `app/Http/Resources/OrderItemResource.php:31-104` — multiple reads
- `app/Http/Resources/KDSOrderItemsResource.php:31-35`
- `app/Http/Controllers/Admin/PosOrderController.php:211,243`
- `app/Services/KitchenDisplaySystemOrderService.php:270`
- `app/Services/Stock/StockService.php:280` — addons decoded for stock release

**Weakness — no DB-level immutability**: `composition_snapshot` is a vanilla nullable JSON column. There's NO trigger, NO model-level `updating` guard, NO test that fails when a developer accidentally writes it twice. The comment at `PricingService.php:778` says "composition_snapshot ⇒ NF525 fiscal SSOT breach" if it drifts — but the only enforcement is "we promise not to". Compare with `app/Models/AuditLog.php:42-58` which has `static::updating(...)` + DB trigger; OrderItem has neither for that column.

A `MenuHealLightV3Command` comment at `app/Console/Commands/MenuHealLightV3Command.php:57` reads "composition_snapshot historical preservation: add-only on items.name (490)" which acknowledges the risk and says heal commands MUST avoid touching the column. But that's a process discipline, not a guarantee.

---

### D4 — Audit chain HMAC integrity (score: 9/10)

`AuditLogService` (`app/Services/Fiscal/AuditLogService.php`) is excellent:
- L100-120: `Cache::lock('audit_chain_b{n}')` serialises per-branch writers
- L112: `DB::transaction(...)` wraps tail-read + insert atomically
- L93-98: refuses NULL branch_id (CLI must pass branch_id=0 explicitly) — this fixed an iter11 chain-poisoning race
- L179-191: catches QueryException, retries ONCE on UNIQUE(branch_id, prev_hash) violation (defence-in-depth when cache is split-brain)
- L237-243: `computeHash()` uses `hash_hmac('sha256', $prev . '|' . canonical(payload), secret)`
- L335-374: `canonicalise()` recursively `ksort`s assoc arrays — chain stable across PHP array orderings; preserves list order (uses `array_is_list`)
- L303-327: `assertProductionSafe()` rejects dev sentinels + secrets < 32 chars in `APP_ENV=production`

DB-level enforcement (migrations/2026_04_22_000002:96-135):
- MySQL/MariaDB: `BEFORE UPDATE` + `BEFORE DELETE` triggers SIGNAL SQLSTATE '45000'
- SQLite: `RAISE(ABORT, ...)` for both
- Eloquent: `AuditLog::booted()` rejects `updating` + `deleting` (model layer = belt + braces)
- Rollback blocked in production (`down()` throws if `APP_ENV=production`) — NF525 6y retention

Tests:
- `tests/Feature/Fiscal/AuditLogHashChainTest.php` — pristine, tamper, forge, canonicalisation
- `tests/Feature/Fiscal/AuditLogImmutabilityTest.php`
- `tests/Feature/Fiscal/AuditLogConcurrencyTest.php`
- `tests/Feature/Fiscal/AuditLogBranchRequiredTest.php`

**Weakness**:
- TRUNCATE bypasses MySQL triggers — only mitigation is "revoke TRUNCATE GRANT on production DB user" (deploy doc, NOT enforced by code). No CI check, no boot-time assertion.
- The chain is per-branch. A cross-branch tamper (e.g., move rows from branch 1 to branch 99 via UPDATE) would be caught by the trigger BLOCKING the UPDATE, but if triggers are dropped (developer rollback) all per-branch verifications still pass individually because each chain is recomputed in isolation. There is no global / system-wide chain.

---

### D5 — Z-report chain (score: 8/10)

`ZReportService` (`app/Services/Fiscal/ZReportService.php`):
- L463-572: `verifyChain($branchId)` re-walks all CLOSED z_reports for a branch in `sequence_no` order
- L505-516: detects `chain_break` (prev_hash mismatch)
- L519-527: detects `sequence_gap` (non-monotonic sequence)
- L529-538: detects `signature_mismatch` (HMAC recompute)
- L546-568: in `strict` mode (default = production), throws `RuntimeException`. In degraded mode (CI/local), logs to `fiscal` channel and returns the error list.
- L88-95: `open()` and `close()` BOTH call `verifyChain()` before reserving a new sequence — fails fast if the historical chain is broken (refuses to add a row on top of a corrupted chain).
- L100-122: `open()` inside `DB::transaction`, uses `existingOpen` check + `max('sequence_no') + 1` — but **not** `lockForUpdate()` on the max query.
- L203-268: `close()` uses `lockForUpdate()` on the open Z row, computes aggregates, signs, persists in one TX.

DB-level enforcement (migrations/2026_05_09_160000:50-58):
- MySQL/MariaDB: `BEFORE DELETE` trigger SIGNAL 45000 on `z_reports`
- UPDATE intentionally allowed (state machine: open → closed → archived)
- SQLite test runner: skipped silently

Test coverage:
- `tests/Feature/Fiscal/FiscalArchiveVerifyChainTest.php`
- `tests/Feature/Fiscal/FiscalSealingHmacTest.php`
- `tests/Feature/Fiscal/NF525ComplianceE2ETest.php`

**Weaknesses**:
- `open()`'s `max('sequence_no')` (L113-114) is NOT `lockForUpdate()`. The unique key `z_reports_branch_sequence_unique` will reject a colliding insert, but the open() path does not retry — it surfaces a UNIQUE-violation 500 to the operator. Compare with AuditLogService's explicit retry on UNIQUE collision.
- The `verifyChain()` `expectedSequenceNo` check (L519) only fires AFTER a first iteration sets `$expectedSequenceNo = (int) $zReport->sequence_no + 1`. So a sequence that starts at, say, 5 (because rows 1-4 were silently DELETEd via TRUNCATE) is NOT caught — `first_z_id` becomes 5 and the chain is reported valid from 5 onward. There is no anchoring to "first sequence_no must be 1 (or genesis_prev_hash matches)".
- `FiscalSequenceService` and `ZReportService` use TWO different sequence spaces. `orders.fiscal_sequence_no` is independent from `z_reports.sequence_no`. That's by design (one is per-receipt, one is per-close-day) but means "gap-free" must be asserted twice with two different mechanisms. No cross-test asserts they evolve coherently.

---

### D6 — Fiscal sequence gap detection (score: 7/10)

`FiscalSequenceService::next()` (`app/Services/Fiscal/FiscalSequenceService.php:60-100`):
- L67-70: rejects `branch_id <= 0`
- L72-77: `Cache::lock('fiscal_seq_b{n}', 5s)` blocks up to 3s
- L80-92: inside `DB::transaction`: `Order::withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->max('fiscal_sequence_no')` — note explicit `lockForUpdate()` for defence-in-depth
- L93: `return $max + 1`
- DB unique index `orders_branch_fiscal_seq_unique` (migrations/2026_04_22_000001:38) is the ultimate gate

But the column `fiscal_sequence_no` remains **NULLABLE** (migrations/2026_04_22_000001:28). No follow-up migration tightens it to NOT NULL. Kiosk-paid orders can fail alloc and remain NULL (tracked by `fiscal_alloc_error_at` since migrations/2026_05_09_200000). The retry cron `RetryFiscalAllocCommand` (`app/Console/Commands/RetryFiscalAllocCommand.php:64`) WHEREs on `fiscal_alloc_error_at IS NOT NULL`.

**Weaknesses**:
- "Gap-free" is per-branch but the gap detector is `verifyChain()` on z_reports — there is no `assertFiscalSequenceGapFree()` for the order-level fiscal_sequence_no itself. A row whose alloc errored, was retried, and somehow committed at `MAX+2` (eg cache-eviction split-brain not retried by `next()` itself — `next()` has NO `QueryException` retry, unlike AuditLogService) would silently create a gap.
- `ZReportService::aggregate()` (L337-341) filters `whereNotNull('fiscal_sequence_no')`. Orphan paid orders are correctly EXCLUDED from totals but the warning at L586-616 is best-effort (try/catch swallows everything). No CI test asserts `count(orders WHERE payment_status=PAID AND fiscal_sequence_no IS NULL AND fiscal_alloc_error_at IS NULL) == 0`.

---

### D7 — Transaction boundaries (score: 6/10)

`OrderService.php` has 11 `DB::transaction(...)` blocks (lines 307, 601, 1098, 1505, 1515, 1590 …). Each wraps create/update/audit symmetrically.

`PaymentService.php` has 2 — `pay()` (L156) and `cancel()` (L379) — both wrap audit + state change.

**Critical concern — listeners dispatched INSIDE the transaction**:
- `OrderService.php:548`, `1051`, `1361`: `\App\Events\OrderCreated::dispatch($this->order)` is fired inside the create transaction.
- `app/Listeners/DecrementStockOnOrderCreated.php:13-34` is **synchronous** (no `ShouldQueue`, no `afterCommit` interface) and runs in-process. The comment at L13-16 says "let the StockUnavailableException bubble up so the order creation transaction can be rolled back upstream" — confirming the listener intentionally throws to roll back. That's correct, but:
- Other listeners that fire on `OrderCreated` (`SendFcmOnOrderCreated`, `PersistOrderCreatedToOutbox`, etc.) — if any of THEM throws, the order tx is rolled back too. Some of them use `DB::afterCommit(...)` (`PersistOrderCreatedToOutbox.php:61`) which is correct, but several "Send*" listeners (`SendFcmOnOrderCreated`, `SendOrderMail`, etc.) dispatch jobs/notifications without an `afterCommit` guard. A notification system outage could roll back a finalized PAID order.

**Cash trail inside same TX**: comments at OrderService:1017-1029 confirm cash_movement INSERT happens INSIDE the order TX, AFTER fiscal_sequence_no allocation. Strict mode (throw `CashDrawerSessionNotOpenException`) rolls back the order. That's atomic — good — but it means a closed cash drawer blocks order creation entirely. No degradation path.

**Z-close warning is best-effort**: `ZReportService::warnOnOrphanedPaidOrders()` L611-615 wraps the orphan-count in try/catch. If the count crashes, the Z silently closes without warning ops — observability is sacrificed for "never let observability break a Z". Acceptable, but not asserted.

---

### D8 — Order state transitions audited (score: 5/10)

There are **TWO PARALLEL TRANSITION LOGS**:
1. `order_status_transitions` (migration 2026_04_15_230000) — written by `OrderStateMachine::recordTransition()` (`app/Domain/Order/OrderStateMachine.php:144-159`). Polymorphic (`order_id` + `order_type` string). NO foreign key to orders (polymorphic dispatch).
2. `action_logs` (migration 2026_03_06_182733) — generic human-readable trail, written by older code paths.

**Coverage gaps**:
- `OrderStateMachine::recordTransition()` is BEST-EFFORT: try/catch swallows failures (L156-158: `Log::warning('... Failed to record transition')`). A row mutation succeeds but its transition row may silently fail. There is no DB constraint forcing "every status change has a transition row".
- `OrderService` calls `OrderStateMachine::recordTransition` only in 3 places (lines 1533, 1611, 1717). Many other status mutations exist in OrderService and FrontendOrderService that go through the legacy `$order->status = $next; save()` pattern (per the OrderStateMachine.php:22 comment "Existing OrderService / FrontendOrderService call sites keep their historical pattern"). Those mutations do NOT write to `order_status_transitions`.
- The HMAC `audit_logs` chain is ONLY written for high-sensitivity events: order discount (OrderService:980), refund (lines 1681, 1745, 1872, 1927, 2113), payment events (PaymentService lines 104, 203, 410, 501), cash drawer events (CashDrawerService:466), split payment, receipt print, etc. **Plain status transitions (PENDING → ACCEPT → PREPARING → DELIVERED) are NOT in the HMAC chain.** They live only in `order_status_transitions` which has no immutability trigger, no HMAC, no chain. An attacker with DB access can rewrite the entire status trail of an order without breaking any fiscal invariant.

**Soft-delete audit**: `app/Observers/SoftDeleteAuditObserver.php` writes to `DeletionLog` (NOT to `audit_logs`). Wired for Order, FrontendOrder, OrderItem, Branch, ItemCategory (AppServiceProvider:67-72). Observer is `deleted(...)` only — no `restoring`, no `restored`, no `forceDeleting`. Restoring Order is blocked at the model level (`Order::boot()` L108-116). `forceDelete` is NOT blocked.

---

### D9 — Soft-delete handling vs fiscal (score: 7/10)

Order soft-delete: `app/Models/Order.php:17` `use SoftDeletes`. `restoring` is BLOCKED at model layer (L108-116) because OrderService destroy hard-deletes child OrderAddress + OrderCoupon (no SoftDeletes on those), so restore would leave an inconsistent aggregate.

`ZReportService::aggregate()` (L337-338) explicitly uses `withTrashed()` to include soft-deleted post-allocation orders in the Z totals. This is the P0-FIX-1/2 iter15 fix mentioned at L306-323. The rationale: a soft-delete after fiscal_sequence_no allocation MUST still appear in exactly one Z (NF525 fiscal continuity). Exclusion of refunded/cancelled is done via `$terminalStatuses` whitelist (L349-353), NOT via soft-delete.

**Hidden risk — child OrderAddress / OrderCoupon are HARD-deleted by destroy()** (per Order.php:103 comment). Z-report still shows the order's totals (good — fiscal sound) but the original delivery address is permanently lost. If a tax audit asks "to which address was this delivery for order 12345 made", the answer is "unknown — hard-deleted on cancellation". For dine-in this is fine; for DELIVERY orders this is a NF525 gray zone.

`FrontendOrder` also `use SoftDeletes` (`app/Models/FrontendOrder.php:17`).

`OrderItem` `use SoftDeletes` (`app/Models/OrderItem.php:13`) — soft-deleted line items. A line item soft-deleted post-payment would change `sum(order_items.tax_amount)` and break the cross-model invariant (D10). Not asserted anywhere.

---

### D10 — Cross-model invariants (score: 3/10)

There is NO runtime assertion that `Order.total == sum(OrderItem.subtotal) + Order.delivery_charge + Order.total_tax - Order.discount`.

Found only:
- `PricingService::calculateOrder` computes Order.total at creation time via in-memory sum (L236, 323-355). Stored values are correct AT WRITE TIME.
- After write, no observer / scheduled task / CI test asserts the invariant.
- Refund path (`RefundWithCounterEntryService::store`) creates a mirror order with `total = - original.total` — no symmetry check.
- Split payment (`SplitPaymentService::persistTranches`) validates `sum(tranche.amount) >= order.total` only at insert time (comment at OrderService:1008-1009).

**Risk concrete**: A migration like `MenuHealLightV3Command` or any retroactive recalculation (`MenuHealLightV2Command.php:36` warns about "composition_snapshot historical preservation: pricing changes do NOT recompute historical orders") can drift `items.price` while `order_items.price` stays frozen — that's CORRECT behaviour (immutable receipts) BUT there is no boot-time check `orders.total == sum(order_items.price * quantity)` to catch a bug where someone DID retroactively update `order_items.price`. The HMAC chain protects only the `audit_logs` entry, not the underlying rows it points to.

`order_items.tax_rate` is `decimal(19,6)` (migrations/2023_07_20_095843:18) but ZReportService treats it as a STRING key after canonicalisation (`ZReportService.php:663` uses `rtrim(rtrim(number_format((float)..., 2, '.', ''), '0'), '.')` to dedupe "10" vs "10.00"). Comment at L660 confirms the inconsistency: "tax_rate is stored as a string with inconsistent precision". This canonicalisation is done at Z-aggregate time only — not at write time. If a different consumer reads tax_rate raw, it gets the inconsistent representation.

---

### D11 — Branch isolation at DB level (score: 4/10)

App-level: `app/Models/Scopes/BranchScope.php` is applied globally on 11+ models (per CLAUDE.md §9). Verified at `app/Models/Order.php:92` (`addGlobalScope(new BranchScope())`).

DB-level: there is **NO multi-tenant row-level security**. No PostgreSQL RLS (the project is MySQL). No view per-tenant. Branch isolation is entirely:
- App-level via `BranchScope` (depends on `Auth::check()` and `Auth::user()->branch_id`)
- `BranchScope` returns early for the `User` model itself (L21-23) to avoid Sanctum recursion
- `BranchScope` returns early when `App::runningInConsole()` AND NOT `runningUnitTests()` (L27). **Commands and queue workers run with NO scope.** This is by design (cron jobs need cross-branch reach), but it means any raw `Eloquent::all()` inside a Console Command sees every branch's data.

`audit_logs.branch_id` is index-only (D1); a raw SQL `UPDATE audit_logs SET branch_id = 42 WHERE id = X` would change tenant attribution AND would be REJECTED by the immutability trigger — so that path is safe. But a raw INSERT with branch_id=0 (admin) when the caller is really a branch staffer would bypass scope at write time. AuditLogService rejects NULL branch_id (L93-98) but accepts branch_id=0 as the "system/CLI chain" — there is no further check that branch_id matches Auth::user()->branch_id at write time.

---

### D12 — Currency / rounding (score: 5/10)

Single currency assumption: there is NO `currency_id` column on `orders` (verified by grep on the orders migration and Order model fillable list, lines 20-58). There is a `currencies` table (migration 2022_05_25_124629) and a `settings.currency` value used at display time, but the per-order currency is implicit.

Rounding policy: every monetary computation routes through `round(..., 2)` at write time:
- `PricingService.php:236, 323, 328, 355, 357, 812`
- `OrderService` writes use `round((float)..., 2)`
- ZReportService `applyOrderToTotals` casts to float and rounds via `round(..., 2)` at aggregate time

**Weaknesses**:
- DB columns are mixed: `orders.total` is `decimal(19,6)` (migrations/2022_11_17_110810:21) but `z_reports.total_ttc` is `decimal(15,2)` (migrations/2026_04_22_000003:41) and `order_payments.amount` is `decimal(10,2)`. Going from 6 decimals at the source to 2 decimals at the Z aggregate creates a forced round-off — for a single order of 12.345678 € (impossible but illustrative) the Z would record 12.35 €. In practice the source values are already rounded to 2 places by PricingService, but there's nothing in the DB schema preventing a developer from writing 6-decimal precision.
- Float comparisons throughout (`(float) $r->total_tax_for_rate`, `hash_equals` for signatures but not for amounts). A `tax_rate=10.0000001` vs `tax_rate=10.0` would round to the same string key in ZReportService L663 but accumulate float drift in `total_tva` over thousands of rows. No bcmath, no Money value object.
- Currency code is NOT in the HMAC signature payload. A change of presentational currency (EUR → USD admin setting) silently re-displays historical Z totals as USD numbers. The signature remains valid because the amounts didn't change, but the unit interpretation drifts.

---

## 2. TOP 5 WEAKNESSES (ranked)

1. **Status transitions are NOT on the HMAC chain.** `order_status_transitions` is mutable (no DB trigger, no model `updating` guard), and `OrderStateMachine::recordTransition()` is best-effort try/catch. An attacker with DB write access can rewrite the entire status history of any order without breaking any fiscal invariant. (D8)

2. **`composition_snapshot` has no immutability guard.** Despite being labelled "NF525 fiscal SSOT" (PricingService:778), the column is a plain nullable JSON with no trigger, no observer, no model boot guard. A buggy heal command or a tinker session can rewrite history. (D3)

3. **FK gaps on fiscal/cash tables**: `audit_logs.branch_id`/`.user_id`, `z_reports.branch_id`/`.opened_by`/`.closed_by`, `cash_movements.branch_id`/`.order_id` have no foreign keys. Orphans are reachable via direct INSERT or via hard-delete of parents that lack their own protections (e.g. Order has no `forceDeleting` guard). (D1, D2)

4. **No cross-model total invariant check.** Nothing asserts at runtime or in CI that `Order.total == sum(OrderItem.subtotal) - discount + tax + delivery`. A silent corruption (concurrent retroactive update, partial restore, soft-deleted OrderItem) goes undetected until a tax inspector recomputes manually. (D10)

5. **Single currency is assumed but not enforced.** No `currency_id` on Order. No currency in the HMAC payload. DB columns use mixed decimal precisions (decimal(19,6) source vs decimal(15,2) aggregate). A future multi-currency rollout will silently corrupt historical Z reports' interpretation. (D12)

---

## 3. TOP 3 MITIGATIONS (priority order)

1. **Bring status transitions into the HMAC chain.** Add an `audit_logs` write inside `OrderStateMachine::apply()` (already wraps lock+mutate+audit at L208-253) for every transition, with payload `{from, to, actor_id, reason}`. Drop the silent try/catch on `recordTransition` — if the HMAC fails, the transition must roll back. Cost: ~1 day, contained to `OrderStateMachine.php`. Test: extend `AuditLogHashChainTest` to assert `count(audit_logs WHERE action LIKE 'order.transition.%') == count(distinct status changes)` after a 1-day fixture run.

2. **Add a DB-level immutability guard on `composition_snapshot`.** Cheapest path: MySQL trigger BEFORE UPDATE on `order_items` that rejects any change of `composition_snapshot` once non-NULL. Mirror in SQLite. Add model-layer guard `OrderItem::updating(function ($o) { if ($o->isDirty('composition_snapshot') && $o->getOriginal('composition_snapshot') !== null) throw ... })`. Backstop: a phpunit test that explicitly tries to rewrite and asserts the trigger fires. Cost: ~2h.

3. **Add a boot-time / CI cross-model invariant check.** Schedule an hourly Artisan command `foodking:invariants:totals` that runs `SELECT id, total, (SELECT SUM(price*quantity) FROM order_items WHERE order_id = orders.id AND deleted_at IS NULL) AS computed FROM orders WHERE deleted_at IS NULL AND ABS(total - delivery_charge - total_tax + discount - computed) > 0.01`. Any non-empty result is a P0 fiscal drift. Emit to `fiscal` log channel + Sentry. Cost: ~2h, contained. Long-term: replace floats with `brick/money` Money value object at the service boundary; major refactor (defer to V1.1).

---

## 4. SECONDARY OBSERVATIONS (non-blocking)

- `audit_logs.branch_id` is nullable but `AuditLogService::write()` rejects null callers (L93-98). The DB schema is laxer than the service — a direct SQL INSERT bypassing the service can still store NULL.
- `RefundWithCounterEntryService.php:135` clones `composition_snapshot` from the original line into the refund line — good. But the refund's `composition_snapshot.kind` is not marked as "refund-mirror" inside the JSON, so a consumer parsing the snapshot can't distinguish original from mirror without joining `parent_order_id`.
- `cash_movements_no_delete` trigger exists (P0-FIX-4) but `cash_movements_no_update` does NOT. The justification is implicit (cash_drawer_sessions UPDATE legitimate for close), but a malicious change of `amount` is not blocked.
- `OrderStatusTransition` table has indexes on `(order_id, order_type, occurred_at)` and `occurred_at` — good — but no compound index on `(actor_id, occurred_at)` despite frequent "audit trail for cashier X over date range" queries (verified in `PosOrderController` filters).
- `order_status_transitions.correlation_id` is filled from `X-Correlation-ID` header (`OrderStateMachine.php:153`). Idempotency keys are NOT propagated into the transition row — debugging a duplicate transition requires joining via the `idempotency_keys` cache (no DB row).

---

## 5. EXIT VERDICT

The fiscal core (audit_logs HMAC, z_reports chain, fiscal_sequence_no monotonic + lockForUpdate + unique index, immutability triggers, RetryFiscalAllocCommand for orphans) is genuinely production-grade and well-tested. The work in `tests/Feature/Fiscal/` (30 files) is detailed and adversarial.

But the **surface area** around the fiscal core is uneven:
- Status transitions are NOT on the chain.
- Snapshots are NOT triggered-immutable.
- Cross-model invariants are NOT runtime-asserted.
- Multi-currency is assumed away rather than enforced.
- FK coverage on cash/audit tables is intentional-defensive in some places but accidentally absent in others (cash_movements.order_id).

NF525-COMPLIANT for fiscal SSOT: **YES, at the chain level**. SHIPPABLE for V1 Le Cayenne single-restaurant: **YES**, with mitigations 1 + 3 added before scaling to a second branch. SCALABLE to multi-restaurant V2 SaaS: **NO** without addressing D11 (DB-level tenancy) and D12 (currency).
