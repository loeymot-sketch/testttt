const path = require('path');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders/show/${process.env.ORDER_ID}`, { waitUntil: "networkidle", timeout: 45000 });
  await page.evaluate(() => document.querySelector("#app")?.__vue_app__?.config?.globalProperties?.$router?.isReady());
  await page.waitForTimeout(3000);
  await page.waitForTimeout(1500);
  const ctaCount = await page.locator('[data-testid=pos-loyalty-redeem-open]').count();
  console.log('CTA count:', ctaCount);
  await page.screenshot({ path: path.join(__dirname, 'debug-show-page.png') });
  if (ctaCount) {
    await page.locator('[data-testid=pos-loyalty-redeem-open]').click();
    await page.waitForTimeout(800);
    await page.locator('[data-testid=pos-loyalty-redeem-code-input]').fill('LIVE2TAB1');
    await page.locator('[data-testid=pos-loyalty-redeem-points-input]').fill('100');
    page.on('response', async (r) => { if (r.url().includes('redeem-loyalty')) console.log('HTTP', r.status(), JSON.stringify(await r.json().catch(()=>null)).slice(0,300)); });
    await page.locator('[data-testid=pos-loyalty-redeem-apply]').click();
    await page.waitForTimeout(4000);
    const err = await page.locator('[data-testid=pos-loyalty-redeem-error]').innerText().catch(() => 'no-error-el');
    console.log('modal error:', err);
    await page.screenshot({ path: path.join(__dirname, 'debug-modal.png') });
  }
  console.log('sink http:', JSON.stringify(sink.http.slice(0,5))); console.log('sink console:', JSON.stringify(sink.console.slice(0,6)));
  await browser.close();
})().catch((e) => { console.error('FATAL', e.message); process.exit(1); });
