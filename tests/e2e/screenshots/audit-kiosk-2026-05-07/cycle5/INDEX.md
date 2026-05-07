# AUDIT KIOSK CYCLE K5 — Captures Index 2026-05-07

Total findings: 8

| Step | Slug | State | Sev | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| K5-01 | lockdown-admin-redirect | redirected | OK | /kiosk/admin → http://localhost:8000/kiosk/idle | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/01-lockdown-redirect-from-admin.png` |
| K5-02 | lockdown-admin-bundle | status-404 | OK | /js/kiosk-admin.js status=404 (expected 404) | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/02-lockdown-kiosk-admin-js-404.png` |
| K5-03 | error-payment-refused | rendered | OK | Page error/payment-refused length=204 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/03-error-payment-refused-rendered.png` |
| K5-04 | error-menu-unavailable | rendered | OK | Length=176 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/04-error-menu-unavailable-rendered.png` |
| K5-05 | error-product-removed | rendered | OK | Length=196 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/05-error-product-removed-rendered.png` |
| K5-06 | sentinel-lockdown | enforced | OK | lint=true, test=true, policy=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/06-sentinel-lockdown-enforced.png` |
| K5-07 | auto-return-spec | present | OK | kiosk-post-payment-auto-return.spec.js exists=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/07-auto-return-spec-present.png` |
| K5-08 | sentinel-offline | wired | OK | kioskOfflineQueue=true, db=true, V2 spec=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5/08-sentinel-offline-wired.png` |