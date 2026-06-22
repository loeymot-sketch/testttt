# Codex — VA-SYS-02 Composer / Catalog Request Contract Hardening — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-02`

## Verdict

`VA_SYS_02_VERDICT: PASS_LOCAL_STRONG`

`NEXT_CODEX_MISSION_AFTER_AUDIT_PASS: VA-SYS-03`

## Scope

Software-only central management contract hardening. Hardware, TPE, printer, Google Maps live, and industrial UAT remain deferred by `docs/gates/GATE_VERSION_A_SOFTWARE_SCOPE_2026-04-30.md`.

## Findings Closed

### P0 — Unsupported `fixed` composer steps could bypass backend wizard enforcement

Before this mission, `source_type=fixed` could be saved and published while pricing enforced only `item_attribute`, `extra_group`, and `addon` steps. A required fixed step could therefore be ignored by backend pricing.

Closed by:

- `ComposerProfileRequest` and `ComposerStepRequest` now reject `source_type=fixed`.
- `ComposerStepService` now rejects unsupported source types, invalid surfaces, and invalid min/max ranges even for direct service calls.
- `PricingService` now rejects unsupported source types in a published profile instead of silently continuing.
- Dashboard composer UI no longer offers or creates a `fixed` source type.
- `ItemWizardStepFactory` default source type is now supported (`item_attribute`) so new tests do not manufacture invalid wizard profiles by default.

### P1 — Published empty/legacy composer profiles could represent a broken wizard contract

Closed by:

- `ComposerProfileService::publish()` now validates that a profile has active steps before publication.
- Publish rejects legacy unsupported steps with a validation error.
- Required steps with no projected choices are refused at publish time.
- Product with no wizard remains represented by no published composer profile, not by an empty/fixed profile.

### P1 — Nested product modifier surfaces were only JSON-validated

Closed by:

- `ItemRequest` now validates `variations[*].visible_on[*]` and `extras[*].visible_on[*]` inside the JSON payload.
- Invalid nested surfaces such as `terminal` are rejected before `ItemService` can persist them.

## Files Changed

- `app/Http/Requests/ComposerProfileRequest.php`
- `app/Http/Requests/ComposerStepRequest.php`
- `app/Http/Requests/ItemRequest.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Pricing/PricingService.php`
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue`
- `resources/js/components/admin/items/composer/StepEditorComponent.vue`
- `database/factories/ItemWizardStepFactory.php`
- `tests/Feature/Composer/ComposerProfileApiTest.php`
- `tests/Feature/Composer/ComposerStepServiceContractTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/Feature/Composer/ComposerPublishSyncTest.php`
- `tests/Feature/Requests/ItemRequestTest.php`
- `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
- `tests/Feature/ItemAttributeComposerResourceTest.php`

## Validation

- `php -l` on changed PHP request/service/test files: PASS.
- `php artisan test tests/Feature/Composer`: PASS, 24 tests.
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`: PASS, 13 tests.
- `php artisan test tests/Feature/Requests/ItemRequestTest.php`: PASS, 3 tests.
- `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`: PASS, 5 tests.
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php`: PASS, 5 tests.
- `npx vitest run tests/js/productComposerEditor.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/posWizardComposerProfile.spec.js`: PASS, 12 tests.
- `git diff --check` scoped VA-SYS-02 files: PASS.

## Audit

- `AUDIT_CHANNEL: cursor-session`
- `AUDIT_FALLBACK_REASON: subagent_quota_limit_until_2026-04-30T18:53:00`
- `AUDIT_VERDICT: PASS`

The attempted adversarial sub-agent audit hit the local usage limit before producing findings. The fallback audit re-ran the targeted backend and frontend suites above, checked the fixed-source scan, verified no frontend pricing authority was added, and kept VA-SYS-03 blocked until VA-SYS-02 reached this PASS state.

## Invariants Checked

- Backend pricing SSOT: PASS. Frontend does not calculate authoritative wizard prices; unsupported published source types now fail in `PricingService`.
- Branch isolation: PASS. Composer authz matrix still passes after request/service hardening.
- Dispatch after commit: PASS by unchanged event classes and passing `ComposerPublishSyncTest`.
- OrderService / FrontendOrderService: untouched.
- Frozen zones: no migration or order service edits.

## Residual Follow-Up

- VA-SYS-03 should validate runtime wizard UX paths after the backend contract change: no profile = no wizard, simple supported profile = wizard, complex supported profile = wizard with enforced min/max.
- VA-SYS-04 should add stable dashboard `data-testid` hooks before full VA-SYS-05 Playwright.
- Product nested modifier validation now covers surfaces. A broader future cleanup may route nested product modifier writes through the dedicated variation/extra services, but this is not required to close the VA-SYS-02 contract risk.
