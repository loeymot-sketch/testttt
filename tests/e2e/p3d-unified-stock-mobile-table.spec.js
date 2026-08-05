// [PHASE 3d-UI 2026-07-24] Confirm the matières table reflows to STACKED CARDS on mobile.
const { test } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const OUT_DIR = path.join(__dirname, '__screenshots__', 'p3d-unified');
fs.mkdirSync(OUT_DIR, { recursive: true });

test('P3d — mobile matières table reflow (stacked cards)', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('#formEmail').fill('admin@lecayenne.fr');
    await page.locator('#formPassword').fill('123456');
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 }).catch(() => {});

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/stock/unified', { waitUntil: 'networkidle', timeout: 30000 });
    await page.locator('[data-testid="usv-raw"]').waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(6000);
    await page.locator('[data-testid="usv-raw"]').scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(OUT_DIR, 'mobile-table.png'), fullPage: false });
});
