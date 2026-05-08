# Plan – TASK_V1_EVENT_CONTRACT_001 – 2026-04-15

## TASK_ID
TASK_V1_EVENT_CONTRACT_001

## PRIMARY_MODEL
GPT-5.4 (complex — architecture multi-surface, event typing, contract enforcement)

## TEST_STRATEGY
local-validation

## PRIOR_CONTEXT
Graphiti unavailable — proceeding without prior context.
Dependency TASK_V1_OUTBOX_001 closed 2026-04-15. Outbox system in place:
- 3 listeners persist events to `domain_events` table
- `DispatchDomainEventsJob` broadcasts via Pusher using `$domainEvent->payload` directly
- Events (OrderCreated, OrderStatusChanged, ItemAvailabilityChanged) are now plain Dispatchable classes

## Design Decision: Envelope at broadcast time
The DispatchDomainEventsJob currently broadcasts `$domainEvent->payload` (the raw business data). This task wraps it in the canonical envelope `{version, type, aggregate_id, branch_id, occurred_at, correlation_id, payload}` at broadcast time. The job is modified to build the envelope from DomainEvent columns, so ALL broadcasts automatically conform to the contract. No listener changes needed.

## Design Decision: No ajv dependency
The task description suggests ajv for frontend validation. For V1, we use a lightweight `validateEnvelope()` function (no external dependency) that checks required keys and types. The JSON schema file is created as documentation/reference, not as a runtime dependency. This avoids adding ajv + ajv-formats to the bundle.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Enums/EventType.php` | New — enum of V1 event types | Write | No | No |
| `app/Jobs/DispatchDomainEventsJob.php` | Wrap payload in canonical envelope | Write | No | Yes |
| `app/Listeners/PersistOrderCreatedToOutbox.php` | Use EventType enum for event_type | Write | No | Yes |
| `app/Listeners/PersistOrderStatusChangedToOutbox.php` | Use EventType enum for event_type | Write | No | Yes |
| `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` | Use EventType enum for event_type | Write | No | Yes |
| `resources/js/services/eventContract.js` | New — parseEvent, validateEnvelope, onEvent helper | Write | No | No |
| `resources/js/services/eventContract.schema.json` | New — JSON schema documentation | Write | No | No |
| `resources/js/components/admin/pos/PosComponent.vue` | Use eventContract.onEvent | Write | No | No |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Use eventContract.onEvent | Write | No | No |
| `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` | Use eventContract.onEvent | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | Use eventContract.onEvent (ItemAvailability) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | Use eventContract.onEvent (OrderCreated, OrderStatusChanged) | Write | No | No |
| `docs/EVENT_CONTRACT.md` | New documentation | Write | No | No |
| `tests/Feature/EventContractTest.php` | New tests | Write | No | Yes |

## SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — frozen zone
- `app/Services/FrontendOrderService.php` — frozen zone
- `app/Models/DomainEvent.php` — read only (columns used but model not modified)
- `database/migrations/*` — no schema changes
- Pricing logic
- State machine

## INVARIANTS_AT_RISK
- **OrderStatus enum** — the payload uses integer status values from the OrderStatus enum (already the case from OUTBOX_001 listeners). The EventType enum is a NEW enum specific to event types — no conflict.
- **branch_id data isolation** — branch_id is explicitly in the canonical envelope. Channel routing remains `private-branch.{id}`. No cross-branch bleed.

## GATE_CONDITIONS
- None anticipated.
- Stop-gate if: proposal to add event types beyond V1 list.
- Stop-gate if: proposal to modify OrderService or FrontendOrderService.

## Execution Steps

### E1 — EventType enum
Create `app/Enums/EventType.php`:
```php
class EventType
{
    const ORDER_CREATED = 'order.created';
    const ORDER_STATUS_CHANGED = 'order.status_changed';
    const ORDER_ITEM_ADDED = 'order.item_added';
    const ORDER_CANCELLED = 'order.cancelled';
    const MENU_ITEM_AVAILABILITY_CHANGED = 'menu.item_availability_changed';
    const STOCK_LOW = 'stock.low';
}
```
Use class constants (not PHP 8.1 enum) for compatibility with the existing enum pattern in the project.

### E2 — Update listeners to use EventType constants
- `PersistOrderCreatedToOutbox`: change `'event_type' => OrderCreated::class` to `'event_type' => EventType::ORDER_CREATED`
- `PersistOrderStatusChangedToOutbox`: change to `EventType::ORDER_STATUS_CHANGED`
- `PersistItemAvailabilityChangedToOutbox`: change to `EventType::MENU_ITEM_AVAILABILITY_CHANGED`
Also add `'correlation_id' => (string) \Illuminate\Support\Str::uuid()` to each listener's create call if not already present. Also update `'broadcast_as'` to match the new event type format for consistency, but keep the old broadcast_as values for backward compatibility during transition. Actually — keep broadcast_as as-is (e.g., "OrderCreated") because the frontend currently listens for `.OrderCreated`. The envelope `type` field will carry the new dotted format.

### E3 — DispatchDomainEventsJob: wrap in canonical envelope
Modify `handle()` to broadcast the full envelope instead of raw payload:
```php
$envelope = [
    'version' => 1,
    'type' => $domainEvent->event_type,
    'aggregate_id' => $domainEvent->aggregate_id,
    'branch_id' => $domainEvent->branch_id,
    'occurred_at' => $domainEvent->occurred_at->toIso8601String(),
    'correlation_id' => $domainEvent->correlation_id,
    'payload' => $domainEvent->payload,
];
$connection->getPusher()->trigger($channels, $domainEvent->broadcast_as, $envelope);
```

### E4 — JSON schema file
Create `resources/js/services/eventContract.schema.json` with the canonical schema (documentation/reference).

### E5 — eventContract.js frontend service
Create `resources/js/services/eventContract.js`:
- `validateEnvelope(data)`: checks required keys (version, type, aggregate_id, branch_id, occurred_at, payload), logs warning on mismatch, returns boolean.
- `parseEvent(raw)`: calls validateEnvelope, returns `{ version, type, aggregateId, branchId, occurredAt, correlationId, payload }` (camelCase JS convention).
- `onEvent(branchId, eventType, handler)`: subscribes to `Echo.private('branch.{branchId}')`, listens for the corresponding broadcast_as name, validates via parseEvent, calls handler with parsed envelope. Returns unsubscribe function.
- `EVENT_TYPES` object exported with all V1 types.
- Maps broadcast_as names to event types: `OrderCreated` -> `order.created`, etc.

### E6 — Refactor POS surface
In `PosComponent.vue`, replace direct `Echo.private().listen()` calls with `onEvent()` from eventContract.js. The handler receives the parsed envelope and extracts `payload` as needed.

### E7 — Refactor KDS surface
In `KitchenDisplaySystemComponent.vue`, same pattern. Replace `.listen('.OrderStatusChanged', ...)` and `.listen('.OrderCreated', ...)` with `onEvent()` calls.

### E8 — Refactor OSS surface
In `PreparingAndReadyComponent.vue`, same pattern.

### E9 — Refactor Kiosk surfaces
- `KioskAppComponent.vue`: replace `.listen('.ItemAvailabilityChanged', ...)` with `onEvent()`.
- `KioskWaitingComponent.vue`: replace `.listen('.OrderCreated', ...)` and `.listen('.OrderStatusChanged', ...)`.

### E10 — Documentation
Create `docs/EVENT_CONTRACT.md`:
- Canonical envelope schema
- JSON example for each V1 event type
- Versioning rules
- How to add a new event type

### E11 — Tests
Create `tests/Feature/EventContractTest.php`:
- Test: OrderCreated listener persists event_type as EventType::ORDER_CREATED
- Test: DispatchDomainEventsJob broadcasts canonical envelope shape
- Test: envelope contains version=1, type, aggregate_id, branch_id, occurred_at, correlation_id, payload
- Test: all V1 event types are defined in EventType class

## SYMMETRY_NOTE
N/A — OrderService / FrontendOrderService not modified.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[x] Passed — cycle closed 2026-04-15
[ ] Gate opened — `docs/gates/GATE_TASK_V1_EVENT_CONTRACT_001_2026-04-15.md`
