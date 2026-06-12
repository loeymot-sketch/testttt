/* D-B2 round 2: real interactions — pagination, filters, search, refresh, modals + same-second DB veracity. */
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');

const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const WT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const TOKEN = process.env.FK_TOKEN;
const log = (o) => { console.log(JSON.stringify(o)); fs.appendFileSync(path.join(OUT, 'd-b2-interact-raw.jsonl'), JSON.stringify(o) + '\n'); };

function tinker(code) {
  try {
    return execSync(`cd ${WT} && APP_ENV=e2e php artisan tinker --execute=${JSON.stringify(code)} 2>/dev/null`, { timeout: 60000 }).toString().trim();
  } catch (e) { return 'TINKER_ERR ' + String(e).slice(0, 120); }
}

async function row1(page) {
  return await page.evaluate(() => (document.querySelector('table tbody tr')?.innerText || '').replace(/\n/g, ' | ').slice(0, 160));
}
async function showing(page) {
  return await page.evaluate(() => (document.body.innerText.match(/(Affichage[^\n]{0,80})/) || ['', ''])[1]);
}

(async () => {
  const { browser, page, sink } = await makePage(TOKEN);
  await uiLogin(page);
  const flush = () => { const s = { console: [...sink.console], http: [...sink.http] }; sink.console.length = 0; sink.http.length = 0; return s; };

  async function go(p) {
    await page.goto(BASE + p, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
    await page.waitForTimeout(800);
  }

  // ---------- 1. pos-orders: pagination page 2 + Filtrer ----------
  await go('/admin/pos-orders'); flush();
  let before = await row1(page), showBefore = await showing(page);
  await page.locator('button', { hasText: /^2$/ }).first().click().catch(async e => log({ step: 'pos-orders.page2.clickfail', err: String(e).slice(0, 120) }));
  await page.waitForTimeout(1800);
  log({ step: 'pos-orders.page2', before, after: await row1(page), showBefore, showAfter: await showing(page), sink: flush() });
  await page.screenshot({ path: OUT + '/D-B2-pos-orders-page2.png' });
  // Filtrer panel
  await page.locator('button:has-text("Filtrer")').first().click().catch(() => {});
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/D-B2-pos-orders-filter.png' });
  const filterPanelText = await page.evaluate(() => (document.querySelector('.db-card-filter, [class*="filter"]')?.innerText || '').replace(/\n/g, ' | ').slice(0, 400));
  log({ step: 'pos-orders.filterOpen', filterPanelText, sink: flush() });

  // ---------- 2. online-orders: page 2 ----------
  await go('/admin/online-orders'); flush();
  before = await row1(page);
  await page.locator('button', { hasText: /^2$/ }).first().click().catch(e => log({ step: 'online.page2.clickfail', err: String(e).slice(0, 120) }));
  await page.waitForTimeout(1800);
  log({ step: 'online-orders.page2', before, after: await row1(page), sink: flush() });

  // ---------- 3. historique: page 2 ----------
  await go('/admin/historique'); flush();
  before = await row1(page);
  await page.locator('button', { hasText: /^2$/ }).first().click().catch(e => log({ step: 'histo.page2.clickfail', err: String(e).slice(0, 120) }));
  await page.waitForTimeout(1800);
  log({ step: 'historique.page2', before, after: await row1(page), sink: flush() });

  // ---------- 4. transactions: page 2 + per-page 25/50 ----------
  await go('/admin/transactions'); flush();
  before = await row1(page); showBefore = await showing(page);
  await page.locator('button', { hasText: /^2$/ }).first().click().catch(e => log({ step: 'tx.page2.clickfail', err: String(e).slice(0, 120) }));
  await page.waitForTimeout(1800);
  log({ step: 'transactions.page2', before, after: await row1(page), showBefore, showAfter: await showing(page), sink: flush() });
  // per-page selector (TableLimitComponent) — usually a select or dropdown labeled "10"
  const limitSel = page.locator('select').first();
  if (await limitSel.count()) {
    const opts = await limitSel.evaluate(s => [...s.options].map(o => o.value));
    log({ step: 'transactions.limitOptions', opts });
    if (opts.includes('50')) {
      await limitSel.selectOption('50').catch(() => {});
      await page.waitForTimeout(1800);
      log({ step: 'transactions.limit50', showAfter: await showing(page), rows: await page.evaluate(() => document.querySelectorAll('table tbody tr').length), sink: flush() });
    }
  } else { log({ step: 'transactions.limit', note: 'no select found' }); }

  // ---------- 5. tracker: same-second DB veracity + search + tab ----------
  await go('/admin/pos-orders-tracker'); flush();
  const trackerHeader = await page.evaluate(() => (document.body.innerText.match(/(\d+)\s*actives?\s*(\d+)\s*aujourd/i) || []).slice(1));
  const dbToday = tinker("echo App\\Models\\Order::withoutGlobalScopes()->whereDate('created_at', now()->toDateString())->count();");
  log({ step: 'tracker.todayCount', uiActivesToday: trackerHeader, dbOrdersToday: dbToday, sink: flush() });
  // search
  const search = page.locator('input[placeholder*="echercher"], input[type="search"]').first();
  if (await search.count()) {
    await search.fill('A0009');
    await page.waitForTimeout(1500);
    const bodyTxt = await page.evaluate(() => document.body.innerText.slice(0, 50));
    log({ step: 'tracker.search', note: 'filled A0009', sink: flush() });
    await page.screenshot({ path: OUT + '/D-B2-pos-orders-tracker-search.png' });
    await search.fill('');
  }
  // tab Borne
  await page.locator('button:has-text("Borne")').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  log({ step: 'tracker.tabBorne', sink: flush() });

  // ---------- 6. encaissement: same-second DB count + Actualiser + open/close modal ----------
  await go('/admin/encaissement'); flush();
  const encHeader = await page.evaluate(() => {
    const t = document.body.innerText;
    return { badge: (t.match(/Commandes en attente d.encaissement[\s\S]{0,40}/) || [''])[0].replace(/\n/g, ' '), total: (t.match(/Total en attente d.encaissement\s*\n?\s*([\d\s,.]+€)/) || ['', ''])[1] };
  });
  const badgeNum = await page.evaluate(() => { const els = [...document.querySelectorAll('span,div')].filter(e => e.children.length === 0 && /^\d+$/.test(e.innerText.trim())); return els.length ? els[0].innerText.trim() : ''; });
  const dbPending = tinker("echo App\\Models\\Order::withoutGlobalScopes()->where('payment_status', App\\Enums\\PaymentStatus::PENDING_COUNTER)->where(function($q){$q->where(function($k){$k->where('source_surface','kiosk')->whereIn('order_type',[App\\Enums\\OrderType::KIOSK, App\\Enums\\OrderType::TAKEAWAY]);})->orWhere(function($p){$p->where('source_surface','pos')->where('pos_payment_method', App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED);});})->count();");
  log({ step: 'encaissement.veracity', encHeader, badgeNum, dbPending, sink: flush() });
  // Actualiser
  await page.locator('button:has-text("Actualiser")').first().click().catch(() => {});
  await page.waitForTimeout(1500);
  log({ step: 'encaissement.actualiser', sink: flush() });
  // open first Encaisser modal then close (no confirm)
  await page.locator('button:has-text("Encaisser")').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.screenshot({ path: OUT + '/D-B2-encaissement-modal.png' });
  const modalTxt = await page.evaluate(() => (document.querySelector('[class*="modal"], [role="dialog"]')?.innerText || '').replace(/\n/g, ' | ').slice(0, 400));
  log({ step: 'encaissement.modalOpen', modalTxt, sink: flush() });
  await page.keyboard.press('Escape');
  await page.locator('button:has-text("Annuler"), button:has-text("Fermer")').first().click({ timeout: 2000 }).catch(() => {});
  await page.waitForTimeout(800);
  log({ step: 'encaissement.modalClose', sink: flush() });

  // ---------- 7. cash-overview: widen date range -> Rechercher ----------
  await go('/admin/cash-overview'); flush();
  const d = page.locator('input[type="date"]');
  if (await d.count() >= 2) {
    await d.nth(0).fill('2026-06-01');
    await d.nth(1).fill('2026-06-12');
  }
  const t0 = Date.now();
  await page.locator('button:has-text("Rechercher")').first().click().catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
  await page.waitForTimeout(600);
  const ovw = await page.evaluate(() => {
    const t = document.body.innerText;
    return { grandTotal: (t.match(/GRAND TOTAL\s*\n?\s*([\d\s,.]+€)/i) || ['', ''])[1], txCount: (t.match(/(\d+)\s*tx/) || ['', ''])[1], hasTable: !!document.querySelector('table tbody tr') };
  });
  log({ step: 'cash-overview.search', ms: Date.now() - t0, ovw, sink: flush() });
  await page.screenshot({ path: OUT + '/D-B2-cash-overview-range.png' });

  // ---------- 8. cash-sessions-report: date range + Rechercher ----------
  await go('/admin/cash-sessions-report'); flush();
  const d2 = page.locator('input[type="date"]');
  if (await d2.count() >= 2) {
    await d2.nth(0).fill('2026-06-01');
    await d2.nth(1).fill('2026-06-12');
  }
  await page.locator('button:has-text("Rechercher")').first().click().catch(() => {});
  await page.waitForTimeout(2000);
  const groups = await page.evaluate(() => [...document.querySelectorAll('table')].length + ' tables; days: ' + [...document.body.innerText.matchAll(/(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+\d+\s+\w+\s+\d{4}/g)].map(m => m[0]).join(', '));
  log({ step: 'cash-sessions.range', groups, sink: flush() });
  await page.screenshot({ path: OUT + '/D-B2-cash-sessions-range.png' });

  // ---------- 9. credit-balance: Filtrer + veracity ----------
  await go('/admin/credit-balance-report'); flush();
  await page.locator('button:has-text("Filtrer")').first().click().catch(() => {});
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/D-B2-credit-balance-filter.png' });
  const dbCust = tinker("echo App\\Models\\User::role('customer')->count() ?? 'n/a';");
  log({ step: 'credit-balance.filterOpen', dbCustomers: dbCust, sink: flush() });

  // ---------- 10. table-orders: Exporter dropdown ----------
  await go('/admin/table-orders'); flush();
  await page.locator('button:has-text("Exporter")').first().click().catch(() => {});
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/D-B2-table-orders-export.png' });
  log({ step: 'table-orders.exportOpen', sink: flush() });

  await browser.close();
  log({ step: 'DONE' });
})().catch(e => { log({ step: 'FATAL', err: String(e).slice(0, 300) }); process.exit(1); });
