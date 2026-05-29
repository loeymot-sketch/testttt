const { test, expect } = require('@playwright/test');

test.describe('Stock Cascade Visual S1', () => {
  test.setTimeout(120000);

  test('S1 visual: kiosk badge + admin rupture page', async ({ page, context }) => {
    const OUT = '/tmp/foodking-wave-e-2026-05-29/stkcsc';

    // 1) Capture kiosk catalog initial
    await page.goto('http://127.0.0.1:8000/kiosk/idle', { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${OUT}/visual-01-kiosk-idle.png`, fullPage: false });

    // Click to enter catalog
    try {
      const startBtn = page.locator('button, [role="button"]').filter({ hasText: /commander|commencer|start|tap|touch/i }).first();
      if (await startBtn.isVisible({ timeout: 3000 })) {
        await startBtn.click();
        await page.waitForTimeout(3000);
      } else {
        await page.locator('body').click({ position: { x: 400, y: 400 } });
        await page.waitForTimeout(3000);
      }
    } catch(e) { console.log('start click skipped:', e.message); }

    await page.screenshot({ path: `${OUT}/visual-02-kiosk-catalog-pre.png`, fullPage: true });

    // 2) Admin login + go to rupture
    const admin = await context.newPage();
    await admin.goto('http://127.0.0.1:8000/login', { waitUntil: 'networkidle' });
    await admin.fill('input[name="email"], input[type="email"]', 'admin@lecayenne.fr').catch(()=>{});
    await admin.fill('input[name="password"], input[type="password"]', '123456').catch(()=>{});
    await admin.locator('button[type="submit"]').first().click().catch(()=>{});
    await admin.waitForTimeout(3000);
    await admin.screenshot({ path: `${OUT}/visual-03-admin-after-login.png` });

    await admin.goto('http://127.0.0.1:8000/admin/stock-rupture-dashboard', { waitUntil: 'networkidle' }).catch(()=>{});
    await admin.waitForTimeout(2500);
    await admin.screenshot({ path: `${OUT}/visual-04-admin-rupture-dashboard.png`, fullPage: true });

    console.log('VISUAL CAPTURES SAVED to ' + OUT);
  });
});
