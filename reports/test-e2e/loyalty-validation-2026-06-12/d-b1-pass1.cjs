/* D-B1 coeur-gestion pass 1: load each page, measure latency, sink console/http errors, screenshot, dump innerText */
const path = require('path');
const fs = require('fs');
const DIR = __dirname;
const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');
const { chromium } = require('playwright');

const PAGES = [
  ['dashboard', '/admin/dashboard'],
  ['items', '/admin/items'],
  ['ingredients', '/admin/ingredients'],
  ['ingredients-attribute', '/admin/ingredients/attribute'],
  ['ingredients-extra', '/admin/ingredients/extra'],
  ['ingredients-addon', '/admin/ingredients/addon'],
  ['stock-rupture', '/admin/stock/rupture'],
];

(async () => {
  // makePage without token override: build our own context (no /api route rewrite since token undefined)
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console-error] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

  await uiLogin(page);
  console.log('LOGIN OK url=', page.url());
  sink.console.length = 0; sink.http.length = 0;

  const results = [];
  for (const [name, route] of PAGES) {
    sink.console.length = 0; sink.http.length = 0;
    const t0 = Date.now();
    try {
      await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 30000 });
    } catch (e) {
      console.log(`${name}: goto error ${String(e).slice(0,150)}`);
    }
    const tLoad = Date.now() - t0;
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(DIR, `D-B1-${name}.png`), fullPage: false });
    const text = await page.evaluate(() => document.body.innerText);
    fs.writeFileSync(path.join(DIR, `D-B1-${name}.txt`), text);
    results.push({ name, route, ms: tLoad, console: [...sink.console], http: [...sink.http], finalUrl: page.url() });
    console.log(`PAGE ${name} ${route} load=${tLoad}ms console=${sink.console.length} http400=${sink.http.length} url=${page.url()}`);
  }
  fs.writeFileSync(path.join(DIR, 'd-b1-pass1.json'), JSON.stringify(results, null, 2));
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
