# CODEX VA-SYS-07B — Extended Central Management Authz Execution — 2026-04-30

## Verdict

`AUDIT_VERDICT: PASS_LOCAL_STRONG`

`VERSION_A_SYSTEM_SCOPE: SOFTWARE_ONLY_HARDWARE_DEFERRED`

VA-SYS-07 is upgraded from `PASS_LOCAL_PARTIAL` to `PASS_LOCAL_STRONG` for the central management authz matrix.

## What Changed

- Dashboard runtime aggregates are now branch-scoped for branch users and global for `Admin` / `Tenant Admin`.
- Dashboard customer counts/top customers are derived from orders in the visible branch, not from `users.branch_id`, so global customer accounts remain counted correctly per restaurant branch.
- Composer profile `show` now resolves deterministically:
  - branch actor without query defaults to own branch profile;
  - global actor without query defaults to global profile;
  - no accidental “latest foreign profile” leak.
- Product photo mutation is reserved to global catalog roles (`Admin`, `Tenant Admin`) because product media is global catalog state in V1.
- Availability toggle matrix is covered:
  - no `items_edit` => 403;
  - own branch => success;
  - foreign branch => 403;
  - null branch fanout for branch user => own branch only;
  - null branch fanout for admin => all branches.
- `ComposerPermissionsMinimalSeeder` is now wired into `DatabaseSeeder`.

## Files Changed

- `app/Services/DashboardService.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Http/Controllers/Admin/ItemController.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php`
- `tests/Feature/Menu/AvailabilityToggleAuthzMatrixTest.php`
- `tests/Feature/Catalog/ProductPhotoAuthzTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/Feature/Seeders/ComposerPermissionSeederProductionTest.php`
- `tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php`
- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `reports/post_execute_latest.log`

## Validation

- `php -l` scoped files: PASS
- `php artisan test tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php`: 3 PASS
- `php artisan test tests/Feature/Menu/AvailabilityToggleAuthzMatrixTest.php`: 3 PASS
- `php artisan test tests/Feature/Catalog/ProductPhotoAuthzTest.php`: 1 PASS
- `php artisan test tests/Feature/Composer/ComposerAuthzMinimalTest.php`: 11 PASS
- `php artisan test tests/Feature/Seeders/ComposerPermissionSeederProductionTest.php`: 2 PASS
- `php artisan test tests/Feature/Catalog`: 14 PASS
- `php artisan test tests/Feature/Composer`: 17 PASS
- `php artisan test tests/Feature/Menu`: 23 PASS, 6 SKIP (existing SQLite/MySQL JSON surface filtering skip)
- `php artisan test tests/Feature/Stock`: 21 PASS
- `php artisan test tests/Feature/Services/Menu`: 23 PASS
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`: 12 PASS
- `php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`: 1 PASS
- `git diff --check` scoped files: PASS
- `npm run production`: PASS

## Sync Risk Review

- Architecture risk: low. Dashboard scoping is centralized in `DashboardService` helpers; catalog photo policy is enforced at controller boundary before mutation.
- State consistency risk: reduced. Branch dashboard now sees only own order/SLA/channel data, while admin remains global.
- Business rule risk: reduced. Product images are treated as global catalog state; branch users cannot overwrite shared product media.
- Authz risk: reduced. Composer read path no longer selects a foreign latest profile before authorization.
- Missing tests: MySQL JSON surface-filtering tests remain skipped under SQLite by existing design and must still run in the MySQL CI job.

## Remaining Work

- VA-SYS-08 remains next: realtime/outbox production-like simulation.
- VA-SYS-05 remains required later for full dashboard-to-kiosk/POS/KDS browser flow.
- Hardware remains intentionally deferred.
