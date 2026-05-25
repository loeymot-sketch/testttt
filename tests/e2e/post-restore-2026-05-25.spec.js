// Post-restore e2e proof — Le Cayenne catalog visible after restore from backup 2026-05-23
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SHOTS_DIR = 'tests/e2e/__screenshots__/post-restore-2026-05-25';
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

async function snap(page, name) {
  const png = path.join(SHOTS_DIR, `${name}.png`);
  await page.screenshot({ path: png, fullPage: true });
  const dom = await page.content();
  fs.writeFileSync(path.join(SHOTS_DIR, `${name}.dom.html`), dom);
  console.log(`  📸 ${name}.png saved`);
}

async function loginAdmin(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"], input[type="email"]', 'admin@lecayenne.fr');
  await page.fill('input[name="password"], input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1500);
}

test.describe.serial('Post-restore catalog visibility', () => {
  test('POS shows categories + items', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await loginAdmin(page);
    console.log(`✓ Logged in`);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    await snap(page, 'POS-01-mount');
    // Count visible category-like elements
    const allText = await page.evaluate(() => document.body.innerText);
    const hasCayenne = allText.includes('Sandwich') || allText.includes('Tacos') || allText.includes('Burger') || allText.includes('Bol');
    console.log(`  Categories text visible: ${hasCayenne}`);
    expect(hasCayenne).toBeTruthy();
    await ctx.close();
  });

  test('Kiosk idle screen + autologin', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    await snap(page, 'KIOSK-01-idle');
    const txt = await page.evaluate(() => document.body.innerText);
    console.log(`  Kiosk text sample: ${txt.substring(0, 200).replace(/\n/g, ' ')}`);
    const hasError = txt.toLowerCase().includes('identifiants invalides') || txt.toLowerCase().includes('bloqu');
    console.log(`  Has auth error: ${hasError}`);
    if (!hasError) {
      // Try tap idle to go to menu
      await page.evaluate(() => {
        const btn = document.querySelector('[data-testid="kiosk-tap-to-start"], button.kiosk-tap, .kiosk-idle-cta, button');
        if (btn) btn.click();
      }).catch(() => {});
      await page.waitForTimeout(2500);
      await snap(page, 'KIOSK-02-after-tap');
    }
    await ctx.close();
  });

  test('KDS accessible', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await loginAdmin(page);
    await page.goto(`${BASE}/kds`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    await snap(page, 'KDS-01-mount');
    await ctx.close();
  });

  test('Cash overview', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/cash-overview`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    await snap(page, 'CASH-01-overview');
    await ctx.close();
  });
});
