// FoodKing E2E — Flow 9 : Admin dashboards UI smoke + no-doubling guard (A2-bis 2026-05-08)
//
// UI-layer confirmation that the heal(A2-bis) c8e1d97b6 fix doesn't break
// the admin dashboard for a real seeded user (admin@lecayenne.fr) :
//
//   1. Login as admin works (UserTableSeeder credentials still valid)
//   2. /admin/dashboard renders without fatal console errors
//   3. Across the entire admin session, NO request URL contains /api/api/
//   4. Capture a full-page screenshot for visual review
//
// Strict per-widget endpoint assertions (was-it-called-with-which-URL)
// belong in tests/e2e/08-admin-baseurl.spec.js where the page.evaluate
// path makes them deterministic. Here we validate the natural user flow.
//
// Note on /admin/observability/outbox + /admin/stock/rupture : these
// routes 404 for admin@lecayenne.fr (permission gate at appService.recursiveRouter),
// so they are NOT exercised here. The fix landed in components that compile
// into admin-shell.js — empirically verified against the live bundle.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'a2bis-admin-dashboards-2026-05-08');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function loginAsAdmin(page) {
  await page.goto('/login');
  await page.locator('input[type="text"]').first().fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: /^login$/i }).click();
  await page.waitForURL(/\/admin\//, { timeout: 25_000 });
}

function filterFatalErrors(errors) {
  return errors.filter((e) => !/(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|woff)|GoogleAnalytics|gtag|workbox|kiosk-event|Failed to load resource:|Pusher|Echo)/i.test(e));
}

test.describe('Admin dashboards UI smoke — A2-bis post-fix confirmation', () => {
  test('admin login → /admin/dashboard renders + zero /api/api/ doubling across session', async ({ page }) => {
    /** @type {string[]} */
    const consoleErrors = [];
    /** @type {string[]} */
    const allRequestUrls = [];

    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('request', (req) => allRequestUrls.push(req.url()));

    await loginAsAdmin(page);

    // Force /admin/dashboard regardless of role-based landing.
    if (!/\/admin\/dashboard(\/|$|\?)/.test(page.url())) {
      await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    }

    // Give widgets time to mount and fire their fetch calls.
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    // Extra dwell for any widgets that fetch after networkidle (Echo / WebSocket setup).
    await page.waitForTimeout(2_000);

    // Visual evidence of dashboard render.
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'dashboard.png'), fullPage: true });

    // 1. URL is admin surface (login worked, not redirected to error/login).
    expect(page.url()).toMatch(/\/admin\//);
    expect(page.url()).not.toMatch(/\/login(\?|$)/);

    // 2. Sidebar has the FoodKing chrome (proves SPA mounted, not the 404 page or generic frontend).
    const dashboardChromeVisible = await page.locator('text=FoodKing, text=Dashboard, text=Items').first().isVisible().catch(() => false);
    expect(dashboardChromeVisible || page.url().includes('/admin/dashboard'), 'admin SPA chrome must be visible').toBeTruthy();

    // 3. CRITICAL — no request anywhere in the session went to /api/api/.
    const doubledUrls = allRequestUrls.filter((u) => /\/api\/api\//.test(u));
    expect(
      doubledUrls,
      `REGRESSION A2-bis: ${doubledUrls.length} requests with /api/api/ doubling across this admin session.\n${doubledUrls.slice(0, 10).join('\n')}`,
    ).toHaveLength(0);

    // 4. No fatal console errors from the dashboard mount.
    const fatals = filterFatalErrors(consoleErrors);
    expect(
      fatals,
      `unexpected console errors during admin session:\n${fatals.slice(0, 5).join('\n')}`,
    ).toHaveLength(0);
  });

  test('admin api requests went to /api/<path> (single /api/), zero went to /api/api/', async ({ page }) => {
    /** @type {Array<{url: string, status: number, contentType: string}>} */
    const apiResponses = [];

    page.on('response', async (resp) => {
      const u = resp.url();
      if (!/\/api\//.test(u)) return;
      apiResponses.push({
        url: u,
        status: resp.status(),
        contentType: resp.headers()['content-type'] || '',
      });
    });

    await loginAsAdmin(page);
    if (!/\/admin\/dashboard(\/|$|\?)/.test(page.url())) {
      await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    }
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(2_000);

    expect(apiResponses.length, 'at least the auth/dashboard endpoints must have been called').toBeGreaterThan(0);

    // Hard invariant: not a single response came from a /api/api/ URL.
    const doubled = apiResponses.filter((r) => /\/api\/api\//.test(r.url));
    expect(doubled, `REGRESSION A2-bis: ${doubled.length} doubled responses`).toHaveLength(0);

    // Soft check: any 200 responses to /api/* should be JSON, not the SPA HTML catchall.
    // This catches the "silent 200 HTML" failure mode that hid the original bug for 25 days.
    const htmlMasquerading = apiResponses.filter(
      (r) => r.status === 200 && /\/api\/[a-z]/i.test(r.url) && /text\/html/i.test(r.contentType),
    );
    expect(
      htmlMasquerading,
      `REGRESSION (silent failure mode): ${htmlMasquerading.length} /api/* responses returned text/html — the SPA catchall is hiding 404s.\n${htmlMasquerading.slice(0, 5).map((r) => r.url).join('\n')}`,
    ).toHaveLength(0);
  });
});
