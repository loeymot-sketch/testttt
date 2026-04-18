# Plan – TASK_V1_OUTBOX_001 – 2026-04-15

## TASK_ID
TASK_V1_OUTBOX_001

## PRIMARY_MODEL
GPT-5.4 (complex — migrations, transactional dispatch, jobs, scheduler)

## TEST_STRATEGY
local-validation

## PRIOR_CONTEXT
Graphiti unavailable — proceeding without prior context.
Dependency TASK_V1_SYNC_BACKBONE_001 closed 2026-04-15 (broadcast driver hardened, queue boot guards active).

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `database/migrations/2026_04_15_create_domain_events_table.php` | New migration — domain_events table | Write | Yes (indexed) | Yes |
| `app/Models/DomainEvent.php` | New Eloquent model for outbox table | Write | Yes (column) | Yes |
| `app/Traits/HasDomainEvents.php` | New trait for aggregate models | Write | No | Yes |
| `app/Jobs/DispatchDomainEventsJob.php` | New job — broadcasts persisted events | Write | No | Yes |
| `app/Console/Commands/OutboxRescueCommand.php` | New — re-queues stale pending events | Write | No | Yes |
| `app/Console/Commands/OutboxRetryFailedCommand.php` | New — retries exhausted events | Write | No | Yes |
| `app/Console/Kernel.php` | Add scheduler entry for outbox:rescue | Write | No | Yes |
| `app/Events/OrderCreated.php` | Remove ShouldBroadcastNow, keep as plain event (outbox handles broadcast) | Write | No | Yes |
| `app/Events/OrderStatusChanged.php` | Remove ShouldBroadcastNow, keep as plain event | Write | No | Yes |
| `app/Events/ItemAvailabilityChanged.php` | Remove ShouldBroadcastNow, keep as plain event | Write | No | Yes |
| `app/Models/Order.php` | Add `use HasDomainEvents;` | Write (trait addition only) | No | No |
| `app/Models/FrontendOrder.php` | Add `use HasDomainEvents;` | Write (trait addition only) | No | No |
| `docs/OUTBOX_PATTERN.md` | New documentation | Write | No | No |
| `tests/Feature/OutboxTest.php` | New integration test | Write | No | Yes |
| `tests/Unit/HasDomainEventsTest.php` | New unit test for trait | Write | No | Yes |

## SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — **frozen zone**. Event dispatches in this file stay as-is (they call `OrderCreated::dispatch()` etc. — the event class itself changes, not the dispatch call sites).
- `app/Services/FrontendOrderService.php` — **frozen zone**. Same rationale.
- `app/Services/KitchenDisplaySystemOrderService.php` — dispatch calls stay, event class changes transparently.
- `app/Services/ItemService.php` — dispatch call stays, event class changes transparently.
- Pricing logic — hors scope.
- State machine — hors scope.

## INVARIANTS_AT_RISK
- **branch_id data isolation** — `domain_events` table has `branch_id` column with index. Events are always persisted with the aggregate's `branch_id`. The rescue/retry commands do NOT filter by branch (they process all pending events globally), which is correct since dispatch targets `private-branch.{id}` per the event's own channel logic. Risk is LOW — verified by design, no cross-branch data bleed.
- **Dispatch after DB commit** — This is the core purpose of the task. The trait's `afterCommit` hook persists events in the same transaction. The `DispatchDomainEventsJob` is pushed to queue AFTER commit. If the transaction rolls back, the domain_events rows are also rolled back. This STRENGTHENS the invariant.

## GATE_CONDITIONS
- None anticipated.
- Stop-gate if: any proposal to modify OrderService.php or FrontendOrderService.php dispatch call sites.
- Stop-gate if: proposal to remove the existing polling fallback in frontend surfaces.

## Execution Steps

### E1 — Migration: `domain_events` table
Create `database/migrations/2026_04_15_HHMMSS_create_domain_events_table.php`:
```
Schema: domain_events
- id: bigIncrements
- event_type: string(128), indexed
- aggregate_type: string(128)
- aggregate_id: unsignedBigInteger
- branch_id: unsignedBigInteger, nullable
- payload: json
- channel: string(128), nullable (Pusher channel name for broadcast)
- broadcast_as: string(128), nullable (event name for Pusher)
- correlation_id: char(36), nullable
- occurred_at: dateTime(3)
- dispatched_at: dateTime(3), nullable
- attempts: unsignedSmallInteger, default 0
- last_error: text, nullable
- timestamps()
Indices: idx_pending(dispatched_at, occurred_at), idx_aggregate(aggregate_type, aggregate_id), idx_branch(branch_id, occurred_at)
```

