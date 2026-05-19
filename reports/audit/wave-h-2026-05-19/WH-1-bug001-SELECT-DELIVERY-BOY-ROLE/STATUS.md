# WH-1 — bug_001 selectDeliveryBoy role lookup heal — STATUS

**Date** : 2026-05-19
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Status** : GREEN — heal landed, sentinel passing, regression filter clean
**Wall-clock** : ~35 min

> **Parallel-session note** : the OrderService.php 1-line role fix was
> committed in `5e906658d` (bug_002 cash audit heal — the agent bundled the
> bug_001 line into the same diff inadvertently, presumably picking up our
> in-progress working tree). This commit therefore ships the **sentinel +
> collateral test fix** that pins the heal. The heal itself reads identical
> to what's described below (verified by `git show 5e906658d -- app/Services/OrderService.php`).

---

## Bug

**File** : `app/Services/OrderService.php` line 2184 (was 2133 at task spec time; file drifted in dirty state)
**Method** : `OrderService::selectDeliveryBoy(Order, Request, bool $auth)`
**Symptom** : 500 / 403 on every legitimate driver assignment on fresh-seeded environments.

### Root cause (verified against primary source)

```php
// BEFORE
$target = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
    ->role(\App\Enums\Role::DELIVERY_BOY)   // == ->role(3)
    ->where('id', (int) $targetId)
    ->first();
```

`\App\Enums\Role::DELIVERY_BOY` is the integer constant `3`. Spatie's
`HasRoles::scopeRole` (`vendor/spatie/laravel-permission/src/Traits/HasRoles.php:84`)
dispatches via `is_numeric($role) ? 'findById' : 'findByName'`. So this calls
`findById(3, $guard)`.

On a fresh-seeded environment where `roles.id=3` does not exist (AUTO_INCREMENT
skipped past 3 after rollbacks — `firstOrCreate` lands the 'Delivery Boy' row
at id=73+), `findById(3, 'sanctum')` throws `RoleDoesNotExist` and the whole
request 500s. Every legitimate POS dispatch + customer self-service driver
assignment breaks until the roles table happens to have a row at id=3.

The stable identity is the role NAME + guard, not the legacy enum integer.
See `database/seeders/SpatieRoleLookup.php` for the same rationale.

### Guard note (vs original bug report)

The original bug report claimed the wrong guard was the dominant issue ('web'
vs 'sanctum'). Primary-source verification (`vendor/spatie/laravel-permission/src/Guard.php:70-82`
+ `config/auth.php:17` setting `'guard' => 'sanctum'`) shows
`Guard::getDefaultName()` returns `'sanctum'` when `auth.defaults.guard='sanctum'`
matches the model's possible guards. So `->role(3)` actually calls
`findById(3, 'sanctum')` — correct guard, broken lookup.

The live failure mode is the id-skip alone. The fix uses `'sanctum'` explicitly
anyway to match the 5 sibling heals — belt-and-suspenders, not load-bearing.

---

## Fix

```php
// AFTER  (1-call change + WAVE-H doc comment)
$target = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
    ->role('Delivery Boy', 'sanctum')        // findByName, stable
    ->where('id', (int) $targetId)
    ->first();
```

Mirrors the 5 sibling heals from the GOAL-PAGEBY-STOCK-2026-05-18 wave:
- `app/Services/DeliveryBoyService.php:45`
- `app/Services/AdministratorService.php:46`
- `app/Services/ChefService.php:43`
- `app/Services/CustomerService.php:43`
- `app/Services/WaiterService.php:44`

The OrderService occurrence missed the original heal wave because it was added
by the same PR (the new authz preflight for the GOAL-2026-05-18 P0-LIV-01
multi-tenant guard).

---

## Sentinel

**File** : `tests/Feature/Sentinels/SelectDeliveryBoyRoleByNameSentinelTest.php` (NEW)

Four cases:
1. `test_sentinel_setup_forces_delivery_boy_role_off_legacy_enum_id` — sanity
   that the discriminator (Delivery Boy at id=73, not 3) is actually in place.
2. `test_select_delivery_boy_succeeds_when_role_id_skipped_past_legacy_enum`
   — the CORE bug surface. Outcome-agnostic (asserts assignment landed, not
   which error path the broken code takes).
3. `test_select_delivery_boy_still_rejects_non_driver_target_post_heal` —
   negative regression guard: heal must not weaken the role rejection.
4. `test_select_delivery_boy_happy_path_with_skipped_role_id` — smoke of the
   happy path under skipped-id conditions.

### Discriminator (the key design choice)

Spatie's `Role` model guards the primary key (`vendor/spatie/laravel-permission/src/Models/Role.php:36`:
`$this->guarded[] = $this->primaryKey;`). So
`Role::firstOrCreate(['id' => 73], [...])` silently strips the id and the row
lands wherever AUTO_INCREMENT chooses — the sentinel **cannot** force the row
off id=3 via Eloquent.

