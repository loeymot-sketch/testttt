const path = require('path');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2000);
  const body = await page.evaluate(() => document.body.innerText);
  console.log('list has LIVE2TAB-1:', body.includes('LIVE2TAB-1'));
  const row = page.locator('tr', { hasText: 'LIVE2TAB-1' }).first();
  console.log('row count:', await row.count());
  if (await row.count()) {
    await row.locator('a, button').last().click();
    await page.waitForTimeout(2500);
    console.log('url now:', page.url());
    const cta = await page.locator('[data-testid=pos-loyalty-redeem-open]').count();
    console.log('CTA count:', cta);
    await page.screenshot({ path: path.join(__dirname, 'debug-show2.png') });
  }
  console.log('http>=400:', JSON.stringify(sink.http.slice(0,5)), 'console:', JSON.stringify(sink.console.slice(0,6)));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
