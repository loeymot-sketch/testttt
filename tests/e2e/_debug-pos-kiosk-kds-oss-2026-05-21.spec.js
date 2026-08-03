// FoodKing debug capture — POS + Kiosk + KDS + OSS surfaces (2026-05-21)
// Run: PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/_debug-pos-kiosk-kds-oss-2026-05-21.spec.js --reporter=list

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator, loginAsChefOperator } = require('./helpers/login');

const OUT_DIR = '/tmp/foodking-debug-2026-05-21';
fs.mkdirSync(OUT_DIR, { recursive: true });

function attachDiagnostics(page, name) {
  const consoleMsgs = [];
  const pageErrors = [];
  const requestFailures = [];
  const httpErrors = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      consoleMsgs.push({ type: msg.type(), text: msg.text(), location: msg.location() });
    }
  });
  page.on('pageerror', (err) => pageErrors.push({ message: err.message, stack: err.stack }));
  page.on('requestfailed', (req) => requestFailures.push({ url: req.url(), method: req.method(), failure: req.failure()?.errorText }));
  page.on('response', (res) => {
    if (res.status() >= 400) {
      httpErrors.push({ url: res.url(), method: res.request().method(), status: res.status() });
    }
  });

  return {
    save: () => {
      const report = { name, consoleMsgs, pageErrors, requestFailures, httpErrors };
      fs.writeFileSync(path.join(OUT_DIR, `${name}-diagnostics.json`), JSON.stringify(report, null, 2));
      return report;
    },
  };
}

test.describe('Debug capture POS/Kiosk/KDS/OSS 2026-05-21', () => {
  test.setTimeout(120_000);

  test('Login /login baseline', async ({ page }) => {
    const diag = attachDiagnostics(page, 'login');
    await page.goto('/login', { waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(2_000);
    await page.screenshot({ path: path.join(OUT_DIR, '00-login.png'), fullPage: true });
    const r = diag.save();
    console.log(`[login] err=${r.pageErrors.length} cErr=${r.consoleMsgs.filter(c=>c.type==='error').length} http=${r.httpErrors.length}`);
  });

  test('Kiosk /kiosk/idle', async ({ page }) => {
    const diag = attachDiagnostics(page, 'kiosk-idle');
    await page.goto('/kiosk/idle', { waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(3_000);
    await page.screenshot({ path: path.join(OUT_DIR, '01-kiosk-idle.png'), fullPage: true });
    const r = diag.save();
    console.log(`[kiosk-idle] err=${r.pageErrors.length} cErr=${r.consoleMsgs.filter(c=>c.type==='error').length} http=${r.httpErrors.length}`);
  });

  test('Kiosk /kiosk', async ({ page }) => {
    const diag = attachDiagnostics(page, 'kiosk');
    await page.goto('/kiosk', { waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(3_000);
    await page.screenshot({ path: path.join(OUT_DIR, '02-kiosk.png'), fullPage: true });
    const r = diag.save();
    console.log(`[kiosk] err=${r.pageErrors.length} cErr=${r.consoleMsgs.filter(c=>c.type==='error').length} http=${r.httpErrors.length}`);
  });

  test('POS /admin/pos (auth)', async ({ page }) => {
    const diag = attachDiagnostics(page, 'admin-pos');
    try {
      await loginAsPosOperator(page);
    } catch (e) {
      console.log(`[admin-pos] login failed: ${e.message}`);
    }
    await page.waitForTimeout(5_000);
    await page.screenshot({ path: path.join(OUT_DIR, '03-admin-pos.png'), fullPage: true });
    const r = diag.save();
    console.log(`[admin-pos] url=${page.url()} err=${r.pageErrors.length} cErr=${r.consoleMsgs.filter(c=>c.type==='error').length} http=${r.httpErrors.length}`);
  });

  test('KDS /kds (auth chef)', async ({ page }) => {
    const diag = attachDiagnostics(page, 'kds');
    try {
      await loginAsChefOperator(page);
    } catch (e) {
      console.log(`[kds] login failed: ${e.message}`);
    }
    await page.waitForTimeout(5_000);
    await page.screenshot({ path: path.join(OUT_DIR, '04-kds.png'), fullPage: true });
    const r = diag.save();
    console.log(`[kds] url=${page.url()} err=${r.pageErrors.length} cErr=${r.consoleMsgs.filter(c=>c.type==='error').length} http=${r.httpErrors.length}`);
  });

  test('OSS /admin/order-status-screen', async ({ page }) => {
    const diag = attachDiagnostics(page, 'oss');
    await page.goto('/admin/order-status-screen', { waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(4_000);
    await page.screenshot({ path: path.join(OUT_DIR, '05-oss.png'), fullPage: true });
    const r = diag.save();
    console.log(`[oss] url=${page.url()} err=${r.pageErrors.length} cErr=${r.consoleMsgs.filter(c=>c.type==='error').length} http=${r.httpErrors.length}`);
  });
});
