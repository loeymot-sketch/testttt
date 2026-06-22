# PRODUCT-COMPOSER-SYNC-B7-KIOSK-LOCKDOWN-RELEASE-AUDIT

Source plan: `reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md`

## Scope

1. Extend kiosk bundle lockdown beyond B0 explicit `kiosk-admin*.js`.
2. Block forbidden deleted kiosk bundle names from falling through to the SPA catch-all.
3. Remove stale non-manifest public kiosk bundle artifacts found by the B7 audit.
4. Add E2E and feature tests covering route, asset, customer-screen, and payment back-button contracts.
5. Document D-KIOSK-01/02/03 in the kiosk lockdown policy.

## Scope Adjustment From Plan

The initial scan found that `public/js/kiosk.js` was a stale non-manifest customer bundle containing old kiosk admin signatures. Deleting it and its `kiosk.js.LICENSE.txt` sidecar is a B7 corrective action because B7 owns release lockdown scanning. `routes/web.php` was added to enforce 404 for deleted forbidden asset names instead of letting Laravel's catch-all return the SPA with HTTP 200.

## Forbidden

- No cash-at-counter lifecycle implementation; B5b owns it.
- No payment state machine work.
- No order service edits.
- No stock or catalog schema work.
- No new kiosk cash feature flag.

## Validation Required

- `php -l routes/web.php`
- `php -l tests/Feature/KioskBundleLockdownTest.php`
- `php artisan test tests/Feature/KioskBundleLockdownTest.php --colors=never`
- `node tools/lint/scan_kiosk_bundles.mjs`
- `tools/lint/forbidden_bundles.sh`
- `curl` checks for 404 on `/js/kiosk-admin.js`, `/js/kiosk-admin.js.LICENSE.txt`, `/js/kiosk.js`, `/js/kiosk.js.LICENSE.txt`
- `npx playwright test tests/e2e/kiosk-lockdown.spec.js --project=chromium --reporter=list`
- `npm run production`
- post-build `node tools/lint/scan_kiosk_bundles.mjs`
- post-build `tools/lint/forbidden_bundles.sh`
- scoped `git diff --check`

## Invariants

- Kiosk customer surface has no admin/POS escape.
- `/kiosk/admin` redirects to kiosk idle.
- Deleted forbidden asset names return 404, not the SPA shell.
- Payment back button remains active before submit and disabled while submitting.
