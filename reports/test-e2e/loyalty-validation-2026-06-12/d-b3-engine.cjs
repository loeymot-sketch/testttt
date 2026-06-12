/* D-B3-rapports-equipe — micro-audit engine for 9 admin list/report pages.
 * Read-only interactions: filter open, nonsense search -> empty state, clear -> restore,
 * pagination page 2, per-page limit, export dropdown. Incremental JSON report. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const TOKEN = process.env.E2E_TOKEN;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

const PAGES = [
  { name: 'sales-report', route: '/admin/sales-report', searchSel: '#order_id', searchReal: null },
  { name: 'items-report', route: '/admin/items-report', searchSel: null, searchReal: null },
  { name: 'administrators', route: '/admin/administrators', searchSel: '#searchName', searchReal: 'admin' },
  { name: 'employees', route: '/admin/employees', searchSel: null, searchReal: null },
  { name: 'chefs', route: '/admin/chefs', searchSel: null, searchReal: null },
  { name: 'customers', route: '/admin/customers', searchSel: null, searchReal: null },
  { name: 'delivery-boys', route: '/admin/delivery-boys', searchSel: null, searchReal: null },
  { name: 'delivery-boy-cash-sessions', route: '/admin/delivery-boy-cash-sessions', searchSel: null, searchReal: null },
  { name: 'waiters', route: '/admin/waiters', searchSel: null, searchReal: null },
];
const ONLY = process.argv[2] ? process.argv[2].split(',') : null;

const EN_RESIDUALS = /\b(Showing|results|entries|Previous|Next|Search|Clear|Filter by|Add New|Actions?\b|Active\b|Inactive|Loading|No data|per page|Export to|Download|View All)\b/g;
const AMPM = /\b\d{1,2}:\d{2}\s?(AM|PM)\b/i;
const RAWLBL = /\b[a-z_]+\.[a-z_]{3,}\.[a-z_]{3,}\b|Label\.[A-Za-z_]+|\b(label|button|menu|message)\.[a-z_]+\b|0undefined|undefined|NaN\b/g;
const DOTMONEY = /\d+\.\d{2}\s?€|€\s?\d+\.\d|\$\s?\d/g;

async function rows(page) {
  return page.evaluate(() => document.querySelectorAll('.db-table .db-table-body-tr').length);
}
async function firstRowSig(page) {
  return page.evaluate(() => {
    const tr = document.querySelector('.db-table .db-table-body-tr');
    return tr ? tr.innerText.replace(/\s+/g, ' ').slice(0, 120) : '(none)';
  });
}
async function bodyText(page) {
  return page.evaluate(() => document.body.innerText);
}
async function settle(page, ms = 900) {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(ms);
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  if (TOKEN) {
    await ctx.route(/\/api\//, (route) => {
      const headers = { ...route.request().headers(), authorization: 'Bearer ' + TOKEN };
      route.continue({ headers });
    });
  }
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

  // UI login (real)
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await settle(page);
  console.log('LOGIN OK');

  const file = path.join(OUT, 'd-b3-report.json');
  const report = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file)) : {};

  for (const cfg of PAGES.filter(p => !ONLY || ONLY.includes(p.name))) {
    const R = { route: cfg.route, steps: {} };
    sink.console.length = 0; sink.http.length = 0;
    try {
      const t0 = Date.now();
      await page.goto(BASE + cfg.route, { waitUntil: 'networkidle', timeout: 60000 });
      R.loadMs = Date.now() - t0;
      await page.waitForFunction(() => document.querySelectorAll('.db-card, table').length > 0, null, { timeout: 20000 }).catch(() => { R.steps.readiness = 'NO .db-card/table in 20s'; });
      await page.waitForTimeout(1000);
      R.rows0 = await rows(page);
      R.firstRow0 = await firstRowSig(page);
      await page.screenshot({ path: path.join(OUT, `D-B3-${cfg.name}.png`), fullPage: false });

      const txt = await bodyText(page);
      R.scan = {
        rawLabels: [...new Set(txt.match(RAWLBL) || [])].slice(0, 12),
        enResiduals: [...new Set(txt.match(EN_RESIDUALS) || [])].slice(0, 12),
        ampm: (txt.match(AMPM) || []).slice(0, 4),
        dotMoney: [...new Set(txt.match(DOTMONEY) || [])].slice(0, 8),
        emptyStateVisible: /Aucune donnée/.test(txt),
      };
      R.a11y = await page.evaluate(() => {
        const bad = [];
        document.querySelectorAll('.db-card input:not([type=hidden]), .db-card select, .db-card textarea').forEach(el => {
          const id = el.id;
          const lbl = id && document.querySelector(`label[for="${id}"]`);
          if (!lbl && !el.getAttribute('aria-label') && !el.getAttribute('placeholder')) bad.push((el.tagName + '#' + (id || el.name || el.className)).slice(0, 60));
        });
        return bad.slice(0, 10);
      });
      // pagination text (Affichage X à Y de Z)
      R.paginationText = await page.evaluate(() => {
        const els = [...document.querySelectorAll('.db-card p, .db-card .text-sm, .db-card span')].map(e => e.innerText).filter(t => /\d+\s.*\d+/.test(t) && /[Aa]ffichage|[Ss]howing|résultat|results/.test(t));
        return els[0] ? els[0].replace(/\s+/g, ' ').slice(0, 120) : null;
      });

      // ---- Interaction 1: open filter panel
      const filterBtn = page.locator('.table-filter-btn').first();
      if (await filterBtn.count()) {
        await filterBtn.click().catch(e => { R.steps.filterOpen = 'CLICK FAIL ' + String(e).slice(0, 80); });
        await page.waitForTimeout(700);
        const panelVisible = await page.evaluate(() => {
          const p = document.querySelector('.table-filter-div');
          return p ? getComputedStyle(p).display !== 'none' && p.offsetHeight > 10 : false;
        });
        R.steps.filterOpen = panelVisible ? 'OK panel visible' : 'DEAD? panel not visible after click';

        // ---- Interaction 2: nonsense search -> expect empty
        if (panelVisible && R.rows0 > 0) {
          const input = cfg.searchSel ? page.locator(cfg.searchSel) : page.locator('.table-filter-div input[type=text]').first();
          if (await input.count()) {
            await input.fill('ZZZNOPE99');
            await page.locator('.table-filter-div button.bg-primary').first().click();
            await settle(page);
            const r1 = await rows(page);
            const t1 = await bodyText(page);
            R.steps.searchNonsense = { rows: r1, emptyFR: /Aucune donnée/.test(t1) };
            if (r1 === 0) await page.screenshot({ path: path.join(OUT, `D-B3-${cfg.name}-empty.png`) });
            // ---- Interaction 3: clear -> restore
            await page.locator('.table-filter-div button.bg-gray-600').first().click();
            await settle(page);
            R.steps.clearRestore = { rows: await rows(page), expected: R.rows0 };
            // ---- real search (data actually changes)
            if (cfg.searchReal) {
              await input.fill(cfg.searchReal);
              await page.locator('.table-filter-div button.bg-primary').first().click();
              await settle(page);
              R.steps.searchReal = { value: cfg.searchReal, rows: await rows(page), firstRow: await firstRowSig(page) };
              await page.locator('.table-filter-div button.bg-gray-600').first().click();
              await settle(page);
            }
          } else R.steps.searchNonsense = 'NO text input in filter panel';
        }
      } else R.steps.filterOpen = 'no filter button';

      // ---- Interaction 4: pagination page 2 (real data change check)
      const page2 = page.locator('.db-card nav button, .db-card nav a', { hasText: /^2$/ }).first();
      if (await page2.count()) {
        const sigBefore = await firstRowSig(page);
        await page2.click();
        await settle(page);
        const sigAfter = await firstRowSig(page);
        R.steps.pagination = { changed: sigBefore !== sigAfter, before: sigBefore.slice(0, 60), after: sigAfter.slice(0, 60) };
        // back to page 1
        const page1 = page.locator('.db-card nav button, .db-card nav a', { hasText: /^1$/ }).first();
        if (await page1.count()) { await page1.click(); await settle(page); }
      } else R.steps.pagination = 'no page-2 control';

      // ---- Interaction 5: per-page limit 10 -> 25
      const limitBtn = page.locator('.db-card-filter .dropdown-group > button.dropdown-btn').first();
      if (await limitBtn.count() && (await limitBtn.innerText()).trim().match(/^\d+/)) {
        await limitBtn.click();
        await page.waitForTimeout(400);
        const opt25 = page.locator('.db-card-filter .dropdown-group li', { hasText: /^\s*25\s*$/ }).first();
        if (await opt25.count()) {
          await opt25.click();
          await settle(page);
          R.steps.perPage25 = { rows: await rows(page) };
        } else R.steps.perPage25 = 'no 25 option visible';
      } else R.steps.perPage25 = 'no limit dropdown (total<=10)';

      // ---- Interaction 6: export dropdown opens (dead-button check)
      const expBtn = page.locator('.db-card-filter .dropdown-group > button.dropdown-btn', { hasText: /Export/i }).first();
      if (await expBtn.count()) {
        await expBtn.click();
        await page.waitForTimeout(500);
        R.steps.exportDropdown = await page.evaluate(() => {
          const lists = [...document.querySelectorAll('.db-card-filter-dropdown-list')];
          return lists.some(l => !l.className.includes('scale-y-0') || getComputedStyle(l).transform === 'none' || l.offsetHeight > 5) ? 'opens' : 'DEAD? stays collapsed';
        });
        await page.keyboard.press('Escape');
        await page.mouse.click(640, 100); // close
        await page.waitForTimeout(300);
      } else R.steps.exportDropdown = 'no export button';

      R.console = [...new Set(sink.console)].slice(0, 10);
      R.http = [...new Set(sink.http)].slice(0, 10);
    } catch (e) {
      R.fatal = String(e).slice(0, 300);
      R.console = [...new Set(sink.console)].slice(0, 10);
      R.http = [...new Set(sink.http)].slice(0, 10);
      await page.screenshot({ path: path.join(OUT, `D-B3-${cfg.name}-FATAL.png`) }).catch(() => {});
    }
    report[cfg.name] = R;
    fs.writeFileSync(file, JSON.stringify(report, null, 2));
    console.log(`== ${cfg.name}: load=${R.loadMs}ms rows=${R.rows0} console=${(R.console || []).length} http=${(R.http || []).length}${R.fatal ? ' FATAL' : ''}`);
  }
  await browser.close();
  console.log('D-B3 DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
