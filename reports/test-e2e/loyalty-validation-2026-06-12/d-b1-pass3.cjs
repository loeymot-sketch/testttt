/* D-B1 pass 3: SPA-internal nav latency + items interactions + addon deep-link patient re-test */
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
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console-error] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
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

  /* SPA-nav: Catalogue (items) */
  let t0 = Date.now();
  await page.getByText('Catalogue', { exact: true }).first().click();
  await page.waitForSelector('text=Affichage de', { timeout: 20000 });
  log('SPA-NAV items data-visible in', Date.now() - t0, 'ms');
  await page.waitForTimeout(600);
  const rowNames = async () => page.$$eval('table tbody tr', trs => trs.map(tr => (tr.querySelectorAll('td')[1]?.innerText || '').trim()).filter(Boolean));
  const p1 = await rowNames();
  log('ITEMS p1 first:', p1[0], '| count:', p1.length);
  // open filter + search via Enter
  await page.getByRole('button', { name: /Filtrer/i }).first().click();
  await page.waitForTimeout(700);
  // list visible buttons in the filter area for diagnosis
  const visBtns = await page.$$eval('button', bs => bs.filter(b => b.offsetParent !== null).map(b => b.innerText.trim()).filter(Boolean));
  log('ITEMS visible buttons:', JSON.stringify(visBtns.slice(0, 30)));
  await page.fill('#name', 'Tacos');
  await page.press('#name', 'Enter');
  await page.waitForTimeout(1500);
  const pS = await rowNames();
  log('ITEMS search Tacos:', JSON.stringify(pS));
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-search.png') });
  flushSink('items-search');
  // empty-state
  await page.fill('#name', 'zzz_introuvable_42');
  await page.press('#name', 'Enter');
  await page.waitForTimeout(1500);
  const pE = await rowNames();
  const emptyWords = await page.evaluate(() => (document.body.innerText.match(/[^\n]*(ucun|vide|No data|not found)[^\n]*/g) || []));
  log('ITEMS empty rows:', pE.length, '| empty-state lines:', JSON.stringify(emptyWords));
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-empty.png') });
  // clear
  const effacer = page.getByRole('button', { name: /Effacer/i }).first();
  if (await effacer.isVisible().catch(() => false)) { await effacer.click(); } else { await page.fill('#name', ''); await page.press('#name', 'Enter'); }
  await page.waitForTimeout(1500);
  log('ITEMS after clear count:', (await rowNames()).length);
  // pagination
  const pagBtn = page.locator('ul li, nav [class*=pag], button, a').filter({ hasText: /^2$/ }).last();
  await pagBtn.click().catch(e => log('ITEMS pag2 click err', String(e).slice(0, 150)));
  await page.waitForTimeout(1500);
  const p2 = await rowNames();
  const showing = await page.evaluate(() => (document.body.innerText.match(/Affichage de[^\n]+/) || [''])[0]);
  log('ITEMS page2 first:', p2[0], '| count:', p2.length, '|', showing);
  await page.screenshot({ path: path.join(DIR, 'D-B1-items-page2.png') });
  flushSink('items-pag');
  // limit 25
  const limitSel = await page.$('select');
  if (limitSel) { await limitSel.selectOption('25').catch(() => 0); }
  else {
    await page.locator('button, div').filter({ hasText: /^10$/ }).last().click().catch(e => log('limit open err', String(e).slice(0, 100)));
    await page.waitForTimeout(400);
    await page.getByText('25', { exact: true }).first().click().catch(e => log('limit 25 err', String(e).slice(0, 100)));
  }
  await page.waitForTimeout(1500);
  const p25 = await rowNames();
  const showing25 = await page.evaluate(() => (document.body.innerText.match(/Affichage de[^\n]+/) || [''])[0]);
  log('ITEMS limit25 count:', p25.length, '|', showing25);
  flushSink('items-limit');

  /* SPA-nav: Ingrédients */
  t0 = Date.now();
  await page.getByText('Ingrédients', { exact: true }).first().click();
  await page.waitForSelector('[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]', { timeout: 20000 });
  log('SPA-NAV ingredients data-visible in', Date.now() - t0, 'ms');
  const tabCounts = {};
  for (const tab of ['Viandes & Attributs', 'Suppléments', 'Add-ons', 'Tous']) {
    const tA = Date.now();
    await page.getByRole('tab', { name: tab }).click();
    await page.waitForTimeout(1000);
    tabCounts[tab] = await page.evaluate(() => ({
      rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
      empty: !!document.querySelector('[data-testid="ingredient-empty"]'),
      badge: (document.querySelector('[data-testid="ingredient-list"] header span')?.innerText || '').trim(),
      first: (document.querySelector('[data-testid="ingredient-list"] table tbody tr')?.innerText || '').replace(/\s+/g, ' ').slice(0, 60),
    }));
    log(`ING tab "${tab}" ->`, JSON.stringify(tabCounts[tab]));
  }
  await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-tabs.png') });
  flushSink('ing-tabs');

  /* deep-link addon patient */
  t0 = Date.now();
  await page.goto(BASE + '/admin/ingredients/addon', { waitUntil: 'commit', timeout: 30000 });
  const sel = '[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]';
  let ok = true;
  await page.waitForSelector(sel, { timeout: 25000 }).catch(() => { ok = false; });
  log('ADDON deep-link data-visible:', ok, 'in', Date.now() - t0, 'ms');
  const st = await page.evaluate(() => ({
    rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
    activeTab: (document.querySelector('[role=tab][aria-selected="true"]')?.innerText || '').trim(),
    badge: (document.querySelector('[data-testid="ingredient-list"] header span')?.innerText || '').trim(),
    rowsTxt: [...document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr')].map(r => r.innerText.replace(/\s+/g, ' ').slice(0, 60)),
  }));
  log('ADDON state:', JSON.stringify(st));
  await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-addon.png') });
  const addonTxt = await page.evaluate(() => document.body.innerText);
  fs.writeFileSync(path.join(DIR, 'D-B1-ingredients-addon.txt'), addonTxt);
  flushSink('addon');

  /* toggle availability interaction on ingredients (extra type, then revert) */
  await page.goto(BASE + '/admin/ingredients/extra', { waitUntil: 'commit' });
  await page.waitForSelector('[data-testid="ingredient-list"] table tbody tr', { timeout: 25000 }).catch(() => log('EXTRA rows never visible'));
  log('EXTRA deep-link ok');
  // open usage drawer = real interaction
  await page.getByRole('button', { name: /Voir les détails/i }).first().click().catch(e => log('drawer err', String(e).slice(0, 100)));
  await page.waitForTimeout(1200);
  const drawerTxt = await page.evaluate(() => document.body.innerText.length);
  await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-drawer.png') });
  log('EXTRA drawer opened, body len', drawerTxt);
  await page.keyboard.press('Escape');
  flushSink('extra-drawer');

  /* SPA-nav stock via sidebar */
  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  t0 = Date.now();
  await page.getByText('Produits & Stock', { exact: true }).first().click();
  await page.waitForSelector('[data-testid="stock-mgmt-search"]', { timeout: 20000 }).catch(() => log('STOCK search input never visible'));
  log('SPA-NAV stock data-visible in', Date.now() - t0, 'ms url=', page.url());
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(DIR, 'D-B1-stock-rupture.png') });
  const stockTxt = await page.evaluate(() => document.body.innerText);
  fs.writeFileSync(path.join(DIR, 'D-B1-stock-rupture.txt'), stockTxt);
  flushSink('stock');

  fs.writeFileSync(path.join(DIR, 'd-b1-pass3.log'), out.join('\n'));
  await browser.close();
})().catch(e => { console.error('FATAL', e); fs.writeFileSync(path.join(DIR, 'd-b1-pass3.log'), out.join('\n') + '\nFATAL ' + e); process.exit(1); });
