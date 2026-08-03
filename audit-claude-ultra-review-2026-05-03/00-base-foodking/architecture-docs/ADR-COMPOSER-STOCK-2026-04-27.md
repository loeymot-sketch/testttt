# ADR — Product Composer Schema + Stock V2 Foundation

Date: 2026-04-27

## Status

Accepted for implementation after human approval captured in the Claude final execution plan:

- `HG-COMPOSER-SCHEMA-ADR`: approved.
- `HG-STOCK-STOCKABLE-SCOPE`: approved.
- `D-COMPOSER-01`: addon role enum approved as `drink|side|dessert|menu_component|upsell`.

## Decision

FoodKing adds a thin product-composer schema:

- `item_wizard_profiles`
- `item_wizard_steps`

The composer schema references existing catalogue primitives. It does not store final price logic.

FoodKing also adds Stock V2 foundations:

- `stock_levels` scoped by `branch_id + stockable_type + stockable_id`
- `stock_movements` as append-only movement log

The stockable polymorphic key supports base items, variations, extras, addon choices, and future ingredient-like entities without forcing a second stock table later.

## Constraints

- Pricing remains backend SSOT.
- Product composer profiles cannot duplicate item/addon/extra prices.
- Stock is branch-isolated.
- Stock movements are append-only.
- `OrderService` and `FrontendOrderService` integration is not part of B2 and requires the frozen-order gate mission B5a.

## Consequences

- Dashboard composer write can be built on these tables in B3.
- Runtime wizard migration can consume published profiles in B4.
- Stock decrement/release can be wired to order lifecycle in B5a.
