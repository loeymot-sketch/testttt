/* D-B2-commandes-argent sweep: 10 pages, load + interaction, console/http sink, DOM facts, screenshots. */
const path = require('path');
const fs = require('fs');
const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');

const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const TOKEN = process.env.FK_TOKEN;

const PAGES = [
  '/admin/pos-orders',
  '/admin/pos-orders-tracker',
  '/admin/online-orders',
  '/admin/table-orders',
  '/admin/historique',
  '/admin/encaissement',
  '/admin/cash-overview',
  '/admin/cash-sessions-report',
  '/admin/credit-balance-report',
  '/admin/transactions',
];

function slug(p) { return p.replace('/admin/', '').replace(/\//g, '_'); }

async function domFacts(page) {
  return await page.evaluate(() => {
    const body = document.body.innerText || '';
    const facts = {};
    facts.title = (document.querySelector('h1,h2,.db-card-title,.breadcrumb-title')?.innerText || '').slice(0, 80);
    const tables = document.querySelectorAll('table');
    facts.tableCount = tables.length;
    facts.rowCount = tables.length ? tables[0].querySelectorAll('tbody tr').length : 0;
    facts.firstRowText = tables.length ? (tables[0].querySelector('tbody tr')?.innerText || '').replace(/\n/g, ' | ').slice(0, 220) : '';
    // raw labels / i18n leakage
    const rawLabel = body.match(/\b[a-z_]+\.[a-z_]+\.[a-z_]+\b/g) || [];
    facts.rawLabels = [...new Set(rawLabel.filter(s => /^(label|kiosk|menu|button|message|all)\./.test(s)))].slice(0, 10);
    facts.zeroUndef = /0undefined|undefined|NaN(?![a-zA-Z])/.test(body) ? (body.match(/.{0,40}(0undefined|undefined|NaN).{0,40}/) || [''])[0] : '';
    facts.ampm = (body.match(/\b\d{1,2}:\d{2}\s?(AM|PM|am|pm)\b/g) || []).slice(0, 5);
    // currency dot format like €1.50 or 1.50€ or $ — FR expects 1,50 €
    facts.dotMoney = (body.match(/\d+\.\d{2}\s?€|€\s?\d+\.\d{2}|\$\s?\d/g) || []).slice(0, 5);
    facts.dates = (body.match(/\b\d{2}[-/]\d{2}[-/]\d{4}\b|\b\d{4}-\d{2}-\d{2}\b/g) || []).slice(0, 6);
    // pagination + search presence
    facts.hasPagination = !!document.querySelector('.pagination, ul.pagination, [class*="paginat"]');
    facts.paginationText = (document.querySelector('.pagination, [class*="paginat"]')?.innerText || '').replace(/\n/g, ' ').slice(0, 120);
    facts.searchInputs = document.querySelectorAll('input[type="search"], input[placeholder*="echerch"], input[placeholder*="earch"]').length;
    facts.emptyText = /No data|Aucune donnée|Aucun |Rien à afficher|No Data Available|not found/i.test(body) ? (body.match(/.{0,10}(No data[^\n]*|Aucune donnée[^\n]*|Aucun [^\n]{0,60}|No Data Available[^\n]*)/i) || [''])[0] : '';
    // total/count text shown
    facts.showingText = (body.match(/(Affichage|Showing)[^\n]{0,80}/i) || [''])[0];
    // obvious english words leak (heuristic on common admin words)
    const en = body.match(/\b(Showing|entries|Search|Filter(?!s? actif)|Status|Actions|Total Amount|Paid|Unpaid|Download|Export|Clear|Apply|Submit|Cancel(?!l))\b/g) || [];
    facts.enWords = [...new Set(en)].slice(0, 12);
    facts.buttons = [...document.querySelectorAll('button')].map(b => (b.innerText || b.getAttribute('aria-label') || '').trim()).filter(Boolean).slice(0, 25);
    return facts;
  });
}

(async () => {
  if (!TOKEN) { console.error('FK_TOKEN missing'); process.exit(1); }
  const { browser, page, sink } = await makePage(TOKEN);
  // also pre-seed localStorage auth? Do real UI login for SPA store.
  await uiLogin(page);
  const results = [];
  for (const p of PAGES) {
    const r = { page: p, slug: slug(p) };
    sink.console.length = 0; sink.http.length = 0;
    const t0 = Date.now();
    try {
      await page.goto(BASE + p, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => { r.networkidleTimeout = true; });
    } catch (e) { r.gotoError = String(e).slice(0, 200); }
    r.loadMs = Date.now() - t0;
    await page.waitForTimeout(1200);
    r.url = page.url();
    r.loadConsole = [...sink.console];
    r.loadHttp = [...sink.http];
    r.facts = await domFacts(page);
    await page.screenshot({ path: path.join(OUT, `D-B2-${r.slug}.png`), fullPage: false }).catch(() => {});
    // interaction: prefer page-2 click, else first sortable th, else open filter
    sink.console.length = 0; sink.http.length = 0;
    r.interaction = 'none';
    try {
      const page2 = page.locator('.pagination li a, .pagination button, [class*="paginat"] a, [class*="paginat"] button').filter({ hasText: /^2$/ }).first();
      if (await page2.count() && await page2.isVisible().catch(() => false)) {
        const before = r.facts.firstRowText;
        await page2.click();
        await page.waitForTimeout(1800);
        const after = await domFacts(page);
        r.interaction = 'pagination->2';
        r.interactionChanged = after.firstRowText !== before;
        r.afterFirstRow = after.firstRowText;
      } else {
        const th = page.locator('table thead th').first();
        if (await th.count()) {
          const before = r.facts.firstRowText;
          await th.click({ timeout: 3000 }).catch(() => {});
          await page.waitForTimeout(1500);
          const after = await domFacts(page);
          r.interaction = 'th-click(sort?)';
          r.interactionChanged = after.firstRowText !== before;
          r.afterFirstRow = after.firstRowText;
        }
      }
    } catch (e) { r.interactionError = String(e).slice(0, 160); }
    r.interConsole = [...sink.console];
    r.interHttp = [...sink.http];
    await page.screenshot({ path: path.join(OUT, `D-B2-${r.slug}-after.png`), fullPage: false }).catch(() => {});
    results.push(r);
    console.log(JSON.stringify(r));
    fs.appendFileSync(path.join(OUT, 'd-b2-sweep-raw.jsonl'), JSON.stringify(r) + '\n');
  }
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
