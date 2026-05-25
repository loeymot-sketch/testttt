/**
 * Wave Y-C — KDS + OSS + Admin Auxiliary Pages (2026-05-21)
 * GStack capture agent — Wave Y Le Cayenne V2 catalog audit.
 *
 * Strategy:
 *  - Login ONCE via global setup-style beforeAll storing storageState.
 *  - All subsequent tests reuse storage state -> skip the slow networkidle wait.
 *  - Real Vue SPA routes (verified via grep resources/js/router/modules):
 *      /admin/kitchen-display-system   (KDS)
 *      /admin/order-status-screen      (OSS — admin route, public Vue redirect 404)
 *      /admin/pos                      (POS V5 Vue route — different from /admin/pos-v4 SPA)
 *      /admin/items                    (Laravel resource page, not Vue)
 *      /admin/stock/rupture            (stock dashboard, Vue)
 *      /admin/pos-orders               (orders listing, Vue)
 *      /admin/pos-orders-tracker       (orders tracker, Vue)
 *      /admin/cash-overview            (cash overview, Vue)
 *      /admin/settings                 (settings hub, Vue)
 *      /admin/observability            (observability dashboard)
 *  - /kds redirects to admin.kitchen-display-system (verified in router/index.js).
 *  - /order-status-screen (without /admin) -> 404 Vue page (public, expected).
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_CAP =
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-C';
const OUT_LOG =
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2';
// Keep session cookies out of the reports tree (which is committed). /tmp ok.
// Force fresh login for round-2 (don't reuse stale round-1 state if expired).
const STATE_FILE = '/tmp/_wave-y-c-storage-state-round-2.json';

test.use({ viewport: { width: 1366, height: 900 } });
// Single-shot per test, no retries to avoid 2x time cost.
test.describe.configure({ retries: 0 });

function attachListeners(page, store) {
  page.on('console', (msg) => {
    const t = msg.type();
    if (t === 'error' || t === 'warning') {
      store.console.push({ type: t, text: msg.text().slice(0, 600) });
    }
  });
  page.on('pageerror', (err) => {
    store.pageErrors.push({ message: String(err).slice(0, 600) });
  });
  page.on('response', (resp) => {
    const s = resp.status();
    if (s >= 400) {
      store.network.push({ url: resp.url(), status: s });
    }
  });
  page.on('requestfailed', (req) => {
    store.failed.push({
      url: req.url(),
      failure: (req.failure() && req.failure().errorText) || '',
    });
  });
}

function persist(store) {
  try {
    fs.writeFileSync(
      path.join(OUT_LOG, `wave-C-events-${store.name}.json`),
      JSON.stringify(store, null, 2)
    );
  } catch (_e) {
    /* noop */
  }
}

function logEvents(name) {
  return { name, console: [], network: [], failed: [], pageErrors: [] };
}

// One-time login. Reused via storageState.
test.beforeAll(async ({ browser }) => {
  if (fs.existsSync(STATE_FILE)) {
    return; // already authed
  }
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  // /login and /admin/pos-v4 are both Vue SPAs. Use /admin/pos-v4 (verified
  // working in Wave B's spec) — Vue mounts after ~2-3s.
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  // Wait for Vue to mount the login modal (>=2 inputs visible).
  try {
    await page.waitForFunction(
      () => document.querySelectorAll('input').length >= 2,
      null,
      { timeout: 20000 }
    );
  } catch (_e) {
    /* fall through and try anyway */
  }
  const inputs = await page.locator('input').all();
  if (inputs.length >= 2) {
    await inputs[0].fill('admin@lecayenne.fr').catch(() => {});
    await inputs[1].fill('123456').catch(() => {});
  }
  // Wait for the POST /api/auth/login response BEFORE clicking, then click and await.
  const loginRespPromise = page
    .waitForResponse(
      (resp) =>
        resp.url().includes('/api/auth/login') && resp.request().method() === 'POST',
      { timeout: 15000 }
    )
    .catch(() => null);
  const btn = page.getByRole('button', { name: /connexion/i }).first();
  if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
    await btn.click().catch(() => {});
  } else {
    await page.locator('button[type="submit"]').first().click().catch(() => {});
  }
  const loginResp = await loginRespPromise;
  if (loginResp) {
    // eslint-disable-next-line no-console
    console.log(
      `[wave-c-r2 login] POST /api/auth/login -> ${loginResp.status()}`
    );
  } else {
    // eslint-disable-next-line no-console
    console.log('[wave-c-r2 login] login response not observed (timeout)');
  }
  // Settle SPA and let pinia/store hydrate (admin app calls /api/auth/me etc).
  await page.waitForTimeout(3500);
  // Confirm cookie present
  const cookies = await ctx.cookies();
  const hasSession = cookies.some((c) => c.name === 'le_cayenne_session');
  // eslint-disable-next-line no-console
  console.log(
    `[wave-c-r2 login] cookies=${cookies.length} session-present=${hasSession}`
  );
  await ctx.storageState({ path: STATE_FILE });
  await ctx.close();
});

