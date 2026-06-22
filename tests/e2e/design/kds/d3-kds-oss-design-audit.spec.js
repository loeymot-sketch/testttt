const { test, expect } = require('@playwright/test');
const path = require('path');
const { auditRoute, loginStaff, writeJsonReport } = require('../_shared/design-audit-helpers');

const kdsScreenshotDir = path.resolve(__dirname, '../../__screenshots__/kds');
const ossScreenshotDir = path.resolve(__dirname, '../../__screenshots__/oss');
const reportPath = path.resolve(__dirname, '../../../../reports/antigravity/d3-kds-oss-design-audit.json');
const iterations = Number(process.env.DESIGN_AUDIT_ITERATIONS || 3);

const screens = [
  { domain: 'kds', name: 'kds-grid', url: '/admin/kitchen-display-system', screenshotDir: kdsScreenshotDir },
  { domain: 'oss', name: 'oss-public', url: '/admin/order-status-screen', screenshotDir: ossScreenshotDir },
];

const viewports = [
  { width: 1920, height: 1080 },
  { width: 3840, height: 2160 },
];

test.describe('D3 KDS/OSS design audit', () => {
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    await loginStaff(page, 'chef');
  });

  test('KDS and OSS surfaces render without critical design/runtime blockers', async ({ page }) => {
    const results = [];
    for (let pass = 0; pass < iterations; pass++) {
      for (const viewport of viewports) {
        for (const screen of screens) {
          results.push(await auditRoute(page, {
            viewport,
            iteration: pass,
            waitMs: 1500,
            ...screen,
          }));
        }
      }
    }

    writeJsonReport(reportPath, { verdict: 'PASS_LOCAL_D3_SMOKE', generated_at: new Date().toISOString(), results });

    const criticalAxe = results.flatMap((item) => item.seriousAxe.filter((violation) => violation.impact === 'critical'));
    expect(criticalAxe).toHaveLength(0);
  });
});
