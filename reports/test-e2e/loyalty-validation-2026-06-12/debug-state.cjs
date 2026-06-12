const path = require('path');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/coupons`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2500);
  const body = await page.evaluate(() => document.body.innerText.slice(0, 400));
  console.log('BODY:', body.replace(/\n+/g, ' | '));
  await page.screenshot({ path: path.join(__dirname, 'debug-coupons-now.png') });
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
