/* D-B1 pass 4: NO interception (real-user) — precise data-visible timing + remaining interactions */
const path = require('path');
const fs = require('fs');
const DIR = __dirname;
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const out = [];
const log = (...a) => { const s = a.join(' '); out.push(s); console.log(s); };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console-error] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  const reqLog = [];
  let tNav = Date.now();
  page.on('request', r => { if (r.url().includes('/api/')) reqLog.push({ ev: 'start', url: r.url().replace(BASE, '').slice(0, 100), t: Date.now() - tNav }); });
  page.on('response', r => { if (r.url().includes('/api/')) reqLog.push({ ev: 'end', url: r.url().replace(BASE, '').slice(0, 100), t: Date.now() - tNav, status: r.status() }); });
  const flushSink = (tag) => {
    if (sink.console.length) log(`SINK ${tag} console:`, JSON.stringify(sink.console));
    if (sink.http.length) log(`SINK ${tag} http:`, JSON.stringify(sink.http));
    sink.console.length = 0; sink.http.length = 0;
  };

  const login = async () => {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.waitForLoadState('networkidle');
  };
  await login();
  log('LOGIN OK');
  flushSink('login');

  /* A) ITEMS deep timing + interactions */
  try {
    reqLog.length = 0; tNav = Date.now();
    await page.goto(BASE + '/admin/items', { waitUntil: 'commit', timeout: 30000 });
    await page.waitForSelector('table tbody tr td', { timeout: 20000 });
    log('ITEMS data-visible in', Date.now() - tNav, 'ms');
    await page.waitForTimeout(700);
    const rowNames = async () => page.$$eval('table tbody tr', trs => trs.map(tr => (tr.querySelectorAll('td')[1]?.innerText || '').trim()).filter(Boolean));
    const p1 = await rowNames();
    log('ITEMS p1:', p1.length, 'first=', p1[0]);
    await page.getByRole('button', { name: /Filtrer/i }).first().click();
    await page.waitForTimeout(700);
    await page.fill('#name', 'Tacos');
    await page.press('#name', 'Enter');
    await page.waitForTimeout(1500);
    const pS = await rowNames();
    log('ITEMS search Tacos ->', JSON.stringify(pS));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-search.png') });
    await page.fill('#name', 'zzz_introuvable_42');
    await page.press('#name', 'Enter');
    await page.waitForTimeout(1500);
    const pE = await rowNames();
    const emptyLines = await page.evaluate(() => (document.body.innerText.match(/[^\n]*(ucun|vide|No data|not found|Désolé)[^\n]*/g) || []));
    log('ITEMS empty rows:', pE.length, 'empty-lines:', JSON.stringify(emptyLines));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-empty.png') });
    const effacer = page.getByRole('button', { name: /Effacer/i }).first();
    if (await effacer.isVisible().catch(() => false)) await effacer.click();
    else { await page.fill('#name', ''); await page.press('#name', 'Enter'); }
    await page.waitForTimeout(1500);
    log('ITEMS after clear:', (await rowNames()).length);
    // pagination: PaginationBox links
    await page.locator('a, button, li').filter({ hasText: /^2$/ }).last().click();
    await page.waitForTimeout(1500);
    const p2 = await rowNames();
    const showing = await page.evaluate(() => (document.body.innerText.match(/Affichage de[^\n]+/) || [''])[0]);
    log('ITEMS page2:', p2.length, 'first=', p2[0], '|', showing, '| changed=', p2[0] !== p1[0]);
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-page2.png') });
    flushSink('items');
  } catch (e) { log('ITEMS error', String(e).slice(0, 250)); flushSink('items-err'); }

  /* B) INGREDIENTS timeline */
  try {
    reqLog.length = 0; tNav = Date.now();
    await page.goto(BASE + '/admin/ingredients', { waitUntil: 'commit', timeout: 30000 });
    let dataAt = -1;
    for (let i = 0; i < 250; i++) {
      const got = await page.$('[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]');
      if (got) { dataAt = Date.now() - tNav; break; }
      await page.waitForTimeout(100);
    }
    log('ING data-visible in', dataAt, 'ms');
    log('ING api timeline:', JSON.stringify(reqLog.filter(r => /ingredient|default-access|setting/.test(r.url)).slice(0, 14)));
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients.png') });
    const ingTxt = await page.evaluate(() => document.body.innerText);
    fs.writeFileSync(path.join(DIR, 'D-B1-ingredients.txt'), ingTxt);
    // tabs via role=tab
    for (const tab of ['Viandes & Attributs', 'Suppléments', 'Add-ons', 'Tous']) {
      await page.getByRole('tab', { name: tab }).click();
      await page.waitForTimeout(1100);
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
    flushSink('ingredients');
  } catch (e) { log('ING error', String(e).slice(0, 250)); flushSink('ing-err'); }

  /* C) ADDON deep-link patient */
  try {
    reqLog.length = 0; tNav = Date.now();
    await page.goto(BASE + '/admin/ingredients/addon', { waitUntil: 'commit', timeout: 30000 });
    let dataAt = -1;
    for (let i = 0; i < 300; i++) {
      const got = await page.$('[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-empty"]');
      if (got) { dataAt = Date.now() - tNav; break; }
      await page.waitForTimeout(100);
    }
    log('ADDON data-visible in', dataAt, 'ms (-1 = NEVER within 30s)');
    log('ADDON api timeline:', JSON.stringify(reqLog.filter(r => /ingredient/.test(r.url)).slice(0, 10)));
    const st = await page.evaluate(() => ({
      mounted: !!document.querySelector('[data-testid="ingredient-list"]'),
      rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
      activeTab: (document.querySelector('[role=tab][aria-selected="true"]')?.innerText || '').trim(),
      badge: (document.querySelector('[data-testid="ingredient-list"] header span')?.innerText || '').trim(),
      rowsTxt: [...document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr')].map(r => r.innerText.replace(/\s+/g, ' ').slice(0, 55)),
    }));
    log('ADDON state:', JSON.stringify(st));
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-addon.png') });
    fs.writeFileSync(path.join(DIR, 'D-B1-ingredients-addon.txt'), await page.evaluate(() => document.body.innerText));
    flushSink('addon');
  } catch (e) { log('ADDON error', String(e).slice(0, 250)); flushSink('addon-err'); }

  /* D) usage drawer on extra */
  try {
    await page.goto(BASE + '/admin/ingredients/extra', { waitUntil: 'commit' });
    await page.waitForSelector('[data-testid="ingredient-list"] table tbody tr', { timeout: 25000 });
    await page.getByRole('button', { name: /Voir les détails/i }).first().click();
    await page.waitForTimeout(1300);
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-drawer.png') });
    const dTxt = await page.evaluate(() => document.body.innerText.slice(-600));
    log('DRAWER tail:', JSON.stringify(dTxt.slice(-350)));
    await page.keyboard.press('Escape');
    flushSink('drawer');
  } catch (e) { log('DRAWER error', String(e).slice(0, 200)); flushSink('drawer-err'); }

  /* E) STOCK timing + capture */
  try {
    reqLog.length = 0; tNav = Date.now();
    await page.goto(BASE + '/admin/stock/rupture', { waitUntil: 'commit', timeout: 30000 });
    let dataAt = -1;
    for (let i = 0; i < 250; i++) {
      const got = await page.$('[data-testid="stock-mgmt-search"]');
      if (got) { dataAt = Date.now() - tNav; break; }
      await page.waitForTimeout(100);
    }
    log('STOCK ui-visible in', dataAt, 'ms; url=', page.url());
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(DIR, 'D-B1-stock-rupture.png') });
    fs.writeFileSync(path.join(DIR, 'D-B1-stock-rupture.txt'), await page.evaluate(() => document.body.innerText));
    flushSink('stock');
  } catch (e) { log('STOCK error', String(e).slice(0, 200)); flushSink('stock-err'); }

  /* F) dashboard re-capture loaded (named per-page capture) */
  try {
    await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(DIR, 'D-B1-dashboard.png') });
    flushSink('dashboard');
  } catch (e) { log('DASH error', String(e).slice(0, 200)); }

  fs.writeFileSync(path.join(DIR, 'd-b1-pass4.log'), out.join('\n'));
  await browser.close();
})().catch(e => { console.error('FATAL', e); fs.writeFileSync(path.join(DIR, 'd-b1-pass4.log'), out.join('\n') + '\nFATAL ' + e); process.exit(1); });
