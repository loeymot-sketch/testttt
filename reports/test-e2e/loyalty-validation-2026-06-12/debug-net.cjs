const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  const reqs = [];
  page.on('response', (r) => reqs.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '').slice(0, 80)}`));
  await uiLogin(page);
  reqs.length = 0;
  await page.goto(`${BASE}/admin/coupons`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2500);
  console.log(reqs.join('\n'));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
