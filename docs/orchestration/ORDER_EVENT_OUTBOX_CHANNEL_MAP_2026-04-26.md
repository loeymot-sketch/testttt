# Order Event Outbox Channel Map — 2026-04-26

Scope: Caisse V1 Wave 2 D-02 + B5b counter payment lifecycle. This file maps the order lifecycle/payment events that drive POS, KDS and OSS realtime projections.

## Invariants

- Order realtime events are plain domain events, not direct `ShouldBroadcastNow` events.
- Event dispatch uses `DispatchableAfterCommit`, so listeners run only after the surrounding DB transaction commits.
- Outbox listener persistence happens before queue dispatch; `DispatchDomainEventsJob` is scheduled with `DB::afterCommit()`.
- Branch isolation is carried by `domain_events.branch_id` and by the stored Echo channel `private-branch.{branch_id}`.
- Broadcast payload is read from the persisted `domain_events.payload`, not recomputed by websocket consumers.

## Map

| Domain event | Listener | `domain_events.event_type` | Aggregate | Branch source | Stored channel | `broadcast_as` | Required payload keys |
|---|---|---|---|---|---|---|---|
| `App\Events\OrderCreated` | `App\Listeners\PersistOrderCreatedToOutbox` | `order.created` | `get_class($order)` + `$order->id` | `$order->branch_id` | `["private-branch.{branch_id}"]` | `OrderCreated` | `order_id`, `queue_number`, `status`, `order_type`, `total`, `created_at` |
| `App\Events\OrderStatusChanged` | `App\Listeners\PersistOrderStatusChangedToOutbox` | `order.status_changed` | `get_class($order)` + `$order->id` | `$order->branch_id` | `["private-branch.{branch_id}"]` (+ `"private-customer.{user_id}"` si `source_surface==='web'`) | `OrderStatusChanged` | `order_id`, `queue_number`, `old_status`, `new_status`, `token` |
| `App\Events\OrderPaidAtCounter` | `App\Listeners\PersistOrderPaidAtCounterToOutbox` | `order.payment_confirmed` | `get_class($order)` + `$order->id` | `$order->branch_id` | `["private-branch.{branch_id}"]` | `OrderPaidAtCounter` | `order_id`, `queue_number`, `_origin`, `payment_method`, `payment_status`, `fiscal_sequence_no` |

### [C3-CLIENT-NOTIF 2026-07-18] Canal client — notification « prête »

`PersistOrderStatusChangedToOutbox::resolveChannels()` construit le tableau de canaux :
il inclut TOUJOURS le canal staff `private-branch.{branch_id}` (sync caisse/KDS/OSS, inchangé)
et, pour une commande WEB (`source_surface==='web'` + `user_id>0`), y AJOUTE le canal privé
du client `private-customer.{user_id}`. Le même événement `OrderStatusChanged` (contrat/enveloppe
inchangés) est donc diffusé aux deux canaux via l'outbox existant (retry/dedup réutilisés).

- Canal client déclaré dans `routes/channels.php` : `customer.{customerId}`, autorisé par
  `(int)$user->id === (int)$customerId` (identitaire, inspoofable, sans requête DB — anti-fuite
  cross-client ; chaque commande web/invité a un `user_id` unique).
- Fallback robuste : `/api/frontend/order(/show)` expose `status`/`status_name` (PREPARED = « Prête »)
  → le compte client bascule « en cours » → « prête » même sans WebSocket.
- Commandes borne (user = machine) / POS (user = staff/null) : PAS de canal client (par design).

## Registration

`app/Providers/EventServiceProvider.php` must keep:

- `OrderCreated::class => [SendFcmOnOrderCreated::class, PersistOrderCreatedToOutbox::class, DecrementItemAvailabilityOnOrder::class]`
- `OrderStatusChanged::class => [AwardLoyaltyPointsOnDelivery::class, SendFcmOnOrderStatusChange::class, PersistOrderStatusChangedToOutbox::class]`
- `OrderPaidAtCounter::class => [PersistOrderPaidAtCounterToOutbox::class]`

The outbox listeners are intentionally registered alongside legacy non-broadcast side effects. Realtime delivery must remain durable through the outbox even if FCM or other notification layers fail independently.

## Consumer Contract

Frontend code subscribes to the branch channel and receives the canonical envelope built by `App\Domain\Events\EventContract` in `DispatchDomainEventsJob`:

- channel: `private-branch.{branch_id}`
- event names: `OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`
- canonical event types: `order.created`, `order.status_changed`, `order.payment_confirmed`

Consumers must treat the database-backed API as source of truth and use realtime events only as invalidation/projection signals.

## Regression Test

`tests/Feature/AfterCommitDispatchTest.php` locks:

- `DispatchableAfterCommit` on the order event classes;
- listener registration in `EventServiceProvider`;
- listener mapping to event type, channel, broadcast name and `DB::afterCommit()`;
- this channel map remaining present as the D-02 contract artifact.
