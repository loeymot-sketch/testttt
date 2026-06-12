// DISPUTE round-1 Vague C — C4 : rupture MID-FLOW. Grande Frites(34) au panier puis
// UPDATE items.is_available=0 pendant la composition → checkout → quel écran/message ?
// Code attendu : AvailabilityService.php:245-250 → 422 « Article 34 indisponible dans le catalogue. Commande rejetée. »
// Écran error/product-removed = jamais appelé par le code de prod (orphelin, cf. S3).
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => logLine('_c4-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

try {
  const r = await addProduct(page, 7, 34); // Grande Frites — wizard éventuel (style frites)
  L(`add Grande Frites(34): ${JSON.stringify(r).slice(0, 400)}`);
  const r2 = await addProduct(page, 10, 52); // + 1 Coca pour un panier 2 lignes
  L(`add Coca(52): ${JSON.stringify(r2)}`);
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await snap(page, sink, 'c4-01-cart-before-rupture');
  L(`cart avant rupture: ${JSON.stringify(await cartState(page))}`);

  // RUPTURE pendant que le client regarde son panier
  sql("UPDATE items SET is_available=0 WHERE id=34");
  L(`DB: items.is_available=0 pour id=34 → ${sql("SELECT id,is_available FROM items WHERE id=34")}`);

  // checkout
  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  await page.waitForTimeout(2800);
  L(`après checkout → ${page.url().replace(BASE, '')}`);
  await snap(page, sink, 'c4-02-after-checkout-rupture');
  const quoteErr = await page.locator('[data-testid="kiosk-cart-quote-error"]').innerText({ timeout: 2000 }).catch(() => null);
  L(`quote-error affiché: "${quoteErr ? quoteErr.replace(/\n/g, ' ') : 'ABSENT'}"`);
  const bodyErr = await page.evaluate(() => document.body.innerText.match(/(indisponible|rejetée|erreur|désolé|retiré)[^\n]*/gi)?.slice(0, 6) || []);
  L(`messages visibles: ${JSON.stringify(bodyErr)}`);

  // si on a quand même avancé (loyalty/upsell/payment), continuer pour voir où ça bloque
  for (let hop = 0; hop < 3 && !page.url().includes('/cart'); hop++) {
    if (page.url().includes('/loyalty')) {
      const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
      if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
    } else if (page.url().includes('/upsell')) {
      const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
      await skip.first().click().catch(() => {});
      await page.waitForTimeout(1600);
    } else break;
  }
  if (page.url().includes('/payment')) {
    L('ARRIVÉ SUR PAYMENT malgré rupture — tentative de confirm pour voir le refus');
    await snap(page, sink, 'c4-03-payment-malgre-rupture');
    await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(() => {});
    await page.waitForTimeout(3500);
    L(`après confirm → ${page.url().replace(BASE, '')}`);
    await snap(page, sink, 'c4-04-confirm-result');
    const errPay = await page.evaluate(() => document.body.innerText.match(/(indisponible|rejetée|erreur|Désolé|retiré)[^\n]*/gi)?.slice(0, 6) || []);
    L(`messages payment: ${JSON.stringify(errPay)}`);
  }

  // retour panier : la ligne 34 est-elle purgée/affichée ? toast ?
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await snap(page, sink, 'c4-05-cart-after');
  L(`cart après reload: ${JSON.stringify(await cartState(page))}`);
  const toast = await page.evaluate(() => document.querySelector('.catalog-change-toast, .kiosk-toast')?.innerText || null);
  L(`toast catalogue: ${toast ? toast.replace(/\n/g, ' ') : 'ABSENT'}`);
} finally {
  sql("UPDATE items SET is_available=1 WHERE id=34");
  L(`DB restaurée: ${sql("SELECT id,is_available FROM items WHERE id=34")}`);
}
fs.writeFileSync(OUT + '_c4-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C4 done');
