# Gate Brief – TASK_V1_STATUS_MACHINE_001 – 2026-04-15

## Trigger
Two hard gate triggers per `human-gates.mdc`:
1. **Frozen zone edits** — `OrderService.php` and `FrontendOrderService.php` will have their `changeStatus`-related methods refactored to delegate to `OrderStateMachine`.
2. **Schema migration** — new `order_status_transitions` audit trail table.

Note: the task file states "Gate requise: NON" but `project-invariants.mdc` §6 (Frozen Zones) and `human-gates.mdc` (Schema migration) override.

## Affected Subsystems
| Subsystem | Change |
|---|---|
| `app/Domain/Order/OrderStateMachine.php` | New — transition table + guard |
| `app/Domain/Order/IllegalTransitionException.php` | New — exception |
| `app/Models/OrderStatusTransition.php` | New — audit trail model |
| `database/migrations/*_create_order_status_transitions_table.php` | **New table** — audit trail |
| `app/Services/OrderService.php` | **FROZEN** — `changeStatus`, `deliveryBoyOrderChangeStatus` refactored |
| `app/Services/FrontendOrderService.php` | **FROZEN** — `changeStatus`, `finalizePaidKioskOrder` refactored |
| `app/Services/KitchenDisplaySystemOrderService.php` | `changeStatus` refactored |
| `app/Enums/OrderStatus.php` | **NOT modified** — read only |
| `app/Rules/ValidStatusTransition.php` | **NOT modified** — deprecated docblock only |

## Invariants at Risk
- **OrderStatus enum** — enforced (not modified, transitions formalized).
- **Frozen zones** — edits scoped to status-transition methods only; pricing, item creation, event dispatch logic untouched.
- **Dispatch after DB commit** — `OrderStatusChanged::dispatch` stays after transaction; state machine handles `$order->save()` + audit log inside `DB::transaction()`, event dispatch after.
- **OrderService / FrontendOrderService symmetry** — both delegate to the same `OrderStateMachine::transition()`.

## Decision Required
Approve:
1. Controlled refactoring of `changeStatus`-family methods in frozen services to delegate to `OrderStateMachine`.
2. Creation of `order_status_transitions` audit trail table (new migration).

---

## Current transition matrix (from `ValidStatusTransition.php`)

| From | Legal targets | Special condition |
|---|---|---|
| PENDING (1) | ACCEPT (4), CANCELED (16), REJECTED (19) | — |
| ACCEPT (4) | PREPARING (7), CANCELED (16) | + DELIVERED (13) if POS operator |
| PREPARING (7) | PREPARED (8), CANCELED (16) | + DELIVERED (13) if POS operator |
| PREPARED (8) | OUT_FOR_DELIVERY (10), DELIVERED (13) | — |
| OUT_FOR_DELIVERY (10) | DELIVERED (13) | — |
| DELIVERED (13) | RETURNED (22) | — |
| CANCELED (16) | *terminal* | Admin: any state (override) |
| REJECTED (19) | *terminal* | Admin: any state (override) |
| RETURNED (22) | *terminal* | Admin: any state (override) |

The new `OrderStateMachine` encodes **exactly this table** — no transitions added or removed.

---

## Schema: `order_status_transitions` table

| Column | Type | Notes |
|---|---|---|
| id | bigint auto | PK |
| order_id | bigint unsigned | FK-like (polymorphic) |
| order_type | string | `App\Models\Order` or `App\Models\FrontendOrder` |
| from_status | integer | OrderStatus value before |
| to_status | integer | OrderStatus value after |
| actor_id | bigint unsigned, nullable | Who triggered the change |
| actor_type | string, nullable | User, KioskMachine, system |
| reason | text, nullable | Required for some cancellation flows |
| correlation_id | uuid, nullable | From X-Correlation-ID header |
| occurred_at | timestamp | When the transition happened |

Indexes: `(order_id, order_type, occurred_at)`, `(occurred_at)`.

Estimated storage: ~100 bytes/row × 200 orders/day × 3 transitions avg = ~60KB/day. Negligible.

---

## Frozen zone change scope

### OrderService.php — what changes
- `changeStatus()` (~L1263): `$order->status = $request->status` → `$this->stateMachine->transition($order, $request->status, ...)`.
- `deliveryBoyOrderChangeStatus()` (~L1221): same pattern.
- Constructor: add `OrderStateMachine` injection.
- **NOT changed**: `myOrderStore`, `posOrderStore`, `tableOrderStore` initial status assignment at order creation, pricing logic, event dispatch, notification dispatch.

### FrontendOrderService.php — what changes
- `changeStatus()` (~L577): same refactor pattern.
- `finalizePaidKioskOrder()` (~L508): `$this->frontendOrder->status = OrderStatus::ACCEPT` → `$this->stateMachine->transition(...)`.
- Line ~678: `$locked->status = OrderStatus::ACCEPT` → same.
- Constructor: add `OrderStateMachine` injection.
- **NOT changed**: `myOrderStore` initial creation, pricing logic, loyalty logic, event dispatch.

---

## Rollback Plan

| Level | Action | Time |
|---|---|---|
| **Migration rollback** | `php artisan migrate:rollback --step=1` drops `order_status_transitions` | < 1 min |
| **Code revert** | `git revert` the merge commit; services fall back to direct `$order->status =` assignment | < 5 min |
| **Partial** | `OrderStateMachine` is injected — can be replaced with a passthrough that just does `$order->status = $new; $order->save()` | < 10 min |

No feature flag needed for this task (unlike PRICING_SSOT) because the transition table matches the existing `ValidStatusTransition` rule exactly — the behavior is identical, just formalized and audit-logged.

---

## Options
1. **Approve** — frozen zone edits + migration proceed under conditions above.
2. **Approve with constraint** — approve but require keeping `ValidStatusTransition` as a secondary validation layer (belt-and-suspenders).
3. **Cancel cycle** — do not proceed.

## Approval
- [ ] Approved — option selected: ___
- [ ] Cancelled

**Approver:** _______________
**Date:** _______________
