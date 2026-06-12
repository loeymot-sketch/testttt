// DISPUTE round-2 Vague C — C-30 : rupture MID-FLOW (item indisponible pendant que
// le panier est ouvert) → checkout. Attendu post-heal: prune + toast FR + panier cohérent.
import { boot, snap2, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, log2, OUT2 } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c30-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

const { browser, page, sink } = await boot();
try {
  await kioskBoot(page);
  await startFlow(page);
  let r = { ok: false };
  for (let a = 1; a <= 3 && !r.ok; a++) {
    r = await addProduct(page, 7, 34); // Grande Frites
    L(`add Grande Frites(34) essai ${a}: ${JSON.stringify(r).slice(0, 150)}`);
    if (!r.ok) { await kioskBoot(page); await startFlow(page); }
  }
  const r2 = await addProduct(page, 10, 52); // Coca
  L(`add Coca(52): ${JSON.stringify(r2).slice(0, 120)}`);
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  L(`cart avant rupture: ${JSON.stringify(await cartState(page))} totals=${JSON.stringify(await readCartTotals(page))}`);
  await snap2(page, sink, 'c30-01-cart-before-rupture');

  sql("UPDATE items SET is_available=0 WHERE id=34");
  L(`RUPTURE DB: ${sql("SELECT id,is_available FROM items WHERE id=34").replace(/\n/g, ' ')}`);

  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  await page.waitForTimeout(3000);
  L(`après checkout → ${page.url().replace(BASE, '')}`);
  const toast = await page.evaluate(() => document.querySelector('.kiosk-toast, [role="alert"], .catalog-change-toast')?.innerText?.replace(/\n/g, ' ') || null);
  const quoteErr = await page.locator('[data-testid="kiosk-cart-quote-error"]').innerText({ timeout: 1500 }).catch(() => null);
  L(`toast="${toast}" quoteErr="${quoteErr ? quoteErr.replace(/\n/g, ' ') : null}"`);
  L(`cart après 1er checkout: ${JSON.stringify(await cartState(page))} totals=${JSON.stringify(await readCartTotals(page))}`);
  await snap2(page, sink, 'c30-02-after-checkout-rupture');

  // 2e tap Valider (post-prune) — doit aboutir avec le panier purgé
  if (page.url().includes('/cart')) {
    await page.locator('[data-testid="kiosk-cart-checkout"]').click();
    await page.waitForTimeout(2600);
    L(`après 2e checkout → ${page.url().replace(BASE, '')}`);
    await snap2(page, sink, 'c30-03-after-second-checkout');
    if (page.url().includes('/payment')) {
      const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
      L(`PAYMENT total post-prune: "${payTotal}"`);
    }
  }
} catch (e) {
  L(`FLOW FAIL: ${String(e).split('\n')[0]}`);
  await snap2(page, sink, 'c30-99-flow-fail');
} finally {
  sql("UPDATE items SET is_available=1 WHERE id=34");
  L(`RESTORE: ${sql("SELECT id,is_available FROM items WHERE id=34").replace(/\n/g, ' ')}`);
  fs.writeFileSync(OUT2 + '_c30-orders.json', JSON.stringify(sink.orders, null, 2));
  await browser.close();
}
L('C30 done');
