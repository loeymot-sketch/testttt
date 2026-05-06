# RUN — CV1-V1-FINISH-A11Y-INGREDIENTS-001 — 2026-05-04

EXECUTE_DELEGATION: foodking-complex-implementer

## Scope

- `resources/js/components/admin/ingredients/IngredientAvailabilityToggleComponent.vue`
  - Added explicit keyboard handlers for Space/Enter on the `role="switch"` button.
  - Added `aria-busy`, dynamic `aria-label`, conditional `aria-describedby`, reason `id`, and visible focus ring utilities.
- `resources/js/components/admin/ingredients/IngredientListComponent.vue`
  - Replaced filter buttons from `aria-pressed` pattern to WAI-ARIA tablist/tab/tabpanel semantics.
  - Implemented roving tabindex and Right/Left/Home/End tab keyboard navigation.
  - Added table `caption`, `scope="col"` headers, and first-column `scope="row"` row headers.
- `resources/js/components/admin/ingredients/IngredientUsageDrawer.vue`
  - Added translated close labels and `aria-live="polite"` to loading/error states.
- `resources/js/components/admin/demo/WizardAdvancedLauncherComponent.vue`
  - Added submit `aria-busy` and `aria-live="polite"` on the error alert.
- `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js`
  - Extended axe coverage for page loaded, usage drawer open, keyboard toggle, and mocked empty state.
- `tests/js/ingredientToggleA11y.spec.js`
  - Added switch role/state, Space, Enter, and pending `aria-busy` coverage.
- `tests/js/ingredientListA11y.spec.js`
  - Added tablist, tab `aria-selected`, roving tabindex, caption, and scoped header coverage.

## i18n

Added these two keys under `label.ingredient` in all 5 language files (`fr`, `en`, `de`, `bn`, `ar`):

- `tablist_label`
- `table_caption`

## Validation

### Targeted Vitest

```text
✓ tests/js/ingredientUsageDrawer.spec.js  (3 tests) 30ms
✓ tests/js/ingredientToggleA11y.spec.js  (4 tests) 26ms
✓ tests/js/ingredientToggleOptimistic.spec.js  (3 tests) 30ms
✓ tests/js/ingredientListA11y.spec.js  (4 tests) 41ms
✓ tests/js/ingredientListComponent.spec.js  (3 tests) 35ms

Test Files  5 passed (5)
Tests  17 passed (17)
```

### Global Vitest

```text
Test Files  193 passed (193)
Tests  1157 passed | 2 skipped (1159)
```

Baseline >= 1149 preserved.

### Build

```text
✔ Mix: Compiled successfully in 10.15s
webpack compiled successfully
```

### IDE diagnostics

```text
No linter errors found.
```

### Post-execute hook

```text
[post-execute] task: CV1-V1-FINISH-A11Y-INGREDIENTS-001
[post-execute] running: php artisan test --stop-on-failure
[post-execute] tests: FAILED — see reports/test_CV1-V1-FINISH-A11Y-INGREDIENTS-001_20260504_150443.log
[post-execute] lint: SKIPPED — no lint script in package.json
[post-execute] playwright: SKIPPED — aucune stratégie playwright déclarée dans le plan
```

Failure detail:

```text
FAIL Tests\Feature\Composer\ComposerProfileVersionConflictTest
⨯ update with matching version succeeds
Expected response status code [200] but received 404.
tests/Feature/Composer/ComposerProfileVersionConflictTest.php:40
Tests: 1 failed, 3 skipped, 372 passed, 1061 pending
```

Assessment: backend Composer failure is outside H3 scope (`SUBSYSTEMS_OFF_LIMITS`: all backend and Studio/Composer outside ingredients/demo). H3 frontend targeted/global Vitest and build are PASS.

## WCAG 2.1 AA conformité

- [x] Keyboard nav OK: toggle Space/Enter and tablist Right/Left/Home/End covered.
- [x] ARIA tablist conforme: `tablist` / `tab` / `tabpanel`, `aria-selected`, `aria-controls`, roving tabindex.
- [x] Table scope OK: hidden caption, column scopes, row scopes.
- [x] Focus visible OK: toggle gets `focus-visible` ring utilities.
- [x] Aria-live notifications OK: drawer loading/error and launcher error are polite live regions.
- [x] Pending state exposed: toggle and wizard launcher buttons expose `aria-busy`.

## Invariants

- I1 pricing: not touched.
- I2 OrderStatus: not touched.
- I3 branch_id: not touched.
- I4 dispatch after commit: not touched.
- I5 OrderService / FrontendOrderService symmetry: not touched.
- I6 frozen zones: not touched.

## Risques résiduels

- Axe-core does not cover every focus-order nuance.
- Contrast ratios and visual focus affordances should still be visually checked by a human during final cutover QA.
- Post-execute PHP hook currently fails in an out-of-scope Composer backend test; validator/orchestrator should route that to the owning H1/H6 path, not H3.
