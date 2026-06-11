const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, ready, sinkSummary } = require('./06-util.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  const reqs = [];
  page.on('request', r => { if (/api\/admin\/transaction/i.test(r.url())) reqs.push(r.url().slice(0, 260)); });
  await uiLogin(page);
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page); await page.waitForTimeout(1000);
  await page.getByRole('button', { name: /Filtrer|Filter/i }).first().click();
  await page.waitForTimeout(1000);
  const dateInput = page.locator('.dp__input').first();
  await dateInput.click();
  await page.waitForTimeout(800);
  const todayCell = page.locator('.dp__cell_inner.dp__today').first();
  await todayCell.click();           // start of range
  await page.waitForTimeout(300);
  await todayCell.click();           // end of range (same day) -> autoApply
  await page.waitForTimeout(800);
  await snap(page, '06-transactions-retry2-date-set');
  reqs.length = 0; sink.http.length = 0; sink.console.length = 0;
  await page.getByRole('button', { name: /Rechercher|Search/i }).first().click();
  await page.waitForTimeout(2500);
  await snap(page, '06-transactions-retry2-filtered');
  const body = await page.evaluate(() => document.body.innerText);
  const has10 = body.includes('10-06-2026');
  const has11 = body.includes('11-06-2026');
  log('transactions-filter-retry2', (reqs.some(u => /from_date|date/.test(u)) && !has10 && sink.console.length === 0) ? 'OK' : 'FAIL',
    `requête=[${reqs.join(' ; ') || 'AUCUNE'}] ; lignes 10-06=${has10}, 11-06=${has11} ; ${sinkSummary(sink)}`);
  await browser.close();
})().catch(e => { log('transactions-filter-retry2', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
