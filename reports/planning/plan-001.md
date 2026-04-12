# Planning Report 001 — Sprint 2 : Test Harness Stabilization
> **Date**: 2026-03-10
> **Based on**: `reports/antigravity/report-001.md`
> **Author**: Claude (Planning Agent)

---

## 🛑 Context & Objective

The primary objective of this sprint is to stabilize the testing environment in SQLite memory. The Playwright / E2E verification Report 001 revealed that the basic test harness is broken not because of the application logic itself, but because of missing database fixtures and schema drifts in our factories.

We must fix these critical blockers before writing new business tests or features.

---

## 🛠️ Execution Plan (Tasks for Kimi)

**Kimi** must implement the following changes sequentially. **Do not modify application controllers or business logic.**

### Task 1: Fix `ItemFactory` Schema Mismatch
- **File**: `database/factories/ItemFactory.php`
- **Problem**: The factory includes `'discount_price' => null`, but this column does not exist in the SQLite `items` table, causing `QueryException` (500) on item creation.
- **Action**: Remove the `discount_price` field completely from the `definition()` array.

### Task 2: Seed Spatie Roles for Testing
- **File**: `tests/TestCase.php`
- **Problem**: In-memory SQLite boots empty. When tests attempt `$user->assignRole('admin')` or the middleware checks for roles, Spatie crashes with `RoleDoesNotExist`.
- **Action**: 
  - Add a new protected method `seedSpatieRoles(): void`.
  - Inside, use Spatie's `Role::firstOrCreate(['name' => 'admin'])` and `Role::firstOrCreate(['name' => 'kds'])`.
  - Call `$this->seedSpatieRoles()` inside the `TestCase::setUp()` method (ideally right next to `seedMinimalSettings()`).

### Task 3: POS/KDS Test Setup Consistency
- **File**: `tests/Feature/AntiGravityTest.php`
- **Problem**: The `setupAdmin()` and `setupKiosk()` helper methods create users but do not grant them the roles required by the endpoints. This causes false-positive 403 Forbidden errors (e.g., T12, T18, T20).
- **Action**:
  - In `setupAdmin()`, explicitly assign the admin role: `$admin->assignRole('admin');`.
  - If a KDS user is needed, create a `setupKds()` method (or adjust `setupAdmin`) and `$chef->assignRole('kds');`.

---

## 🧠 Review & Analysis Plan (Tasks for Claude)

Once Kimi has completed the setup:

### Task 4: Execution Review
- **Claude** will review `reports/execution/execution-001.md` to ensure Kimi only touched the factories and test classes, strictly avoiding core app logic.

### Task 5: Deep Dive into Remaining Failures
- **Claude** will analyze the next Playwright / E2E verification report. With roles and factories fixed, any remaining 403s or 500s on T12-T20 will be legitimate business logic or architectural bugs that Claude must resolve via targeted refactoring.

---

## 🧪 Verification Plan (For Playwright / E2E verification)

After Kimi's implementation, Playwright / E2E verification must re-run the exact same test suite:
```bash
php artisan test tests/Feature/AntiGravityTest.php
```

**Expected Results:**
- **T06, T08, T09, T10** must instantly pass (Item creation works).
- **T12, T13, T14, T18, T20** must either pass or throw a clean HTTP 4xx validation error, but **no more SQL 500 Errors or RoleDoesNotExist Exceptions**.
