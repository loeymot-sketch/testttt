# Codex — VA-SYS-03 Wizard Runtime Contract — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-03`

## Verdict

`VA_SYS_03_VERDICT: PASS_LOCAL`

`NEXT_CODEX_MISSION: VA-SYS-04`

## Scope

Runtime wizard behavior after VA-SYS-02 composer contract hardening.

## Contract Locked

- Published composer profile wins and drives the wizard steps.
- `composer_profile: null` with `wizard_template: simple` now means an explicit no-wizard/simple product.
- Legacy payloads without a `composer_profile` key keep the old name/category heuristic fallback.
- POS continues to delegate to the shared Kiosk wizard runtime.
- Backend pricing remains authoritative for min/max, source choices, stale choices, addon choices, and stock rupture.

## Changes

- `KioskWizardComponent.vue`
  - `effectiveWizardTemplate()` now treats `composer_profile: null` + `wizard_template: simple` as an explicit no-wizard lock.
  - Legacy payloads without `composer_profile` still fall back to `detectTemplateFromName()`.
- `tests/js/KioskWizard.spec.js`
  - Updated the previous “simple does not block heuristic” expectation.
  - Added explicit coverage for the new no-wizard lock and legacy fallback.

## Validation

- `npx vitest run tests/js/KioskWizard.spec.js --testNamePattern="P5 detectTemplateFromName"`: PASS, 13 targeted tests.
- `npx vitest run tests/js/KioskWizard.spec.js`: PASS, 96 tests.
- `npx vitest run tests/js/kioskWizardGenericComposer.spec.js tests/js/posWizardComposerProfile.spec.js`: PASS, 8 tests.
- `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`: PASS, 5 tests.
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`: PASS, 13 tests.
- `git diff --check` scoped VA-SYS-03 files: PASS.

## Invariants Checked

- Backend pricing SSOT: PASS. Frontend only decides runtime UX flow; authoritative validation remains in `PricingService`.
- Branch isolation: PASS via unchanged composer projection/resource branch selection and prior VA-SYS-02 authz tests.
- OrderService / FrontendOrderService: untouched.
- Frozen zones: no migration or order service edits.

## Residual Follow-Up

- VA-SYS-04 must add stable dashboard hooks and operator-safe composer UI controls so managers can intentionally choose no wizard vs supported wizard steps.
- VA-SYS-05 must prove full dashboard-to-kiosk/POS/KDS E2E after VA-SYS-04 hooks.

