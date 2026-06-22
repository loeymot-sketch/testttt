// Round 2 LIVREUR — API check via authed browser session
const { test } = require('@playwright/test');

test('LIVREUR API check', async ({ page }) => {
  test.setTimeout(60000);
  page.setDefaultTimeout(15000);

  await page.goto('http://127.0.0.1:8000/login');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  const inputs = await page.locator('input:not([type=checkbox]):not([type=hidden])').all();
  await inputs[0].fill('admin@lecayenne.fr');
  await inputs[1].fill('123456');
  await page.click('button:has-text("Connexion"), button[type="submit"]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);

  // Try fetching API from inside the Vue session
  // Capture network: visit cash session list page and watch the XHR
  const apiResponses = [];
  page.on('response', async (r) => {
    if (r.url().includes('/admin/delivery-boy')) {
      let ct = r.headers()['content-type'] || '';
      let snippet = '';
      try {
        const txt = await r.text();
        snippet = txt.slice(0, 200);
      } catch(_) {}
      apiResponses.push({ url: r.url(), status: r.status(), ct, snippet });
    }
  });
  await page.goto('http://127.0.0.1:8000/admin/delivery-boy-cash-sessions');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(3000);
  console.log('LIST_PAGE_API_CALLS:', JSON.stringify(apiResponses));

  apiResponses.length = 0;
  await page.goto('http://127.0.0.1:8000/admin/delivery-boys');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(3000);
  console.log('DB_LIST_PAGE_API_CALLS:', JSON.stringify(apiResponses));

  const sessionsRes = await page.evaluate(async () => {
    try {
      const token = window.localStorage.getItem('token') || window.sessionStorage.getItem('token');
      const headers = { Accept: 'application/json' };
      if (token) headers.Authorization = `Bearer ${token}`;
      const r = await fetch('/api/admin/delivery-boy/cash-sessions', { headers });
      const j = await r.json();
      return { status: r.status, body: j, hadToken: !!token };
    } catch (e) {
      return { error: e.message };
    }
  });
  console.log('SESSIONS_API:', JSON.stringify(sessionsRes));

  const showRes = await page.evaluate(async () => {
    try {
      const r = await fetch('/api/admin/delivery-boy/cash-sessions/1', { headers: { Accept: 'application/json' } });
      const j = await r.json();
      return { status: r.status, body: j };
    } catch (e) {
      return { error: e.message };
    }
  });
  console.log('SHOW_API:', JSON.stringify(showRes));

  const dbList = await page.evaluate(async () => {
    try {
      const r = await fetch('/api/admin/delivery-boy', { headers: { Accept: 'application/json' } });
      const j = await r.json();
      return { status: r.status, count: (j.data || []).length, sample: (j.data || [])[0] };
    } catch (e) {
      return { error: e.message };
    }
  });
  console.log('DB_LIST_API:', JSON.stringify(dbList));
});
