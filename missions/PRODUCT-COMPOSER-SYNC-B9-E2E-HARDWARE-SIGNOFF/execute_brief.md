# Execute Brief - PRODUCT-COMPOSER-SYNC-B9-E2E-HARDWARE-SIGNOFF

## Intent

Validate the Product Composer / POS / Kiosk / KDS / payment sync delivery with browser evidence and prepare the hardware signoff packet.

## Scope

- Add `tests/e2e/composer-mega-flow.spec.js`.
- Exercise the cash-at-counter lifecycle:
  - pending kiosk cash order visible on KDS with `PAIEMENT COMPTOIR - NON REGLE`;
  - POS sees the pending kiosk cash order;
  - POS collect action confirms payment;
  - `fiscal_sequence_no` is allocated only on collect;
  - cancel path moves to `REFUNDED/CANCELED` and keeps `fiscal_sequence_no` null.
- Produce a signable hardware UAT checklist.
- Produce final local audit evidence.

## Forbidden

- No product-code changes.
- No service-layer bypass of NF525 audit guards.
- No deletion of `audit_logs`.
- No commercial PASS without signed physical hardware evidence.

## Validation

- `npx playwright test tests/e2e/composer-mega-flow.spec.js --project=chromium --retries=0`
- `npx playwright test tests/e2e/composer-mega-flow.spec.js tests/e2e/kiosk-lockdown.spec.js tests/e2e/01-auth-refresh.spec.js tests/e2e/02-pos-cash.spec.js tests/e2e/03-kiosk-wizard.spec.js tests/e2e/04-kds-status.spec.js tests/e2e/05-pos-card.spec.js tests/Playwright/kiosk-legacy-redirect.spec.js tests/Playwright/kiosk-order-type-required.spec.js tests/Playwright/pos-receives-kiosk-realtime.spec.js --project=chromium`
- `git diff --check`

## Exit Criteria

Local verdict can be PASS only if all automated checks pass. Release verdict remains HARDWARE_PENDING until TPE, printer, kiosk touch screen, KDS screen, and network-loss hardware flows are signed by the human operator.
