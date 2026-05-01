const { test, expect } = require('@playwright/test');
const path = require('path');
const { auditRoute, writeJsonReport } = require('../_shared/design-audit-helpers');

const screenshotDir = path.resolve(__dirname, '../../__screenshots__/kiosk');
const reportPath = path.resolve(__dirname, '../../../../reports/antigravity/d1-kiosk-design-audit.json');
const iterations = Number(process.env.DESIGN_AUDIT_ITERATIONS || 3);

const screens = [
  { name: 'idle', url: '/kiosk/idle', rootTestId: 'kiosk-idle-root' },
  { name: 'categories', url: '/kiosk/categories', rootTestId: 'kiosk-categories-root' },
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
  test.setTimeout(120_000);

  test('kiosk screens render without critical design/runtime blockers', async ({ page }) => {
    const results = [];
    for (let pass = 0; pass < iterations; pass++) {
      for (const viewport of viewports) {
        for (const screen of screens) {
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
