# D-PH2 - Data Ownership For POS/Kiosk/KDS/Dashboard Sync

Date: 2026-04-27
Status: ACCEPTED_FOR_TRAIN_B_KICKOFF
Task: CV2-PH2-01-DATA-OWNERSHIP-MATRIX

## Decision

FoodKing Phase 2 will use one explicit owner per business data family before
adding Dashboard control-plane writes or migrating POS/Kiosk menu consumers.

| Data family | Owner |
| --- | --- |
| Pricing and quote totals | `PricingService`, sealed by `OrderQuoteService`, committed by `OrderService` / `FrontendOrderService`. |
| Catalog item source fields | Admin catalog services (`ItemService` and related item composition services). |
| Menu read projection | `MenuProjectionService` after parity sentinels prove POS/Kiosk compatibility. |
| Branch availability | `AvailabilityService` and `item_branch_availability`. |
| Quantitative stock | Not accepted in V1/Train B yet; future dedicated stock service/table required. |
| Orders and queue number | `OrderService`, `FrontendOrderService`, D-M13 unique `(branch_id, business_date, queue_number)`. |
| Order lifecycle | `OrderStatus` enum and `OrderStateMachine`. |
| Payments | Payment services plus audited order payment methods, never UI-only state. |
| Realtime dispatch | Outbox listeners and `DispatchDomainEventsJob` after DB commit. |
| Dashboard roles | Future `backoffice-catalog` and `backoffice-ops`, branch-scoped. |

## Consequences

1. Dashboard is the control plane, but it does not become the pricing engine.
2. Kiosk is a customer surface and must not expose admin, POS, catalog, stock, or cashier access.
3. POS and Kiosk can have different UX, wizard, and design, but they must read the same backend-owned catalog/projection data once parity is proven.
4. Catalog write UI is blocked until catalog event/snapshot/cache coverage is proven.
5. Quantitative stock UI is blocked until a stock subsystem exists with atomic DB decrement and movement ledger.
6. Any delivery fee implementation must live in backend quote/order services. The human-requested rule is: 0-5 km = 5 EUR, then +1 EUR per km beyond 5 km.
7. New order status actions must call the backend state machine. Raw status strings from Dashboard or frontend are prohibited.
8. Realtime fanout must remain post-commit and branch-scoped.

## Implementation Gates

| Gate | Required before |
| --- | --- |
| Projection parity sentinel PASS | POS/Kiosk consumer migration. |
| Catalog snapshot/event coverage PASS | Dashboard catalog write UI. |
| Category branch scope ADR | Branch-specific category UI or migration. |
| Dashboard authz sentinel PASS | Any new catalog/ops write endpoint. |
| Stock subsystem migration signoff | Quantity stock manager. |
| Delivery quote tests | Pickup/delivery address and fee rollout. |

## Non-Goals

This ADR does not implement:

- Dashboard UI;
- stock quantity tables;
- catalog event classes;
- POS/Kiosk projection migration;
- payment simulation;
- delivery fee/address calculation;
- archive or deletion of legacy files.

Those remain separate Train B missions.
