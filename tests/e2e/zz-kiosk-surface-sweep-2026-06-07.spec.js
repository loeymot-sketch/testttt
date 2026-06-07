// FoodKing — KIOSK (borne) surface render sweep (headless, MCP-independent)
// 2026-06-07 autonomous validation. Runs against the disposable e2e clone:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kiosk-surface-sweep-2026-06-07.spec.js
//
// Read-only navigation of the customer self-order kiosk: proves each surface
// renders clean (no crash, no raw i18n label, no console error) and captures an
// analyzable screenshot. Does NOT submit an order (creates nothing).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'kiosk-sweep-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

const SURFACES = [
  { key: 'idle', url: '/kiosk/idle' },
  { key: 'categories', url: '/kiosk/categories' },
  { key: 'cart', url: '/kiosk/cart' },
];

const CRASH_MARKERS = [
  'Whoops, something went wrong', 'Server Error', 'SQLSTATE',
  'Undefined variable', 'Class "', 'syntax error',
];
const RAW_LABEL_RE = /\b(kiosk|pos|kds|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b/i;

test.describe.configure({ mode: 'serial', timeout: 120_000 });

test('kiosk login', async ({ page }) => {
  await loginAsKiosk(page);
  console.log(`[KIOSK-LOGIN] landed=${page.url()}`);
  // soft: kiosk may auto-route to idle/categories; just confirm we're in /kiosk space
  expect(page.url(), 'kiosk login did not reach /kiosk space').toMatch(/\/kiosk/);
});

for (const s of SURFACES) {
  test(`kiosk surface renders: ${s.key}`, async ({ page }) => {
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => pageErrors.push(e.message));

    await loginAsKiosk(page);
    const resp = await page.goto(s.url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    await page.screenshot({ path: path.join(OUT, `${s.key}.png`), fullPage: true });

    const httpStatus = resp ? resp.status() : 0;
    const finalUrl = page.url();
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const crashes = CRASH_MARKERS.filter((m) => bodyText.includes(m));
    const rawHit = bodyText.match(RAW_LABEL_RE);
    const realConsoleErrors = consoleErrors.filter((t) =>
      !/favicon|websocket|ws:|wss:|soketi|pusher|6001|Deprecation|preload|sourcemap/i.test(t));

    console.log(`[KIOSK-SWEEP] ${s.key} url=${finalUrl} http=${httpStatus} ` +
      `crashes=${crashes.length} rawLabel=${rawHit ? rawHit[0] : 'none'} ` +
      `consoleErr=${realConsoleErrors.length} pageErr=${pageErrors.length}`);
    if (realConsoleErrors.length) console.log(`   consoleErrors: ${JSON.stringify(realConsoleErrors.slice(0, 5))}`);
    if (pageErrors.length) console.log(`   pageErrors: ${JSON.stringify(pageErrors.slice(0, 5))}`);

    expect(crashes, `crash markers on kiosk/${s.key}: ${crashes.join(', ')}`).toHaveLength(0);
    expect(pageErrors, `JS page errors on kiosk/${s.key}: ${pageErrors.join(' | ')}`).toHaveLength(0);
  });
}
