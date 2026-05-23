# PROPOSAL — apply() silently drops `reason` on model row via isFillable('reason') gate

**ID** : PROP-OSM-002
**Date** : 2026-05-23
**Phase** : B.5 — PROPOSAL AGENT for `app/Domain/Order/OrderStateMachine.php`
**Frozen file** : `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7)
**Severity** : P1 — real semantic divergence from legacy paths ; mitigated by audit-row
fallback but creates a UI / search inconsistency for cancelled orders
**Touch** : ZERO (read-only audit, proposal only)

---

## 1. Finding (read-only)

`OrderStateMachine::apply()` at lines 234-237 :

```php
$locked->status = $next;
if ($reason !== null && $locked->isFillable('reason')) {
    $locked->reason = $reason;
}
$locked->save();
```

The gate `$locked->isFillable('reason')` returns **false** for BOTH `Order` and
`FrontendOrder` because **neither model lists `'reason'` in its `$fillable`
array** :

- `app/Models/Order.php` :20-58 — `$fillable` does NOT contain `'reason'`
- `app/Models/FrontendOrder.php` :36-73 — `$fillable` does NOT contain `'reason'`

Therefore `apply()`'s `if (... && $locked->isFillable('reason'))` block is
**dead code in all current production paths**. When the cron job
`CleanupStalePendingKioskOrders` calls `apply($order, REJECTED, null,
'Auto-rejected stale pending kiosk order after 15 minutes.')`, the
audit-row gets the reason but the order's own `reason` column stays NULL.

---

## 2. Why this is a real bug (not a no-op)

The schema **does have** an `orders.reason` `TEXT NULL` column (created
`2022_11_17_110810_create_orders_table.php` :39 — `$table->text('reason')
->nullable();`). It is a long-standing column used by every other code path
to surface the cancellation/rejection reason in the admin UI, KDS, customer
emails, and OSS.

Legacy callsites correctly populate the column by **direct attribute
assignment** which bypasses the `$fillable` mass-assignment guard :

- `app/Services/OrderService.php` :1819 — `$locked->reason = $request->reason;`
- `app/Services/OrderService.php` :1923 — `$order->reason = $request->reason;`
- `app/Services/FrontendOrderService.php` :736 — `$frontendOrder->reason = $cancelReason;`
- `app/Services/PaymentService.php` :534 — `$locked->pos_payment_note = $reason;`
  (different field, but same pattern)

So the 11 legacy callsites write `$order->reason = X` directly — and Eloquent
honours it because direct attribute assignment via `setAttribute()` does
NOT check `$fillable`. Only mass-assignment (`fill()`, `create()`, etc.) does.

`apply()` is the **only** path that wraps the assignment in an `isFillable()`
guard, and that guard silently fails for the very models the SM is designed
to serve. This is the OPPOSITE behaviour to legacy paths — same input
(`reason="..."`), same DB column, but `apply()` writes NULL while legacy
paths write the string.

---

## 3. Reproduction (read-only logic walk)

Given :

```php
$order = Order::find(123);            // status=PENDING, reason=NULL
OrderStateMachine::apply($order, OrderStatus::CANCELED, $cashier, 'walked_away');
```

Walk through `apply()` :

1. `DB::transaction(...)` opens.
2. `$locked = Order::query()->whereKey(123)->lockForUpdate()->firstOrFail()` reads the row.
3. `$from = (int) $locked->status` → 1 (PENDING).
4. `$from === $next` ? No (1 ≠ 16).
5. `allows(1, 16, $cashier)` → true.
6. `requiresReason(16)` → true ; `'walked_away'` is non-empty ; OK.
7. `$locked->status = 16` ✅
8. `'walked_away' !== null` ✅ AND `$locked->isFillable('reason')` → **false** ❌
9. So the `$locked->reason = 'walked_away'` line is SKIPPED.
10. `$locked->save()` writes status=16, reason=NULL.
11. `recordTransition(...)` writes an audit row WITH the reason.

Net result :
- `order_status_transitions.reason` = `'walked_away'` ✅ (audit chain intact)
- `orders.reason` = `NULL` ❌ (admin UI, KDS, OSS see "no reason recorded")

The user-facing surfaces (`AdminOrderTable.vue`, `KdsV2Card.vue`,
`OssOrderRow.vue`) all read `order.reason` — they would silently display
"Pas de motif" while the audit chain has the truth one table away.

---

## 4. Test gap

`OrderStateMachineApplyTest::test_apply_cancel_with_reason_succeeds_and_persists_reason()`
at line 88-100 asserts :

```php
OrderStateMachine::apply($order, OrderStatus::CANCELED, null, 'customer_request');
$order->refresh();
$this->assertSame(OrderStatus::CANCELED, (int) $order->status);

