/* Precise content-ready latency: goto -> first data row visible (user-perceived). */
const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');
const TOKEN = process.env.FK_TOKEN;
(async () => {
  const { browser, page } = await makePage(TOKEN);
  await uiLogin(page);
  const targets = [
    ['/admin/pos-orders', 'table tbody tr'],
    ['/admin/transactions', 'table tbody tr'],
    ['/admin/historique', 'table tbody tr'],
    ['/admin/cash-overview', 'input[type="date"]'],
  ];
  for (const [p, sel] of targets) {
    const t0 = Date.now();
    await page.goto(BASE + p, { waitUntil: 'commit', timeout: 30000 });
    await page.waitForSelector(sel, { timeout: 30000 });
    console.log(p + ' content-ready(full reload): ' + (Date.now() - t0) + 'ms');
  }
  // in-SPA navigation: click sidebar links
  for (const [label, sel] of [['Historique', 'table tbody tr'], ['Commandes Caisse', 'table tbody tr'], ['Vue Caisse Unifiée', 'input[type="date"]']]) {
    const t0 = Date.now();
    await page.locator('aside a, nav a, a').filter({ hasText: new RegExp('^\\s*' + label + '\\s*$') }).first().click();
    await page.waitForSelector(sel, { timeout: 30000 });
    console.log('in-SPA nav -> ' + label + ': ' + (Date.now() - t0) + 'ms');
  }
  await browser.close();
})().catch(e => { console.error('FATAL', String(e).slice(0, 300)); process.exit(1); });
