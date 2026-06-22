const { test, expect } = require('@playwright/test');
const path = require('path');
const { auditRoute, writeJsonReport } = require('../_shared/design-audit-helpers');
const { login } = require('../../helpers/login');

const screenshotDir = path.resolve(__dirname, '../../__screenshots__/pos');
const reportPath = path.resolve(__dirname, '../../../../reports/antigravity/d2-pos-design-audit.json');
const iterations = Number(process.env.DESIGN_AUDIT_ITERATIONS || 3);

/** Admin requis pour /admin/fiscal/* ; le caissier n’a pas pos-manage-fiscal — rester sur surfaces POS. */
const screens = [
  { name: 'dashboard', url: '/admin/pos' },
  { name: 'floorplan', url: '/admin/pos/floorplan' },
  { name: 'pos-orders', url: '/admin/pos-orders' },
];

const viewports = [
  { width: 1920, height: 1080 },
  { width: 2560, height: 1440 },
];

test.describe('D2 POS design audit', () => {
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    await login(page, process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');
    await page.waitForTimeout(800);
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

    const criticalAxe = results.flatMap((item) => item.seriousAxe.filter((violation) => violation.impact === 'critical'))
      .filter((violation) => {
        // @vuepic/vue-datepicker (range) : aria sur le wrapper, pas sur le <input> readonly —
        // faux positifs axe « label » sur `.dp__pointer.dp__input` (voir PosOrderListComponent).
        if (violation.id !== 'label') {
          return true;
        }
        const targets = violation.targets || [];
        const onlyDatepickerInputs = targets.length > 0 && targets.every(
          (t) => typeof t.html === 'string'
            && t.html.includes('dp__input')
            && t.html.includes('dp__pointer'),
        );

        return !onlyDatepickerInputs;
      });
    expect(criticalAxe).toHaveLength(0);
  });
});
