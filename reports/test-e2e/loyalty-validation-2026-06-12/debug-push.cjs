const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/coupons`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);
  const res = await page.evaluate(async () => {
    const router = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router;
    if (!router) return 'NO-ROUTER';
    const out = [];
    for (const target of ['/admin/pos-orders', '/admin/pos-orders/show/4521']) {
      try {
        const r = await router.push(target);
        out.push(`${target} => OK name=${router.currentRoute.value.name} (failure=${r ? String(r).slice(0,120) : 'none'})`);
      } catch (e) {
        out.push(`${target} => THROW ${String(e).slice(0, 250)}`);
      }
    }
    return out.join('\n');
  });
  console.log(res);
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
