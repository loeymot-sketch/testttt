const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  page.on('pageerror', (e) => console.log('PAGEERROR:', String(e).slice(0, 200)));
  await uiLogin(page);
  await page.goto(`${BASE}/admin/coupons`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2000);
  const body = await page.evaluate(() => document.body.innerText);
  console.log('coupons header visible:', body.includes('Coupons'), '| Ajouter btn:', body.includes('Ajouter Un Coupon'));
  console.log('console:', JSON.stringify(sink.console.slice(0, 4)));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
