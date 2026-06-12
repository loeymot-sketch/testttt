const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text().slice(0, 220)}`));
  await uiLogin(page);
  logs.length = 0;
  await page.goto(`${BASE}/admin/pos-orders/show/${process.env.ORDER_ID}`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2500);
  console.log(logs.filter((l) => !l.startsWith('[debug]')).slice(0, 15).join('\n') || 'NO-LOGS');
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
