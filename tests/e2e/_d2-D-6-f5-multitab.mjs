// DISPUTE R2 vague D — F5 à chaque étape (panier survit) + multi-tab comportement
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState, cartCount, gotoPayment } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
L(`cart initial: ${JSON.stringify(await cartState(p))}`);

// F5 à chaque étape
const steps = [
  ['catalogue', BASE + '/kiosk/categories?cat=9'],
  ['cart', BASE + '/kiosk/cart'],
];
for (const [name, url] of steps) {
  await p.goto(url, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1400);
  await p.reload({ waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2000);
  L(`F5 ${name}: url=${p.url().replace(BASE, '')} cart=${await cartCount(p)}`);
  await rec.snap(`d2D6-01-f5-${name}`);
}
// upsell / loyalty / payment via flux réel puis F5
await gotoPayment(p);
for (const name of ['payment']) {
  await p.reload({ waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2200);
  L(`F5 ${name}: url=${p.url().replace(BASE, '')} cart=${await cartCount(p)}`);
  await rec.snap(`d2D6-02-f5-${name}`);
}

// ---------- multi-tab : tab B ouvre /kiosk/cart pendant que tab A est sur payment ----------
const pB = await ctx.newPage();
const recB = attachRecorder(pB);
await pB.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(2000);
L(`TAB-B /kiosk/cart: cart=${await cartCount(pB)} lignes=${await pB.locator('[data-testid^="kiosk-cart-item-name-"]').count()}`);
await recB.snap('d2D6-03-tabB-cart');

// tab B ajoute un produit
await pB.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(1400);
await pB.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {});
await pB.waitForTimeout(1500);
L(`TAB-B après ajout: cart=${await cartCount(pB)}`);
await recB.snap('d2D6-04-tabB-added');

// tab A : reflète-t-il l'ajout de B ? (état EN MÉMOIRE vs localStorage partagé)
const memA = await p.evaluate(() => {
  try {
    const app = document.querySelector('#app').__vue_app__;
    const store = app.config.globalProperties.$store;
    return { memItems: store.state.kioskCart.items.length };
  } catch (e) { return { err: String(e) }; }
});
L(`TAB-A (payment) store EN MÉMOIRE: ${JSON.stringify(memA)} · localStorage partagé: ${await cartCount(p)}`);
await rec.snap('d2D6-05-tabA-after-tabB-add');

// tab A confirme la commande — la commande inclut-elle l'item ajouté par B ?
const orders = [];
p.on('response', async (r) => {
  if (r.url().includes('frontend/order') && r.request().method() === 'POST' && !r.url().includes('quote')) {
    try { const j = await r.json(); const d = j?.data || j; orders.push({ status: r.status(), id: d?.id, total: d?.total ?? d?.order_amount }); } catch { orders.push({ status: r.status() }); }
  }
});
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(4000);
L(`TAB-A confirm → ${p.url().replace(BASE, '')} ORDERS=${JSON.stringify(orders)}`);
await rec.snap('d2D6-06-tabA-confirmed');
// tab B état final après commande de A
await pB.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(2000);
L(`TAB-B après commande A: cart=${await cartCount(pB)} lignes=${await pB.locator('[data-testid^="kiosk-cart-item-name-"]').count()}`);
await recB.snap('d2D6-07-tabB-after-orderA');

fs.writeFileSync(`${OUT}/_d2D-6-f5-multitab-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D6');
