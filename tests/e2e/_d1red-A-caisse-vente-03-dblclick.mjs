// ADVERSARIAL — edge double-clic « Confirmer & Imprimer ticket » : 1 seule commande ?
import { boot, login, gotoPos } from './_d1-A-lib.mjs';
import path from 'path';
const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/A-caisse-vente/', import.meta.url).pathname;
const { browser, page, netLog } = await boot();

const posts = [];
page.on('response', async (r) => {
  if (r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos')) {
    let body = '';
    try { body = (await r.text()).slice(0, 200); } catch {}
    posts.push({ status: r.status(), body });
  }
});

try {
  await login(page);
  await gotoPos(page);
  await page.locator('button.pos-v5-category[aria-label="Boissons"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Coca-Cola 33cl")').first().click();
  // wizard éventuel (instruction spéciale) -> Ajouter au panier
  for (let w = 0; w < 8; w++) {
    await page.waitForTimeout(400);
    if (await page.evaluate(() => !!document.querySelector('.wizard-btn-cart'))) {
      await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click());
      break;
    }
    if ((await page.locator('.pos-v5-cart-item').count()) > 0 && w >= 2) break;
  }
  await page.waitForTimeout(1000);
  console.log('CART COUNT:', await page.locator('.pos-v5-cart-item').count());
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  await page.fill('#cashInput', '5');
  await page.waitForTimeout(400);

  // double-clic rapide (2 clics ~0ms) sur Confirmer
  const btn = page.locator('[data-testid="pos-payment-confirm"]');
  await btn.dblclick().catch(() => {});
  // + un 3e clic 150ms après, pendant le vol de la requête
  await page.waitForTimeout(150);
  await btn.click({ force: true }).catch(() => {});
  await page.waitForTimeout(6000);

  console.log('POSTS /api/admin/pos:', JSON.stringify(posts));
  const receipt = await page.evaluate(() => (document.querySelector('#print-receipt-client')?.innerText || 'ABSENT').slice(0, 400));
  console.log('RECEIPT head:', JSON.stringify(receipt.slice(0, 220)));
  await page.screenshot({ path: path.join(OUT, '_red03-dblclick-result.png') });
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 8)));
} finally { await browser.close(); }