$row = OrderStatusTransition::query()->where('order_id', $order->id)->first();
$this->assertNotNull($row);
$this->assertSame('customer_request', $row->reason);
```

The test asserts the **audit-row** reason. It does NOT assert
`$order->reason === 'customer_request'`. The test would pass even though the
model-row reason is NULL — confirming the bug is hidden from CI.

---

## 5. Impact on production today

The bug surfaces only for the **one** `apply()` callsite :

**`CleanupStalePendingKioskOrders::handle()`** (auto-reject after 15 min stale) :

```php
OrderStateMachine::apply(
    $locked,
    OrderStatus::REJECTED,
    null,
    'Auto-rejected stale pending kiosk order after 15 minutes.'
);
```

The cron job is the **single path that goes through apply()**. Result :

- The `order_status_transitions` row carries the explanatory string for forensic / NF525 audit. ✅
- The `orders.reason` column on the kiosk order itself stays NULL. ❌
- A customer / admin checking the kiosk order in `/admin/orders/123` sees
  "Status: REJETÉE" with no explanation, even though the cron job DID
  record one in the parallel audit table.

For kiosk auto-rejects this is **low-impact in V1** (stale unpaid kiosk orders
are typically never re-opened by anyone), but it IS a contract violation of the
`$order->reason` semantic that every other code path honours.

---

## 6. Three resolution paths

### Option A — Drop the `isFillable` gate

Simplest fix. `apply()` becomes consistent with the 11 legacy paths :

```diff
- if ($reason !== null && $locked->isFillable('reason')) {
+ if ($reason !== null) {
      $locked->reason = $reason;
  }
```

**Pros:**
- 1-line change. Trivial regression surface.
- Restores the contract that `$order->reason === $audit_row->reason`.
- Direct attribute assignment is safe in Eloquent — it does NOT escape into
  unsanitised mass-assignment.
- The schema has the column, so the write is well-formed.

**Cons:**
- Touches `OrderStateMachine.php` — frozen-zone gate required.
- If a future model passes through `apply()` without a `reason` column, the
  assignment would silently land in `$attributes` and be discarded on `save()`
  (Eloquent ignores attributes that don't correspond to a column at save time
  for non-fillable usage — actually depends on the driver). Low risk because
  the SM is dedicated to Order + FrontendOrder.

### Option B — Add `'reason'` to both models' `$fillable`

```diff
// app/Models/Order.php :58
      'fiscal_alloc_error_at',
+     'reason',
  ];

// app/Models/FrontendOrder.php :72
      'fiscal_alloc_error_at',
+     'reason',
  ];
```

Then leave `apply()` as-is — the `isFillable('reason')` gate would start
returning `true` and the assignment would land.

**Pros:**
- Does NOT touch `OrderStateMachine.php` frozen file.
- Brings the model `$fillable` array into accuracy (`reason` IS a legitimate column).
- Defensive : future callers using `Model::create(['reason' => ...])` would
  also be unblocked.

**Cons:**
- Adds `reason` to mass-assignment surface — IF a controller validates user-
  supplied JSON and `extract()` passes through, `reason` could now be mass-
  assigned. Audit all `Order::create(...)` and `Order::update($request->all())`
  callsites before shipping.
- Indirectly modifies behaviour of legacy callsites' adjacent code (they all
  use direct attribute assignment so they are unaffected, but `$order->fill()`
  paths would silently change).

### Option C — Both A AND B (belt + suspenders)

Drop the gate AND add to `$fillable`. Pure defence-in-depth.

**Pros:**
- Maximum consistency. `apply()` matches legacy semantic AND models declare
  the column as intentional.

**Cons:**
- Touches three files (frozen file + 2 models).
- More LOC = more review surface.

---

## 7. Recommendation

**Option A** — drop the `isFillable` gate.

Reasoning :

1. The `isFillable` check was introduced as defensive programming against
   "what if this is called on a future model without a reason column" — but
   in practice (a) the SM is constructed for Order + FrontendOrder ONLY,
   (b) those models DO have the column, (c) legacy paths assign directly
   without the check, (d) the inconsistency causes the silent NULL bug.
2. Option B widens the mass-assignment surface, which is a security concern
   that needs separate review (Spatie validation rules, request-merging
   utilities, etc.).
3. The fix is 1-LOC and reversible.
4. Companion test added : assert `$order->reason === $reason` AND audit-row
   reason after `apply(... CANCELED, $user, 'reason')`.

---

## 8. LOCK feasibility (if Option A pursued)

- ≤3 LOC change ? **YES** (delete one `&& $locked->isFillable('reason')` clause)
- Architectural redesign ? **NO**
- Frozen file ? **YES** — `app/Domain/Order/OrderStateMachine.php`
- Owner gate ? **REQUIRED** — even a 3-LOC change to a frozen domain file
  needs a `LOCK_OSM_REASON_FILLABLE_<date>.md` per CLAUDE.md §7.

---

## 9. Verification plan (post-implement)

- Vitest : N/A (PHP backend only).
- PHPUnit new test :
  ```php
  public function test_apply_persists_reason_on_model_row(): void {
      $order = $this->makeOrder(OrderStatus::PENDING);
      OrderStateMachine::apply($order, OrderStatus::CANCELED, null, 'customer_walked');
      $order->refresh();
      $this->assertSame('customer_walked', $order->reason);  // NEW assertion
  }
  ```
- Regression : run `OrderStateMachineApplyTest`, `OrderStateMachineLockForUpdateTest`,
  `OrderServiceCancelTest`, and any `Order::create` mass-assignment tests.
- Frozen-zone diff = 1 deletion ; LOC delta = -1.

---

## 10. Owner sign-off

- [ ] APPLY-OPTION-A (drop gate ; LOCK required)
- [ ] APPLY-OPTION-B (mass-assignment widening ; security review required)
- [ ] APPLY-OPTION-C (both)
- [ ] DEFER-V1.0.2 (acceptable IF owner accepts NULL reason on kiosk auto-rejects)
- [x] **DEFER-V1.0.2 with audit-row reason fallback noted in BACKLOG**
       (recommended — low operational impact today, single callsite, mitigated
       by `order_status_transitions.reason` being populated)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 11. References

- `app/Domain/Order/OrderStateMachine.php` :234-237
- `app/Models/Order.php` :20-58 — fillable list (no `reason`)
- `app/Models/FrontendOrder.php` :36-73 — fillable list (no `reason`)
- `database/migrations/2022_11_17_110810_create_orders_table.php` :39 — schema has column
- `app/Services/OrderService.php` :1819, :1923 — legacy direct assignment
- `app/Services/FrontendOrderService.php` :736 — legacy direct assignment
- `tests/Feature/Domain/OrderStateMachineApplyTest.php` :88-100 — test that misses the bug
