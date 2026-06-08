const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const OUT = path.resolve('tests/e2e/__screenshots__/uiux-dashboard-2026-06-08');
require('fs').mkdirSync(OUT, { recursive: true });

test('dashboard renders FR (Dernier Z datetime, SLA rollup) + FR pagination on lists', async ({ page }) => {
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));
  await loginAsAdmin(page);
  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  await page.screenshot({ path: path.join(OUT, 'dashboard-after-fix.png'), fullPage: true });
  const dashText = await page.locator('body').innerText();
  // SLA must not render raw "NNNN minutes" (5+ digit) — rolled up now.
  expect(/depuis\s+\d{4,}\s+minutes/i.test(dashText), 'SLA raw minutes must be rolled up').toBeFalsy();

  // A list page → FR pagination labels, no English Previous/Next.
  await page.goto('/admin/sales-report', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  await page.screenshot({ path: path.join(OUT, 'sales-report-pagination.png'), fullPage: true });
  const listText = await page.locator('body').innerText();
  expect(/\bPrevious\b/.test(listText), 'no English "Previous"').toBeFalsy();
  expect(/\bNext\b/.test(listText), 'no English "Next"').toBeFalsy();

  expect(errs, `JS errors: ${errs.join(' | ')}`).toHaveLength(0);
});
