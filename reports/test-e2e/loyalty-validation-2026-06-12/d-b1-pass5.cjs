/* D-B1 pass 5: clean scoped interactions (forced token) — items filter panel, pagination; ingredients tabs role=tab; addon patient; stock capture */
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

  /* A) ITEMS — scoped filter panel */
  try {
    await page.goto(BASE + '/admin/items', { waitUntil: 'commit', timeout: 30000 });
    await page.waitForSelector('table tbody tr td', { timeout: 25000 });
    await page.waitForTimeout(800);
    const rowNames = async () => page.$$eval('table tbody tr', trs => trs.map(tr => (tr.querySelectorAll('td')[1]?.innerText || '').trim()).filter(Boolean));
    const p1 = await rowNames();
    log('ITEMS p1 count:', p1.length, 'first:', p1[0]);
    // diagnose slide panel
    await page.getByRole('button', { name: /Filtrer/i }).first().click();
    await page.waitForTimeout(800);
    const panel = await page.evaluate(() => {
      const el = document.getElementById('item-filter');
      if (!el) return { exists: false };
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return { exists: true, x: r.x, w: r.width, display: cs.display, cls: el.className.slice(0, 120) };
    });
    log('ITEMS #item-filter panel:', JSON.stringify(panel));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-filterpanel.png') });
    // scoped fill inside panel (avoid create-drawer #name)
    const nameInput = page.locator('#item-filter input#name');
    await nameInput.waitFor({ state: 'visible', timeout: 8000 });
    await nameInput.fill('Tacos');
    await page.locator('#item-filter').getByRole('button', { name: /Rechercher/i }).click();
    await page.waitForTimeout(1500);
    const pS = await rowNames();
    log('ITEMS search Tacos ->', JSON.stringify(pS));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-search.png') });
    // empty-state
    await page.getByRole('button', { name: /Filtrer/i }).first().click().catch(() => 0);
    await page.waitForTimeout(500);
    if (await nameInput.isVisible().catch(() => false)) {
      await nameInput.fill('zzz_introuvable_42');
      await page.locator('#item-filter').getByRole('button', { name: /Rechercher/i }).click();
    }
    await page.waitForTimeout(1500);
    const pE = await rowNames();
    const tableZone = await page.evaluate(() => (document.querySelector('table')?.parentElement?.innerText || '').slice(0, 300));
    log('ITEMS empty rows:', pE.length, '| zone:', JSON.stringify(tableZone));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-empty.png') });
    // clear via panel Effacer
    await page.getByRole('button', { name: /Filtrer/i }).first().click().catch(() => 0);
    await page.waitForTimeout(500);
    await page.locator('#item-filter').getByRole('button', { name: /Effacer/i }).click().catch(async () => {
      await nameInput.fill(''); await page.locator('#item-filter').getByRole('button', { name: /Rechercher/i }).click();
    });
    await page.waitForTimeout(1500);
    log('ITEMS after clear:', (await rowNames()).length);
    // pagination
    await page.locator('a, button, li').filter({ hasText: /^2$/ }).last().click();
    await page.waitForTimeout(1500);
    const p2 = await rowNames();
    const showing = await page.evaluate(() => (document.body.innerText.match(/Affichage de[^\n]+/) || [''])[0]);
    log('ITEMS page2 count:', p2.length, 'first:', p2[0], '|', showing, '| changed:', p2[0] !== p1[0]);
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-page2.png') });
    // sort affordance check
    const sortable = await page.evaluate(() => [...document.querySelectorAll('table thead th')].map(th => ({ t: th.innerText.trim(), sortIcon: !!th.querySelector('svg, i, [class*=sort]'), cursor: getComputedStyle(th).cursor })));
    log('ITEMS th sort affordances:', JSON.stringify(sortable));
    flushSink('items');
  } catch (e) { log('ITEMS error', String(e).slice(0, 250)); flushSink('items-err'); }

  /* B) INGREDIENTS tabs via role=tab */
  try {
    await page.goto(BASE + '/admin/ingredients', { waitUntil: 'commit', timeout: 30000 });
    await page.waitForSelector('[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]', { timeout: 25000 });
    log('ING list visible');
    for (const tab of ['Viandes & Attributs', 'Suppléments', 'Add-ons', 'Tous']) {
      await page.getByRole('tab', { name: tab }).click();
      await page.waitForTimeout(1200);
      const st = await page.evaluate(() => ({
        rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
        empty: !!document.querySelector('[data-testid="ingredient-empty"]'),
        emptyTxt: (document.querySelector('[data-testid="ingredient-empty"]')?.innerText || '').trim(),
        badge: (document.querySelector('[data-testid="ingredient-list"] header span')?.innerText || '').trim(),
        first: (document.querySelector('[data-testid="ingredient-list"] table tbody tr')?.innerText || '').replace(/\s+/g, ' ').slice(0, 55),
      }));
      log(`ING tab "${tab}" ->`, JSON.stringify(st));
    }
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-tabs.png') });
    flushSink('ing-tabs');
  } catch (e) { log('ING tabs error', String(e).slice(0, 250)); flushSink('ing-tabs-err'); }

  /* C) ADDON deep-link patient (forced token, no contamination) */
  try {
    const t0 = Date.now();
    await page.goto(BASE + '/admin/ingredients/addon', { waitUntil: 'commit', timeout: 30000 });
    let dataAt = -1;
    for (let i = 0; i < 300; i++) {
      const got = await page.$('[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]');
      if (got) { dataAt = Date.now() - t0; break; }
      await page.waitForTimeout(100);
    }
    log('ADDON data-visible in', dataAt, 'ms (-1 = NEVER in 30s)');
    const st = await page.evaluate(() => ({
      mounted: !!document.querySelector('[data-testid="ingredient-list"]'),
      rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
      activeTab: (document.querySelector('[role=tab][aria-selected="true"]')?.innerText || '').trim(),
      badge: (document.querySelector('[data-testid="ingredient-list"] header span')?.innerText || '').trim(),
      rowsTxt: [...document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr')].map(r => r.innerText.replace(/\s+/g, ' ').slice(0, 55)),
      url: location.pathname,
    }));
    log('ADDON state:', JSON.stringify(st));
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-addon.png') });
    fs.writeFileSync(path.join(DIR, 'D-B1-ingredients-addon.txt'), await page.evaluate(() => document.body.innerText));
    flushSink('addon');
  } catch (e) { log('ADDON error', String(e).slice(0, 250)); flushSink('addon-err'); }

  /* D) drawer on extra */
  try {
    await page.goto(BASE + '/admin/ingredients/extra', { waitUntil: 'commit' });
    await page.waitForSelector('[data-testid="ingredient-list"] table tbody tr', { timeout: 25000 });
    await page.getByRole('button', { name: /Voir les détails/i }).first().click();
    await page.waitForTimeout(1300);
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-drawer.png') });
    const dTxt = await page.evaluate(() => (document.querySelector('[role=dialog], aside, [class*=drawer]')?.innerText || 'NO DRAWER NODE').replace(/\s+/g, ' ').slice(0, 400));
    log('DRAWER text:', JSON.stringify(dTxt));
    await page.keyboard.press('Escape');
    flushSink('drawer');
  } catch (e) { log('DRAWER error', String(e).slice(0, 200)); flushSink('drawer-err'); }

  /* E) STOCK re-capture loaded */
  try {
    const t0 = Date.now();
    await page.goto(BASE + '/admin/stock/rupture', { waitUntil: 'commit', timeout: 30000 });
    let dataAt = -1;
    for (let i = 0; i < 250; i++) {
      const got = await page.$('[data-testid="stock-mgmt-search"]');
      if (got) { dataAt = Date.now() - t0; break; }
      await page.waitForTimeout(100);
    }
    log('STOCK ui-visible in', dataAt, 'ms url=', page.url());
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(DIR, 'D-B1-stock-rupture.png') });
    fs.writeFileSync(path.join(DIR, 'D-B1-stock-rupture.txt'), await page.evaluate(() => document.body.innerText));
    flushSink('stock');
  } catch (e) { log('STOCK error', String(e).slice(0, 200)); flushSink('stock-err'); }

  fs.writeFileSync(path.join(DIR, 'd-b1-pass5.log'), out.join('\n'));
  await browser.close();
})().catch(e => { console.error('FATAL', e); fs.writeFileSync(path.join(DIR, 'd-b1-pass5.log'), out.join('\n') + '\nFATAL ' + e); process.exit(1); });
