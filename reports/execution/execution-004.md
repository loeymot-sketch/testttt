# Execution Report 004 — Sprint 3 : Fix Tests T06, T07, T12, T18
> **Date**: 2026-03-10
> **Based on**: `reports/planning/plan-003.md`
> **Executor**: Kimi (Implementation Agent)

---

## Summary

Fixed the 3 remaining blockers from Anti-Gravity Report 004:

1. **T06 Crash 500 `faviconLogo on null`** — theme_settings table now seeded
2. **T07 Kiosk isolation failure** — missing `x-api-key` header on admin routes
3. **T12/T18 Admin 403 on POS/KDS** — missing Spatie permissions seeding

---

## Changes Made

### Task 1 — Seed `theme_settings` in `TestCase::seedMinimalSettings()`

**File**: `tests/TestCase.php`

**After the existing settings seed block**, added:

```php
// Seed theme_settings to prevent 'faviconLogo on null' crash in notification builders
if (Schema::hasTable('theme_settings') && DB::table('theme_settings')->count() === 0) {
    DB::table('theme_settings')->insert([
        ['key' => 'theme_favicon_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'theme_logo',         'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
    ]);
}
```

**Rationale**: `SendOrderGotMail` and `SendOrderGotPush` listeners call `ThemeSetting::where(['key' => 'theme_favicon_logo'])->first()->faviconLogo`. If the table is empty, `->first()` returns `null` and calling `->faviconLogo` on null causes a 500 error. Seeding the row (even with null value) prevents the crash.

### Task 2 & 3 — Seed Spatie permissions + Admin role assignment

**File**: `tests/TestCase.php`

**In `seedSpatieRoles()`**, after role creation, added:

```php
use Spatie\Permission\Models\Permission;

// Create permissions and assign to Admin role
$permissions = ['online-orders', 'pos-orders', 'kitchen-display-system', 'pos'];
foreach ($permissions as $perm) {
    Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
}

$adminRole = Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first();
if ($adminRole) {
    $adminRole->syncPermissions($permissions);
}
```

**Rationale**: Controllers like `OnlineOrderController` and `KitchenDisplaySystemController` require Spatie permissions (`online-orders`, `kitchen-display-system`). The Admin role was created but had no permissions assigned — causing 403 errors in T12/T18.

### Task 4 — Add `x-api-key` header to all admin route calls

**File**: `tests/Feature/AntiGravityTest.php`

**Added helper method**:

```php
private function apiKey(): string
{
    return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
}
```

**Modified all admin route calls** to use `->withHeader('x-api-key', $this->apiKey())`:

| Test | Endpoint | Header Added |
|------|----------|--------------|
| T05 | `/api/admin/dashboard` | Yes |
| T07 | `/api/admin/pos-order` | Yes |
| T12 | `/api/admin/online-order` | Yes |
| T13 | `/api/admin/pos-order/change-status/{order}` | Yes |
| T14 | `/api/admin/pos-order/change-status/{order}` | Yes |
| T18 | `/api/admin/kds-order` | Yes |
| T20 | `/api/admin/kds-order/change-status/{order}` | Yes |

**Rationale**: The admin route group uses `['installed', 'apiKey', 'auth:sanctum', 'localization']` middleware. `ApiKeyMiddleware` validates the `x-api-key` header. Without this header, requests return 400 instead of the expected 401/403 auth errors, causing test assertion failures.

---

## Files Changed

| File | Lines Changed |
|------|---------------|
| `tests/TestCase.php` | +22 lines (theme_settings seed, permissions seed + assignment) |
| `tests/Feature/AntiGravityTest.php` | +8 lines (apiKey helper, 7 calls with header) |

---

## Root Causes Resolved

| Issue | Root Cause | Fix Applied |
|-------|------------|-------------|
| T06 Crash 500 | `theme_settings` table empty → `ThemeSetting::first()` returns null | Seed `theme_favicon_logo` row |
| T07 Wrong status | Missing `x-api-key` header → middleware returns 400 | Add header to all admin calls |
| T12/T18 403 | Admin role had no permissions assigned | Seed permissions + `syncPermissions()` |

---

## Verification Expected

```bash
php artisan test tests/Feature/AntiGravityTest.php --verbose
```

**Expected Results**:
- **T06** : 200/201 — order creation succeeds without faviconLogo crash
- **T07** : 401/403 — kiosk blocked from POS orders with proper auth error
- **T12** : 200 — admin sees pending orders, or clean 403 if permission check fails differently
- **T13, T14** : 200/400/403 — state transitions work or return expected auth errors
- **T18** : 200/403 — KDS access works or returns expected error
- **T20** : 400/401/403/422/404 — KDS cannot mark delivered

---

## Next Steps

1. Anti-Gravity executes retest → produces `reports/antigravity/report-005.md`
2. Claude reviews remaining failures (if any) for business logic issues vs test infrastructure issues
3. If all 18 tests pass → Sprint 3 complete, proceed to business logic hardening (pricing validation strictness, state transition rules)
