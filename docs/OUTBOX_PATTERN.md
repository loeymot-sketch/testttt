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
7. Queue worker runs `DispatchDomainEventsJob` (queue `high`).
8. **Phase 1 — CLAIM.** Under a row lock, the job stamps `dispatched_at` and
   increments `attempts`. `dispatched_at` is the mark of a claim, **not** of a
   delivery: it is written *before* anything is broadcast.
9. **Phase 2 — BROADCAST.** Outside any transaction, the job validates the
   envelope and broadcasts to the stored Pusher channel list.
10. **Phase 3a — PROOF OF DELIVERY.** Only once the broadcast has returned does
    the job stamp `broadcast_at` and clear `last_error`.
11. **Phase 3b — RELEASE.** On failure the job clears `dispatched_at` (releasing
    the claim so the retry curve can re-attempt) and writes `last_error` — from
    the FIRST of the `$tries` attempts, not only at the end.
12. If the retries are exhausted, `failed()` keeps the final message in
    `last_error` and the job lands in `failed_jobs`.

Key design rules:
- Persistence happens before queue dispatch.
- Queue dispatch happens after commit only.
- Channels are stored as a JSON array string to support one-to-many fanout.
- Broadcast payload comes from persisted `payload`, not re-derived state.
- **`broadcast_at` is the ONLY proof of delivery.** `dispatched_at` proves a
  worker took the row, nothing more. Every consumer — rescue, monitor, prune,
  the admin cockpit — reads `broadcast_at`. Reading `dispatched_at` instead
  makes a worker killed between phases 1 and 2 look like a successful delivery,
  and the row then disappears from the "pending" population without ever having
  reached a client. Measured on the served database on 2026-09-02: 2 149 rows
  claimed and never broadcast.
- **`last_error` alone does not mean "terminal".** It is written on the first
  failed attempt. A row is terminal when `attempts >= DispatchDomainEventsJob::$tries`
  (6), or as soon as the error is prefixed `contract_violation:` — that one
  short-circuits via `$this->fail()` because a malformed payload never heals by
  being replayed.

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
select id, event_type, aggregate_type, aggregate_id, attempts,
       dispatched_at, broadcast_at, last_error
from domain_events
order by id desc
limit 50;

-- never claimed = genuinely waiting
select *
from domain_events
where dispatched_at is null
order by occurred_at asc;

-- claimed but NEVER delivered: the population that used to be invisible.
-- 10 minutes = the same threshold as OutboxRescueCommand lane B and as the
-- admin cockpit (SyncOverviewController::CLAIM_STALE_MINUTES).
select id, event_type, attempts, dispatched_at, last_error
from domain_events
where dispatched_at is not null
  and broadcast_at is null
  and dispatched_at < now() - interval 10 minute
order by dispatched_at desc;

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
- `dispatched_at` is set at CLAIM time and cleared again when the attempt fails.
  It is **not** a delivery marker — do not read it as one.
- `broadcast_at` stays `null` until a broadcast has actually succeeded. This is
  the delivery marker.
- `last_error` holds the last failure message; it appears from the first failed
  attempt onwards, and the job clears it on the next success.
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
- `dispatched_at`: CLAIM timestamp — set before the broadcast, cleared again if
  the attempt fails. Not a delivery marker.
- `broadcast_at`: DELIVERY timestamp — set only after a broadcast succeeded
  (migration `2026_08_04_120000_add_broadcast_at_to_domain_events`). This is the
  only column that proves a client could have received the event.
- `attempts`: retry counter, monotonic (a manual replay does not reset it).
- `last_error`: last error text; written from the first failed attempt.
- `created_at` / `updated_at`: persistence metadata.

## Order Event Channel Map

The Caisse V1 order realtime map is documented in
`docs/orchestration/ORDER_EVENT_OUTBOX_CHANNEL_MAP_2026-04-26.md`.

Current order lifecycle mappings:

| Event | Listener | Channel | Broadcast name |
|---|---|---|---|
| `App\Events\OrderCreated` | `App\Listeners\PersistOrderCreatedToOutbox` | `private-branch.{branch_id}` | `OrderCreated` |
| `App\Events\OrderStatusChanged` | `App\Listeners\PersistOrderStatusChangedToOutbox` | `private-branch.{branch_id}` | `OrderStatusChanged` |

Any change to these listeners, channels, or broadcast names must update the map
and keep `tests/Feature/AfterCommitDispatchTest.php` green.

## Delivery states, as the cockpit counts them

`GET /api/admin/observability/outbox` publishes five **disjoint** populations.
They are defined here so a second screen cannot invent a sixth.

| State | Predicate | Meaning |
|---|---|---|
| `pending` | `dispatched_at IS NULL` | never claimed by a worker |
| `in_flight` | `dispatched_at` set, `broadcast_at IS NULL`, claimed < 10 min ago | a worker holds it right now |
| `stale_claimed` | same, claimed >= 10 min ago | orphan: the worker died between claim and broadcast |
| `delivered_24h` | `broadcast_at` within 24 h | actually broadcast |
| `terminal_failures` | pending, `last_error` set, and (`attempts >= $tries` **or** `contract_violation:`) | no automatic retry will come |

Two further counters exist so that each button reflects what it actually does —
they are **actions**, not states, and they must never be swapped for one another:

| Counter | Table | Drives |
|---|---|---|
| `replayable_events` | `domain_events` | the **Replay** button: pending + `last_error` + not a contract violation + within the 7-day age window — exactly `outboxRetryFailed`'s own selection |
| `purgeable_failed_jobs` | `failed_jobs` | the **Purge** button: `DispatchDomainEventsJob` rows older than 24 h — exactly `outboxDrainFailed`'s own selection, computed by the same helper |

Until 2026-09-03 the Purge button was driven by `terminal_failures` — a count of
`domain_events`, while the action deletes `failed_jobs`. It announced "5 will be
deleted" and deleted nothing.
