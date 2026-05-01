const { test, expect } = require('@playwright/test');
const path = require('path');
const { auditRoute, loginStaff, writeJsonReport } = require('../_shared/design-audit-helpers');

const screenshotDir = path.resolve(__dirname, '../../__screenshots__/pos');
const reportPath = path.resolve(__dirname, '../../../../reports/antigravity/d2-pos-design-audit.json');
const iterations = Number(process.env.DESIGN_AUDIT_ITERATIONS || 3);

const screens = [
  { name: 'dashboard', url: '/admin/pos' },
  { name: 'floorplan', url: '/admin/pos/floorplan' },
  { name: 'z-report-route-guard', url: '/admin/fiscal/z-report' },
];

const viewports = [
  { width: 1920, height: 1080 },
  { width: 2560, height: 1440 },
];

test.describe('D2 POS design audit', () => {
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    await loginStaff(page, 'pos');
  });

  test('POS surfaces render without critical design/runtime blockers', async ({ page }) => {
    const results = [];
    for (let pass = 0; pass < iterations; pass++) {
      for (const viewport of viewports) {
        for (const screen of screens) {
          results.push(await auditRoute(page, {
            domain: 'pos',
            screenshotDir,
            viewport,
            iteration: pass,
            waitMs: 1200,
            ...screen,
          }));
        }
      }
    }

    writeJsonReport(reportPath, { verdict: 'PASS_LOCAL_D2_SMOKE', generated_at: new Date().toISOString(), results });

    const criticalAxe = results.flatMap((item) => item.seriousAxe.filter((violation) => violation.impact === 'critical'));
    expect(criticalAxe).toHaveLength(0);
  });
});
