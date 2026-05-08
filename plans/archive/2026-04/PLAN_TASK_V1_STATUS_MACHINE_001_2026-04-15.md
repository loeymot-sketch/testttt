# Plan – TASK_V1_STATUS_MACHINE_001 – 2026-04-15

## TASK_ID
TASK_V1_STATUS_MACHINE_001

## PRIMARY_MODEL
GPT-5.4 (complex — frozen zones, state machine, schema migration)

## TEST_STRATEGY
`local-validation` — PHPUnit: all legal + illegal transitions, integration with outbox.

## PRIOR_CONTEXT
- Outbox pattern (OUTBOX_001) is live — `OrderStatusChanged` events are intercepted by `PersistOrderStatusChangedToOutbox` listener and dispatched via `DispatchDomainEventsJob`. The state machine must continue to dispatch `OrderStatusChanged` so the outbox listener picks it up.
- Event contract (EVENT_CONTRACT_001) is live — events are wrapped in canonical envelope by the dispatch job. No change needed in the event dispatch call.
- `OrderStatus` is a PHP **interface** with integer constants (not a PHP 8.1 backed enum). Values: PENDING(1), ACCEPT(4), PREPARING(7), PREPARED(8), OUT_FOR_DELIVERY(10), DELIVERED(13), CANCELED(16), REJECTED(19), RETURNED(22).
- `ValidStatusTransition` rule already exists at `app/Rules/ValidStatusTransition.php` and encodes the current transition matrix. The state machine will supersede this rule as the authoritative guard.

## RECONCILIATION — Task vs Actual Enum
The task file uses idealized state names that don't match the codebase. Mapping:

| Task file state | Actual OrderStatus constant | Value |
|---|---|---|
| `draft` | *Does not exist* — orders are created directly as PENDING or ACCEPT | — |
| `pending` | `PENDING` | 1 |
| `preparing` | `PREPARING` | 7 |
| `ready` | `PREPARED` | 8 |
| `served` | `DELIVERED` | 13 |
| `completed` | *Does not exist* — DELIVERED is the terminal happy state | — |
| `cancelled` | `CANCELED` | 16 |

