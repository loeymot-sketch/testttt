/**
 * BAD-MOOD AUDIT 3 — Re-capture kiosk_idle + KDS with URL-level response tracking.
 * Captures 401/403 URLs to identify which sub-resources fail.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login } = require('../e2e/helpers/login');

const OUTPUT_DIR = path.join(
  __dirname,
  '..',
  '..',
  'reports',
  'audits',
  'BAD-MOOD-AUDIT-3-VISUAL'
);

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

async function capture(context, key, url, opts = {}) {
  const page = await context.newPage();
  const errResponses = [];
  page.on('response', (resp) => {
    if (resp.status() >= 400) {
      errResponses.push({ status: resp.status(), url: resp.url(), method: resp.request().method() });
    }
  });
  page.on('console', (msg) => {
    // ignore — we now use response handler for status info
  });
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(opts.settleMs || 5000);
  } catch (e) {
    errResponses.push({ status: 'NAV_ERROR', url, error: e.message.slice(0, 200) });
  }
  await page.close().catch(() => {});
  const out = path.join(OUTPUT_DIR, `${key}-errors-detail.json`);
  fs.writeFileSync(out, JSON.stringify(errResponses, null, 2));
  console.log(`${key}_ERRORS=${errResponses.length} → ${out}`);
  return errResponses;
}

test('BAD-MOOD AUDIT 3 RE-CAPTURE — 401/403 URLs', async ({ browser }) => {
  test.setTimeout(300_000);
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
  });
  try {
    // 1) Kiosk idle (anonymous)
    await capture(context, 'kiosk_idle', 'http://127.0.0.1:8000/kiosk/idle', { settleMs: 5000 });

    // 2) Login then KDS
    const authPage = await context.newPage();
    try {
      await login(authPage, ADMIN_EMAIL, ADMIN_PASSWORD);
    } catch (e) {
      fs.writeFileSync(
        path.join(OUTPUT_DIR, 'kds-errors-detail.json'),
        JSON.stringify([{ error: 'login failed: ' + e.message.slice(0, 200) }], null, 2)
      );
      await context.close();
      return;
    }
    await authPage.close().catch(() => {});

    await capture(
      context,
      'kds',
      'http://127.0.0.1:8000/admin/kitchen-display-system',
      { settleMs: 6000 }
    );
  } finally {
    await context.close().catch(() => {});
  }
});
