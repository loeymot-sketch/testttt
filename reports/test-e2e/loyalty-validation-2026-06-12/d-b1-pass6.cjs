/* D-B1 pass 6: items search/empty/clear/pagination/limit/sort with correct Filtrer toggle */
const path = require('path');
const fs = require('fs');
const DIR = __dirname;
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const TOKEN = process.env.FK_TOKEN;
const out = [];
const log = (...a) => { const s = a.join(' '); out.push(s); console.log(s); };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.route(/\/api\//, (route) => {
    const headers = { ...route.request().headers(), authorization: 'Bearer ' + TOKEN };
    route.continue({ headers });
  });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console-error] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  const flushSink = (tag) => {
    if (sink.console.length) log(`SINK ${tag} console:`, JSON.stringify(sink.console));
    if (sink.http.length) log(`SINK ${tag} http:`, JSON.stringify(sink.http));
    sink.console.length = 0; sink.http.length = 0;
  };

  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  log('LOGIN OK');
  flushSink('login');

  await page.goto(BASE + '/admin/items', { waitUntil: 'commit', timeout: 30000 });
  await page.waitForSelector('table tbody tr td', { timeout: 60000 });
  await page.waitForTimeout(800);
  const rowNames = async () => page.$$eval('table tbody tr', trs => trs.map(tr => (tr.querySelectorAll('td')[1]?.innerText || '').trim()).filter(Boolean));
  const panelH = async () => page.evaluate(() => {
    const el = document.getElementById('item-filter');
    return el ? Math.round(el.getBoundingClientRect().height) : -1;
  });
  const p1 = await rowNames();
  log('p1:', p1.length, p1[0]);
  log('panel height before:', await panelH());
  // the real toggle: a "Filtrer" button NOT a catalog metric
  const realFilter = page.locator('button:not(.catalog-control-plane__metric)').filter({ hasText: /^\s*Filtrer\s*$/ }).first();
  await realFilter.click();
  await page.waitForTimeout(900);
  log('panel height after toggle:', await panelH());
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-filterpanel.png') });
  const nameInput = page.locator('#item-filter input#name');
  await nameInput.fill('Tacos');
  await page.locator('#item-filter').getByRole('button', { name: /Rechercher/i }).click();
  await page.waitForTimeout(1600);
  const pS = await rowNames();
  log('search Tacos ->', JSON.stringify(pS));
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-search.png') });
  flushSink('search');
  // empty
  if ((await panelH()) < 50) { await realFilter.click(); await page.waitForTimeout(700); }
  await nameInput.fill('zzz_introuvable_42');
  await page.locator('#item-filter').getByRole('button', { name: /Rechercher/i }).click();
  await page.waitForTimeout(1600);
  const pE = await rowNames();
  const zone = await page.evaluate(() => (document.querySelector('table')?.closest('div')?.innerText || '').replace(/\s+/g, ' ').slice(0, 250));
  const showingE = await page.evaluate(() => (document.body.innerText.match(/Affichage[^\n]+/) || [''])[0]);
  log('empty rows:', pE.length, '| zone:', JSON.stringify(zone), '| showing:', showingE);
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-empty.png') });
  flushSink('empty');
  // clear
  if ((await panelH()) < 50) { await realFilter.click(); await page.waitForTimeout(700); }
  await page.locator('#item-filter').getByRole('button', { name: /Effacer/i }).click();
  await page.waitForTimeout(1600);
  const pC = await rowNames();
  log('after Effacer:', pC.length, pC[0]);
  flushSink('clear');
  // pagination
  await page.locator('a, button, li').filter({ hasText: /^2$/ }).last().click();
  await page.waitForTimeout(1600);
  const p2 = await rowNames();
  const showing2 = await page.evaluate(() => (document.body.innerText.match(/Affichage[^\n]+/) || [''])[0]);
  log('page2:', p2.length, 'first:', p2[0], '|', showing2, '| changed:', p2[0] !== p1[0]);
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-page2.png') });
  flushSink('pag');
  // limit via TableLimitComponent (vue-select probably)
  const limitInfo = await page.evaluate(() => {
    const sel = document.querySelector('select');
    return sel ? { kind: 'select', options: [...sel.options].map(o => o.value) } : { kind: 'custom' };
  });
  log('limit widget:', JSON.stringify(limitInfo));
  if (limitInfo.kind === 'select') {
    await page.selectOption('select', '25');
  } else {
    await page.locator('.vue-select, [class*=limit], button').filter({ hasText: /^\s*10\s*$/ }).first().click().catch(e => log('limit open err', String(e).slice(0, 100)));
    await page.waitForTimeout(500);
    await page.getByText('25', { exact: true }).first().click().catch(e => log('limit 25 err', String(e).slice(0, 100)));
  }
  await page.waitForTimeout(1600);
  const p25 = await rowNames();
  const showing25 = await page.evaluate(() => (document.body.innerText.match(/Affichage[^\n]+/) || [''])[0]);
  log('limit25 count:', p25.length, '|', showing25);
  flushSink('limit');
  // sort affordances
  const sortable = await page.evaluate(() => [...document.querySelectorAll('table thead th')].map(th => ({ t: th.innerText.trim().slice(0, 12), icon: !!th.querySelector('svg, i, [class*=sort]'), cursor: getComputedStyle(th).cursor })));
  log('th sort affordances:', JSON.stringify(sortable));

  fs.writeFileSync(path.join(DIR, 'd-b1-pass6.log'), out.join('\n'));
  await browser.close();
})().catch(e => { console.error('FATAL', e); fs.writeFileSync(path.join(DIR, 'd-b1-pass6.log'), out.join('\n') + '\nFATAL ' + e); process.exit(1); });
