// FoodKing E2E — rush-sync Wave POSAPP verification
// RUN=rush-sync-2026-05-13 / round-1 / wave-POSAPP
//
// Mission : after applying the WB-R1-01 heal in resources/js/pos-app.js
// (route stubs for admin.pos-orders.tracker / admin.order-status-screen /
// admin.pos-orders.list / admin.pos-orders.show / auth.login), verify the
// POS V4 page load produces 0 unhandled-promise rejections (was 37 in
// rush-100 round-1 wave-B baseline).
//
// Single state : /admin/pos-v4 idle, no wizard, no cart.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const REPO_ROOT = path.resolve(__dirname, '../..');
const REPORT_DIR = path.join(REPO_ROOT, 'reports/test-e2e/rush-sync-2026-05-13/round-1');
fs.mkdirSync(REPORT_DIR, { recursive: true });

const CONSOLE_OUT = path.join(REPORT_DIR, 'wave-POSAPP-console.json');
const SCREENSHOT = path.join(REPORT_DIR, 'wave-POSAPP-pos-v4.png');

test.describe.configure({ mode: 'serial' });

test('WB-R1-01 heal: /admin/pos-v4 emits 0 unhandled-promise rejections', async ({ page }) => {
  const consoleMsgs = [];
  const pageErrors = [];

  page.on('console', (msg) => {
    consoleMsgs.push({
      type: msg.type(),
      text: msg.text(),
      location: msg.location(),
    });
  });
  page.on('pageerror', (err) => {
    pageErrors.push({
      message: err.message,
      stack: err.stack,
    });
  });

  await loginAsAdmin(page);
  await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });

  // Wait for the POS V4 inner content to render
  await expect(page.locator('[data-testid="pos-tracker-open"]').first()).toBeVisible({ timeout: 20000 });

  // Give the reactive effects 2s to flush — rush-100 captured 37 rejections
  // during this idle window.
  await page.waitForTimeout(2000);

  await page.screenshot({ path: SCREENSHOT, fullPage: false });

  const rejections = consoleMsgs.filter((m) => {
    if (m.type !== 'error') return false;
    const t = String(m.text || '');
    return (
      t.includes('Unhandled promise rejection') ||
      t.includes('Unhandled (in promise)') ||
      t.includes('pos-app.js') && t.includes('get value') ||
      t.startsWith('error: Error')
    );
  });

  const payload = {
    url: '/admin/pos-v4',
    timestamp: new Date().toISOString(),
    pageErrorCount: pageErrors.length,
    pageErrors,
    consoleMsgCount: consoleMsgs.length,
    rejectionCount: rejections.length,
    rejections,
    allConsole: consoleMsgs,
  };
  fs.writeFileSync(CONSOLE_OUT, JSON.stringify(payload, null, 2));

  console.log(`[POSAPP-verify] pageErrors=${pageErrors.length} rejections=${rejections.length} consoleMsgs=${consoleMsgs.length}`);

  // Acceptance: 0 unhandled-promise rejections (was 37 baseline).
  expect(rejections.length).toBeLessThanOrEqual(0);
  expect(pageErrors.length).toBeLessThanOrEqual(0);
});
