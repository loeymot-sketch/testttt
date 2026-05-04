# RUN CV1-V1-PIVOT-RUPTURE-PROPAGATION-001 - 2026-05-04

## Header

- TASK_ID: `CV1-V1-PIVOT-RUPTURE-PROPAGATION-001`
- Plan ref: `plans/PLAN_CV1-V1-PIVOT-MASTER_2026-05-04.md`
- Audit source: `reports/audit/ULTRA_REVIEW_PIVOT_V1_2026-05-04.md`
- EXECUTE_DELEGATION: `foodking-complex-implementer`

## Implementation

- T1 event: `app/Events/IngredientAvailabilityChanged.php` lines 9-21 creates the after-commit ingredient availability event using `DispatchableAfterCommit`.
- T2 listener: `app/Listeners/InvalidateMenuProjectionOnIngredientChange.php` lines 22-46 invalidates `kiosk.menu.branch.{id}` / bumps menu snapshot and dispatches `CatalogChanged`; lines 57-69 perform branch cache forget.
- T2 registration: `app/Providers/EventServiceProvider.php` lines 153-158 registers `IngredientAvailabilityChanged` and direct `CatalogChanged` outbox persistence.
- T3 dispatch: `app/Services/Ingredients/IngredientAvailabilityService.php` lines 16-36 saves inside the existing transaction and dispatches after successful save; addon remains read-only/false.
- T4 resolver: `app/Services/Stock/ChoiceAvailabilityResolver.php` lines 69-75 and 161-168 route extra availability through the ingredient-aware helper; lines 282-290 enforce `ingredient_rupture` before stock state while preserving original DB availability when resources mutate display availability.
- T6 ingredient tests: `tests/Feature/Ingredients/IngredientServiceListTest.php` lines 30-73, `IngredientAvailabilityChangedAfterCommitTest.php` lines 21-91, and `IngredientControllerToggleTest.php` lines 34-83 cover list/filter/find, after-commit payload/drop-on-rollback, admin API, and addon no-op.
- T7 stock sentinel: `tests/Feature/Stock/ChoiceAvailabilityResolverIngredientRuptureTest.php` lines 19-56 covers extra manual rupture, stock rupture with available extra, and orderability rejection.
- API compatibility: `routes/api.php` line 654 accepts both `PUT` and `PATCH` for ingredient availability toggle.

## Validation

- `php artisan test --filter="Ingredient|ChoiceAvailability|IngredientAvailabilityChanged"`: PASS, 17 passed.
- `php artisan test --filter="ItemAttributeComposerResourceTest|Ingredient|ChoiceAvailability|IngredientAvailabilityChanged"`: PASS, 22 passed.
- `php artisan test`: PASS, 1404 passed, 24 skipped, time 228.68s.
- `npx vitest run`: PASS, 184 files passed, 1125 tests passed, 2 skipped, time 14.09s.

## Notes

- First full PHPUnit run exposed a reason-precedence regression caused by resource-level mutation of `ItemExtra::is_available`; fixed in `ChoiceAvailabilityResolver::availabilityForExtra()` by using the DB original ingredient availability for manual rupture precedence.
- V1 limitation: attribute toggles dispatch the event and invalidate runtime cache, but visible runtime attribute rupture still requires a later cycle to extend `ComposerProfileProjection` to read `ItemAttribute::is_available`.
- Invariants checked: no pricing logic added; no order status strings added; V1 branch payload remains nullable `branchId`; dispatch is after commit via `DispatchableAfterCommit`; no frozen order/payment/pricing services touched.
