# Codex — Final Sync/Security Software Audit Before Hardware UAT — 2026-04-30

AUDIT_VERDICT: `PASS_SOFTWARE_READY_FOR_HARDWARE_UAT`
RELEASE_DECISION: `HOLD_FOR_HARDWARE_UAT_NOT_PRODUCTION_GO`

Scope: central catalogue/data sync, dashboard management path, product composer/wizard, stock/projection sync, KDS/POS/Kiosk data propagation, branch/security invariants, and the two review findings:

- P2 dashboard CRUD browser proof missing.
- P3 obsolete `MenuProjectionService` comment.

This report also covers the deeper multi-agent audit findings raised during the final review and the rework applied after those findings.

---

## Executive Verdict

No open P0 or P1 software blocker remains for hardware/provider UAT.

The system is locally validated for the software path:

1. Admin browser creates central data.
2. Product, image, variation, extra, addon and composer profile are published.
3. POS and Kiosk consume the same central projection.
4. Pricing remains backend-authoritative.
5. KDS receives complete order data including composer addons.
6. Stock decrements after order persistence.
7. Branch/data isolation guards are enforced on composer and nested product modifiers.
8. Catalogue refresh events use after-commit dispatch paths.

Production GO is still not granted here. Hardware/provider UAT remains mandatory for TPE, fiscal printer, kiosk OS lockdown, real KDS screens, provider realtime, Google Maps live and physical network-loss behavior.

---

## Review Findings Closed

### Finding 1 — P2 Dashboard CRUD navigateur pas prouvé de bout en bout

Status: `CLOSED`

Implemented:

- `tests/e2e/central-management-dashboard-crud.spec.js`
  - Creates via admin browser UI:
    - category
    - item attribute
    - addon product
    - main product
    - product photo update
    - variation
    - extra
    - addon link
    - composer profile
    - publish
  - Verifies runtime sync:
    - POS projection includes composer/addon data
    - Kiosk projection includes composer/addon data
    - Kiosk menu shows product
    - POS quote/store persists order
    - KDS shows order
    - stock decrements
    - order snapshot includes variation, extra and addon

Validation:

- `npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --reporter=line --workers=1 --timeout=180000 --retries=0 --repeat-each=2`
- Result: `2 passed (2.5m)`
- Proof artifact: `reports/antigravity/central-management-dashboard-crud.json`

Residual note: stock setup in this E2E is backend-seeded for runtime decrement proof. Dashboard stock-management UI is not treated as a hardware-UAT blocker.

### Finding 2 — P3 Commentaire obsolète

Status: `CLOSED`

Implemented:

- `app/Services/Menu/MenuProjectionService.php`
  - Docblock now states that POS/Kiosk/KDS sync tests and menu-projection APIs use this service as canonical catalog projection, while legacy per-surface callers must preserve parity.

Validation:

- `php -l app/Services/Menu/MenuProjectionService.php`
- Result: PASS

---

## Additional Audit Findings Raised And Fixed

### P1-A — Nested Product Update Could Mutate Foreign Modifiers

Status: `CLOSED`

Risk:

- `ItemService::update()` previously updated nested variation/extra IDs with raw ID queries.
- An actor with item edit permission could submit product A update payload containing modifier IDs from product B.

Fix:

- `app/Services/ItemService.php`
  - Variation update now uses `$item->variations()->whereKey($variationId)->update(...)`.
  - Extra update now uses `$item->extras()->whereKey($extraId)->update(...)`.
  - If no row is updated, the service throws `all.item_match` and the transaction rolls back.

Tests:

- `tests/Feature/ItemExtraManagementTest.php`
  - `test_item_update_rejects_foreign_nested_variation_id`
  - `test_item_update_rejects_foreign_nested_extra_id`

Validation:

- `php artisan test tests/Feature/ItemExtraManagementTest.php --stop-on-failure`
- Result: `4 passed`

### P1-B — KDS Did Not Show Composer Addons

Status: `CLOSED`

Risk:

- POS/Kiosk order persistence had addon data in `composition_snapshot.addons`.
- KDS views and print tickets rendered variations/extras but not addons.
- Kitchen/packing staff could miss drinks/sides/desserts/menu components.

Fix:

- `app/Http/Resources/KDSOrderItemsResource.php`
  - Adds `item_addons` resolved from immutable `composition_snapshot.addons`.
- `app/Http/Resources/OrderItemResource.php`
  - Adds `item_addons` for KDS order detail/list path.
- `app/Services/KitchenDisplaySystemOrderService.php`
  - Adds normalized addon hash to the item-board grouping key so two lines with different addon choices do not merge incorrectly.
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
  - Renders addons on item board and order cards.
  - Adds addons to printed kitchen ticket.
  - Escapes printed addon values through the existing `escapeHtml` path.

Tests:

- `tests/Feature/KDS/KdsSnapshotImmutableTest.php`
  - `test_kds_order_item_resource_exposes_composer_addons_from_snapshot`
  - `test_kds_items_board_keeps_distinct_addon_choices_unmerged`
- `tests/js/userReportedBlockersRuntime.spec.js`
  - Static guard that KDS resource and Vue component expose/add render addon data.

Validation:

- `php artisan test tests/Feature/KDS/KdsSnapshotImmutableTest.php --stop-on-failure`
- Result: `4 passed`
- `npx vitest run tests/js/userReportedBlockersRuntime.spec.js ...`
- Result included in Vitest group: `7 files / 56 tests passed`

