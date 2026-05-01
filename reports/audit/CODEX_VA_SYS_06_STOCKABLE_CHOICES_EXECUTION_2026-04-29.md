# Codex VA-SYS-06 Stockable Choices Execution - 2026-04-29

## Verdict

`VA-SYS-06_VERDICT: PASS_LOCAL`

`SCOPE: software/runtime local validation`

Hardware, TPE, printer and industrial device UAT remain deferred. This report covers the software system: dashboard composer choices, branch stock state, menu projection, kiosk/POS rendering, backend pricing rejection, stock decrement/release and catalog sync invalidation.

## User Decision Implemented

Stock is managed at two levels:

- Product-level rupture: sandwich, menu, drink, dessert, prepared item can be unavailable as a full product.
- Choice-level rupture: stockable wizard choices can be unavailable inside a composition, including ingredients, sauces, crudites, supplements, drinks, desserts, meat/fish or addon target products.

The runtime behavior is now consistent:

- Dashboard/composer publishes wizard steps.
- Menu/Kiosk/POS projections expose `is_available` and `unavailable_reason` for stockable choices.
- Kiosk generic wizard disables unavailable choices.
- POS legacy modal disables unavailable variations/extras/addons and blocks direct method calls.
- Backend pricing rejects stale/forged payloads selecting an unavailable choice.
- Backend pricing rejects stale active choices that are no longer present in the current published composer profile.
- POS and kiosk edit/restore paths sanitize choices that became unavailable or were removed from the current profile before submit.
- Order stock decrement includes addon target items from `composition_snapshot.addons`.
- Cancel/refund release can restore addon target stock idempotently.
- `StockLevelChanged` invalidates kiosk menu cache and persists catalog outbox refresh.

## Implementation Summary

### Backend

- Added `app/Services/Stock/ChoiceAvailabilityResolver.php`.
  - Resolves branch-scoped availability for `ItemVariation`, `ItemExtra`, and addon target `Item`.
  - Applies surface visibility and catalog status checks.
  - Provides a server-side orderability guard for stale payload rejection.

- Updated menu projection services.
  - `app/Services/Menu/MenuProjectionService.php`
  - `app/Services/Kiosk/KioskMenuService.php`
  - `app/Services/Composer/ComposerProfileProjection.php`
  - These now decorate stockable composer choices, variations, extras and addons with availability metadata by branch.

- Updated API resources.
  - `app/Http/Resources/ItemResource.php`
  - `app/Http/Resources/NormalItemResource.php`
  - `app/Http/Resources/ItemExtraResource.php`
  - `app/Http/Resources/ItemAddonResource.php`
  - Kiosk token branch resolution is server-side; public forged `branch_id` is ignored.

- Updated pricing backend.
- `app/Services/Pricing/PricingService.php`
  - Validates active/visible variations, extras and addons.
  - Rejects branch-stock rupture for selected stockable choices.
  - Rejects selected choices missing from the current published profile projection.
  - Enforces composer min/max/repeat constraints against published profile projection.

- Updated stock and sync.
  - `app/Services/Stock/StockService.php`
  - `app/Listeners/ReleaseStockOnRefundCreated.php`
  - `app/Events/CatalogChanged.php`
  - `app/Providers/EventServiceProvider.php`
  - Addon target stock is decremented/released from composition snapshots.
  - Stock release runs before availability release so the `released_qty` ledger does not block stock compensation.
  - Choice-level stock boundary changes emit catalog invalidation/outbox refresh.

### Frontend

- Updated `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue`.
  - Generic composer choices with `is_available=false` cannot be selected.
- Updated `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`.
  - Restored cart selections are filtered against the current composer profile choices.
  - Unavailable/removed composer choices do not count for step advancement and are not serialized.

- Updated `resources/js/components/admin/pos/ItemComponent.vue`.
  - Variations, extras and addons show disabled/rupture state when unavailable.
  - Direct JS method calls cannot add/increment unavailable choices.
  - Existing selected unavailable choices can still be removed, preventing trapped cart lines.
  - Default single-choice selection skips unavailable options.
  - Restored cart selections are pruned before rebuilding a POS cart payload.

## Validation Matrix

| Area | Proof | Result |
| --- | --- | --- |
| Menu projection | `php artisan test tests/Feature/Services/Menu` | PASS, 23 tests |
| Resource branch/composer projection | `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php` | PASS, 5 tests |
| Backend pricing/forgery rejection | `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` | PASS, 12 tests |
| Stock decrement/release/refund | `php artisan test tests/Feature/Stock` | PASS, 21 tests |
| Composer authz/publish sync | `php artisan test tests/Feature/Composer` | PASS, 15 tests |
| Kiosk/POS choice UX | `npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/kioskRuptureUx.spec.js tests/js/posWizardComposerProfile.spec.js` | PASS, 13 tests |
| PHP syntax | `php -l` on changed PHP files | PASS |
| Production bundle | `npm run production` | PASS |

Total targeted proof for VA-SYS-06 in this pass: 89 automated tests + production build.

## Adversarial Audit Closure

Read-only adversarial audit found two P1 issues:

- Stale composer choices could be accepted if active/stocked but absent from the current profile.
- POS/kiosk edit restore could carry old selections to submit.

Both were fixed and covered by:

- `test_published_profile_rejects_stale_choice_ids_not_in_current_projection`
- `removes unavailable restored POS selections before rebuilding cart payload`
- Kiosk runtime guards `sanitizeComposerChoicesForCurrentProfile` and `currentComposerAllowedChoiceKeys`

## Invariants Checked

- Pricing SSOT: frontend only disables/labels choices; `PricingService` remains authoritative and rejects invalid selected choices.
- `branch_id` isolation: availability is resolved by server branch context; kiosk branch comes from machine token, not client query.
- Dispatch after commit: no order event dispatch moved into a transaction; catalog invalidation/outbox hooks are event listeners.
- OrderService / FrontendOrderService symmetry: no new direct order-service pricing logic was added; stock release/decrement is centralized in `StockService`.
- Frozen zones: no migration added for this mission; no new business pricing in frontend.

## Residual Risks / Next Missions

- VA-SYS-05 still needs the full dashboard-to-kiosk/POS/KDS E2E flow with a real dashboard-created product, photo, stockable wizard, publish, order, KDS visibility and stock mutation.
- VA-SYS-07 authz matrix remains a broader role/scope campaign beyond the targeted Composer tests run here.
- VA-SYS-08 should stress realtime/outbox replay and reconnect after stockable-choice mutation.
- Hardware UAT remains intentionally out of scope until software Version A reaches final `VA-SYS-10`.

## Execution Trace

`EXECUTE_DELEGATION: explicit-prompt-bind`

`AUDIT_CHANNEL: codex-local-self-audit`

`TERMINAL_AUDIT_OK: 0`

`AUDIT_FALLBACK_REASON: Claude terminal not used in this continuation because the user requested Codex to continue autonomously while Claude is quota-limited; local code/test evidence is recorded here for later external audit.`

`STATUS: PASS_LOCAL_READY_FOR_NEXT_VA_SYS_MISSION`
