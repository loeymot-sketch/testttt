# Gate Brief - Stock Stockable Scope

Gate ID: `HG-STOCK-STOCKABLE-SCOPE`
Date drafted: 2026-04-27
Status: `PENDING_HUMAN_GATE`

## Decision Needed

Choose the stock model for FoodKing Product Composer and POS/kiosk stock synchronization.

## Recommended Option

Option A - stockable polymorphic scope:

- `stock_levels(branch_id, stockable_type, stockable_id, available_qty, low_threshold, status, track_stock, version)`
- `stock_movements(branch_id, stockable_type, stockable_id, delta, reason, reference, correlation_id, actor_id)`

This covers:

- base products;
- variations such as meat/bread/size;
- extras such as sauces/crudites/supplements;
- addon items such as drinks/sides;
- future ingredients.

## Alternatives

- Option B - item-only stock. Faster but does not cover supplements/choices, so it does not satisfy the user demand.
- Option C - ingredient-only stock. More restaurant-grade but too large for the current Caisse V1 sequence.

## Files Potentially Touched After Approval

- stock migrations;
- `app/Models/StockLevel.php`;
- `app/Models/StockMovement.php`;
- `app/Services/Stock/StockService.php`;
- stock tests;
- later order-service integration only if frozen order gate is also approved.

## Invariants

- Stock must be branch-scoped.
- Atomic decrement must happen server-side.
- Stock events must dispatch after DB commit.
- POS/kiosk must never invent stock state client-side.

## Human Approval

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
