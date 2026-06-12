/* D-B5 stage 4: coupons initial-load x3 (rows@5s/8s + pagination), pagination click,
   observability URL after settle, offers create-modal datepicker check. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';
const TOKEN = process.argv[2];
function log(s) { fs.appendFileSync(path.join(OUT, 'D-B5-divers.md'), s + '\n'); console.log(s); }

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.route(/\/api\//, (route) => {
    const h = { ...route.request().headers() };
    if (!route.request().url().includes('/api/auth/login')) h.authorization = 'Bearer ' + TOKEN;
    route.continue({ headers: h });
  });
  const page = await ctx.newPage();
  const sink = { http: [], reqs: [] };
  page.on('response', async r => {
    if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`);
    if (r.url().includes('/api/admin/coupon?')) {
      let n = -1; try { const j = await r.json(); n = (j.data || []).length; } catch (e) {}
      sink.reqs.push(`${r.status()} ${r.url().replace(BASE, '').slice(0, 110)} -> data.len=${n}`);
    }
  });

  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  log(`\n## STAGE 4 — coupons load x3 + obs URL ${new Date().toISOString()}`);

  for (let i = 1; i <= 3; i++) {
    sink.reqs.length = 0; sink.http.length = 0;
    await page.goto(BASE + '/admin/coupons', { waitUntil: 'commit' });
    await page.waitForTimeout(5000);
    const at5 = await page.evaluate(() => ({
      rows: [...document.querySelectorAll('tbody tr td:first-child')].map(t => t.innerText.trim().slice(0, 25)).slice(0, 11),
      pag: (document.querySelector('.pagination') || {}).innerText || null,
      total: (document.body.innerText.match(/Affichage[^\n]{0,80}|Showing[^\n]{0,80}/) || [null])[0],
    }));
    await page.waitForTimeout(3000);
    const at8 = await page.evaluate(() => ({
      rows: document.querySelectorAll('tbody tr').length,
      first: (document.querySelector('tbody tr td') || {}).innerText || null,
      pag2link: !!([...document.querySelectorAll('.pagination a, .pagination button, ul.pagination li')].find(e => e.innerText.trim() === '2')),
    }));
    log(`- run${i} @5s rows=${JSON.stringify(at5.rows)} pag=${JSON.stringify(at5.pag && at5.pag.replace(/\s+/g, ' '))} total=${JSON.stringify(at5.total)}`);
    log(`  run${i} @8s rowCount=${at8.rows} first=${JSON.stringify(at8.first && at8.first.slice(0, 30))} pag2=${at8.pag2link} | api: ${JSON.stringify(sink.reqs)} ${sink.http.length ? '| http>=400: ' + sink.http.join(',') : ''}`);
    if (i === 1) await page.screenshot({ path: path.join(OUT, 'D-B5-divers-coupons-run1-8s.png'), fullPage: true });
  }

  // pagination click test (state now: 12 coupons, per_page=10)
  try {
    const p2 = page.locator('.pagination a, .pagination button, ul.pagination li a').filter({ hasText: /^2$/ }).first();
    if (await p2.count()) {
      const rows1 = await page.evaluate(() => [...document.querySelectorAll('tbody tr td:first-child')].map(t => t.innerText.trim()));
      await p2.click();
      await page.waitForTimeout(2000);
      const rows2 = await page.evaluate(() => [...document.querySelectorAll('tbody tr td:first-child')].map(t => t.innerText.trim()));
      log(`- pagination p1->${JSON.stringify(rows1.slice(0, 3))}... p2->${JSON.stringify(rows2)} changed=${JSON.stringify(rows1) !== JSON.stringify(rows2)}`);
      await page.screenshot({ path: path.join(OUT, 'D-B5-divers-coupons-pagination-p2.png') });
    } else {
      const pagDump = await page.evaluate(() => { const p = document.querySelector('.pagination, [class*=pagination]'); return p ? p.outerHTML.slice(0, 400) : 'NO .pagination NODE'; });
      log(`- pagination "2" absent. pagination DOM: ${pagDump}`);
    }
  } catch (e) { log('PAG ERROR ' + String(e).slice(0, 200)); }

  // observability URL after long settle
  await page.goto(BASE + '/admin/observability', { waitUntil: 'networkidle' });
  await page.waitForTimeout(6000);
  const obs = await page.evaluate(() => ({ url: location.pathname, hasOutbox: document.body.innerText.includes('Pipeline outbox'), pending: (document.body.innerText.match(/\((\d+)\)/) || [])[1] || null, generated: (document.body.innerText.match(/Généré à [^\n]+/) || [null])[0] }));
  log(`- /admin/observability settle 6s: ${JSON.stringify(obs)}`);
  await page.screenshot({ path: path.join(OUT, 'D-B5-divers-observability-url.png') });

  await browser.close();
  log('STAGE4 DONE');
})().catch(e => { log('FATAL ' + String(e).slice(0, 300)); process.exit(1); });
