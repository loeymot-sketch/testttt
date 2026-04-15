# Event Contract — FoodKing V1

## Canonical Envelope

All domain events broadcast to realtime clients MUST use this flat V1 envelope:

```json
{
  "version": 1,
  "type": "order.created",
  "aggregate_id": 123,
  "branch_id": 4,
  "occurred_at": "2026-04-15T20:30:00+00:00",
  "correlation_id": "7d6a5ed8-b5d6-41f7-9cfb-c6f32b4956cd",
  "payload": {}
}
```

Field descriptions:

| Field | Meaning |
|---|---|
| `version` | Contract version. V1 is always `1`. |
| `type` | Canonical dotted event name, used for durable integration semantics. |
| `aggregate_id` | Domain aggregate identifier affected by the event. |
| `branch_id` | Branch scope for the event, or `null` for cross-branch menu events. |
| `occurred_at` | ISO-8601 timestamp for the domain event occurrence. |
| `correlation_id` | UUID used to correlate retries, logs, and downstream processing. |
| `payload` | Existing business payload preserved as-is for frontend consumers. |

## V1 Event Types

| Event type | Meaning |
|---|---|
| `order.created` | A new order entered the system. |
| `order.status_changed` | An order changed lifecycle state. |
| `order.item_added` | Reserved V1 type for future order-line additions. |
| `order.cancelled` | Reserved V1 type for future cancellation broadcasts. |
| `menu.item_availability_changed` | A menu item status or price changed. |
| `stock.low` | Reserved V1 type for future stock alerts. |

## JSON Examples

`order.created`

```json
{
  "version": 1,
  "type": "order.created",
  "aggregate_id": 125,
  "branch_id": 2,
  "occurred_at": "2026-04-15T20:31:05+00:00",
  "correlation_id": "2ee2fc0b-3cc7-4fea-8722-d1b0dc947975",
  "payload": {
    "order_id": 125,
    "queue_number": "Q-125",
    "status": 1
  }
}
```

`order.status_changed`

```json
{
  "version": 1,
  "type": "order.status_changed",
  "aggregate_id": 125,
  "branch_id": 2,
  "occurred_at": "2026-04-15T20:33:10+00:00",
  "correlation_id": "e631d84f-e93c-4fb2-a626-9328514027be",
  "payload": {
    "order_id": 125,
    "old_status": 4,
    "new_status": 7
  }
}
```

`menu.item_availability_changed`

```json
{
  "version": 1,
  "type": "menu.item_availability_changed",
  "aggregate_id": 77,
  "branch_id": null,
  "occurred_at": "2026-04-15T20:35:42+00:00",
  "correlation_id": "4d64128d-c884-4dfd-b3cf-b75e3c4561f1",
  "payload": {
    "item_id": 77,
    "status": 1,
    "price": 12.5,
    "type": "status"
  }
}
```

## Versioning Rules

- V1 remains a flat envelope with the required top-level fields shown above.
- `broadcast_as` values stay unchanged for Echo subscriptions (`OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`).
- New event types require an explicit pull request that updates backend constants, frontend helpers, tests, and this document together.
- Backward-compatible payload additions are allowed inside `payload`; breaking envelope changes require a new `version`.

## Frontend Usage

Use `resources/js/services/eventContract.js` as the only frontend adapter for realtime domain events:

```js
import { onEvent, onEvents } from "../../../services/eventContract";
```

- `onEvent(branchId, broadcastAs, handler)` subscribes to one broadcast name.
- `onEvents(branchId, bindings)` subscribes multiple handlers on the same private branch channel.
- Handlers receive `{ version, type, aggregateId, branchId, occurredAt, correlationId, payload }`.
- Frontend code should read business values from `parsed.payload`, not from the top level.