// Helper: open a route with auth context.
async function authedPage(browser, store) {
  const ctx = await browser.newContext({ storageState: STATE_FILE });
  const page = await ctx.newPage();
  attachListeners(page, store);
  return { ctx, page };
}

// -- KDS --------------------------------------------------------------------

test('C-01 KDS board', async ({ browser }) => {
  const store = logEvents('C-01-kds-board');
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin/kitchen-display-system`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForTimeout(5500);
  await page.screenshot({
    path: `${OUT_CAP}/C-01-kds-board.png`,
    fullPage: true,
  });
  // First card detail (if any).
  const firstCard = page
    .locator(
      '.kds-order-card, .kds-order, [data-kds-order], .order-card, .ticket-card'
    )
    .first();
  if (await firstCard.count()) {
    try {
      await firstCard.scrollIntoViewIfNeeded({ timeout: 1500 });
      await page.waitForTimeout(400);
      await firstCard.screenshot({
        path: `${OUT_CAP}/C-01b-kds-first-card.png`,
      });
    } catch (_e) {
      /* noop */
    }
  }
  persist(store);
  await ctx.close();
});

test('C-02 KDS historique drawer', async ({ browser }) => {
  const store = logEvents('C-02-kds-history');
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin/kitchen-display-system`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForTimeout(4500);
  const histBtn = page
    .getByRole('button', { name: /historique|history/i })
    .first();
  let clicked = false;
  if (await histBtn.count()) {
    await histBtn.click({ timeout: 2500 }).catch(() => {});
    clicked = true;
  } else {
    const link = page.getByText(/historique|history/i, { exact: false }).first();
    if (await link.count()) {
      await link.click({ timeout: 2500 }).catch(() => {});
      clicked = true;
    }
  }
  await page.waitForTimeout(2000);
  await page.screenshot({
    path: `${OUT_CAP}/C-02-kds-historique${clicked ? '' : '-no-btn'}.png`,
    fullPage: true,
  });
  persist(store);
  await ctx.close();
});

// -- OSS --------------------------------------------------------------------

test('C-03 OSS — public 404 + authed admin', async ({ browser }) => {
  const store = logEvents('C-03-oss');
  // Anonymous attempt against the unprefixed public route.
  const anonCtx = await browser.newContext();
  const anonPage = await anonCtx.newPage();
  attachListeners(anonPage, store);
  await anonPage.goto(`${BASE}/order-status-screen`, {
    waitUntil: 'domcontentloaded',
  });
  await anonPage.waitForTimeout(3500);
  await anonPage.screenshot({
    path: `${OUT_CAP}/C-03-oss-anon.png`,
    fullPage: true,
  });
  await anonCtx.close();

  // Authenticated against the real admin route.
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin/order-status-screen`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForTimeout(5500);
  await page.screenshot({
    path: `${OUT_CAP}/C-03b-oss-admin.png`,
    fullPage: true,
  });
  persist(store);
  await ctx.close();
});

// -- ADMIN ------------------------------------------------------------------

test('C-04 Admin landing (root + dashboard)', async ({ browser }) => {
  const store = logEvents('C-04-admin-dashboard');
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4500);
  await page.screenshot({
    path: `${OUT_CAP}/C-04-admin-root.png`,
    fullPage: true,
  });
  await page
    .goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
    .catch(() => {});
  await page.waitForTimeout(3500);
  await page.screenshot({
    path: `${OUT_CAP}/C-04b-admin-dashboard.png`,
    fullPage: true,
  });
  persist(store);
  await ctx.close();
});

test('C-05 Admin items catalog', async ({ browser }) => {
  const store = logEvents('C-05-admin-items');
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin/items`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await page.screenshot({
    path: `${OUT_CAP}/C-05-admin-items.png`,
    fullPage: true,
  });
  await page.evaluate(() => window.scrollTo(0, 800)).catch(() => {});
  await page.waitForTimeout(700);
  await page.screenshot({
    path: `${OUT_CAP}/C-05b-admin-items-scroll.png`,
    fullPage: false,
  });
  persist(store);
  await ctx.close();
});

