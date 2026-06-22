# CV2-PH2-03 - Menu Projection Parity Sentinels

Date: 2026-04-27
Train: B - Phase 2 Enhancement
Mode: IMPLEMENTATION + SENTINELS

## Verdict

`CV2_PH2_03_VERDICT: PASS`

The canonical `MenuProjectionService` is now strong enough to act as the next
POS/Kiosk menu read model candidate. This mission does not migrate the
consumers; it adds the parity proof and fills projection fields needed before a
safe migration.

## Changes

| File | Change |
| --- | --- |
| `app/Services/Menu/MenuProjectionService.php` | Adds eager-loaded variations, extras, addons; exposes shared item identity fields; combines global item availability with branch availability. |
| `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php` | Adds POS/Kiosk parity sentinels for identity, price, availability, channel-filtered composition, addons, and legacy kiosk shared fields. |

## What Is Now Covered

1. POS and Kiosk projections keep the same shared identity fields:
   `id`, `category_id`, `item_category_id`, `name`, `slug`, `price`, `tax_id`,
   `item_type`, `status`.
2. Branch-level unavailability is projected identically for POS and Kiosk.
3. Global product availability is no longer ignored by the canonical projection.
4. Shared variation/extra prices stay identical across POS and Kiosk.
5. Channel-specific variation/extra visibility remains respected:
   `visible_on=["pos"]` does not leak to Kiosk and `visible_on=["kiosk"]` does
   not leak to POS.
6. Addon references are present in the canonical projection for both channels.
7. Existing legacy kiosk shared fields match the canonical kiosk projection for
   the tested common contract.

## Invariants Checked

| Invariant | Result |
| --- | --- |
| Backend pricing SSOT | Preserved. Prices are read from DB and not recalculated in frontend. |
| Branch isolation | Preserved. Availability query is scoped to requested branch. |
| POS/Kiosk design separation | Preserved. Kiosk can still add kiosk-only fields such as emoji. |
| Consumer migration safety | Improved, but not complete. Consumers are not switched in this mission. |
| Order services | Not touched. |

## Validation

```text
php -l app/Services/Menu/MenuProjectionService.php
php -l tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php
```

Result: PASS.

```text
php artisan test tests/Feature/Services/Menu
```

Result: PASS, 16 tests.

```text
php artisan test tests/Feature/Menu
```

Result: PASS, 20 passed, 6 skipped. Skips are the existing SQLite/
`JSON_CONTAINS` surface-filtering skips documented for MySQL CI.

```text
php artisan test
```

Result: PASS, 1105 passed, 8 skipped.

## Remaining Limits

1. `CV2-PH2-04` still needs to migrate actual consumers. This mission only
   proves the target projection.
2. Category branch scope is still unresolved and remains `CV2-PH2-06`.
3. Dashboard authz remains unresolved and must precede write endpoints.
4. The canonical projection still intentionally allows channel-specific
   differences, such as kiosk emoji and channel-filtered options.

## Next Mission

Recommended next step: `CV2-PH2-06-CATEGORY-BRANCH-SCOPE-ADR` before any
Dashboard category write UI, or `CV2-PH2-04-KIOSK-POS-CONSUME-MENU-PROJECTION`
if the next priority is runtime consumer migration.
