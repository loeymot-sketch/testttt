// DISPUTE R1 vague D — probe : panier non vidé à cash-instruction → double commande possible ?
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const orders = [];
p.on('response', async (r) => {
  if (r.url().includes('frontend/order') && r.request().method() === 'POST' && !r.url().includes('quote') && !r.url().includes('change-status')) {
    try { const j = await r.json(); const d = j?.data || j; orders.push({ status: r.status(), id: d?.id, queue: d?.queue_number, total: d?.total ?? d?.order_amount }); L(`ORDER-POST ${r.status()} id=${d?.id} queue=${d?.queue_number} total=${d?.total ?? d?.order_amount}`); } catch { L(`ORDER-POST ${r.status()} (non-json)`); }
  }
});

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
L(`cart: ${JSON.stringify(await cartState(p))}`);

// checkout → payment → confirm (commande 1)
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1200);
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(2000);
if (p.url().includes('/upsell')) { await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {}); await p.waitForTimeout(1500); }
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(3500);
L(`commande 1 → ${p.url().replace(BASE, '')}`);
L(`cart à cash-instruction: ${JSON.stringify(await cartState(p))}`);
await rec.snap('d2b-01-cash-instruction-order1');

// l'utilisateur re-navigue vers le panier (deep nav SPA, PAS de F5)
const nav = await p.evaluate(() => {
  try { document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/kiosk/cart'); return 'PUSHED'; } catch (e) { return String(e); }
});
L(`router.push(/kiosk/cart) depuis cash-instruction → ${nav}`);
await p.waitForTimeout(2000);
L(`url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
const lines = await p.locator('[data-testid^="kiosk-cart-item-name-"]').count();
L(`panier rendu: ${lines} lignes`);
await rec.snap('d2b-02-cart-after-order1');

if (lines > 0) {
  // re-checkout → commande 2 identique ?
  await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
  await p.waitForTimeout(2000);
  if (p.url().includes('/upsell')) { await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {}); await p.waitForTimeout(1500); }
  await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
  await p.waitForTimeout(3500);
  L(`commande 2 → ${p.url().replace(BASE, '')}`);
  await rec.snap('d2b-03-second-order');
}
L(`ORDERS: ${JSON.stringify(orders)}`);
fs.writeFileSync(`${OUT}/_d2b-double-order-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2B');
