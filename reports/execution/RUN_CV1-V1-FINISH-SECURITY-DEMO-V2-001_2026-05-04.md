# RUN — CV1-V1-FINISH-SECURITY-DEMO-V2-001 — 2026-05-04

EXECUTE_DELEGATION: foodking-complex-implementer

## Scope

Security healing H1 for Demo V2 gating. Added a profile/step guard so item-owned legacy composer profiles cannot be mutated through shared profile/step routes while `FEATURE_WIZARD_PER_ITEM_DEMO=false`.

## Routes Newly Protected

- `PUT/PATCH /api/admin/composer/profiles/{profile}`
- `GET /api/admin/composer/profiles/{profile}/diff`
- `POST /api/admin/composer/profiles/{profile}/unpublish`
- `POST /api/admin/composer/profiles/{profile}/steps`
- `PUT/PATCH /api/admin/composer/steps/{step}`
- `DELETE /api/admin/composer/steps/{step}`

## Files Created / Modified

- Created `app/Http/Middleware/EnsureProfileNotItemOwnedUnlessDemoEnabled.php`
- Modified `app/Http/Kernel.php`
- Modified `routes/api.php`
- Created `tests/Feature/WizardPerItemProfileGuardTest.php`
- Modified `tests/Feature/Composer/ComposerProfileVersionConflictTest.php`
- Created `reports/execution/RUN_CV1-V1-FINISH-SECURITY-DEMO-V2-001_2026-05-04.md`

## Validation Output

### PHPUnit targeted — new guard

Command: `php artisan test tests/Feature/WizardPerItemProfileGuardTest.php`

Result: PASS

```text
PASS  Tests\Feature\WizardPerItemProfileGuardTest
✓ flag off blocks item owned profile update
✓ flag off blocks item owned profile step creation
✓ flag off blocks item owned step update
✓ flag off allows category owned profile update
✓ flag off allows category owned profile step creation
✓ flag on allows item owned profile update

Tests:  6 passed
Time:   1.11s
```

### PHPUnit filtered — WizardPerItem

Command: `php artisan test --filter="WizardPerItem" --colors=never`

Result: PASS

```text
PASS  Tests\Feature\WizardPerItemDemoMiddlewareTest
PASS  Tests\Feature\WizardPerItemProfileGuardTest

Tests:  9 passed
Time:   2.34s
```

### PHPUnit compatibility — Composer profile version conflict

Command: `php artisan test tests/Feature/Composer/ComposerProfileVersionConflictTest.php --colors=never`

Result: PASS

```text
PASS  Tests\Feature\Composer\ComposerProfileVersionConflictTest
✓ update with matching version succeeds
✓ update with stale version returns 409 with expected body
✓ update without version field still succeeds for back compat

Tests:  3 passed
Time:   0.57s
```

### PHPUnit global

Command: `php artisan test --colors=never`

Result: PASS

```text
Tests:  24 skipped, 1413 passed
Time:   229.93s
```

### Post-execute hook

Command: `bash .cursor/hooks/post-execute.sh`

Result: PASS

```text
[post-execute] tests: PASSED
[post-execute] lint: SKIPPED — no lint script in package.json
[post-execute] playwright: SKIPPED — aucune stratégie playwright déclarée dans le plan
[post-execute] done — invoke Composer validate phase next
```

### Vitest global

Command: `npx vitest run`

Result: PASS

```text
Test Files  191 passed (191)
Tests       1149 passed | 2 skipped (1151)
Duration    22.08s
```

## Invariants Checklist

- I1 Backend pricing SSOT: preserved. No pricing logic touched.
- I2 OrderStatus enum: preserved. No order status logic touched.
- I3 `branch_id` isolation: preserved. No branch query/mutation rules changed; existing controller branch-scope authorization remains in place after the guard.
- I4 Dispatch after DB commit: preserved. No event/job dispatch layer changed.
- I5 OrderService / FrontendOrderService symmetry: not applicable. Neither service touched.
- I6 Frozen zones: preserved. No frozen-zone files touched.
- Demo V2 security invariant: reinforced. Item-owned legacy profiles are hidden behind 404 on shared mutation/diff/unpublish routes while the demo flag is off; category-owned profiles remain editable for V1 normal flow; flag ON preserves legacy item-owned behavior.

## Residual Risks

- The middleware may perform one profile lookup on guarded routes when route model binding did not already resolve the model. Overhead is negligible on admin composer mutations and usually avoided by existing route binding.
- Category-owned wizard editing remains intentionally allowed on the shared routes.
- Existing direct read route `GET /api/admin/composer/profiles/{profile}` is not present in `routes/api.php`; no new read route was added or guarded.
