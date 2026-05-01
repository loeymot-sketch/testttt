# Codex VA-SYS-07 Central Management Authz Execution - 2026-04-29

## Verdict

`VA-SYS-07_VERDICT: PASS_LOCAL_PARTIAL`

This pass hardens the highest-risk central management authorization gaps discovered during the VA-SYS-07 route/controller mapping. It does not claim the full dashboard aggregation matrix is complete yet.

## Corrections Applied

- `app/Http/Controllers/Admin/ItemVariationController.php`
  - `items_show` can read variations only.
  - `items_edit` is now required for create/update/delete.

- `app/Http/Controllers/Admin/ItemExtraController.php`
  - `items_show` can read extras only.
  - `items_edit` is now required for create/update/delete.

- `app/Http/Controllers/Admin/ItemAddonController.php`
  - `items_show` can read addons only.
  - `items_edit` is now required for create/delete.

- `app/Http/Controllers/Admin/ItemCategoryController.php`
  - `settings` now covers category index/show/create/update/delete/sort/export/download/import.

- `app/Http/Controllers/Admin/ItemController.php`
  - `items_show` now covers item list/show/details/barcode/sample read endpoints.
  - Branch-scoped users cannot request a foreign `branch_id` availability overlay.

- `routes/api.php`
  - Category static routes `export`, `download-sample`, `import/file` are declared before `{itemCategory}` to avoid route-model shadowing.

## Tests Added / Updated

- `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`
  - `items_show` can read modifiers but cannot mutate variations/extras/addons.
  - Category management routes require `settings`.
  - Item read endpoints require `items_show`.
  - Branch user with `items_show` cannot forge a foreign `branch_id` overlay.

- `tests/Feature/Catalog/AddonRolePersistenceTest.php`
  - Addon write API now uses `items_edit`, matching the hardened middleware.

## Validation

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Catalog` | PASS, 13 tests |
| `php artisan test tests/Feature/Composer` | PASS, 15 tests |
| `php artisan test tests/Feature/Services/Menu` | PASS, 23 tests |
| `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` | PASS, 12 tests |
| `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php` | PASS, 5 tests |
| `php artisan test tests/Feature/Stock` | PASS, 21 tests |
| `npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/kioskRuptureUx.spec.js tests/js/posWizardComposerProfile.spec.js` | PASS, 13 tests |
| `npm run production` | PASS |

## Remaining VA-SYS-07 Work

Still pending for a full `PASS_STRONG`:

- Dashboard aggregate branch-scope matrix: totals, sales/order summaries, channel stats, featured/popular/top customers.
- Composer `show` route explicit own/foreign/global read expectations.
- Availability toggle no-permission, own-branch null fan-out, and branch staff own success cases.
- Photo mutation policy decision: branch-scoped user with `items_edit` currently mutates global item photo. Needs product decision: global-only media or branch-local media model.
- Seeder production check: ensure composer permissions seeder is called in production deployment path or covered by deployment runbook.

## Invariants

- Pricing SSOT unchanged.
- No frontend price authority added.
- `branch_id` overlay access is now guarded for item read projections.
- No migration added.
- No self-approved human gate.

## Execution Trace

`EXECUTE_DELEGATION: explicit-prompt-bind`

`AUDIT_CHANNEL: codex-local-self-audit + read-only explorer mapping`

`TERMINAL_AUDIT_OK: 0`

`STATUS: PASS_LOCAL_PARTIAL_READY_FOR_VA_SYS_08_OR_VA_SYS_07_EXTENDED_MATRIX`
