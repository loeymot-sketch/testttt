import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [GOAL-2026-05-29 BTN-P1 DEAD-BUTTON-FIX] The kiosk payment-refused screen
 * (route kiosk.error.payment-refused) showed "Réessayer le paiement" and
 * "Payer au comptoir" CTAs, but retryPayment()/payCounter() ONLY $emit()'d
 * ('retry' / 'pay-at-counter') — and this screen is a ROUTE, not a child of
 * the (frozen) KioskAppComponent, so the emits reached ZERO listener with no
 * router fallback => dead buttons. (cancelOrder already worked because it also
 * pushed to kiosk.idle.) Surface-button agent-army audit, P1.
 *
 * Fix (non-frozen component): mirror cancelOrder's working pattern — keep the
 * emit (future parent listener) + add a $router.push fallback:
 *   retry      -> kiosk.payment        (re-attempt on the vuex-persisted cart)
 *   pay-counter-> kiosk.cash-instruction (Plan-B counter flow; guard-safe redirect)
 * Route names verified in resources/js/router/modules/kioskRoutes.js (205, 245).
 * Latent under Plan B (route_all_to_counter never attempts TPE); live when TPE wired.
 * This sentinel locks the wiring against regression.
 */
const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue'),
  'utf-8',
);

// Isolate each handler body so a stray router.push elsewhere can't satisfy the assertion.
function methodBody(name) {
  const m = src.match(new RegExp(`${name}\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)\\n        \\}`));
  return m ? m[1] : '';
}

describe('KioskErrorPaymentRefused — Retry & Pay-at-counter CTAs are wired (not dead emits)', () => {
  it('retryPayment navigates to kiosk.payment (router fallback, not emit-only)', () => {
    const body = methodBody('retryPayment');
    expect(body).toMatch(/\$router\??\.push\(\s*\{\s*name:\s*['"]kiosk\.payment['"]/);
  });

  it('payCounter navigates to kiosk.cash-instruction (router fallback, not emit-only)', () => {
    const body = methodBody('payCounter');
    expect(body).toMatch(/\$router\??\.push\(\s*\{\s*name:\s*['"]kiosk\.cash-instruction['"]/);
  });

  it('cancelOrder still routes to kiosk.idle (unchanged working baseline)', () => {
    const body = methodBody('cancelOrder');
    expect(body).toMatch(/\$router\??\.push\(\s*\{\s*name:\s*['"]kiosk\.idle['"]/);
  });
});
