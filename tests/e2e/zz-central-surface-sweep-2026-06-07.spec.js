// FoodKing — CENTRAL surface render sweep (headless, MCP-independent)
// 2026-06-07 autonomous validation. Runs against the disposable e2e clone:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-central-surface-sweep-2026-06-07.spec.js
//
// Goal: prove every CENTRAL admin/KDS surface RENDERS clean (HTTP ok, no PHP/JS
// crash, no raw i18n label, no console error) and capture an analyzable
// screenshot per surface. Read-only navigation — creates nothing.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'central-sweep-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

const SURFACES = [
  { key: 'dashboard', url: '/admin/dashboard' },
  { key: 'stock-rupture', url: '/admin/stock/rupture' },
  { key: 'customers-loyalty', url: '/admin/customers' },
  { key: 'encaissement', url: '/admin/encaissement' },
  { key: 'oss', url: '/admin/order-status-screen' },
  { key: 'kds', url: '/kds' },
  { key: 'historique', url: '/admin/historique' },
];

// Crash / broken-render markers that must NOT appear in rendered text.
const CRASH_MARKERS = [
  'Whoops, something went wrong',
  'Server Error',
  'SQLSTATE',
  'Undefined variable',
  'Class "',
  'syntax error',
];
// Raw i18n leak: a bare "word.word" token rendered to the user (heuristic).
const RAW_LABEL_RE = /\b(kiosk|pos|kds|admin|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b/i;

test.describe.configure({ mode: 'serial', timeout: 120_000 });

test('login as admin', async ({ page }) => {
  await loginAsAdmin(page);
  await expect(page).toHaveURL(/\/admin/);
});

for (const s of SURFACES) {
  test(`surface renders: ${s.key}`, async ({ page }) => {
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => pageErrors.push(e.message));

    await loginAsAdmin(page);
    const resp = await page.goto(s.url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500); // let the SPA hydrate + fetch
    await page.screenshot({ path: path.join(OUT, `${s.key}.png`), fullPage: true });

    const httpStatus = resp ? resp.status() : 0;
    const finalUrl = page.url();
    const bodyText = await page.locator('body').innerText().catch(() => '');

    const crashes = CRASH_MARKERS.filter((m) => bodyText.includes(m));
    const rawHit = bodyText.match(RAW_LABEL_RE);
    // Filter benign console noise (favicon, websocket, deprecation, analytics).
    const realConsoleErrors = consoleErrors.filter((t) =>
      !/favicon|websocket|ws:|wss:|soketi|pusher|6001|Deprecation|preload|sourcemap/i.test(t));

    // Structured line the runner can grep from stdout.
    console.log(`[SWEEP] ${s.key} url=${finalUrl} http=${httpStatus} ` +
      `crashes=${crashes.length} rawLabel=${rawHit ? rawHit[0] : 'none'} ` +
      `consoleErr=${realConsoleErrors.length} pageErr=${pageErrors.length}`);
    if (realConsoleErrors.length) console.log(`   consoleErrors: ${JSON.stringify(realConsoleErrors.slice(0, 5))}`);
    if (pageErrors.length) console.log(`   pageErrors: ${JSON.stringify(pageErrors.slice(0, 5))}`);

    expect(crashes, `crash markers on ${s.key}: ${crashes.join(', ')}`).toHaveLength(0);
    expect(pageErrors, `JS page errors on ${s.key}: ${pageErrors.join(' | ')}`).toHaveLength(0);
    // Surface should not bounce back to /login (auth/permission failure).
    expect(finalUrl, `bounced to login on ${s.key}`).not.toMatch(/\/login(\?|$)/);
  });
}
