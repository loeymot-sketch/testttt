# Plan v2 — extrait pertinent pour 1.B (split en 1.B.1)

## 1.B — F-01 + NEW-05 · Stock release idempotent, partial, line-item

### Sous-lot 1.B.1 (CE LOT) — pure addition
- Migration `add_release_tracking_to_order_items` : colonnes `released_qty INT DEFAULT 0`, `released_at TIMESTAMP NULL`.
- Event `app/Events/OrderCanceled.php`.
- Event `app/Events/RefundCreated.php` avec `refunded_items: [{order_item_id, qty}]`.
- Service `AvailabilityService::releaseForOrderItems(array $lineItems)`.
- Listener `ReleaseAvailabilityOnOrderCanceled.php`.
- Listener `ReleaseAvailabilityOnRefundCreated.php`.
- Câblage `EventServiceProvider`.
- Tests : full cancel, partial refund, idempotency.

### Sous-lot 1.B.2 (HORS SCOPE — orchestrateur) — call-site wiring (frozen zones)
- `FrontendOrderService::changeStatus` → dispatch OrderCanceled after-commit (gate frozen + parity OrderService).
- `PaymentService::cashBack` → dispatch RefundCreated (full ou partial) after-commit (gate LOCK_B_POS_9_2_3_PaymentService).

**Critère mesurable du sous-lot 1.B.1 :** les 4 tests passent en isolation avec dispatch direct des events (sans wiring call-site).
