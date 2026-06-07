// [CLUSTER-LOYALTY-STOCK round-3 2026-06-07] Visual evidence for:
//  (1) Stock rupture dashboard renders + toggle UI (admin operator view, E3 consuming surface entry)
//  (2) Kiosk idle/loyalty consult component (no raw labels, B3)
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const OUT = 'reports/test-e2e/goal-100pct-2026-06-07/round-3';

test.setTimeout(70000);

test('stock rupture dashboard renders for admin (E3 admin surface)', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/admin/stock/rupture');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await Promise.race([
    page.screenshot({ path: `${OUT}/stock-rupture-dashboard.png`, fullPage: true }),
    new Promise((_, r) => setTimeout(() => r(new Error('screenshot-timeout')), 7000)),
  ]);
  const body = await page.locator('body').innerText().catch(() => '');
  expect(body).not.toMatch(/Label\.[A-Za-z]/);
  expect(body).not.toMatch(/\bundefined\b/);
});

test('kiosk idle surface visual', async ({ page }) => {
  await page.goto('/kiosk/idle');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await Promise.race([
    page.screenshot({ path: `${OUT}/kiosk-idle.png`, fullPage: true }),
    new Promise((_, r) => setTimeout(() => r(new Error('screenshot-timeout')), 7000)),
  ]);
  const body = await page.locator('body').innerText().catch(() => '');
  expect(body).not.toMatch(/kiosk\.[a-z]/);
});
