# Gate Brief - Product Composer Schema

Gate ID: `HG-COMPOSER-SCHEMA-ADR`
Date drafted: 2026-04-27
Status: `PENDING_HUMAN_GATE`

## Decision Needed

Approve or reject adding a thin Product Composer schema layer:

- `item_wizard_profiles`
- `item_wizard_steps`

These tables reference existing catalogue entities and must not duplicate final price logic.

## Recommended Option

Option A - approve thin composer layer:

- keep `items`, `item_attributes`, `item_variations`, `item_extras`, `item_addons` as existing catalogue SSOT;
- add per-product profiles and ordered steps;
- keep final quote/pricing in backend services.

## Alternatives

- Option B - continue with category `wizard_template` only. Lower risk, but does not satisfy per-product composition.
- Option C - build a full new product-builder schema. Higher risk and duplicates existing catalogue primitives.

## Files Potentially Touched After Approval

- `database/migrations/*create_item_wizard_profiles_table.php`
- `database/migrations/*create_item_wizard_steps_table.php`
- `app/Models/ItemWizardProfile.php`
- `app/Models/ItemWizardStep.php`
- composer API/service/controller files in a later mission
- tests under `tests/Feature/Catalog/`

## Invariants

- Backend remains pricing SSOT.
- No frontend final price calculation.
- No OrderService edit under this gate alone.
- Branch-specific availability/stock remains separate from schema unless the stock gate is also approved.

## Human Approval

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