Workaround: raw `DB::table('roles')->insert([...])` with explicit id columns.
This is what the sentinel's `setUp()` does — it plants Admin/Customer/Waiter/Chef
at ids 1, 2, 4, 5 (no row at id=3) and 'Delivery Boy' at id=73 (the value
cited in the heal comments at `app/Services/DeliveryBoyService.php:42-44`).

RED phase verified: with the BROKEN code in place, the sentinel surfaces
`There is no role with id '3'` from `vendor/spatie/laravel-permission/src/Exceptions/RoleDoesNotExist.php:16`.

GREEN phase verified: with the fix in place, all 4 sentinel cases pass.

---

## Collateral test fix (in-scope per advisor)

The existing `tests/Feature/Sentinels/DeliveryBoyHardeningSentinelTest.php`
had a setUp() that was passing tests by triple-coincidence:

1. `seedSpatieRoles()` planted `Branch Manager` at id=3 (3rd `firstOrCreate` in
   the helper).
2. The local loop `Role::firstOrCreate(['id'=>3], ['name'=>'Delivery Boy', ...])`
   matched on id=3 (Branch Manager row) and short-circuited — **no 'Delivery Boy'
   row was ever created**.
3. `$user->assignRole(EnumRole::DELIVERY_BOY /* =3 */)` → Spatie's `getStoredRole(3)`
   resolved to Branch Manager (id=3) → user was assigned Branch Manager role.
4. The broken `selectDeliveryBoy` did `->role(3)` → matched Branch Manager →
   the user matched the role scope → test passed.

The test was exercising "Branch Manager identification" while claiming to test
"Delivery Boy identification" — same coincidence-soup that masked the original
bug.

Healing `selectDeliveryBoy` to `->role('Delivery Boy', 'sanctum')` exposed this
masked test bug (4 tests started failing with `There is no role named 'Delivery Boy'`).
Fixed in-commit via the same raw-DB-insert pattern: setUp now plants the 5
core roles at their EnumRole-aligned ids before calling `seedSpatieRoles()`.

The 4 previously-coincidence-passing tests are now honestly green:
- `test_p0_liv_01_select_delivery_boy_rejects_cross_branch_driver`
- `test_p0_liv_01_select_delivery_boy_rejects_non_delivery_boy_target`
- `test_p0_liv_01_select_delivery_boy_allows_same_branch_driver`
- `test_p0_liv_01_select_delivery_boy_customer_self_service_happy_path`

---

## Regression evidence

| Filter | Before heal | After heal |
|--------|-------------|------------|
| `SelectDeliveryBoyRoleByName` (new sentinel) | 1 passed / 3 failed (RED expected) | 4 passed |
| `DeliveryBoyHardening` | 11 passed (4 coincidence-passing) | 11 passed (honestly) |
| `UserMgmtRoleTarget` | 14 passed | 14 passed |
| `Delivery\|Order` (mandated full filter) | 668 passed / 4 failed / 1 incomplete / 1 skipped | **672 passed / 1 incomplete / 1 skipped** |

Net change: +4 honest greens (the previously-coincidence-passing tests), +4
new sentinel cases. Zero NEW regressions. The 1 incomplete (`s72_n_kiosk_card_orders...`)
and 1 skipped are pre-existing in the baseline.

---

## Frozen-zone status

`app/Services/OrderService.php` is NOT in CLAUDE.md §7 frozen-zones. The
`Delivery Boy` literal addition is a 1-call change with comment, scope-minimal.
Frozen-zone diff = 0 verified (no payment / fiscal / pricing / state-machine
files touched).

---

## Files touched

| File | Status | Lands in | Delta |
|------|--------|----------|-------|
| `app/Services/OrderService.php` | already shipped | `5e906658d` (parallel-session crossing) | ~10 LOC (1-call + comment) |
| `tests/Feature/Sentinels/SelectDeliveryBoyRoleByNameSentinelTest.php` | NEW | this commit | ~215 LOC |
| `tests/Feature/Sentinels/DeliveryBoyHardeningSentinelTest.php` | MODIFIED — setUp() rewrite + `use DB` import (exposes masked test bug, NOT a heal of an unrelated invariant) | this commit | ~30 LOC delta |
| `reports/audit/wave-h-2026-05-19/WH-1-bug001-SELECT-DELIVERY-BOY-ROLE/STATUS.md` | NEW | this commit | this file |

---

## Re-tag note for orchestrator

The tag `v1.0.X-massive-converged-2026-05-19` at commit `a152636cc` predates
this heal. Per mission spec, the orchestrator should re-tag after this commit
lands.

---

**Verdict** : GO — heal scope-minimal, sentinel pinned, regression contained
to a masked-bug exposure that was healed in-commit. Ready for tag advance.
