import { boot, login, gotoPos } from './_d1-A-lib.mjs';
const { browser, page } = await boot({ width: 1366, height: 768 });
try {
  await login(page); await gotoPos(page);
  await page.locator('button.pos-v5-category[aria-label="Boissons"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Coca-Cola 33cl")').first().click();
  for (let w = 0; w < 8; w++) { await page.waitForTimeout(400);
    if (await page.evaluate(() => !!document.querySelector('.wizard-btn-cart'))) { await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click()); break; }
    if ((await page.locator('.pos-v5-cart-item').count()) > 0 && w >= 2) break; }
  await page.waitForTimeout(800);
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  const probe = await page.evaluate(() => {
    const btn = document.querySelector('[data-testid="pos-payment-confirm"]');
    let el = btn, chain = [];
    while (el && el !== document.body) {
      const cs = getComputedStyle(el);
      if (/(auto|scroll)/.test(cs.overflowY) && el.scrollHeight > el.clientHeight + 4)
        chain.push({ tag: el.tagName + '.' + (el.className || '').toString().slice(0, 60), scrollable: true, sh: el.scrollHeight, ch: el.clientHeight });
      el = el.parentElement;
    }
    return chain;
  });
  console.log('SCROLLABLE ANCESTORS of confirm:', JSON.stringify(probe));
  // tenter un scroll molette dans la modal puis re-mesurer
  await page.mouse.move(683, 400); await page.mouse.wheel(0, 300); await page.waitForTimeout(600);
  const after = await page.evaluate(() => { const r = document.querySelector('[data-testid="pos-payment-confirm"]')?.getBoundingClientRect(); return r ? { top: Math.round(r.top), bottom: Math.round(r.bottom), vh: window.innerHeight } : null; });
  console.log('CONFIRM after wheel:', JSON.stringify(after));
} finally { await browser.close(); }
