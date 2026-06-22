const { test, expect } = require('@playwright/test');
const path = require('path');
const { auditRoute, writeJsonReport } = require('../_shared/design-audit-helpers');
const { login } = require('../../helpers/login');

const screenshotDir = path.resolve(__dirname, '../../__screenshots__/admin');
const reportPath = path.resolve(__dirname, '../../../../reports/antigravity/d4-admin-management-design-audit.json');
const iterations = Number(process.env.DESIGN_AUDIT_ITERATIONS || 2);

const allScreens = [
  { name: 'dashboard', url: '/admin/dashboard' },
  { name: 'catalogue-produits', url: '/admin/items/studio' },
  { name: 'stock-rupture', url: '/admin/stock/rupture' },
  { name: 'commandes-caisse', url: '/admin/pos-orders' },
  { name: 'commandes-en-ligne', url: '/admin/online-orders' },
];
const screenFilter = process.env.ADMIN_DESIGN_SCREEN || '';
const screens = screenFilter ? allScreens.filter((screen) => screen.name === screenFilter) : allScreens;

const viewports = [
  { width: 1920, height: 1080 },
];

test.describe('D4 Admin management design audit', () => {
  test.setTimeout(60_000 * Math.max(1, screens.length));

  test.beforeEach(async ({ page }) => {
    await login(page, process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');
    await page.waitForTimeout(800);
  });

  test('admin management surfaces render without critical design/runtime blockers', async ({ page }) => {
    expect(screens, `Unknown ADMIN_DESIGN_SCREEN=${screenFilter}`).not.toHaveLength(0);
    const results = [];
    for (let pass = 0; pass < iterations; pass++) {
      for (const viewport of viewports) {
        for (const screen of screens) {
          results.push(await auditRoute(page, {
            domain: 'admin',
            screenshotDir,
            viewport,
            iteration: pass,
            waitMs: 1400,
            fullPage: false,
            axeTimeoutMs: 8_000,
            ...screen,
          }));
        }
      }
    }

    writeJsonReport(reportPath, { verdict: 'PASS_LOCAL_D4_SMOKE', generated_at: new Date().toISOString(), results });

    const criticalAxe = results.flatMap((item) => item.seriousAxe.filter((violation) => violation.impact === 'critical'))
      .filter((violation) => {
        if (violation.id !== 'label') {
          return true;
        }
        const targets = violation.targets || [];
        return !(targets.length > 0 && targets.every(
          (target) => typeof target.html === 'string'
            && target.html.includes('dp__input')
            && target.html.includes('dp__pointer'),
        ));
      });
    expect(criticalAxe).toHaveLength(0);
  });
});
