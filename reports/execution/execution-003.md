# Execution Report 003 — Sprint 2 Round 3 : Fix Guard Spatie et Noms de Rôles
> **Date**: 2026-03-10
> **Based on**: `reports/planning/plan-002.md`
> **Executor**: Kimi (Implementation Agent)

---

## Summary

Fixed the `RoleDoesNotExist` crash in tests T12–T20 by aligning the test environment's Spatie role seeding with the production `RoleTableSeeder`. The root cause was a double mismatch:
1. Guard mismatch: tests used `web`, production uses `sanctum`
2. Name mismatch: tests used lowercase (`admin`, `kds`), production uses PascalCase (`Admin`, `Chef`)

---

## Changes Made

### Task 1 — Fixed `seedSpatieRoles()` in `tests/TestCase.php`

**File**: `tests/TestCase.php`

**Before**:
```php
protected function seedSpatieRoles(): void
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'kds', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'chef', 'guard_name' => 'web']);
}
```

**After**:
```php
protected function seedSpatieRoles(): void
{
    Role::firstOrCreate(['name' => 'Admin',          'guard_name' => 'sanctum']);
    Role::firstOrCreate(['name' => 'Chef',           'guard_name' => 'sanctum']);
    Role::firstOrCreate(['name' => 'Branch Manager', 'guard_name' => 'sanctum']);
    Role::firstOrCreate(['name' => 'POS Operator',   'guard_name' => 'sanctum']);
    Role::firstOrCreate(['name' => 'Customer',       'guard_name' => 'sanctum']);
    Role::firstOrCreate(['name' => 'Stuff',          'guard_name' => 'sanctum']);
}
```

**Rationale**: Matches exactly the `RoleTableSeeder` production seeder configuration.

### Task 2 — Fixed role names in test helpers

**File**: `tests/Feature/AntiGravityTest.php`

**Changes**:
- `setupAdmin()`: `$admin->assignRole('admin')` → `$admin->assignRole('Admin')`
- `setupKds()`: `$chef->assignRole('kds')` → `$chef->assignRole('Chef')`

**Rationale**: When `assignRole()` is called without explicit guard, Spatie uses the model's default guard (`sanctum` per `config/auth.php`). The role must exist with that guard and exact name.

---

## Files Changed

| File | Lines Changed |
|------|---------------|
| `tests/TestCase.php` | 6 roles updated (guard + name) |
| `tests/Feature/AntiGravityTest.php` | 2 role names updated |

---

## Verification Expected

Anti-Gravity should re-run the test suite:

```bash
php artisan test tests/Feature/AntiGravityTest.php
```

**Expected Results**:
- **T12, T13, T14, T18, T20** : No more `RoleDoesNotExist` crashes
- If these tests now return 403 instead of crashing → next issue is missing Spatie **permissions** (not roles)
- **T07, T08, T09, T10** : Unchanged by this sprint — to be addressed in next planning cycle

---

## Notes for Claude (Next Planning)

If T12–T20 still fail after this fix:
1. Check if the failure is now a clean HTTP 403 (not a crash)
2. If 403, the issue is likely missing permissions (`pos-orders`, `kds-orders`, etc.) not roles
3. The `RolePermissionTableSeeder` assigns permissions to roles — this may need to be mirrored in test setup

T07–T10 failures appear to be in the business logic layer (pricing/coupon validation) and require deeper investigation into `FrontendOrderService` and `OrderRequest` validation rules.
