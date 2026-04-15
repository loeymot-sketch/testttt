# Outbox Pattern — FoodKing V1

## Overview

FoodKing now persists broadcastable domain events in `domain_events` before any
real-time push is attempted.

This replaces direct `ShouldBroadcastNow` delivery for core order and item events.

Why this change exists:
- Prevent lost real-time updates when a transaction rolls back.
- Prevent race conditions where a websocket event arrives before DB commit.
- Add retry visibility for failed broadcasts.
- Give operators a durable audit trail for pending and failed dispatches.

## Architecture

Text sequence diagram:

1. Service saves `Order`, `FrontendOrder`, or `Item` state.
2. Service dispatches a plain Laravel event such as `OrderCreated`.
3. Listener receives the event inside the current DB transaction.
4. Listener inserts one row into `domain_events`.
5. Listener registers `DispatchDomainEventsJob` with `DB::afterCommit()`.
6. Transaction commits successfully.
7. Queue worker runs `DispatchDomainEventsJob`.
8. Job loads the persisted row and increments `attempts`.
9. Job broadcasts to the stored Pusher channel list.
10. Job marks `dispatched_at` on success.
11. If retries are exhausted, `last_error` keeps the final failure message.

Key design rules:
- Persistence happens before queue dispatch.
- Queue dispatch happens after commit only.
- Channels are stored as a JSON array string to support one-to-many fanout.
- Broadcast payload comes from persisted `payload`, not re-derived state.

## How To Add A New Domain Event

1. Create or update a plain event class in `app/Events`.
2. Do not implement `ShouldBroadcast` or `ShouldBroadcastNow`.
3. Keep only the constructor and public properties needed by listeners.
4. Create a listener in `app/Listeners` that maps event data to:
   - `event_type`
   - `aggregate_type`
   - `aggregate_id`
   - `branch_id`
   - `payload`
   - `channel`
   - `broadcast_as`
   - `occurred_at`
5. Register the listener in `app/Providers/EventServiceProvider.php`.
6. Persist the `domain_events` row inside the listener.
7. Dispatch `DispatchDomainEventsJob` using `DB::afterCommit()`.
8. Add a focused feature test for persistence and retry behavior.

## Debugging

Useful SQL queries:

```sql
select id, event_type, aggregate_type, aggregate_id, attempts, dispatched_at, last_error
from domain_events
order by id desc
limit 50;

select *
from domain_events
where dispatched_at is null
order by occurred_at asc;

select id, channel, broadcast_as, payload
from domain_events
where event_type = 'App\\Events\\OrderCreated'
order by id desc;
```

Operational commands:

```bash
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
```

What to inspect:
- `attempts` increases on each delivery attempt.
- `dispatched_at` stays `null` until a successful push.
- `last_error` contains the final failure message after retries.
- `channel` is a JSON array string, even for a single branch.

## Schema Reference

`domain_events` columns:
- `event_type`: PHP event class name.
- `aggregate_type`: model class or aggregate label.
- `aggregate_id`: aggregate primary key.
- `branch_id`: branch isolation marker when applicable.
- `payload`: JSON payload sent to the websocket consumer.
- `channel`: JSON array string of target Pusher channels.
- `broadcast_as`: frontend event name, such as `OrderCreated`.
- `correlation_id`: optional trace identifier.
- `occurred_at`: domain event timestamp.
- `dispatched_at`: successful delivery timestamp.
- `attempts`: retry counter.
- `last_error`: final error text after retries.
- `created_at` / `updated_at`: persistence metadata.
