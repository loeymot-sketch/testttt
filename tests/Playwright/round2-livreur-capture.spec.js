// Round 2 LIVREUR audit — read-only capture
const { test, expect } = require('@playwright/test');

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';
const BASE_URL = 'http://127.0.0.1:8000';
const OUT = '/tmp/foodking-round2-livreur';

async function login(page) {
  await page.goto(`${BASE_URL}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('input[type="email"], input[name="email"], input#email', ADMIN_EMAIL);
  await page.fill('input[type="password"], input[name="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
}

test('LIVREUR admin oversight screens', async ({ page }) => {
  test.setTimeout(120000);

  await login(page);
  await page.screenshot({ path: `${OUT}/00-after-login.png`, fullPage: true });

  // 1. Delivery boys list
  await page.goto(`${BASE_URL}/admin/delivery-boys`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/01-delivery-boys-list.png`, fullPage: true });

  // 2. Cash session list
  await page.goto(`${BASE_URL}/admin/delivery-boy-cash-sessions`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/02-cash-session-list.png`, fullPage: true });

  // 3. Cash session detail (session id 1)
  await page.goto(`${BASE_URL}/admin/delivery-boy-cash-sessions/1`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/03-cash-session-show.png`, fullPage: true });

  // 4. Delivery boy detail (id 10)
  await page.goto(`${BASE_URL}/admin/delivery-boys/10`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/04-delivery-boy-show.png`, fullPage: true });

  // 5. Try potential livreur self-service mobile screen (likely 404 / fallback)
  await page.goto(`${BASE_URL}/livreur`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
  await page.screenshot({ path: `${OUT}/05-livreur-self-service.png`, fullPage: true });

  // 6. Cash overview (consolidated dashboard)
  await page.goto(`${BASE_URL}/admin/cash-overview`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/06-cash-overview.png`, fullPage: true });
});
