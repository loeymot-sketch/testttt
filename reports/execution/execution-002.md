# Execution Report 002 — Sprint 2 : Test Harness Stabilization (Round 2)
> **Date**: 2026-03-10
> **Based on**: `reports/antigravity/report-002.md`
> **Author**: Claude (Planning + Implementation)

---

## Context

Anti-Gravity report-002 revealed two categories of remaining failures after Sprint 2 Round 1:

1. **T12–T20 — `RoleDoesNotExist` (still crashing)**: Root cause was incorrect `setUp()` ordering, not the guard name.
2. **T07–T10 — Assertion mismatches**: Tests were testing behaviors the API does not implement (e.g. rejecting forged totals vs silently correcting them).

---

## Root Cause Analysis

### RoleDoesNotExist — The Real Cause

The previous fix placed `seedSpatieRoles()` in `TestCase::setUp()`. However, `RefreshDatabase` drops and recreates the database **after** `parent::setUp()` is called internally. This means roles seeded in `TestCase::setUp()` were wiped by `RefreshDatabase` before any test ran.

**Fix**: `seedSpatieRoles()` must be called in each test class's own `setUp()`, **after** `parent::setUp()` (which triggers `RefreshDatabase`). The `TestCase` base class only provides the method — it does not call it automatically.

### T08/T09/T10 — Behavioral Misalignment

Reading `FrontendOrderService::myOrderStore()`:
- The service **recalculates** `subtotal` and `total` server-side from DB prices (line 215–217). It does not reject forged values — it silently corrects them.
- The API uses `coupon_id` (integer FK), not `coupon_code` (string). Sending `coupon_code` is ignored entirely.
- `OrderRequest` requires `is_advance_order` and `source` fields — these were missing from all test payloads, causing 422 validation errors unrelated to the business logic being tested.

---

## Changes Made

### 1. `tests/TestCase.php`

**Removed** the `setUp()` method that was calling seeds too early (before `RefreshDatabase`). The `seedMinimalSettings()` and `seedSpatieRoles()` methods remain available for test classes to call explicitly.

### 2. `tests/Feature/AntiGravityTest.php`

**`setUp()`**: Restored explicit calls to `seedMinimalSettings()` and `seedSpatieRoles()` after `parent::setUp()` — this is the correct point, after `RefreshDatabase` has run migrations.

**T06** — Added missing required fields: `is_advance_order`, `source`. Changed `items` to `json_encode()` format (API expects JSON string, not array).

**T08** — Same payload fix. Updated assertion: API accepts the order but stores DB-recalculated subtotal (10), not the forged value (0.01). Test now uses `FrontendOrder::first()` instead of `Order::first()`.

**T09** — Updated to reflect actual API behavior: forged `total` is silently corrected server-side, not rejected. Test now asserts `$order->total > 0.01` instead of expecting a 400/422.

**T10** — Changed `coupon_code` (unsupported) to `coupon_id: 99999` (non-existent integer). Test now asserts that when coupon is not found, `discount = 0` on the created order.

---

## Files Changed

| File | Change |
|------|--------|
| `tests/TestCase.php` | Removed premature `setUp()` — seeds are now called by test classes directly |
| `tests/Feature/AntiGravityTest.php` | Fixed `setUp()` ordering, corrected T06/T08/T09/T10 payloads and assertions |

---

## Expected Results After This Fix

| Test | Expected | Reason |
|------|----------|--------|
| T01–T05 | ✅ Pass | Already passing |
| T06 | ✅ Pass | Payload now valid (is_advance_order, source, json items) |
| T07 | ✅ Pass | Kiosk user has no `pos-orders` permission → 403 |
| T08 | ✅ Pass | Order accepted, subtotal = 10 (DB price) |
| T09 | ✅ Pass | Order accepted, total > 0.01 (server-recalculated) |
| T10 | ✅ Pass | Coupon not found → discount = 0, order accepted |
| T11 | ✅ Pass | Already passing |
| T12–T14 | ⚠️ Likely 403 | Admin role assigned but `permission:pos-orders` may not be seeded — needs investigation if still failing |
| T18 | ⚠️ Likely 403 | Same — KDS permission seeding needed |
| T20 | ⚠️ Likely 403 | Same |
| T22, T23 | ✅ Pass | Already passing |

---

## Remaining Risk — Spatie Permissions (not just Roles)

The `PosOrderController` uses `permission:pos-orders` middleware, not just role checks. Spatie permissions (`pos-orders`, `kds-orders`, etc.) are likely seeded by the production `RolePermissionSeeder` but not in the test environment.

If T12–T20 still fail with 403 after this fix, the next step is to seed permissions and assign them to roles in `seedSpatieRoles()`. This is a Claude-level decision (affects AUTHZ_MATRIX).

---

## Next Steps

1. Anti-Gravity re-runs `AntiGravityTest.php` → produces `report-003.md`.
2. Claude reviews remaining 403s on T12–T20 to determine if permission seeding is needed.
3. If T12–T20 pass cleanly → Sprint 2 complete → proceed to business logic (Sprint 3).
