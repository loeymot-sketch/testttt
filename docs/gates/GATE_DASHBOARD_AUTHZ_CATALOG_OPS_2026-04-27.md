# Gate Brief - Dashboard Catalog/Ops Authorization

Gate ID: `HG-DASHBOARD-AUTHZ-CATALOG-OPS`
Date drafted: 2026-04-27
Status: `PENDING_HUMAN_GATE`

## Decision Needed

Approve who can create, modify, publish, or disable catalogue/composer/stock data from the dashboard.

## Recommended Permissions

- `backoffice-catalog`: create/update/delete categories, products, product composition, photos, variations, extras, addons.
- `backoffice-ops`: branch availability, stock adjustment, out-of-stock toggles.
- `pos`: sell/order only, no catalogue mutation.
- `manager`: can perform stock adjustments and availability toggles for current branch.
- `owner/director`: full catalogue/composer access.

## Files Potentially Touched After Approval

- permission seeders;
- admin controllers/requests for composer and stock;
- dashboard routes;
- authz tests.

## Invariants

- Kiosk never writes catalogue.
- POS cashier flow does not become a catalogue editor unless role explicitly allows it.
- Branch-scoped ops must not affect other branches.

## Human Approval

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
