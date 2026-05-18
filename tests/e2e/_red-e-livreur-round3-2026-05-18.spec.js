// RED-E Livreur visual gate — admin delivery-boys management UI capture.
// Livreur is API-only V1; visual = admin-side mgmt screens.
const { test } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const OUT = '/tmp/foodking-goal-round3/red-e-livreur';

test.describe('RED-E Livreur — visual capture', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('admin delivery-boys list', async ({ page }) => {
    await page.goto('/admin/delivery-boys', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/01-admin-delivery-boys-list.png`, fullPage: true });
  });

  test('admin online-orders index', async ({ page }) => {
    await page.goto('/admin/online-orders', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/02-admin-online-orders.png`, fullPage: true });
  });

  test('admin pos-orders index', async ({ page }) => {
    await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/03-admin-pos-orders.png`, fullPage: true });
  });
});
