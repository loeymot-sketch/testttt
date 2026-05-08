// FoodKing E2E — Flow 7 : Pusher-ACK baseURL doubling regression (A2 fix 2026-05-08)
//
// Pins at the runtime/integration layer the same invariant the unit test
// `tests/js/eventContractAck.spec.js` pins at the module layer:
//
//   window.axios.post('internal/pusher-ack', ...) → resolves to
//     <baseURL>/api/internal/pusher-ack  (single /api/, ✅)
//   NOT
//     <baseURL>/api/api/internal/pusher-ack (doubled /api/api/, ❌ 404)
//
// Ce test charge le VRAI bundle public/js/app.js servi par Laravel,
// donc applySharedAxiosDefaults() s'exécute pour de bon (baseURL '/api'
// posé via resources/js/shared/axios-setup.js:74). Si quelqu'un casse
// soit (a) la valeur de baseURL, soit (b) le code dans sendAck en
// re-introduisant le préfixe `/api/`, ce test fail.
//
// Plan de référence : ULTRA_PLAN_CORRECTION §A2 (Wave A YC GStack).

const { test, expect } = require('@playwright/test');

test.describe('Pusher-ACK — baseURL doubling regression (A2)', () => {
  test('window.axios.post("internal/pusher-ack") resolves to /api/internal/pusher-ack (no doubling)', async ({ page }) => {
    const captured = [];

    // Intercept BEFORE navigation so the route is set up when axios fires.
    // Match any URL that ends with `pusher-ack` so we catch the doubled form too if it ever happens.
    await page.route('**/pusher-ack', async (route) => {
      captured.push(route.request().url());
      await route.fulfill({ status: 204, body: '' });
    });

    // /login is the lightest page that triggers app.js bootstrap +
    // applySharedAxiosDefaults() (axios.defaults.baseURL = `${API_URL}/api`).
    const resp = await page.goto('/login');
    expect(resp?.status(), 'login page must serve 200').toBeLessThan(400);

    // Wait until window.axios is initialised + baseURL applied.
    await page.waitForFunction(
      () => typeof window.axios !== 'undefined'
        && typeof window.axios.defaults?.baseURL === 'string'
        && window.axios.defaults.baseURL.length > 0,
      { timeout: 15_000 },
    );

    const baseURL = await page.evaluate(() => window.axios.defaults.baseURL);
    // baseURL doit se terminer par `/api` (cf. resources/js/shared/axios-setup.js:74)
    expect(baseURL, `axios baseURL must end with /api but got "${baseURL}"`).toMatch(/\/api$/);

    // Fire the call exactly like sendAck() does in eventContract.js:85
    // — relative URL, no leading slash, no `/api/` prefix.
    await page.evaluate(() =>
      window.axios.post('internal/pusher-ack', { e2e_probe: true }, { timeout: 3000 })
        .catch(() => { /* silent fail expected: route fulfilled with 204 */ }),
    );

    // Wait for the interception (axios is async).
    await expect.poll(() => captured.length, { timeout: 5_000 }).toBeGreaterThan(0);

    const finalURL = captured[0];

    // 1. URL must contain `/api/internal/pusher-ack` exactly once.
    expect(finalURL).toMatch(/\/api\/internal\/pusher-ack(?:\?|$)/);

    // 2. URL MUST NOT contain the doubled `/api/api/` pattern.
    expect(
      finalURL,
      `REGRESSION: doubled /api/api/ detected — sendAck or axios baseURL broke. URL: ${finalURL}`,
    ).not.toMatch(/\/api\/api\//);

    // 3. Defensive: no `/api/api/internal/pusher-ack` literal.
    expect(finalURL).not.toMatch(/\/api\/api\/internal\/pusher-ack/);
  });

  test('axios baseURL is /api (or {origin}/api) — config invariant', async ({ page }) => {
    const resp = await page.goto('/login');
    expect(resp?.status()).toBeLessThan(400);

    await page.waitForFunction(
      () => typeof window.axios?.defaults?.baseURL === 'string' && window.axios.defaults.baseURL.length > 0,
      { timeout: 15_000 },
    );

    const baseURL = await page.evaluate(() => window.axios.defaults.baseURL);
    // Acceptable forms: '/api', 'http://host/api', 'https://host/api'.
    // Not acceptable: '/api/' (trailing slash → axios concat differs), '/api/api', etc.
    expect(baseURL).toMatch(/(^|\/)api$/);
    expect(baseURL).not.toMatch(/\/api\//);
    expect(baseURL).not.toMatch(/\/api\/api/);
  });
});
