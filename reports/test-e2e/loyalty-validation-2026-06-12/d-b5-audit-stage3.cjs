/* D-B5 stage 3: timeline polling (spinner/rows), coupons search+pagination réels,
   messages open, push latency x3, obs redirect URL, datepicker AM/PM. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';
const TOKEN = process.argv[2];

function log(s) { fs.appendFileSync(path.join(OUT, 'D-B5-divers.md'), s + '\n'); console.log(s); }
const shot = (page, name, full) => page.screenshot({ path: path.join(OUT, `D-B5-divers-${name}.png`), fullPage: !!full });

async function timeline(page, url, ms = 10000) {
  const t0 = Date.now();
  await page.goto(BASE + url, { waitUntil: 'commit' });
  const samples = [];
  while (Date.now() - t0 < ms) {
    const s = await page.evaluate(() => {
      const ov = document.querySelector('.loading, [class*=loading i]');
      const visible = ov && getComputedStyle(ov).display !== 'none' && ov.offsetParent !== null;
      const tb = document.querySelectorAll('tbody tr').length;
      const main = document.querySelector('#main, main, .db-main, .col-12');
      return { spin: !!visible, rows: tb, txt: document.body.innerText.length };
    }).catch(() => ({ err: 1 }));
    samples.push({ t: Date.now() - t0, ...s });
    await page.waitForTimeout(500);
  }
  return samples;
}
const fmtTL = (tl) => tl.map(s => `${s.t}ms:spin=${s.spin ? 1 : 0},rows=${s.rows},txt=${s.txt}`).join(' | ');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.route(/\/api\//, (route) => {
    const h = { ...route.request().headers() };
    if (!route.request().url().includes('/api/auth/login')) h.authorization = 'Bearer ' + TOKEN;
    route.continue({ headers: h });
  });
  const page = await ctx.newPage();
  const sink = { console: [], http: [], reqs: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  page.on('request', r => { if (r.url().includes('/api/admin/coupon')) sink.reqs.push(r.url().replace(BASE, '')); });
  const flush = (l) => {
    if (sink.console.length) log(`- [${l}] console:\n  ` + [...new Set(sink.console)].join('\n  '));
    if (sink.http.length) log(`- [${l}] http>=400:\n  ` + [...new Set(sink.http)].join('\n  '));
    sink.console.length = 0; sink.http.length = 0;
  };

  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  log(`\n## STAGE 3 — timelines + fonctionnel ${new Date().toISOString()}`);
  sink.console.length = 0; sink.http.length = 0;

  // T1: timelines for suspect pages
  for (const u of ['/admin/coupons', '/admin/messages', '/admin/subscribers', '/admin/push-notifications']) {
    const tl = await timeline(page, u, 10000);
    log(`\n### T1 timeline ${u}\n${fmtTL(tl)}`);
    flush('tl' + u);
    await shot(page, 'tl' + u.replace(/\//g, '_'), true);
  }

  // T2: coupons functional — search réel + pagination réelle
  try {
    sink.reqs.length = 0;
    await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length > 0, null, { timeout: 15000 });
    const rows1 = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(r => r.querySelector('td')?.innerText.trim().slice(0, 30)));
    const pag = await page.evaluate(() => { const p = document.querySelector('ul.pagination, .pagination, nav'); return p ? p.innerText.replace(/\s+/g, ' ').slice(0, 120) : null; });
    log(`\n### T2 coupons fonctionnel\n- page1 rows(${rows1.length}): ${JSON.stringify(rows1)}\n- pagination text: ${JSON.stringify(pag)}\n- list reqs: ${JSON.stringify(sink.reqs.slice(0, 3))}`);
    // pagination page 2
    sink.reqs.length = 0;
    const p2 = page.locator('.pagination li a, .pagination button, .pagination a').filter({ hasText: /^2$/ }).first();
    if (await p2.count()) {
      await p2.click();
      await page.waitForTimeout(1800);
      const rows2 = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(r => r.querySelector('td')?.innerText.trim().slice(0, 30)));
      log(`- page2 rows(${rows2.length}): ${JSON.stringify(rows2)} | changed=${JSON.stringify(rows1) !== JSON.stringify(rows2)} | req: ${JSON.stringify(sink.reqs.slice(0, 2))}`);
      await shot(page, 'coupons-page2-real');
    } else log('- NO pagination "2" found while 12 rows seeded (per_page default?)');
    // search by name via #searchName
    sink.reqs.length = 0;
    await page.getByRole('button', { name: /Filtrer/i }).first().click();
    await page.waitForTimeout(700);
    await page.locator('#searchName').fill('E2E-D5 Coupon 03');
    await page.locator('#coupon-filter button[class*=bg-primary]').first().click();
    await page.waitForTimeout(1800);
    const rowsS = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(r => r.querySelector('td')?.innerText.trim().slice(0, 40)));
    log(`- search #searchName="E2E-D5 Coupon 03" -> rows(${rowsS.length}): ${JSON.stringify(rowsS)} | req: ${JSON.stringify(sink.reqs.slice(0, 2))}`);
    await shot(page, 'coupons-search-real');
    flush('coupons-fn');
    // clear then datepicker AM/PM in filter
    const dp = page.locator('#coupon-filter .dp__input').first();
    if (await dp.count()) {
      await dp.click({ force: true });
      await page.waitForTimeout(900);
      const menu = await page.evaluate(() => { const m = document.querySelector('.dp__menu'); return m ? m.innerText.replace(/\s+/g, ' ').slice(0, 300) : null; });
      log(`- datepicker menu: ${JSON.stringify(menu)}`);
      // open time picker
      const clk = page.locator('.dp__menu [data-test="open-time-picker-btn"], .dp__menu .dp__btn[aria-label*=time i], .dp__menu .dp__button');
      if (await clk.count()) {
        await clk.first().click({ force: true }).catch(() => {});
        await page.waitForTimeout(700);
        const tmenu = await page.evaluate(() => { const m = document.querySelector('.dp__menu'); return m ? m.innerText.replace(/\s+/g, ' ').slice(0, 300) : null; });
        log(`- time picker pane: ${JSON.stringify(tmenu)}`);
      }
      await shot(page, 'coupons-datepicker');
    } else log('- no .dp__input inside #coupon-filter');
    flush('coupons-dp');
  } catch (e) { log('T2 ERROR ' + String(e).slice(0, 300)); flush('T2'); }

  // T3: messages — wait spinner clear, open conversation from MAIN pane
  try {
    await page.goto(BASE + '/admin/messages', { waitUntil: 'networkidle' });
    await page.waitForTimeout(4000);
    const state = await page.evaluate(() => {
      const main = document.querySelector('.db-card, [class*=message]');
      const items = [...document.querySelectorAll('.db-card li, .db-card [class*=cursor]')].map(e => e.innerText.replace(/\n/g, '·').slice(0, 60)).slice(0, 8);
      return { mainText: main ? main.innerText.replace(/\n+/g, ' | ').slice(0, 500) : null, items };
    });
    log(`\n### T3 messages\n- main: ${JSON.stringify(state.mainText)}\n- conv items: ${JSON.stringify(state.items)}`);
    await shot(page, 'messages-real', true);
    // click first conversation INSIDE main card only
    const conv = page.locator('.db-card li, .db-card [class*=conversation], .db-card img[alt]').first();
    if (await conv.count()) {
      await conv.click({ force: true }).catch(() => {});
      await page.waitForTimeout(2000);
      const thread = await page.evaluate(() => document.body.innerText.match(/\d{1,2}:\d{2}\s?(AM|PM)?(,| )\s?\d{2}-\d{2}-\d{4}/g));
      log(`- after open conv, timestamps: ${JSON.stringify(thread && thread.slice(0, 6))}`);
      await shot(page, 'messages-thread', true);
    }
    flush('messages');
  } catch (e) { log('T3 ERROR ' + String(e).slice(0, 300)); flush('T3'); }

  // T4: push latency x3
  try {
    log(`\n### T4 push-notifications latence x3`);
    for (let i = 1; i <= 3; i++) {
      const t0 = Date.now();
      await page.goto(BASE + '/admin/push-notifications', { waitUntil: 'networkidle' });
      log(`- run${i}: ${Date.now() - t0}ms`);
      await page.waitForTimeout(400);
    }
    flush('push-lat');
  } catch (e) { log('T4 ERROR ' + String(e).slice(0, 300)); }

  // T5: observability redirect URL + outbox veracity marker
  try {
    await page.goto(BASE + '/admin/observability', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    const marker = await page.evaluate(() => ({ url: location.pathname, hasOutbox: document.body.innerText.includes('Pipeline outbox'), pending: (document.body.innerText.match(/Événements en attente \((\d+)\)/) || [])[1] || null }));
    log(`\n### T5 observability\n- ${JSON.stringify(marker)}`);
    flush('obs');
  } catch (e) { log('T5 ERROR ' + String(e).slice(0, 300)); }

  await browser.close();
  log('\nSTAGE3 DONE');
})().catch(e => { log('FATAL ' + String(e).slice(0, 400)); process.exit(1); });
