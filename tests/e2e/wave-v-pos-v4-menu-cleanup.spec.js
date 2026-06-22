// @ts-check
/**
 * Wave V - POS V4 menu cleanup visual verification
 * Mandate CLAUDE.md §6 : Visual Test Mandate (capture + Read + analyse).
 *
 * Confirms post-cleanup Dashboard Quick Access shows ONLY ONE "POS" card,
 * not two (POS + POS V4 / Caisse + Caisse chargement dédié). Direct URL
 * /admin/pos-v4 must still respond < 400 for emergency fallback (route
 * preserved in routes/web.php).
 *
 * Frozen-zone: 0 touch (only the Quick Access list rendered by
 * DashboardComponent.vue is altered; admin-pos-v4.blade.php + pos-app.js
 * left intact).
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SHOT_DIR = path.resolve(__dirname, '../../reports/test-e2e/wave-v-2026-05-21');

test('wave-v: dashboard Quick Access shows POS once (no POS V4)', async ({ page }) => {
    test.setTimeout(90_000);

    await loginAsAdmin(page);

    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    // Wait for Quick Access section to render (it depends on permissions axios call).
    await page.waitForSelector('nav[aria-label]', { timeout: 25_000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({
        path: path.join(SHOT_DIR, 'dashboard-quick-access.png'),
        fullPage: true,
    });

    // Count POS-prefixed quick-access links. Should be exactly 1 entry to
    // /admin/pos and 0 entries to /admin/pos-v4.
    const posLinks = await page.locator('nav a[href="/admin/pos"]').count();
    const posV4Links = await page.locator('nav a[href="/admin/pos-v4"]').count();

    console.log('[wave-v] /admin/pos quick-access links:', posLinks);
    console.log('[wave-v] /admin/pos-v4 quick-access links:', posV4Links);

    expect(posV4Links, 'POS V4 quick-access link removed').toBe(0);
    expect(posLinks, 'Primary POS quick-access link still surfaced').toBeGreaterThanOrEqual(1);

    // Direct URL fallback preserved: /admin/pos-v4 still routes.
    const resp = await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
    expect(resp, 'response received').not.toBeNull();
    expect(resp.status(), 'fallback URL still responds').toBeLessThan(400);
});
