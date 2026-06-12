// DISPUTE round-1 Vague C — C2 : file d'attente, 3 commandes d'affilée → numéros A000X séquentiels ?
// + C1d intégré : la 1re commande = Tacos composé MENU COMPLET ×2 (wizard complet) pour couvrir qty>1 composé.
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_c2-log.txt', m);
const { browser, page, sink } = await boot();
await kioskBoot(page);

const results = [];

async function oneOrder(tag, items, { tacosQty2 = false } = {}) {
  await startFlow(page);
  for (const [cat, id] of items) {
    const r = await addProduct(page, cat, id);
    L(`${tag} add item ${id}: ${JSON.stringify(r).slice(0, 700)}`);
  }
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1600);
  if (tacosQty2) {
    const names = [];
    const n = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
    for (let i = 0; i < n; i++) names.push((await page.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).innerText({ timeout: 1500 })).trim());
    const iT = names.findIndex(x => x.toLowerCase().includes('tacos'));
    if (iT >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iT}"]`).click(); await page.waitForTimeout(900); }
    L(`${tag} lignes=${JSON.stringify(names)} (tacos idx ${iT} → qty2)`);
  }
  const totals = await readCartTotals(page);
  L(`${tag} cart totals: ${JSON.stringify(totals)}`);
  await snap(page, sink, `${tag}-cart`);
  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  await page.waitForTimeout(2200);
  if (page.url().includes('/loyalty')) {
    const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
    if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
  }
  if (page.url().includes('/upsell')) {
    const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
    await skip.first().click().catch(() => {});
    await page.waitForTimeout(1600);
  }
  await page.waitForTimeout(700);
  const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 3000 }).catch(() => 'ABSENT')).replace(/\n/g, ' ');
  await snap(page, sink, `${tag}-payment`);
  await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
  await page.waitForTimeout(4200);
  await snap(page, sink, `${tag}-cash`);
  const num = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
  const amt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
  const last = sink.orders[sink.orders.length - 1] || {};
  results.push({ tag, queueUi: num, amountUi: amt, payTotal, post: last });
  L(`${tag} RESULT: ui=${num}/${amt} payment=${payTotal} POST=${JSON.stringify(last)}`);
  // retour idle via CTA « j'ai compris » pour enchaîner
  const cta = page.locator('[data-testid="kiosk-cash-cta-understood"]');
  if (await cta.isVisible().catch(() => false)) { await cta.click(); await page.waitForTimeout(2500); }
  L(`${tag} après CTA → ${page.url().replace(BASE, '')}`);
}

// ordre 1 : Tacos menu complet ×2 (composé qty>1, wizard complet) — couvre le reliquat C1
await oneOrder('c2-o1', [[5, 26]], { tacosQty2: true });
// ordre 2 : simple
await oneOrder('c2-o2', [[10, 53]]);
// ordre 3 : simple
await oneOrder('c2-o3', [[9, 50]]);

L(`SEQUENCE: ${JSON.stringify(results.map(r => ({ q: r.queueUi, id: r.post.id, postQ: r.post.queue })))}`);
fs.writeFileSync(OUT + '_c2-results.json', JSON.stringify({ results, orders: sink.orders }, null, 2));
await browser.close();
L('C2 done');
