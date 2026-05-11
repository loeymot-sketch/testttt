// Quick a11y investigation — dump color-contrast violation node detail
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('inspect — color-contrast nodes detail on Home', async ({ page }) => {
  await page.goto('/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.LC && !!window.LC.storage && !!window.LC.menu);
  await page.evaluate(() => {
    window.LC.storage.clearAuth();
    window.LC.storage.setAuth({ token: 'test', phone: '+33600000000', user_id: 12345 });
    window.LC.storage.markOnboardingSeen();
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]', { timeout: 10_000 });
  await page.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/axe-core@4.10.0/axe.min.js' });
  const violations = await page.evaluate(async () => {
    const r = await window.axe.run(document, { runOnly: ['color-contrast'] });
    return r.violations.map(v => ({
      id: v.id,
      impact: v.impact,
      nodes: v.nodes.map(n => ({
        target: n.target,
        html: (n.html || '').substring(0, 400),
        failureSummary: n.failureSummary
      }))
    }));
  });
  fs.writeFileSync(path.resolve(__dirname, '../../reports/test-e2e/mobile-design-perfect-2026-05-11/contrast-investigation.json'), JSON.stringify(violations, null, 2));
  console.log('VIOLATIONS:', JSON.stringify(violations, null, 2));
});
