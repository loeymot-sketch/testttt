// DISPUTE R1 vague D — scénario 2 : reload sauvage (F5) à chaque étape
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, openWizard, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);

// ---------- 2a. F5 sur catalogue ----------
await p.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
const added = await addSimpleProduct(p);
L(`produit ajouté avant F5: ${added} cart=${JSON.stringify(await cartState(p))}`);
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
L(`F5 catalogue → url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2-01-catalogue-after-f5');

// ---------- 2b. F5 wizard ouvert ----------
const wiz = await openWizard(p);
L(`wizard ouvert: ${wiz}`);
await p.waitForTimeout(500);
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2800);
const overlayAfter = await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false);
L(`F5 wizard → url=${p.url().replace(BASE, '')} overlay=${overlayAfter} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2-02-wizard-after-f5');

// ---------- 2c. F5 panier ----------
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
const cartBefore = await cartState(p);
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
const cartAfter = await cartState(p);
L(`F5 panier → url=${p.url().replace(BASE, '')} avant=${JSON.stringify(cartBefore)} après=${JSON.stringify(cartAfter)}`);
const linesVisible = await p.locator('[data-testid^="kiosk-cart-item-name-"]').count();
const totalTxt = (await p.locator('[data-testid="kiosk-cart-total"]').innerText().catch(() => 'ABSENT')).replace(/\n/g, ' ');
L(`F5 panier rendu: ${linesVisible} lignes visibles, total="${totalTxt}"`);
await rec.snap('d2-03-cart-after-f5');

// ---------- 2d. F5 loyalty ----------
await p.locator('[data-testid="kiosk-cart-loyalty-btn"]').click().catch(() => {});
await p.waitForTimeout(1500);
L(`loyalty atteint: ${p.url().replace(BASE, '')}`);
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
L(`F5 loyalty → url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2-04-loyalty-after-f5');

// ---------- 2e. F5 upsell ----------
if (!p.url().includes('/cart')) { await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(1200); }
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(2000);
if (p.url().includes('/upsell')) {
  await p.reload({ waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2500);
  L(`F5 upsell → url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
  await rec.snap('d2-05-upsell-after-f5');
  await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await p.waitForTimeout(1500);
} else L(`upsell non montré (url=${p.url().replace(BASE, '')})`);

// ---------- 2f. F5 payment ----------
if (!p.url().includes('/payment')) L(`WARN pas sur payment: ${p.url().replace(BASE, '')}`);
await p.waitForTimeout(800);
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2800);
L(`F5 payment → url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
const payTotal = (await p.locator('[data-testid="kiosk-payment-counter-total"]').innerText().catch(() => 'ABSENT')).replace(/\n/g, ' ');
L(`payment total après F5: "${payTotal}"`);
await rec.snap('d2-06-payment-after-f5');

// ---------- 2g. F5 cash-instruction (post-commande) ----------
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(3500);
L(`après confirm → ${p.url().replace(BASE, '')}`);
const numBefore = await p.locator('[data-testid="kiosk-cash-order-number"]').innerText().catch(() => 'ABSENT');
const amtBefore = await p.locator('[data-testid="kiosk-cash-amount"]').innerText().catch(() => 'ABSENT');
L(`cash-instruction avant F5: numéro="${numBefore}" montant="${amtBefore}"`);
await rec.snap('d2-07-cash-instruction-before-f5');
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2800);
const numAfter = await p.locator('[data-testid="kiosk-cash-order-number"]').innerText().catch(() => 'ABSENT');
const amtAfter = await p.locator('[data-testid="kiosk-cash-amount"]').innerText().catch(() => 'ABSENT');
L(`F5 cash-instruction → url=${p.url().replace(BASE, '')} numéro="${numAfter}" montant="${amtAfter}" cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2-08-cash-instruction-after-f5');

fs.writeFileSync(`${OUT}/_d2-reload-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2');
