# Gate Brief - Frozen Order Services Unlock For Product Composer Stock

Gate ID: `HG-FROZEN-ORDERSERVICE-UNLOCK`
Date drafted: 2026-04-27
Status: `PENDING_HUMAN_GATE`

## Decision Needed

Approve strict hunks in `OrderService` and `FrontendOrderService` for Stock V2 decrement/release once schema and stock service are approved.

## Allowed Future Hunks If Approved

- Resolve stock-tracked order elements after backend quote sealing.
- Call `StockService` inside the existing order transaction.
- Abort with 409 if atomic decrement fails.
- Preserve rollback semantics if quote/order creation fails.
- Add explicit `SYMMETRY_NOTE` for POS and kiosk order services.

## Explicitly Not Approved By This Draft

- Any pricing refactor.
- Any queue-number refactor.
- Any status-machine change.
- Any fiscal/NF525 sequence change.
- Any broad rewrite of order creation.

## Preconditions

- `HG-COMPOSER-SCHEMA-ADR` approved if composer step data is involved.
- `HG-STOCK-STOCKABLE-SCOPE` approved.
- Stock service tests green before order-service hunks.

## Invariants

- Backend pricing SSOT.
- OrderStatus enum only.
- Branch isolation.
- Dispatch after commit.
- POS/Kiosk symmetry required.

## Human Approval

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
