/**
 * Wave Y D1 verification — single-toast on burst 401 refresh
 * 2026-05-21
 *
 * Bug A-003 (Wave Y Round 1):
 *   When N concurrent kiosk requests race on a 401, app.js' response
 *   interceptor coalesces ONE re-login via `kioskCart/kioskLogin`
 *   (single-flight `_inFlightKioskLogin`), but then dispatches
 *   `kiosk-auth-retried` once PER replayed request. KioskAppComponent
 *   listens on that CustomEvent and shows a toast each time —
 *   resulting in N overlapping "Session rafraîchie automatiquement"
 *   toasts that mask the cart "Payer" CTA.
 *
 * Fix (resources/js/helpers/kioskAuthInterceptor.js, wired from
 * bootstrap-kiosk.js):
 *   Capture-phase window listener on `kiosk-auth-retried` (and
 *   `kiosk-auth-failed`) with a 1500ms debounce window. The FIRST
 *   event passes through; subsequent ones within the window are
 *   swallowed via `stopImmediatePropagation()`. KioskAppComponent's
 *   bubble-phase listener stays intact — it just sees fewer events.
 *
 * Verification strategy:
 *   We do NOT need to actually expire the Sanctum token + fire real
 *   401s — that requires server state we can't deterministically
 *   reproduce in CI. Instead we directly dispatch N parallel
 *   `kiosk-auth-retried` CustomEvents via `page.evaluate`, exactly
 *   the way app.js:125 does. This isolates the EVENT-LAYER debounce
 *   we just installed, which is the exact layer the bug lives at.
 *
 *   Assertion: after a 5-event burst, exactly ONE
 *   "Session rafraîchie" toast is visible in the DOM.
 *
 * Frozen-zone discipline: zero touch to KioskAppComponent.vue,
 * KioskWizardComponent.vue, app.js. We only assert behavior.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_DIR = path.resolve(
  __dirname,
  '..',
  '..',
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/d1-axios-interceptor'
);
fs.mkdirSync(OUT_DIR, { recursive: true });

test.describe('Wave Y D1 — kioskAuthInterceptor single-flight event debounce', () => {
  test('5 parallel kiosk-auth-retried dispatches → exactly 1 toast', async ({ page }) => {
    // Land on a kiosk page so KioskAppComponent is mounted and
    // window.addEventListener('kiosk-auth-retried', …) is wired.
    // /kiosk/idle is the kiosk root and does not require auth gating.
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });

    // Wait until the helper module installed its capture-phase listener.
    await page.waitForFunction(() => {
      return typeof window.__kioskAuthInterceptorInstalled !== 'undefined'
        && window.__kioskAuthInterceptorInstalled === true;
    }, { timeout: 15_000 });

    // ALSO wait for the Vue root KioskAppComponent to have actually
    // mounted — its `mounted()` hook is what attaches the bubble-phase
    // `kiosk-auth-retried` listener that calls $refs.toast.show(). The
    // capture-phase interceptor is installed earlier (bootstrap-kiosk),
    // so without this wait the FIRST event passes the debounce but no
    // downstream listener exists yet → zero toasts. The toast container
    // element is rendered unconditionally inside KioskAppComponent, so
    // its presence proves the parent component has mounted.
    await page.locator('.kiosk-toast-container').waitFor({ state: 'attached', timeout: 15_000 });

    // Sanity: the test reset hook is exposed by the helper module.
    const resetExposed = await page.evaluate(
      () => typeof window.__resetKioskAuthInterceptorForTests === 'function'
    );
    expect(resetExposed).toBe(true);

    // Reset debounce timestamps so a previous test run / page load
    // does not consume our window.
    await page.evaluate(() => window.__resetKioskAuthInterceptorForTests());

    // Fire 5 CustomEvents in a single microtask burst — exactly what
    // app.js:125 does when N parallel 401s share one re-login.
    await page.evaluate(() => {
      for (let i = 0; i < 5; i += 1) {
        window.dispatchEvent(new CustomEvent('kiosk-auth-retried', {
          detail: { url: `/api/test/burst-${i}`, status: 401 },
        }));
      }
    });

    // Toast TTL in KioskAppComponent is 2500ms; we sample at ~600ms
    // when the burst has settled but the toast is still rendered.
    await page.waitForTimeout(600);

    // Count toasts whose text matches the session-refresh copy.
    // Selector: the kiosk toast container uses `.kiosk-toast` per
    // KioskToastComponent.vue:13. Filter by message text.
    const toastLocator = page.locator('.kiosk-toast', {
      hasText: 'Session rafraîchie',
    });
    const count = await toastLocator.count();

    // Screenshot proof BEFORE assertion so a failure run still leaves
    // evidence on disk.
    const screenshotPath = path.join(OUT_DIR, 'D1-single-toast-after-burst.png');
    await page.screenshot({ path: screenshotPath, fullPage: false });

    // The only acceptable outcome is exactly 1 toast.
    expect(count, `Expected exactly 1 "Session rafraîchie" toast after 5-event burst; got ${count}`).toBe(1);
  });

  test('debounce window expires → second burst delivers a fresh toast', async ({ page }) => {
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.__kioskAuthInterceptorInstalled === true, { timeout: 15_000 });
    await page.locator('.kiosk-toast-container').waitFor({ state: 'attached', timeout: 15_000 });
    await page.evaluate(() => window.__resetKioskAuthInterceptorForTests());

    // Burst 1
    await page.evaluate(() => {
      for (let i = 0; i < 3; i += 1) {
        window.dispatchEvent(new CustomEvent('kiosk-auth-retried', {
          detail: { url: `/api/test/b1-${i}`, status: 401 },
        }));
      }
    });
    await page.waitForTimeout(300);
    let count = await page.locator('.kiosk-toast', { hasText: 'Session rafraîchie' }).count();
    expect(count).toBe(1);

    // Wait past KioskAppComponent's 2500ms TTL so the first toast clears,
    // AND past the interceptor's 1500ms debounce window so the second
    // burst can deliver a fresh signal.
    await page.waitForTimeout(2900);

    // Burst 2 — debounce should have released; one fresh toast expected.
    await page.evaluate(() => {
      for (let i = 0; i < 3; i += 1) {
        window.dispatchEvent(new CustomEvent('kiosk-auth-retried', {
          detail: { url: `/api/test/b2-${i}`, status: 401 },
        }));
      }
    });
    await page.waitForTimeout(300);
    count = await page.locator('.kiosk-toast', { hasText: 'Session rafraîchie' }).count();
    expect(count).toBe(1);
  });
});
