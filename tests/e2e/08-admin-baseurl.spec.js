// FoodKing E2E — Flow 8 : Admin endpoints baseURL doubling regression (A2-bis 2026-05-08)
//
// Same class of bug as A2 (eventContract.js Pusher-ACK) — but in the admin
// surface. Eight axios calls across four components were calling
// `/api/admin/...` (leading `/api/`) while axios.defaults.baseURL is already
// `<API_URL>/api` (resources/js/shared/axios-setup.js:74), producing
// `<API_URL>/api/api/admin/...` 404 silently broken in production.
//
// Affected components healed in this commit:
//   - resources/js/components/admin/observability/OutboxOverviewComponent.vue (3 calls)
//   - resources/js/components/admin/dashboard/StockLowAlertsWidget.vue        (1 call)
//   - resources/js/components/admin/dashboard/LastZReportWidget.vue           (1 call)
//   - resources/js/components/admin/stock/StockRuptureDashboardComponent.vue  (3 calls)
//
// Fix : `/api/admin/...` → `admin/...` (RELATIVE, no leading slash).
// Same convention as the A2 pusher-ack fix.
//
// This spec loads the REAL public/js/app.js bundle so applySharedAxiosDefaults()
// runs for real and we observe what URL axios actually produces at runtime.

const { test, expect } = require('@playwright/test');

const ADMIN_ENDPOINTS = [
  // (method, relative-url-as-coded, expected-resolved-path)
  ['get',  'admin/observability/outbox',                 '/api/admin/observability/outbox'],
  ['post', 'admin/observability/outbox/retry-failed',    '/api/admin/observability/outbox/retry-failed'],
  ['post', 'admin/observability/outbox/drain-failed',    '/api/admin/observability/outbox/drain-failed'],
  ['get',  'admin/fiscal/z-report',                      '/api/admin/fiscal/z-report'],
  ['get',  'admin/stock/low-alerts',                     '/api/admin/stock/low-alerts'],
  ['get',  'admin/stock/scan-rupture/last-summary',      '/api/admin/stock/scan-rupture/last-summary'],
  ['post', 'admin/stock/scan-rupture/run',               '/api/admin/stock/scan-rupture/run'],
];

test.describe('Admin axios — baseURL doubling regression (A2-bis)', () => {
  test('every healed admin call resolves to /api/admin/... (no /api/api/ doubling)', async ({ page }) => {
    /** @type {Array<{method: string, url: string}>} */
    const captured = [];

    // Intercept any URL containing /admin/ to catch both correct and doubled forms.
    await page.route('**/admin/**', async (route) => {
      captured.push({ method: route.request().method(), url: route.request().url() });
      await route.fulfill({ status: 204, body: '' });
    });

    const resp = await page.goto('/login');
    expect(resp?.status(), 'login page must serve 200').toBeLessThan(400);

    await page.waitForFunction(
      () => typeof window.axios?.defaults?.baseURL === 'string' && window.axios.defaults.baseURL.length > 0,
      { timeout: 15_000 },
    );

    const baseURL = await page.evaluate(() => window.axios.defaults.baseURL);
    expect(baseURL, `axios baseURL must end with /api but got "${baseURL}"`).toMatch(/\/api$/);

    // Fire each call exactly like the components do (relative URL, no `/api/` prefix).
    await page.evaluate(async (calls) => {
      for (const [method, url] of calls) {
        try {
          if (method === 'get')  await window.axios.get(url);
          if (method === 'post') await window.axios.post(url, {});
        } catch (_) { /* fulfilled with 204, no body — that's fine */ }
      }
    }, ADMIN_ENDPOINTS.map(([m, u]) => [m, u]));

    await expect.poll(() => captured.length, { timeout: 5_000 }).toBe(ADMIN_ENDPOINTS.length);

    // Per-endpoint assertions
    for (let i = 0; i < ADMIN_ENDPOINTS.length; i++) {
      const [method, , expectedPath] = ADMIN_ENDPOINTS[i];
      const got = captured[i];

      // 1. Method preserved.
      expect(got.method.toLowerCase(), `endpoint #${i} method`).toBe(method);

      // 2. URL contains the expected single-/api/ path.
      expect(
        got.url,
        `endpoint #${i}: expected URL to end with "${expectedPath}" but got "${got.url}"`,
      ).toMatch(new RegExp(expectedPath.replace(/\//g, '\\/') + '(?:\\?|$)'));

      // 3. URL MUST NOT contain the /api/api/ doubled prefix.
      expect(
        got.url,
        `REGRESSION endpoint #${i}: doubled /api/api/ — "${got.url}"`,
      ).not.toMatch(/\/api\/api\//);
    }
  });

  test('axios baseURL config invariant (ends with /api, not doubled)', async ({ page }) => {
    const resp = await page.goto('/login');
    expect(resp?.status()).toBeLessThan(400);

    await page.waitForFunction(
      () => typeof window.axios?.defaults?.baseURL === 'string' && window.axios.defaults.baseURL.length > 0,
      { timeout: 15_000 },
    );

    const baseURL = await page.evaluate(() => window.axios.defaults.baseURL);
    expect(baseURL).toMatch(/(^|\/)api$/);
    expect(baseURL).not.toMatch(/\/api\//);
    expect(baseURL).not.toMatch(/\/api\/api/);
  });
});
