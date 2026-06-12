const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders/show/${process.env.ORDER_ID}`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2000);
  const info = await page.evaluate(() => {
    const app = document.querySelector('#app')?.__vue_app__;
    const route = app?.config?.globalProperties?.$route;
    return {
      path: window.location.pathname,
      routeName: route?.name ?? 'NO-ROUTE',
      matched: route?.matched?.length ?? -1,
      fullPath: route?.fullPath,
    };
  });
  console.log(JSON.stringify(info));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
