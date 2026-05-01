# PRODUCT-COMPOSER-SYNC-B7-KIOSK-LOCKDOWN-RELEASE-AUDIT Report

REPORT_VERDICT: PASS
EXECUTE_DELEGATION: codex-extension

## Changes

- Added `tools/lint/scan_kiosk_bundles.mjs`.
  - Scans all `public/js/*.js` outputs for high-signal kiosk admin runtime signatures.
  - Blocks `kiosk-admin*.js`, `kiosk.js`, and their legacy license sidecars from returning.
  - Avoids false positives on the bare staff/admin i18n key `kiosk_admin_pin`.
- Deleted stale legacy public artifacts:
  - `public/js/kiosk.js`
  - `public/js/kiosk.js.LICENSE.txt`
- Updated `routes/web.php`.
  - `/js/kiosk-admin.js`, `/js/kiosk-admin.js.LICENSE.txt`, `/js/kiosk.js`, and `/js/kiosk.js.LICENSE.txt` now return 404 instead of falling through to the SPA catch-all.
- Extended `tests/Feature/KioskBundleLockdownTest.php`.
  - Verifies deleted bundle/source files.
  - Verifies legacy `kiosk.js` sidecar deletion.
  - Verifies forbidden asset URLs return 404.
- Added `tests/e2e/kiosk-lockdown.spec.js`.
  - Covers `/kiosk/admin` redirect to idle.
  - Covers forbidden public asset URLs.
  - Covers no staff escape controls on customer kiosk screens.
  - Covers the payment back-button contract.
- Added `docs/kiosk/LOCKDOWN_POLICY_2026-04-27.md`.

## Validation

- PASS: `php -l routes/web.php`
- PASS: `php -l tests/Feature/KioskBundleLockdownTest.php`
- PASS: `php artisan test tests/Feature/KioskBundleLockdownTest.php --colors=never` (3 passed)
- PASS: `node tools/lint/scan_kiosk_bundles.mjs`
- PASS: `tools/lint/forbidden_bundles.sh`
- PASS: curl 404 checks for `/js/kiosk-admin.js`, `/js/kiosk-admin.js.LICENSE.txt`, `/js/kiosk.js`, `/js/kiosk.js.LICENSE.txt`
- PASS: `npx playwright test tests/e2e/kiosk-lockdown.spec.js --project=chromium --reporter=list` (4 passed)
- PASS: `npm run production`
- PASS: post-build `node tools/lint/scan_kiosk_bundles.mjs`
- PASS: post-build `tools/lint/forbidden_bundles.sh`
- PASS: scoped `git diff --check`

## Notes

- The plan's raw `kiosk_admin_pin` scan was adjusted because that i18n key is legitimate in staff/admin bundles. The release risk is runtime kiosk admin code or a default PIN fallback in shipped JS, both now blocked.
- `KioskPaymentComponent.vue` was inspected and not changed: it already binds `data-testid="kiosk-payment-back"` to `:disabled="submitting"`.

## Invariants

- Kiosk customer lockdown: PASS.
- Forbidden asset 404: PASS.
- No order service edits: PASS.
- No cash-at-counter implementation in B7: PASS.
- No gate self-approval: PASS.
