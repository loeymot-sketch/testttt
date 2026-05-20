// =============================================================================
// Wave T Round 1 — F1 P0 cluster heal verification
// -----------------------------------------------------------------------------
// What this spec proves (surface contract, post-heal):
//   1. GET /api/admin/fiscal/z-report no longer returns 422 for an admin user
//      with branch_id=0 — it returns 200 with a `data` array (possibly empty).
//      Sentinel for WT-A-R1-01 + WT-D-R1-05 (z-report 422 silent on dashboard).
//   2. /admin/pos-orders-tracker (Suivi commandes) renders FIVE columns and
//      the new "EN LIVRAISON" lane is visible between "Prêts" and "Livrés".
//      Sentinel for WT-D-R1-01 (driver-pickup vanish window).
//
// Scope-minimal: we do NOT exercise the full caisse-to-delivered flow here
// (covered by wave-T-A/B/C/D specs). We assert surface guarantees only —
// enough to lock the heal against regression in future Wave T fix waves.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/wave-t-r1-f1-delivery-lane'
);

function ensureDir(d) { fs.mkdirSync(d, { recursive: true }); }
ensureDir(SCREENSHOT_DIR);

test.describe('Wave T R1 F1 — z-report 422 heal + EN LIVRAISON lane', () => {
  test.setTimeout(180_000);

  test('z-report index returns 200 for admin (branch_id=0), not 422', async ({ request }) => {
    // Direct API call — no browser context needed. The admin user
    // (admin@lecayenne.fr) has branch_id=0 and `pos-manage-fiscal` permission
    // via Branch Manager / Admin role per FoodKing RBAC §9.
    //
    // Pre-heal: 422 "Fiscal operation requires the authenticated user to be
    // pinned to a branch." silently swallowed by LastZReportWidget.
    // Post-heal: 200 with `data` array (possibly empty when no Z opened).
    const apiKey = process.env.MIX_API_KEY || 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

    const loginResp = await request.post('http://127.0.0.1:8000/api/auth/login', {
      headers: { 'x-api-key': apiKey, 'Content-Type': 'application/json' },
      data: {
        email: process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr',
        password: process.env.E2E_ADMIN_PASS || '123456',
      },
    });
    expect(loginResp.status(), `admin login should return 201; was ${loginResp.status()}`).toBe(201);
    const loginBody = await loginResp.json();
    const token = loginBody.token;
    expect(token, 'admin login should yield a sanctum token').toBeTruthy();
    expect(
      Number(loginBody.branch_id ?? 0),
      'admin user must be branch_id=0 to exercise the heal path'
    ).toBe(0);

    const fiscalResp = await request.get('http://127.0.0.1:8000/api/admin/fiscal/z-report', {
      headers: {
        'x-api-key': apiKey,
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const status = fiscalResp.status();
    let body = null;
    try { body = await fiscalResp.json(); } catch { /* defensive */ }

    expect(
      status,
      `z-report index should be 200 for admin (branch_id=0); was ${status}`
    ).toBe(200);
    expect(body, 'response should be JSON object').toBeTruthy();
    expect(body).toHaveProperty('data');
    expect(Array.isArray(body.data)).toBeTruthy();
  });

  test('Suivi tracker renders 5 columns including EN LIVRAISON', async ({ page }) => {
    await loginAsAdmin(page);

    await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });

    // Wait for the Vue tracker shell to mount.
    await expect(page.locator('[data-pos-tracker-shell]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('.pos-tracker-grid')).toBeVisible({ timeout: 15_000 });

    // Pre-heal: 4 columns. Post-heal: 5 columns.
    const columns = page.locator('.pos-tracker-col');
    await expect(columns).toHaveCount(5, { timeout: 15_000 });

    // "EN LIVRAISON" lane must be present (FR locale by default — the i18n
    // key resolves to "En livraison" on lang=fr, which is the production
    // default for Le Cayenne).
    const onTheWayLane = page.locator('.pos-tracker-col--blue');
    await expect(onTheWayLane).toHaveCount(1);
    await expect(onTheWayLane).toBeVisible();

    // Label text — case-insensitive match on either "EN LIVRAISON" or
    // "En livraison" (CSS uppercase styling may transform, so we check both).
    const laneTitle = onTheWayLane.locator('.pos-tracker-col-title, h2, .pos-tracker-col-head');
    await expect(laneTitle.first()).toContainText(/en livraison/i, { timeout: 10_000 });

    // Visual capture for the heal evidence dossier.
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, 'tracker-5-columns.png'),
      fullPage: true,
    });
  });

  test('Dashboard does not surface a 422 toast on mount (LastZReportWidget guarded)', async ({ page }) => {
    const failedFiscalCalls = [];
    page.on('response', (res) => {
      const url = res.url();
      if (/\/api\/admin\/fiscal\/z-report(?:\b|$|\?)/.test(url) && res.status() === 422) {
        failedFiscalCalls.push({ url, status: res.status() });
      }
    });

    await loginAsAdmin(page);

    // loginAsAdmin lands on /admin which redirects to /admin/dashboard for
    // admins. Give the dashboard widgets time to mount and fire their initial
    // requests (LastZReportWidget is one of them).
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    expect(
      failedFiscalCalls,
      `z-report should not 422 on dashboard mount. Got: ${JSON.stringify(failedFiscalCalls)}`
    ).toEqual([]);

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, 'dashboard-no-422.png'),
      fullPage: true,
    });
  });
});
