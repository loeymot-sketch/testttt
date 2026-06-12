const path = require('path');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text().slice(0, 250)}`));
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.evaluate(() => document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router?.isReady());
  await page.waitForTimeout(4000);
  const info = await page.evaluate(() => {
    const route = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$route;
    const tables = document.querySelectorAll('table').length;
    const cards = document.querySelectorAll('.db-card').length;
    return { name: route?.name, matched: route?.matched?.length, tables, cards, appHtmlLen: document.querySelector('#app')?.innerHTML.length };
  });
  console.log(JSON.stringify(info));
  console.log('console:', logs.filter(l => l.startsWith('[error]') || l.startsWith('[warn')).slice(0, 6).join(' || ') || 'clean');
  await page.screenshot({ path: path.join(__dirname, 'debug-list-final.png') });
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