The state machine will use the **actual** OrderStatus constants. No new states are added (per task stop-gate: no new enum values in V1).

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Domain/Order/OrderStateMachine.php` | New — finite state machine with transition table | Write | No | No (delegates dispatch to caller) |
| `app/Domain/Order/IllegalTransitionException.php` | New — exception class | Write | No | No |
| `app/Models/OrderStatusTransition.php` | New — Eloquent model for audit trail | Write | No | No |
| `database/migrations/*_create_order_status_transitions_table.php` | **New migration** — audit trail table | Write | Yes (indexed via order→branch) | No |
| `app/Enums/OrderStatus.php` | Read only — no modifications | Read | No | No |
| `app/Rules/ValidStatusTransition.php` | Read only for reference — kept for backward compat, deprecated in favor of StateMachine | Read | No | No |
| `app/Services/OrderService.php` | **FROZEN — refactor `changeStatus`, `deliveryBoyOrderChangeStatus`** | Write | Yes | Yes (existing dispatch preserved) |
| `app/Services/FrontendOrderService.php` | **FROZEN — refactor `changeStatus`, `finalizePaidKioskOrder`** | Write | Yes | Yes (existing dispatch preserved) |
| `app/Services/KitchenDisplaySystemOrderService.php` | Refactor `changeStatus` to use StateMachine | Write | No | Yes (existing dispatch preserved) |
| `tests/Unit/Domain/Order/OrderStateMachineTest.php` | New — exhaustive transition tests | Write | No | No |
| `tests/Integration/StatusTransitionAuditTest.php` | New — integration test for audit trail + outbox | Write | No | No |
| `docs/ORDER_FLOW.md` | New — state diagram documentation | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Pricing logic — V1_PRICING_SSOT_001
- Menu availability — V1_MENU_86_001
- Outbox internals (DomainEvent, DispatchDomainEventsJob) — V1_OUTBOX_001 (completed, stable)
- Vue components — no UI changes
- Auth / middleware — out of scope

## INVARIANTS_AT_RISK
- **OrderStatus enum** — the invariant being enforced. The enum itself is NOT modified; the state machine formalizes its transition rules. Risk: if the transition table in the state machine doesn't match the existing `ValidStatusTransition` rule, some currently-legal transitions could be blocked. Mitigation: transition table derived directly from `ValidStatusTransition.php`.
- **Frozen zone** — OrderService + FrontendOrderService are edited. Gate brief required.
- **Dispatch after DB commit** — `OrderStatusChanged::dispatch` must remain after the DB transaction commits. The state machine runs `$order->save()` inside a transaction; event dispatch occurs after commit via existing `DB::afterCommit` / post-transaction patterns.
- **OrderService / FrontendOrderService symmetry** — both services must use the same `OrderStateMachine::transition()` call. Symmetry by construction.
- **branch_id data isolation** — `order_status_transitions` records are linked to orders which are branch-scoped. No cross-branch query introduced.

## GATE_CONDITIONS
- **Gate required: YES** (task says NO, but invariant rules override)
  - Trigger 1: Frozen zone edits (OrderService + FrontendOrderService)
  - Trigger 2: Schema migration (new `order_status_transitions` table)
- Gate brief: `docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md`
- Gate must be cleared by human before EXECUTE begins.

## Execution Steps

### E1 — Legal transition table (from actual codebase)

Derived from `ValidStatusTransition.php` + existing usage:

| From | Legal targets | Condition |
|---|---|---|
| `PENDING (1)` | `ACCEPT (4)`, `CANCELED (16)`, `REJECTED (19)` | — |
| `ACCEPT (4)` | `PREPARING (7)`, `CANCELED (16)` | POS operator: also `DELIVERED (13)` bypass |
| `PREPARING (7)` | `PREPARED (8)`, `CANCELED (16)` | POS operator: also `DELIVERED (13)` bypass |
| `PREPARED (8)` | `OUT_FOR_DELIVERY (10)`, `DELIVERED (13)` | — |
| `OUT_FOR_DELIVERY (10)` | `DELIVERED (13)` | — |
| `DELIVERED (13)` | `RETURNED (22)` | — |
| `CANCELED (16)` | *terminal* | Admin override: any state (audit-logged) |
| `REJECTED (19)` | *terminal* | Admin override: any state (audit-logged) |
| `RETURNED (22)` | *terminal* | Admin override: any state (audit-logged) |

Note: "Admin override" from terminal states is preserved from existing `ValidStatusTransition` behavior, but audit-logged with reason.

### E2 — Create Domain namespace (greenfield, no frozen zone)

1. Create `app/Domain/Order/` directory.
2. Create `app/Domain/Order/IllegalTransitionException.php`:
   - Extends `\DomainException`
   - Constructor: `(int $from, int $to)` with descriptive message.

3. Create `app/Domain/Order/OrderStateMachine.php`:
   ```php
   final class OrderStateMachine
   {
       private const TRANSITIONS = [
           OrderStatus::PENDING => [OrderStatus::ACCEPT, OrderStatus::CANCELED, OrderStatus::REJECTED],
           OrderStatus::ACCEPT => [OrderStatus::PREPARING, OrderStatus::CANCELED],
           OrderStatus::PREPARING => [OrderStatus::PREPARED, OrderStatus::CANCELED],
           OrderStatus::PREPARED => [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED],
           OrderStatus::OUT_FOR_DELIVERY => [OrderStatus::DELIVERED],
           OrderStatus::DELIVERED => [OrderStatus::RETURNED],
       ];

       public function transition(
           Order|FrontendOrder $order,
           int $newStatus,
           ?string $reason = null,
           ?User $actor = null,
           array $options = []
       ): void
       {
           $from = (int) $order->status;
           $to = $newStatus;

           if (!$this->isLegal($from, $to, $actor, $options)) {
               throw new IllegalTransitionException($from, $to);
           }

           DB::transaction(function () use ($order, $from, $to, $reason, $actor) {
               $order->status = $to;
               $order->save();
               OrderStatusTransition::create([...]);
           });
           // Event dispatch AFTER commit (existing pattern preserved)
       }

       private function isLegal(int $from, int $to, ?User $actor, array $options): bool
       {
           // Standard transitions
           if (in_array($to, self::TRANSITIONS[$from] ?? [], true)) return true;
           // POS bypass: ACCEPT/PREPARING → DELIVERED
           if (in_array($from, [OrderStatus::ACCEPT, OrderStatus::PREPARING])
               && $to === OrderStatus::DELIVERED
               && ($options['pos_bypass'] ?? false)) return true;
           // Admin override from terminal states
           if (in_array($from, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
               && $actor && $actor->hasRole('Admin')) return true;
           return false;
       }
   }
   ```

### E3 — Migration: `order_status_transitions` table

Create `database/migrations/2026_04_15_300000_create_order_status_transitions_table.php`:
- Columns: `id` (bigIncrements), `order_id` (unsignedBigInteger), `order_type` (string, for polymorphic Order/FrontendOrder), `from_status` (integer), `to_status` (integer), `actor_id` (nullable unsignedBigInteger), `actor_type` (nullable string), `reason` (nullable text), `correlation_id` (nullable uuid), `occurred_at` (timestamp).
- Indexes: `(order_id, order_type, occurred_at)`, `(occurred_at)`.
- No foreign key to orders table (polymorphic — Order + FrontendOrder).

### E4 — Eloquent model `OrderStatusTransition`

Create `app/Models/OrderStatusTransition.php`:
- `$fillable`: all columns.
- `$casts`: `occurred_at` → datetime, `from_status` → integer, `to_status` → integer.
- Relationship: `order()` → morphTo.

### E5 — Refactor OrderService (FROZEN ZONE)

In `app/Services/OrderService.php`, refactor these methods:

1. **`changeStatus`** (line ~1263): Replace direct `$order->status = $request->status; $order->save();` with `$this->stateMachine->transition($order, $request->status, ...)`. Keep existing notification dispatch (mail/SMS/push) after the transition call. Keep `OrderStatusChanged::dispatch` after transition.

2. **`deliveryBoyOrderChangeStatus`** (line ~1221): Same pattern — delegate to `$this->stateMachine->transition()` with `pos_bypass` option where applicable.

3. **Initial status in `create()` calls** (lines 291, 557, 885): These set status at creation time (`PENDING` or `ACCEPT`). These are NOT transitions — they are initial state. Leave them as-is; the state machine governs transitions, not initial creation.

4. Inject `OrderStateMachine` via constructor.

### E6 — Refactor FrontendOrderService (FROZEN ZONE)

1. **`changeStatus`** (line ~577): Replace `$frontendOrder->status = $request->status; $frontendOrder->save();` with `$this->stateMachine->transition(...)`.

2. **`finalizePaidKioskOrder`** (line ~508): `$this->frontendOrder->status = OrderStatus::ACCEPT` — this is a transition from PENDING → ACCEPT. Use `$this->stateMachine->transition($this->frontendOrder, OrderStatus::ACCEPT)`.

3. **Line ~678** (`$locked->status = OrderStatus::ACCEPT`): Same pattern.

4. Inject `OrderStateMachine` via constructor.

### E7 — Refactor KitchenDisplaySystemOrderService

1. **`changeStatus`** (line ~107): Replace `$order->status = $request->status; $order->save();` with `$this->stateMachine->transition(...)`. Keep notification and `OrderStatusChanged::dispatch` calls.

2. Inject `OrderStateMachine` via constructor.

### E8 — Tests

1. **`tests/Unit/Domain/Order/OrderStateMachineTest.php`**:
   - Test every legal transition from E1 table → passes.
   - Test every illegal transition → throws `IllegalTransitionException`.
   - Test POS bypass: ACCEPT → DELIVERED with `pos_bypass` → passes.
   - Test POS bypass: ACCEPT → DELIVERED without option → throws.
   - Test Admin override from terminal state → passes.
   - Test cancellation from PREPARING/PREPARED requires `reason` → passes/throws appropriately.
   - Minimum: 2× the number of states (18+ test cases).

2. **`tests/Integration/StatusTransitionAuditTest.php`**:
   - Legal transition → row created in `order_status_transitions`.
   - Legal transition → `OrderStatusChanged` event dispatched (intercepted by outbox listener → `domain_events` row).
   - Illegal transition → no row in either table, exception thrown.

### E9 — Documentation

Create `docs/ORDER_FLOW.md` with Mermaid state diagram using actual OrderStatus values.

### E10 — Deprecation note on ValidStatusTransition

Add a `@deprecated` docblock to `ValidStatusTransition.php` pointing to `OrderStateMachine`. Do NOT remove it (backward compat for any request validation still using it).

## SYMMETRY_NOTE
Both `OrderService` and `FrontendOrderService` delegate all status transitions to the same `OrderStateMachine::transition()` method. The polymorphic signature (`Order|FrontendOrder`) ensures the same transition table applies to both. `KitchenDisplaySystemOrderService` also uses the same state machine. Symmetry is structural.

Remaining asymmetry (not in scope):
- POS bypass (`ACCEPT/PREPARING → DELIVERED`) is only meaningful for POS-operated orders. For kiosk orders, the bypass is triggered by POS operators managing kiosk cash orders — same code path via `OrderService`.
- Admin override from terminal states — same rule for both Order and FrontendOrder.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md`