### P2-A — Catalog Refresh Dispatch Could Bypass After-Commit Guard

Status: `CLOSED`

Fix:

- `app/Services/ItemService.php`
- `app/Services/ItemVariationService.php`
- `app/Services/ItemExtraService.php`
- `app/Services/ItemAddonService.php`

Catalogue refresh now dispatches `ItemAvailabilityChanged::dispatch(...)`, which uses the repository `DispatchableAfterCommit` trait. This preserves the invariant that sync/outbox/broadcast consumers must not observe data from a rolled-back transaction.

Validation:

- `php artisan test tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php --stop-on-failure`
- Result: `3 passed`
- `php artisan test tests/Feature/AfterCommitDispatchTest.php --stop-on-failure`
- Result: `14 passed`
- `php artisan test tests/Feature/DispatchAfterCommitTest.php --stop-on-failure`
- Result: `8 passed`

### P2-B — Hidden/Inactive Addon Metadata Could Reach Projections

Status: `CLOSED`

Fix:

- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Kiosk/KioskMenuService.php`

Top-level addon projection now filters addon items that are:

- missing
- inactive
- globally unavailable
- hidden from the target channel

Validation:

- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --stop-on-failure`
- Result: `6 passed`
- `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php --stop-on-failure`
- Result: `5 passed`
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php --stop-on-failure`
- Result: `13 passed`

### P2-C — E2E Cleanup Not Type-Scoped

Status: `CLOSED`

Fix:

- `tests/e2e/central-management-dashboard-crud.spec.js`
  - `domain_events` cleanup scopes by `aggregate_type` when the column exists.
  - `stock_movements` and `stock_levels` cleanup scope by `stockable_type = App\Models\Item::class` when the column exists.

Validation:

- `node --check tests/e2e/central-management-dashboard-crud.spec.js`
- Result: PASS
- Playwright repeat: `2 passed`

---

## Validation Matrix

### PHP Feature Tests

- `tests/Feature/ItemExtraManagementTest.php`: `4 passed`
- `tests/Feature/KDS/KdsSnapshotImmutableTest.php`: `4 passed`
- `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`: `6 passed`
- `tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`: `5 passed`
- `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php`: `3 passed`
- `tests/Feature/AfterCommitDispatchTest.php`: `14 passed`
- `tests/Feature/DispatchAfterCommitTest.php`: `8 passed`
- `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`: `13 passed`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`: `11 passed`

Total targeted PHP assertions/tests: `68 passed`.

### Vitest

Command:

```bash
npx vitest run tests/js/userReportedBlockersRuntime.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/kioskWizardComposerProfile.spec.js tests/js/posWizardComposerProfile.spec.js tests/js/posItemAvailabilityHandler.spec.js tests/js/kioskOfflineQueueV2.spec.js tests/js/productComposerEditor.spec.js
```

Result: `7 files passed / 56 tests passed`.

### Playwright Runtime E2E

Command:

```bash
npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --reporter=line --workers=1 --timeout=180000 --retries=0 --repeat-each=2
```

Result: `2 passed (2.5m)`.

### Static / Safety

- `git diff --check`: PASS
- `bash .cursor/hooks/safety-check.sh`: PASS
- Targeted `php -l`: PASS
- `node --check tests/e2e/central-management-dashboard-crud.spec.js`: PASS

---

## Invariants Checked

Backend pricing SSOT: PASS

- No frontend authoritative pricing added.
- Composer/addon price and constraints remain backend-enforced through pricing tests.

Branch/data isolation: PASS

- Composer authz matrix remains green.
- Nested modifier updates are now item-scoped, preventing cross-product modifier mutation.

Dispatch after commit: PASS

- Catalog refresh paths now use the event class dispatch path with `DispatchableAfterCommit`.

KDS data completeness: PASS

- Variations, extras, addons, allergens and instructions are preserved for KDS.
- Addon-different lines stay split on the items board.

Catalog centralization: PASS

- Menu projection filters hidden/inactive/unavailable addon items.
- Legacy Kiosk service stays in parity with canonical menu projection for tested fields.

Stock sync: PASS

- Dashboard CRUD E2E proves runtime stock decrement for the UI-created product after POS order persistence.
- Stock UI setup remains a post-UAT polish item, not a software blocker for hardware UAT.

---

## Remaining Scope For Hardware/Provider UAT

These are not local software blockers, but they must be validated on real equipment/provider setup before production:

- TPE / external payment terminal: success, refusal, timeout, duplicate callback.
- Fiscal printer: print, paper-out, reprint, fiscal/non-fiscal slip behavior.
- Kiosk OS lockdown: touch screen, URL bar escape, keyboard shortcuts, reboot recovery.
- Real KDS display: readability, sound/chime, refresh under service conditions.
- Realtime provider: subscription auth, latency, reconnect, quota/rate behavior.
- Google Maps live: geocode quota, failed geocode, branch zones, distance edge cases.
- Physical network loss/reconnect: POS, Kiosk, KDS, cash-at-counter queue.

---

## Final Decision

`PASS_SOFTWARE_READY_FOR_HARDWARE_UAT`

The local software layer is ready to connect equipment and run industrial/hardware UAT. Production launch remains gated by that hardware/provider UAT, not by an open software P0/P1 in the audited central sync/security scope.
