const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2500);
  const txt = await page.evaluate(() => (document.querySelector('table')?.innerText || document.body.innerText).slice(0, 700));
  console.log(txt.replace(/\n+/g, ' | '));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
