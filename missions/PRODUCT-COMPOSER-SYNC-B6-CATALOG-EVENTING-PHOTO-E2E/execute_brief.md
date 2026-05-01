# Execute Brief - PRODUCT-COMPOSER-SYNC-B6-CATALOG-EVENTING-PHOTO-E2E

## Scope

Implement catalog eventing and product photo propagation proof without adding a parallel outbox.

## Implementation

- Add `CatalogChanged` event contract and `PersistCatalogChangedToOutbox` listener.
- Persist catalog changes into the existing `domain_events` table with `broadcast_as=CatalogChanged`.
- Register `CatalogChanged` fanout for item availability, item create/delete, and category create/update/delete mutations.
- Add `catalog.changed` to PHP and JS event contracts.
- Expose `snapshot_version`, `branch_id`, and `channel` on `/api/frontend/menu`.
- Include item image fields in canonical menu projection.
- Add feature tests for catalog fanout, correlation idempotency, and admin photo upload to kiosk menu refresh.

## Intentional Deviation From Earlier Draft

The earlier draft mentioned a new `catalog_outbox` table. The repository already has a hardened `domain_events` outbox with claim-before-broadcast, correlation IDs, contract validation, and queue routing. Creating a second outbox would split observability and duplicate dispatch semantics, so this mission uses `domain_events` as the unified outbox.

## Validation

Run PHP syntax, targeted PHPUnit, targeted Vitest, production build, kiosk bundle scans, and `git diff --check`.
