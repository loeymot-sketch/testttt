// WE-4 Final Convergence — Visual Capture Spec
// Single-purpose: take screenshots of all healed surfaces in this session for
// the cross-cutting E2E Visual specialist pass. NO assertion failures — this
// is read-only forensic capture.
//
// Captures saved to: reports/audit/wave-e-2026-05-19/WE-4-FINAL-CONVERGENCE/captures/
//
// Underscore prefix excludes from CI test discovery by convention.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsPosOperator, loginAsAdmin, loginAsChefOperator } = require('./helpers/login');

const CAPTURE_DIR = path.resolve(
  __dirname,
  '../../reports/audit/wave-e-2026-05-19/WE-4-FINAL-CONVERGENCE/captures',
);

function safeFilename(name) {
  return name.replace(/[^a-z0-9_\-.]/gi, '_');
}

async function snap(page, name) {
  const filename = path.join(CAPTURE_DIR, safeFilename(name) + '.png');
  await page.screenshot({ path: filename, fullPage: true });
  return filename;
}

test.describe.configure({ mode: 'serial' });

test.describe('WE-4 Final Convergence — Visual Capture', () => {
  test.beforeAll(() => {
    if (!fs.existsSync(CAPTURE_DIR)) {
      fs.mkdirSync(CAPTURE_DIR, { recursive: true });
    }
  });

  test('A — public login page', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await snap(page, 'A-login');
  });

  test('B — admin login then admin dashboard', async ({ page }) => {
    await loginAsAdmin(page);
    await page.waitForTimeout(1500);
    await snap(page, 'B-admin-dashboard');
  });

  test('C — admin stock rupture (SPA path /admin/stock/rupture)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, 'C-admin-stock-rupture');
  });

  test('D — POS main page', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1500);
    await snap(page, 'D-pos-main');
  });

  test('E — KDS board (admin auth)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, 'E-kds-board');
  });

  test('F — OSS admin wall (SPA path /admin/order-status-screen)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, 'F-oss-wall');
  });

  test('G — kiosk idle', async ({ page }) => {
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, 'G-kiosk-idle');
  });

  test('H — admin items', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, 'H-admin-items');
  });

  test('I — root / web standalone (if served)', async ({ page }) => {
    const resp = await page.goto('/', { waitUntil: 'domcontentloaded' }).catch(() => null);
    if (resp && resp.ok()) {
      await page.waitForTimeout(1500);
      await snap(page, 'I-root');
    } else {
      // log only — do not fail
      console.log('[I] / returned non-OK; skipping capture');
    }
  });
});
