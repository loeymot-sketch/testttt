/* D-B1 pass 2: forced-token auth + real interactions per page */
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
  const apiTimings = [];
  page.on('requestfinished', async (req) => {
    if (!req.url().includes('/api/')) return;
    const t = req.timing();
    if (t && t.responseEnd > 0) apiTimings.push({ url: req.url().replace(BASE, '').slice(0, 110), ms: Math.round(t.responseEnd) });
  });
  const flushSink = (tag) => {
    if (sink.console.length) log(`SINK ${tag} console:`, JSON.stringify(sink.console));
    if (sink.http.length) log(`SINK ${tag} http:`, JSON.stringify(sink.http));
    sink.console.length = 0; sink.http.length = 0;
  };

  // login UI (SPA local state) — API calls protected by forced token anyway
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  log('LOGIN OK', page.url());
  flushSink('login');

  /* ---------- DASHBOARD ---------- */
  try {
    await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(1500);
    // interaction: open the first visible date-range picker on the page
    const dateInputs = await page.$$('input.flatpickr-input, input[placeholder*="ate"], .vue-daterange-picker, [class*="datepicker"] input');
    log('DASH dateInputs found:', dateInputs.length);
    if (dateInputs.length) { await dateInputs[0].click().catch(e => log('DASH datepicker click err', String(e).slice(0,120))); await page.waitForTimeout(800); }
    await page.screenshot({ path: path.join(DIR, 'D-B1-dashboard-interact.png') });
    await page.keyboard.press('Escape');
    flushSink('dashboard-interaction');
  } catch (e) { log('DASH section error', String(e).slice(0, 200)); }

  /* ---------- ITEMS ---------- */
  try {
    await page.goto(BASE + '/admin/items', { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForSelector('text=Affichage de', { timeout: 15000 });
    await page.waitForTimeout(800);
    const rowNames = async () => page.$$eval('table tbody tr', trs => trs.map(tr => (tr.querySelectorAll('td')[1]?.innerText || '').trim()).filter(Boolean));
    const p1 = await rowNames();
    log('ITEMS page1 rows:', JSON.stringify(p1));
    // KPI truncation check
    const kpi = await page.evaluate(() => {
      const els = [...document.querySelectorAll('*')].filter(e => e.children.length === 0 && /INDISPONIBLES/i.test(e.textContent || ''));
      return els.map(e => ({ text: e.textContent.trim(), clientW: e.clientWidth, scrollW: e.scrollWidth, truncated: e.scrollWidth > e.clientWidth + 1 }));
    });
    log('ITEMS KPI indisponibles:', JSON.stringify(kpi));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items.png') });
    // search "Tacos"
    await page.getByRole('button', { name: /Filtrer/i }).first().click();
    await page.waitForTimeout(600);
    await page.fill('#name', 'Tacos');
    await page.getByRole('button', { name: /^Rechercher$/i }).click();
    await page.waitForTimeout(1500);
    const pSearch = await rowNames();
    log('ITEMS search Tacos rows:', JSON.stringify(pSearch));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-search.png') });
    // empty search
    await page.fill('#name', 'zzz_introuvable_42');
    await page.getByRole('button', { name: /^Rechercher$/i }).click();
    await page.waitForTimeout(1500);
    const pEmpty = await rowNames();
    const emptyText = await page.$eval('table', t => t.closest('div').innerText.slice(0, 400)).catch(() => 'NO TABLE');
    log('ITEMS empty rows:', JSON.stringify(pEmpty), '| zone:', JSON.stringify(emptyText.slice(0, 300)));
    const bodyEmpty = await page.evaluate(() => document.body.innerText.match(/Aucun|aucune|No data|introuvable|vide/gi));
    log('ITEMS empty-state words:', JSON.stringify(bodyEmpty));
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-empty.png') });
    // clear
    await page.getByRole('button', { name: /Effacer/i }).click();
    await page.waitForTimeout(1500);
    const pClear = await rowNames();
    log('ITEMS after clear count:', pClear.length);
    // pagination page 2
    await page.getByText('2', { exact: true }).last().click();
    await page.waitForTimeout(1500);
    const p2 = await rowNames();
    log('ITEMS page2 rows:', JSON.stringify(p2));
    const showing = await page.evaluate(() => (document.body.innerText.match(/Affichage de[^\n]+/) || [''])[0]);
    log('ITEMS showing:', showing);
    await page.screenshot({ path: path.join(DIR, 'D-B1-items-page2.png') });
    // per-page limit 25
    await page.goto(BASE + '/admin/items', { waitUntil: 'networkidle' });
    await page.waitForSelector('text=Affichage de', { timeout: 15000 });
    const limitBtn = page.locator('text=/^10$/').first();
    await limitBtn.click().catch(e => log('ITEMS limit click err', String(e).slice(0, 120)));
    await page.waitForTimeout(500);
    await page.getByText('25', { exact: true }).first().click().catch(e => log('ITEMS limit 25 err', String(e).slice(0, 120)));
    await page.waitForTimeout(1500);
    const p25 = await rowNames();
    log('ITEMS limit25 count:', p25.length);
    flushSink('items');
  } catch (e) { log('ITEMS section error', String(e).slice(0, 300)); flushSink('items-err'); }

  /* ---------- INGREDIENTS (timing + tabs) ---------- */
  try {
    apiTimings.length = 0;
    const t0 = Date.now();
    await page.goto(BASE + '/admin/ingredients', { waitUntil: 'commit', timeout: 30000 });
    let loadedAt = -1;
    for (let i = 0; i < 100; i++) {
      const st = await page.evaluate(() => {
        const txt = document.body.innerText;
        return { loading: /Chargement…|Chargement\.\.\./.test(txt), rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr, [data-testid="ingredient-list"] li').length, badge: (txt.match(/Ingrédients\n[^0-9]*(\d+)/) || [])[1] };
      }).catch(() => null);
      if (st && !st.loading && (Date.now() - t0) > 1500) { loadedAt = Date.now() - t0; log('ING loaded after', loadedAt, 'ms; rows=', st.rows); break; }
      await page.waitForTimeout(200);
    }
    if (loadedAt < 0) log('ING STILL LOADING after 20s');
    log('ING api timings:', JSON.stringify(apiTimings.filter(t => /ingredient/i.test(t.url))));
    const ingText = await page.evaluate(() => document.body.innerText);
    fs.writeFileSync(path.join(DIR, 'D-B1-ingredients.txt'), ingText);
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients.png') });
    // tab interactions
    const tabCounts = {};
    for (const tab of ['Viandes & Attributs', 'Suppléments', 'Add-ons', 'Tous']) {
      await page.getByRole('button', { name: tab }).first().click().catch(e => log('ING tab err', tab, String(e).slice(0, 100)));
      await page.waitForTimeout(900);
      tabCounts[tab] = await page.evaluate(() => ({
        rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
        firstRow: (document.querySelector('[data-testid="ingredient-list"] table tbody tr')?.innerText || '').slice(0, 80),
      }));
    }
    log('ING tabs:', JSON.stringify(tabCounts));
    await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients-tabs.png') });
    flushSink('ingredients');
    // typed routes
    for (const type of ['attribute', 'extra', 'addon']) {
      const tA = Date.now();
      await page.goto(BASE + `/admin/ingredients/${type}`, { waitUntil: 'networkidle', timeout: 30000 });
      for (let i = 0; i < 60; i++) {
        const loading = await page.evaluate(() => /Chargement…|Chargement\.\.\./.test(document.body.innerText)).catch(() => true);
        if (!loading) break;
        await page.waitForTimeout(250);
      }
      const elapsed = Date.now() - tA;
      const st = await page.evaluate(() => ({
        rows: document.querySelectorAll('[data-testid="ingredient-list"] table tbody tr').length,
        active: (document.querySelector('button[aria-pressed="true"], button.bg-rose-700, button[class*="active"]')?.innerText || '').trim(),
        first: (document.querySelector('[data-testid="ingredient-list"] table tbody tr')?.innerText || '').replace(/\n/g, ' | ').slice(0, 110),
      }));
      log(`ING /${type} loaded ${elapsed}ms rows=${st.rows} activeTab="${st.active}" first="${st.first}"`);
      const txt = await page.evaluate(() => document.body.innerText);
      fs.writeFileSync(path.join(DIR, `D-B1-ingredients-${type}.txt`), txt);
      await page.screenshot({ path: path.join(DIR, `D-B1-ingredients-${type}.png`) });
      flushSink('ing-' + type);
    }
  } catch (e) { log('ING section error', String(e).slice(0, 300)); flushSink('ing-err'); }

  /* ---------- STOCK RUPTURE ---------- */
  try {
    const t0 = Date.now();
    await page.goto(BASE + '/admin/stock/rupture', { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForSelector('[data-testid="stock-mgmt-search"]', { timeout: 15000 });
    log('STOCK loaded in', Date.now() - t0, 'ms url=', page.url());
    await page.waitForTimeout(1000);
    const stockText = await page.evaluate(() => document.body.innerText);
    fs.writeFileSync(path.join(DIR, 'D-B1-stock-rupture.txt'), stockText);
    await page.screenshot({ path: path.join(DIR, 'D-B1-stock-rupture.png') });
    const cards = async () => page.evaluate(() => [...document.querySelectorAll('[data-testid="stock-mgmt-search"]')].length ? document.body.innerText.length : 0);
    const before = await page.evaluate(() => document.body.innerText);
    await page.fill('[data-testid="stock-mgmt-search"]', 'Tacos');
    await page.waitForTimeout(800);
    const afterSearch = await page.evaluate(() => document.body.innerText);
    log('STOCK search changed content:', before !== afterSearch, '| contains Tacos rows:', /Tacos/.test(afterSearch));
    await page.screenshot({ path: path.join(DIR, 'D-B1-stock-rupture-search.png') });
    await page.fill('[data-testid="stock-mgmt-search"]', 'zzz_introuvable');
    await page.waitForTimeout(800);
    const emptyMsg = await page.evaluate(() => document.body.innerText.match(/[^\n]*(Aucun|aucun|résultat|No |vide)[^\n]*/g));
    log('STOCK empty-search msgs:', JSON.stringify(emptyMsg));
    await page.screenshot({ path: path.join(DIR, 'D-B1-stock-rupture-empty.png') });
    await page.fill('[data-testid="stock-mgmt-search"]', '');
    flushSink('stock');
  } catch (e) { log('STOCK section error', String(e).slice(0, 300)); flushSink('stock-err'); }

  fs.writeFileSync(path.join(DIR, 'd-b1-pass2.log'), out.join('\n'));
  await browser.close();
})().catch(e => { console.error('FATAL', e); fs.writeFileSync(path.join(DIR, 'd-b1-pass2.log'), out.join('\n') + '\nFATAL ' + e); process.exit(1); });