test('C-06 Admin stock rupture dashboard', async ({ browser }) => {
  const store = logEvents('C-06-admin-stock-rupture');
  const { ctx, page } = await authedPage(browser, store);
  // Vue route is /admin/stock/rupture per stockRoutes.js. Legacy /admin/stock-rupture-dashboard may 404.
  await page.goto(`${BASE}/admin/stock/rupture`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForTimeout(5500);
  await page.screenshot({
    path: `${OUT_CAP}/C-06-admin-stock-rupture.png`,
    fullPage: true,
  });
  // Also capture the legacy URL for comparison.
  await page
    .goto(`${BASE}/admin/stock-rupture-dashboard`, {
      waitUntil: 'domcontentloaded',
    })
    .catch(() => {});
  await page.waitForTimeout(3500);
  await page.screenshot({
    path: `${OUT_CAP}/C-06b-admin-stock-rupture-dashboard-legacy.png`,
    fullPage: true,
  });
  persist(store);
  await ctx.close();
});

test('C-07 Admin orders + tracker + cash-overview', async ({ browser }) => {
  const store = logEvents('C-07-admin-orders');
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin/pos-orders`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForTimeout(5500);
  await page.screenshot({
    path: `${OUT_CAP}/C-07-admin-pos-orders.png`,
    fullPage: true,
  });
  await page
    .goto(`${BASE}/admin/pos-orders-tracker`, {
      waitUntil: 'domcontentloaded',
    })
    .catch(() => {});
  await page.waitForTimeout(4000);
  await page.screenshot({
    path: `${OUT_CAP}/C-07b-admin-pos-orders-tracker.png`,
    fullPage: true,
  });
  await page
    .goto(`${BASE}/admin/cash-overview`, { waitUntil: 'domcontentloaded' })
    .catch(() => {});
  await page.waitForTimeout(4000);
  await page.screenshot({
    path: `${OUT_CAP}/C-07c-admin-cash-overview.png`,
    fullPage: true,
  });
  persist(store);
  await ctx.close();
});

test('C-08 Admin secondary surfaces', async ({ browser }) => {
  const store = logEvents('C-08-admin-secondary');
  const { ctx, page } = await authedPage(browser, store);

  const candidates = [
    '/admin/settings',
    '/admin/branches',
    '/admin/employees',
    '/admin/customers',
    '/admin/coupons',
    '/admin/sales-report',
    '/admin/items-report',
    '/admin/observability',
    '/admin/cash-sessions-report',
    '/admin/messages',
    '/admin/delivery-boys',
  ];
  for (const route of candidates) {
    try {
      await page.goto(`${BASE}${route}`, {
        waitUntil: 'domcontentloaded',
        timeout: 12000,
      });
      await page.waitForTimeout(2500);
      const slug = route.replace(/^\/admin\//, '').replace(/\//g, '-');
      await page.screenshot({
        path: `${OUT_CAP}/C-08-${slug}.png`,
        fullPage: true,
      });
    } catch (_e) {
      /* skip */
    }
  }
  persist(store);
  await ctx.close();
});
