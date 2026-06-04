# FLOW 4 — Rupture Reverse Cascade Evidence

**Scenario** : Re-enable the item set rupture in FLOW 3 (`is_available=true`).
Verify the same cascade chain fires in reverse: backend toggle, event persisted,
Pusher dispatch within 2s.

## Measurements

| Phase | HTTP Status | Latency | Verdict |
|---|---|---|---|
| `POST /api/admin/menu/availability/toggle` (is_available=true) | 200 | (instant) | OK |
| `ItemAvailabilityChanged` event persisted | — | <1s | OK (eventId=1875) |
| Pusher dispatch | — | **1076ms** | **OK (under 2s budget)** |

## Latency Semantics

1076ms is server-side: time from `DomainEvent` insertion to `BroadcastManager::broadcast()`
call. Browser DOM-update latency not measured separately.

## Verdict

**GREEN.** Reverse cascade verified — Pusher dispatch within budget. The same listener
chain triggers regardless of `is_available` direction, confirming bidirectional symmetry.

## References

- Same anchors as FLOW 3 (toggle is direction-agnostic)
