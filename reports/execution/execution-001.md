# Execution Report 001 — Sprint 2 : Test Harness Stabilization
> **Date**: 2026-03-10
> **Based on**: `reports/planning/plan-001.md`
> **Executor**: Kimi (Implementation Agent)

---

## Summary

All 3 tasks from `plan-001.md` have been completed successfully. No application business logic was touched — only test infrastructure (factories and test classes).

---

## Changes Made

### Task 1 — Fix `ItemFactory` Schema Mismatch

**File**: `database/factories/ItemFactory.php`

**Change**: Removed the `discount_price` field from the factory definition.

**Before**:
```php
'discount_price' => null,
```

**After**: Field completely removed.

**Rationale**: The `items` table in the SQLite migration does not have a `discount_price` column. This was causing SQL errors (500) when creating Items via factories in tests T06, T08, T09, T10.

---

### Task 2 — Seed Spatie Roles in Test Setup

**File**: `tests/TestCase.php`

**Changes**:
1. Added `use Spatie\Permission\Models\Role;` import.
2. Added new protected method `seedSpatieRoles(): void`.
3. Method creates `admin` and `kds` roles with `guard_name = 'web'` if they don't exist.

**Code added**:
```php
/**
 * Seed Spatie roles required for authorization tests.
 * Called in setUp() for tests that need roles/permissions.
 * Prevents "RoleDoesNotExist" exceptions in SQLite memory mode.
 */
protected function seedSpatieRoles(): void
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'kds', 'guard_name' => 'web']);
}
```

**Rationale**: In-memory SQLite boots empty. Tests that call `$user->assignRole('admin')` or check role permissions were crashing with `RoleDoesNotExist`. This method seeds the roles idempotently.

---

### Task 3 — Assign Roles in Test Helpers

**File**: `tests/Feature/AntiGravityTest.php`

**Changes**:
1. Modified `setUp()` to call `$this->seedSpatieRoles()` after `seedMinimalSettings()`.
2. Modified `setupAdmin()` to explicitly assign the 'admin' role:
   ```php
   $admin->assignRole('admin');
   ```
3. Added new helper method `setupKds()` for creating KDS users:
   ```php
   private function setupKds()
   {
       $branch = \Database\Factories\BranchFactory::new()->create();
       $chef = User::factory()->create(['branch_id' => $branch->id]);
       $chef->assignRole('kds');
       return [$branch, $chef];
   }
   ```

**Rationale**: Previously, tests T12-T20 were failing with 403 errors because users created by `setupAdmin()` lacked the required role. The new helper enables proper KDS authorization testing.

---

## Files Changed

| File | Lines Changed | Type |
|------|---------------|------|
| `database/factories/ItemFactory.php` | -1 line | Factory fix |
| `tests/TestCase.php` | +18 lines | Infrastructure |
| `tests/Feature/AntiGravityTest.php` | +9 lines | Test helpers |

---

## Verification Expected

Anti-Gravity should re-run the test suite:

```bash
php artisan test tests/Feature/AntiGravityTest.php
```

**Expected Results**:
- **T06, T08, T09, T10**: Should pass (Item creation works without SQL errors).
- **T12, T13, T14, T18, T20**: Should no longer crash with `RoleDoesNotExist`. Expected HTTP responses should be clean 200 or 4xx (not 500).

---

## Risks & Notes

1. **Guard Name**: Roles are created with `guard_name = 'web'`. If the application uses a different guard configuration for Sanctum/SPA auth, this may need adjustment.

2. **No Business Logic Touched**: All changes are confined to test infrastructure. Production code is unchanged.

3. **Backward Compatibility**: The `seedSpatieRoles()` method is idempotent (uses `firstOrCreate`), so it's safe to call in multiple test classes.

---

---

## Addendum — Post-Execution Correction (2026-03-10)

**Identified by**: Claude (Architecture Review)

**Problem**: `seedSpatieRoles()` was only called in `AntiGravityTest.php`. The other 20 Feature test files would have the same `RoleDoesNotExist` problem. Additionally, `config/auth.php` declares `sanctum` as the default guard, while roles were being created with `guard_name = 'web'`.

**Corrections applied**:

1. **`tests/TestCase.php`**: Added `setUp()` method that calls both `seedMinimalSettings()` and `seedSpatieRoles()` automatically for **all** test classes. Added `manager` and `chef` roles to cover the full AUTHZ_MATRIX.

2. **`tests/Feature/AntiGravityTest.php`**: Removed the now-redundant explicit `setUp()` calls (parent `TestCase::setUp()` handles them).

3. **Guard note**: Roles remain `guard_name = 'web'` — this is correct. Spatie Permission uses `web` as its default guard, and Sanctum defers to it for `hasRole()` / `can()` checks. The `sanctum` guard in `auth.php` is for token authentication, not for role resolution.

---

## Next Steps

1. Anti-Gravity executes retest and produces `reports/antigravity/report-002.md`.
2. Claude reviews the new report for any remaining failures that indicate business logic bugs.
3. If tests T12-T20 still fail with 403/400, Claude must investigate the actual authorization logic in controllers (potential architectural fix needed).
