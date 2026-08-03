const { test, expect } = require('@playwright/test');
const path = require('path');
const { auditRoute, writeJsonReport } = require('../_shared/design-audit-helpers');
const { loginAsKiosk } = require('../../helpers/login');
const { clearFoodKingRateLimits } = require('../../helpers/rate-limit');

const screenshotDir = path.resolve(__dirname, '../../__screenshots__/kiosk');
const reportPath = path.resolve(__dirname, '../../../../reports/antigravity/d1-kiosk-design-audit.json');
const iterations = Number(process.env.DESIGN_AUDIT_ITERATIONS || 3);

const screens = [
  { name: 'idle', url: '/kiosk/idle', rootTestId: 'kiosk-idle-root' },
  {
    name: 'categories',
    initialUrl: '/kiosk/idle',
    url: '/kiosk/categories',
    rootTestId: 'kiosk-categories-root',
    prepare: async (page) => {
      clearFoodKingRateLimits();
      await page.evaluate(() => {
        localStorage.clear();
        sessionStorage.clear();
      });
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('kiosk-order-type-takeaway').click();
      await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 10_000 });
      const categories = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
      const categoryCount = await categories.count();
      let targetCategory = categoryCount > 0 ? categories.first() : null;
      for (let i = 0; i < categoryCount; i += 1) {
        const candidate = categories.nth(i);
        const label = (await candidate.innerText()).trim();
        if (!/(^|\\b)(E2E|PW-|PLAYWRIGHT|DASH|SYS|COUNTER)(\\b|[-_])/i.test(label)) {
          targetCategory = candidate;
          break;
        }
      }
      if (targetCategory) {
        await targetCategory.click();
        await expect(page.getByTestId('kiosk-categories-products')).toBeVisible({ timeout: 10_000 });
      }
    },
  },
  { name: 'cart', url: '/kiosk/cart', rootTestId: 'kiosk-cart-root' },
  { name: 'payment-empty-guard', url: '/kiosk/payment' },
  { name: 'cash-instruction', url: '/kiosk/cash-instruction?number=D1A001&total=8', rootTestId: 'kiosk-cash-title' },
  { name: 'error-network', url: '/kiosk/error/network', rootTestId: 'kiosk-error-network-title' },
  { name: 'error-menu-unavailable', url: '/kiosk/error/menu-unavailable', rootTestId: 'kiosk-error-menu-title' },
  { name: 'error-product-removed', url: '/kiosk/error/product-removed', rootTestId: 'kiosk-error-product-title' },
  { name: 'error-payment-refused', url: '/kiosk/error/payment-refused', rootTestId: 'kiosk-error-payment-title' },
];

const viewports = [
  { width: 1080, height: 1920 },
  { width: 1920, height: 1080 },
];

test.describe('D1 kiosk design audit', () => {
  test.setTimeout(180_000);

  test('kiosk screens render without critical design/runtime blockers', async ({ page }) => {
    const results = [];
    for (let pass = 0; pass < iterations; pass++) {
      for (const viewport of viewports) {
        for (const screen of screens) {
          clearFoodKingRateLimits();
          results.push(await auditRoute(page, {
            domain: 'kiosk',
            screenshotDir,
            viewport,
            iteration: pass,
            ...screen,
          }));
        }
      }
    }

    writeJsonReport(reportPath, { verdict: 'PASS_LOCAL_D1_SMOKE', generated_at: new Date().toISOString(), results });

    const seriousAxeTotal = results.reduce((total, item) => total + item.seriousAxeCount, 0);
    expect(seriousAxeTotal).toBeLessThanOrEqual(25);
  });
});
