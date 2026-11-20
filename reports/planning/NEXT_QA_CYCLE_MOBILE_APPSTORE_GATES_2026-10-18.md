# Next QA cycle — Mobile App Store external gates

Source report: `reports/execution/MOBILE-EXCELLENCE-MEGA-APPSTORE-20261018/report.md`

## Current evidence

- Local mobile E2E critical suite: 11/11 PASS.
- Server target issue resolved: port 8087 now serves the current `testttt/mobile` directory.
- Local order parity: PASS.

## Next actions

1. Create a backend/payment gate for remote promotion and pricing preview integration.
2. Configure Mollie/Apple Pay credentials and merchant capability; validate on a physical iPhone/TestFlight.
3. Configure APNs device-token registration and campaign scheduling; verify opt-in/out and deep links.
4. Supply owner-approved packshots for Orangina, Fuze Tea, Hawaï and Perrier; update the catalog asset gate.
5. Run signed TestFlight smoke: install, onboarding, OTP, catalog, personalization, checkout, Apple Pay,
   pickup confirmation, loyalty refresh and account deletion.

## Routing

This is a complex cross-domain cycle involving payment, notifications, auth and order lifecycle.
It requires architecture/gate review before implementation. No frontend workaround or hardcoded price
must be introduced while the external contracts are pending.
