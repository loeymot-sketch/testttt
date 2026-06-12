/* REFUTER-1 D-B1-02: re-measure full-reload data-visible latency on items/ingredients/stock.
 * Forced-token harness (immune to concurrent token kills) + 3 iterations per page.
 * Iteration 1 = cold HTTP cache (fresh context); 2-3 = warm cache (same context). */
const path = require('path');
const fs = require('fs');
const os = require('os');
const DIR = __dirname;
const { BASE, makePage, uiLogin } = require(path.join(DIR, '..', 'petits-systemes-2026-06-11', 'lib.cjs'));
const TOKEN = process.env.FK_TOKEN;
const out = [];
const log = (...a) => { const s = a.join(' '); out.push(s); console.log(s); };

const PAGES = [
  { name: 'ITEMS', url: '/admin/items', sel: 'table tbody tr td' },
  { name: 'ING', url: '/admin/ingredients', sel: '[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]' },
  { name: 'STOCK', url: '/admin/stock/rupture', sel: '[data-testid="stock-mgmt-search"]' },
];

(async () => {
  log('loadavg-start:', JSON.stringify(os.loadavg()));
  const { browser, page, sink } = await makePage(TOKEN);
  await uiLogin(page);
  log('LOGIN OK url=', page.url());

  // capture localStorage auth so we can replant it into fresh contexts (cold-cache runs)
  const ls = await page.evaluate(() => JSON.stringify(Object.fromEntries(Object.entries(localStorage))));

  const measure = async (pg, p) => {
    let firstApi = -1; let firstApiUrl = '';
    const tNav = Date.now();
    const onReq = r => { if (r.url().includes('/api/') && firstApi < 0) { firstApi = Date.now() - tNav; firstApiUrl = r.url().replace(BASE, '').slice(0, 70); } };
    pg.on('request', onReq);
    await pg.goto(BASE + p.url, { waitUntil: 'commit', timeout: 30000 });
    let dataAt = -1;
    for (let i = 0; i < 250; i++) {
      const got = await pg.$(p.sel);
      if (got) { dataAt = Date.now() - tNav; break; }
      await pg.waitForTimeout(50);
    }
    pg.off('request', onReq);
    return { firstApi, firstApiUrl, dataAt };
  };

  // ---- COLD run: fresh context per page (no HTTP cache), token forced, localStorage replanted
  for (const p of PAGES) {
    const fresh = await makePage(TOKEN);
    await fresh.page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await fresh.page.evaluate((s) => { const o = JSON.parse(s); for (const [k, v] of Object.entries(o)) localStorage.setItem(k, v); }, ls);
    const r = await measure(fresh.page, p);
    log(`COLD ${p.name}: firstApi=${r.firstApi}ms (${r.firstApiUrl}) data-visible=${r.dataAt}ms`);
    if (fresh.sink.console.length) log(`COLD ${p.name} console:`, JSON.stringify(fresh.sink.console.slice(0, 4)));
    if (fresh.sink.http.length) log(`COLD ${p.name} http:`, JSON.stringify(fresh.sink.http.slice(0, 4)));
    await fresh.browser.close();
  }

  // ---- WARM runs: same logged-in context, 3 full reloads per page
  for (const p of PAGES) {
    for (let it = 1; it <= 3; it++) {
      const r = await measure(page, p);
      log(`WARM ${p.name} #${it}: firstApi=${r.firstApi}ms (${r.firstApiUrl}) data-visible=${r.dataAt}ms`);
    }
    if (sink.console.length) { log(`WARM ${p.name} console:`, JSON.stringify(sink.console.slice(0, 4))); sink.console.length = 0; }
    if (sink.http.length) { log(`WARM ${p.name} http:`, JSON.stringify(sink.http.slice(0, 4))); sink.http.length = 0; }
  }

  await page.screenshot({ path: path.join(DIR, 'refuter1-db1-02-stock.png') });
  log('loadavg-end:', JSON.stringify(os.loadavg()));
  fs.writeFileSync(path.join(DIR, 'refuter1-db1-02.log'), out.join('\n'));
  await browser.close();
})().catch(e => { console.error('FATAL', e); fs.writeFileSync(path.join(DIR, 'refuter1-db1-02.log'), out.join('\n') + '\nFATAL ' + e); process.exit(1); });
