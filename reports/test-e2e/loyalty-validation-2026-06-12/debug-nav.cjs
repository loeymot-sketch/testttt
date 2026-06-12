const path = require('path');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  const logs = [];
  page.on('console', (m) => { if (m.type() !== 'debug') logs.push(`[${m.type()}] ${m.text().slice(0, 200)}`); });
  await uiLogin(page);
  await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);
  logs.length = 0;
  await page.getByText('Commandes Caisse', { exact: true }).first().click();
  await page.waitForTimeout(3000);
  const info = await page.evaluate(() => {
    const route = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$route;
    return { name: route?.name, fullPath: route?.fullPath, matched: route?.matched?.length };
  });
  console.log('after menu click:', JSON.stringify(info));
  const body = await page.evaluate(() => document.body.innerText);
  console.log('has table/serial:', body.includes('LIVE2TAB-1'), '| has Aucune donnée:', body.includes('Aucune donnée'));
  console.log('logs:', logs.slice(0, 10).join(' || ') || 'none');
  await page.screenshot({ path: path.join(__dirname, 'debug-nav-menu.png') });
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
