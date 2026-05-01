# PRODUCT-COMPOSER-SYNC-B4-RUNTIME-WIZARD-MIGRATION

Status: PASS
Execution: codex-extension
Date: 2026-04-27

## Scope Delivered

- `MenuProjectionService` now projects published `composer_profile` for POS/kiosk/web channels with branch-specific override priority.
- `KioskMenuService` now emits the same `composer_profile` shape for kiosk runtime payloads.
- `ItemResource` and `NormalItemResource` expose global published composer profiles for item detail payloads.
- `KioskWizardComponent` prioritizes published composer steps before legacy template/name heuristics.
- `kioskAnalytics.trackHeuristicFallback()` records fallback use when no published profile exists.
- `KioskEventController` accepts the mirrored `wizard.composer_heuristic_fallback` analytics event.

## Expanded Scope Note

The B4 plan required `kioskAnalytics.trackHeuristicFallback`. During self-audit, the frontend event whitelist and backend `KioskEventController` whitelist were found to diverge for the new event. B4 was minimally expanded to `app/Http/Controllers/Frontend/KioskEventController.php` and `tests/Feature/KioskPhase5/KioskEventPhase5WhitelistTest.php` to keep the analytics contract real.

## Validations

- `bash .cursor/hooks/safety-check.sh`: PASS
- `php -l` on B4 PHP files: PASS
- `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`: 3 PASS
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`: 5 PASS
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php`: 2 PASS
- `php artisan test tests/Feature/KioskEventTest.php`: 4 PASS
- `php artisan test tests/Feature/KioskPhase5/KioskEventPhase5WhitelistTest.php`: 13 PASS
- `npx vitest run tests/js/kioskWizardComposerProfile.spec.js tests/js/posWizardComposerProfile.spec.js tests/js/productComposerEditor.spec.js --reporter=dot`: 10 PASS
- `npm run production`: PASS
- `bash tools/lint/forbidden_bundles.sh && node tools/lint/scan_kiosk_bundles.mjs`: PASS
- `git diff --check` on B4 files: PASS

## Invariants

- Pricing remains backend-authoritative: composer profile payload excludes price/total fields; item/existing choices keep their original catalog prices outside the profile where already present.
- Branch isolation: branch-specific profiles win only for the requested branch; global profile remains fallback.
- No migrations and no order services were touched.
- Runtime heuristic remains available only as fallback.
