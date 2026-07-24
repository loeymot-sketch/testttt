// [PHASE 3d-UI — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Visual mandate capture.
// Login admin → /admin/stock/unified → capture desktop (full page) + mobile
// (390x844 viewport) + probe for raw-label leaks and the endpoint status.
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const OUT_DIR = path.join(__dirname, '__screenshots__', 'p3d-unified');
fs.mkdirSync(OUT_DIR, { recursive: true });

async function loginAdmin(page) {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
    const emailField = page.locator('#formEmail');
    const passwordField = page.locator('#formPassword');
    await emailField.waitFor({ state: 'visible', timeout: 15000 });
    await emailField.fill('admin@lecayenne.fr');
    await passwordField.fill('123456');
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 }).catch(() => {});
    await page.waitForTimeout(1500);
}

test('P3d — unified stock view capture (desktop + mobile)', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

    let overviewStatus = null;
    page.on('response', (r) => {
        if (r.url().includes('admin/stock/unified-overview')) overviewStatus = r.status();
    });

    await loginAdmin(page);

    // Desktop
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin/stock/unified', { waitUntil: 'networkidle', timeout: 30000 });
    await page.locator('[data-testid="unified-stock-view"]').waitFor({ state: 'visible', timeout: 20000 });
    // Wait for the fetch to resolve into either data or the empty/error state.
    await page.waitForTimeout(15000); // hydration + overview fetch per §6 mandate
    await page.screenshot({ path: path.join(OUT_DIR, 'desktop.png'), fullPage: true });

    const bodyText = await page.locator('[data-testid="unified-stock-view"]').innerText();
    const rawLeak = /admin\.unified_stock\.|menu\.stock_unified|undefined|NaN|\bLabel\./.test(bodyText);
    const hasToBuy = await page.locator('[data-testid="usv-tobuy"]').count();
    const hasRaw = await page.locator('[data-testid="usv-raw"]').count();
    const hasResold = await page.locator('[data-testid="usv-resold"]').count();
    const hasEmpty = await page.locator('[data-testid="usv-empty"]').count();
    const hasError = await page.locator('[data-testid="usv-error"]').count();

    // Mobile viewport (capture viewport, not fullPage, to avoid downscaling)
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/stock/unified', { waitUntil: 'networkidle', timeout: 30000 });
    await page.locator('[data-testid="unified-stock-view"]').waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(6000);
    await page.screenshot({ path: path.join(OUT_DIR, 'mobile.png'), fullPage: false });

    // Structured evidence for the agent to read alongside the images.
    console.log('P3D_EVIDENCE ' + JSON.stringify({
        overviewStatus,
        rawLeak,
        hasToBuy,
        hasRaw,
        hasResold,
        hasEmpty,
        hasError,
        consoleErrors: consoleErrors.slice(0, 8),
        textHead: bodyText.slice(0, 400),
    }));

    // Hard gates: endpoint must succeed, error state must not be the render, no raw label leak.
    expect(overviewStatus, 'unified-overview HTTP status').toBe(200);
    expect(hasError, 'error state must not render').toBe(0);
    expect(rawLeak, 'no raw i18n key / undefined / NaN leak in the screen text').toBe(false);
});