### E2 — DomainEvent Eloquent model
`app/Models/DomainEvent.php`:
- Fillable: event_type, aggregate_type, aggregate_id, branch_id, payload, channel, broadcast_as, correlation_id, occurred_at, dispatched_at, attempts, last_error.
- Cast: payload → array, occurred_at → datetime, dispatched_at → datetime.
- Scope `pending()`: where dispatched_at is null.
- Scope `stale(int $minutes = 2)`: pending + created_at < now() - $minutes.
- Scope `failed(int $maxAttempts = 4)`: pending + attempts >= $maxAttempts.

### E3 — HasDomainEvents trait
`app/Traits/HasDomainEvents.php`:
- Property: `protected array $pendingDomainEvents = []`.
- Method: `recordDomainEvent(string $eventType, array $payload, ?string $channel = null, ?string $broadcastAs = null)`.
- Boot hook: registers an Eloquent `saved` observer with `afterCommit` that flushes `$pendingDomainEvents` into `domain_events` table, then pushes `DispatchDomainEventsJob` for each onto queue `high`.
- The trait reads `$this->branch_id` if it exists on the model for the `branch_id` column.

### E4 — DispatchDomainEventsJob
`app/Jobs/DispatchDomainEventsJob.php`:
- Queue: `high`, connection: config default (redis in prod).
- Constructor: `int $domainEventId`.
- `$backoff = [1, 5, 30, 300]`.
- `$tries = 5`.
- `handle()`: load DomainEvent by id. If already dispatched, return. Broadcast via `Broadcast::event()` using the stored channel + payload. Mark `dispatched_at = now()`. Increment attempts.
- `failed(Throwable $e)`: update `last_error`, log warning.

### E5 — Migrate existing broadcast events
For each of the 3 `ShouldBroadcastNow` events:

1. **OrderCreated.php**: Remove `implements ShouldBroadcastNow`. Remove `broadcastOn()`, `broadcastAs()`, `broadcastWith()`. Keep `Dispatchable` trait. Add a static method `outboxPayload(BroadcastableOrder $order): array` that returns the data needed. The services will NOT be modified — instead, listeners or the HasDomainEvents trait on Order/FrontendOrder will persist the outbox row when the event is dispatched.

**Revised approach** (simpler, no frozen-zone contact): Keep the existing `::dispatch()` calls in OrderService/FrontendOrderService untouched. Instead:
- Create Laravel **event listeners** that listen to `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`.
- Each listener persists a row in `domain_events` and pushes `DispatchDomainEventsJob`.
- The events themselves lose `ShouldBroadcastNow` so Laravel no longer auto-broadcasts them.
- This decouples the outbox from the service layer entirely.

New files:
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- Register in `app/Providers/EventServiceProvider.php`

2. **OrderStatusChanged.php**: Same pattern.
3. **ItemAvailabilityChanged.php**: Same pattern. Channel logic (all active branches) moves into the listener.

### E6 — Scheduler rescue command
`app/Console/Commands/OutboxRescueCommand.php`:
- Signature: `foodking:outbox:rescue`
- Selects `domain_events` where `dispatched_at IS NULL AND created_at < now() - 2 minutes AND attempts < 5`.
- Re-dispatches `DispatchDomainEventsJob` for each.
- Registered in `Kernel::schedule()` as `->everyMinute()->withoutOverlapping()`.

### E7 — Retry failed command
`app/Console/Commands/OutboxRetryFailedCommand.php`:
- Signature: `foodking:outbox:retry-failed {--since=1h}`
- Resets `attempts=0, last_error=null, dispatched_at=null` for matching failed events.
- Re-dispatches each.

### E8 — HasDomainEvents on Order + FrontendOrder
Add `use HasDomainEvents;` to `Order.php` and `FrontendOrder.php`. This enables future direct `$order->recordDomainEvent(...)` calls but is NOT required for this cycle (listeners handle it). Adding the trait now prepares the models for EVENT_CONTRACT_001.

### E9 — Documentation
Create `docs/OUTBOX_PATTERN.md`:
- Sequence diagram (text): Order create → event dispatched → listener → domain_events INSERT (same txn) → DispatchDomainEventsJob → Pusher.
- How to add a new event.
- Debugging: inspect `domain_events` table, retry command.
- Schema reference.

### E10 — Tests
1. `tests/Feature/OutboxTest.php`:
   - Test: OrderCreated dispatch → domain_events row created with correct payload.
   - Test: DB transaction rollback → domain_events table empty.
   - Test: DispatchDomainEventsJob marks dispatched_at.
   - Test: Job retry increments attempts.
   - Test: rescue command re-queues stale events.
   - Test: retry-failed command resets and re-queues.
2. `tests/Unit/HasDomainEventsTest.php`:
   - Test trait `recordDomainEvent` method on a mock model.

## SYMMETRY_NOTE
N/A — OrderService and FrontendOrderService are NOT modified. Event listeners intercept events transparently. The services continue to call `OrderCreated::dispatch()` etc. unchanged.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[x] Passed — cycle closed 2026-04-15
[ ] Gate opened — `docs/gates/GATE_TASK_V1_OUTBOX_001_2026-04-15.md`
