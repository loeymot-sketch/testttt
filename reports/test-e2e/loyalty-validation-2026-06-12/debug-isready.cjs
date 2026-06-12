const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);
  const res = await page.evaluate(() => {
    const router = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router;
    if (!router) return Promise.resolve('NO-ROUTER');
    return Promise.race([
      router.isReady().then(() => 'READY name=' + router.currentRoute.value.name).catch((e) => 'ISREADY-ERROR: ' + String(e).slice(0, 300)),
      new Promise((r) => setTimeout(() => r('STILL-PENDING-after-5s name=' + router.currentRoute.value.name), 5000)),
    ]);
  });
  console.log(res);
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
