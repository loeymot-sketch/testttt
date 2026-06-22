# Kiosk Lockdown Policy - 2026-04-27

## Decision

The customer kiosk is a locked customer surface. It must not expose staff/admin navigation, POS navigation, dashboard links, or a kiosk admin panel from customer screens.

## Contracts

- D-KIOSK-01: The payment back button is active before submit and disabled while submit/payment processing is running.
- D-KIOSK-02: Cash-at-counter is a normal customer payment path. It is not an admin escape and has no kiosk feature flag in B7.
- D-KIOSK-03: `KioskAdminComponent.vue`, `public/js/kiosk-admin.js`, and stale public admin bundles must not be shipped.

## Runtime Rules

- `/kiosk/admin` redirects to `kiosk.idle`.
- `/js/kiosk-admin.js` must return 404.
- `/js/kiosk-admin.js.LICENSE.txt`, `/js/kiosk.js`, and `/js/kiosk.js.LICENSE.txt` must return 404 and must not fall through to the SPA catch-all.
- Legacy non-manifest `public/js/kiosk.js` must not be present because it contained old admin code signatures.
- Customer screens `/kiosk/idle`, `/kiosk/cart`, `/kiosk/payment`, and `/kiosk/cash-instruction` must not expose visible staff escape controls.

## Release Guards

- `tools/lint/forbidden_bundles.sh` guards the explicit B0 deletion.
- `tools/lint/scan_kiosk_bundles.mjs` scans all `public/js/*.js` bundles for high-signal kiosk admin signatures:
  - `KioskAdmin` / `KioskAdminComponent`
  - `kiosk-admin-overlay`
  - default admin PIN fallback patterns involving `1234`
- The scan intentionally does not fail on the bare i18n key `kiosk_admin_pin`: staff/admin settings bundles legitimately contain that translation key. The forbidden condition is kiosk admin runtime code or a weak default PIN fallback in shipped JS.
- `tests/Feature/KioskBundleLockdownTest.php` enforces deleted public/source files.
- `tests/e2e/kiosk-lockdown.spec.js` covers route, public asset, customer-screen escape, and payment back-button contracts.
