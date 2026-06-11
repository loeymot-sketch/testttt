const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, ready, sinkSummary } = require('./06-util.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  const reqs = [];
  page.on('request', r => { if (/api\/admin\/transaction/i.test(r.url())) reqs.push(r.url().slice(0, 220)); });
  await uiLogin(page);
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page); await page.waitForTimeout(1000);
  await page.getByRole('button', { name: /Filtrer|Filter/i }).first().click();
  await page.waitForTimeout(1000);
  // probe filter inputs
  const inputsInfo = await page.evaluate(() =>
    [...document.querySelectorAll('.db-card input, form input')].map(i => `${i.type}|${i.placeholder||''}|${i.className.slice(0,40)}`).slice(0, 12));
  console.log('inputs:', JSON.stringify(inputsInfo));
  const dateInput = page.locator('.dp__input').first();
  if (await dateInput.count()) {
    await dateInput.click();
    await page.waitForTimeout(600);
    // pick today in calendar instead of typing
    const today = page.locator('.dp__today, .dp__active_date').first();
    if (await today.count()) { await today.click(); }
    else { await dateInput.fill('11-06-2026'); await page.keyboard.press('Enter'); }
    await page.waitForTimeout(400);
    await page.keyboard.press('Escape');
  }
  await snap(page, '06-transactions-retry-date-set');
  reqs.length = 0; sink.http.length = 0; sink.console.length = 0;
  await page.getByRole('button', { name: /Rechercher|Search/i }).first().click();
  await page.waitForTimeout(2500);
  await snap(page, '06-transactions-retry-filtered');
  const body = await page.evaluate(() => document.body.innerText);
  const has10 = body.includes('10-06-2026');
  const has11 = body.includes('11-06-2026');
  log('transactions-filter-retry', (!has10 && has11 && sink.console.length === 0) ? 'OK' : 'FAIL',
    `requête=[${reqs.join(' ; ') || 'AUCUNE'}] ; lignes 10-06 présentes=${has10}, 11-06 présentes=${has11} ; ${sinkSummary(sink)}`);
  await browser.close();
})().catch(e => { log('transactions-filter-retry', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
