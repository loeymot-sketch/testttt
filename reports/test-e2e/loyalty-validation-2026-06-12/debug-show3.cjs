const path = require('path');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  page.on('pageerror', (e) => console.log('PAGEERROR:', String(e).slice(0, 250)));
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders/show/${process.env.ORDER_ID}`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2500);
  const body = await page.evaluate(() => document.body.innerText);
  console.log('has serial LIVE2TAB-1:', body.includes('LIVE2TAB-1'), '| has Total:', body.includes('Total'));
  console.log('body excerpt:', body.slice(body.indexOf('Tableau De Bord', 200), body.indexOf('Tableau De Bord', 200) + 250).replace(/\n+/g, ' | '));
  await page.screenshot({ path: path.join(__dirname, 'debug-show3.png') });
  console.log('http>=400:', JSON.stringify(sink.http.slice(0,5)), 'console:', JSON.stringify(sink.console.slice(0,5)));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
