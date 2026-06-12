// ADVERSARIAL — trou de couverture : POS + modal paiement à 1366×768
import { boot, login, gotoPos } from './_d1-A-lib.mjs';
import path from 'path';
const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/A-caisse-vente/', import.meta.url).pathname;
const { browser, page, netLog } = await boot({ width: 1366, height: 768 });

try {
  await login(page);
  await gotoPos(page);
  await page.locator('button.pos-v5-category[aria-label="Boissons"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Coca-Cola 33cl")').first().click();
  for (let w = 0; w < 8; w++) {
    await page.waitForTimeout(400);
    if (await page.evaluate(() => !!document.querySelector('.wizard-btn-cart'))) { await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click()); break; }
    if ((await page.locator('.pos-v5-cart-item').count()) > 0 && w >= 2) break;
  }
  await page.waitForTimeout(800);
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  const metrics = await page.evaluate(() => {
    const btn = document.querySelector('[data-testid="pos-payment-confirm"]');
    const r = btn?.getBoundingClientRect();
    return {
      hScroll: document.documentElement.scrollWidth > window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      confirmVisibleInViewport: r ? r.bottom <= window.innerHeight && r.top >= 0 : null,
      confirmRect: r ? { top: Math.round(r.top), bottom: Math.round(r.bottom) } : null,
      viewportH: window.innerHeight,
    };
  });
  console.log('METRICS 1366x768:', JSON.stringify(metrics));
  await page.screenshot({ path: path.join(OUT, '_red04-pos-1366-paymodal.png') });
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 6)));
} finally { await browser.close(); }
