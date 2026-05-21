# FLOW 2 — Order Status Change Cascade Evidence

**Scenario** : Pick a recent order in ACCEPT (4) or PREPARING (7), bump it via
`POST /api/admin/pos-order/change-status/{order}` and verify `OrderStatusChanged`
DomainEvent propagates via Pusher within 2s.

## Note on Status Transitions

Initially attempted `status=3` which returned 422 "Transition de statut invalide".
Root cause: `OrderStatus` enum values are PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8
(per `app/Enums/OrderStatus.php`). Valid transitions:
- PENDING (1) → ACCEPT (4)
- ACCEPT (4) → PREPARING (7) | CANCELED (16)
- PREPARING (7) → PREPARED (8) | CANCELED (16)

The spec now dynamically picks the next valid status based on the target order's current status.

## Measurements

| Phase | HTTP Status | Latency | Verdict |
|---|---|---|---|
| `POST /api/admin/pos-order/change-status/{id}` | 200 | (instant) | OK |
| `OrderStatusChanged` DomainEvent persisted | — | <500ms | OK |
| Pusher dispatch | — | **278ms** | **OK (well under 2s budget)** |

## Latency Semantics

278ms is the server-side latency: time from `DomainEvent` insertion to
`BroadcastManager::broadcast()` call. Browser-side DOM update latency not measured
separately in this flow.

## Verdict

**GREEN.** OrderStatusChanged cascade verified — 278ms Pusher dispatch latency.

## References

- Route: `routes/api.php:856` — `/api/admin/pos-order/change-status/{order}`
- Controller: `app/Http/Controllers/Admin/PosOrderController.php::changeStatus`
- State machine: `app/Domain/Order/OrderStateMachine.php`
- Listener: `app/Listeners/PersistOrderStatusChangedToOutbox.php`
