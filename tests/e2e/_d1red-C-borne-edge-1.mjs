// ADVERSARIAL re-verify — Vague C borne edge (dispute-2026-06-12 round-1)
// Missions: A promo+fidélité MÊME commande (jamais prouvé par la GStack team),
// B commande 0,00 € (promo clamp), C allergènes Tiramisu, D inactivité payment,
// E double-tap réseau ralenti. ≤8 captures supplémentaires (préfixe r*).
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_d1red-log.txt', m);
const { browser, context, page, sink } = await boot();

await kioskBoot(page);

// ============ MISSION A — promo + fidélité MÊME commande, in-SPA ============
await startFlow(page);
let r = await addProduct(page, 2, 23); L(`A add Galette(23): ${JSON.stringify(r).slice(0, 200)}`);
r = await addProduct(page, 10, 52); L(`A add Coca(52): ${JSON.stringify(r)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
// bump coca → 2 (9,50 total)
const names = [];
const nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
for (let i = 0; i < nLines; i++) names.push((await page.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).innerText({ timeout: 1500 })).trim());
const iCoca = names.findIndex(n => n.toLowerCase().includes('coca'));
if (iCoca >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iCoca}"]`).click(); await page.waitForTimeout(1200); }
L(`A T0: ${JSON.stringify(await readCartTotals(page))}`);

// promo
let promoOk = false;
for (let a = 1; a <= 3 && !promoOk; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2500);
  const t = await readCartTotals(page);
  if (t.promo) { promoOk = true; L(`A T1 promo: ${JSON.stringify(t)}`); break; }
  const err = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText({ timeout: 1200 }).catch(() => null);
  L(`A promo essai ${a} échec: "${err}" — attente 30s`);
  await page.waitForTimeout(30000);
}

// fidélité in-SPA
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1600);
let loyaltyDone = false;
for (let a = 1; a <= 3 && !loyaltyDone; a++) {
  const input = page.locator('.kiosk-loyalty-input').first();
  if (await input.isVisible().catch(() => false)) { try { await input.fill('0612345678'); } catch {} }
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(3000);
  const bal = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || null);
  if (bal) { loyaltyDone = true; L(`A loyalty balance (essai ${a}): ${bal}`); break; }
  const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`A loyalty essai ${a} échec: "${err}" — attente 30s`);
  await page.waitForTimeout(30000);
}
const optTexts = await page.evaluate(() => [...document.querySelectorAll('.kiosk-loyalty-option')].map(o => o.innerText.replace(/\n/g, ' ')));
L(`A options redeem: ${JSON.stringify(optTexts)}`);
await snap(page, sink, 'r1a-loyalty-balance-4eur50');
if (loyaltyDone) {
  const opt = page.locator('.kiosk-loyalty-option').first();
  if (await opt.isVisible().catch(() => false)) { await opt.click(); await page.waitForTimeout(800); }
  // bouton Confirmer
  const conf = page.locator('button:has-text("Confirmer")').first();
  if (await conf.isVisible().catch(() => false)) { await conf.click(); await page.waitForTimeout(2200); }
}
L(`A après loyalty → ${page.url().replace(BASE, '')}`);
if (!page.url().includes('/cart')) {
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(1500);
}
const tA = await readCartTotals(page);
L(`A T2 panier promo+fidélité: ${JSON.stringify(tA)} state=${JSON.stringify(await cartState(page))}`);
await snap(page, sink, 'r1b-cart-promo-plus-loyalty');

// checkout → upsell → payment → confirm → cash
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('.kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1500); }
}
if (page.url().includes('/upsell')) {
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
await page.waitForTimeout(900);
const payA = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`A PAYMENT: ${payA}`);
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
const cashNumA = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
const cashAmtA = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
L(`A CASH: numero="${cashNumA}" montant="${cashAmtA}"`);
await snap(page, sink, 'r1c-cash-promo-loyalty');
L(`A ORDERS POST: ${JSON.stringify(sink.orders)}`);
// retour idle
const ok = page.locator('button:has-text("J\'AI COMPRIS")').first();
if (await ok.isVisible().catch(() => false)) { await ok.click(); await page.waitForTimeout(1500); }

// ============ MISSION B — commande 0,00 € (clamp promo amount) ============
await startFlow(page);
r = await addProduct(page, 10, 52); L(`B add Coca: ${JSON.stringify(r)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await page.locator('[data-testid="kiosk-cart-item-qty-plus-0"]').click().catch(() => {});
await page.waitForTimeout(1000);
let zeroOk = false;
for (let a = 1; a <= 3 && !zeroOk; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2500);
  const t = await readCartTotals(page);
  if (t.promo) { zeroOk = true; L(`B T1 promo sur 3,00: ${JSON.stringify(t)}`); break; }
  L(`B promo essai ${a} échec — attente 30s`); await page.waitForTimeout(30000);
}
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('.kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1500); }
}
if (page.url().includes('/upsell')) {
  await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
const payB = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`B PAYMENT (attendu 0,00): ${payB}`);
await snap(page, sink, 'r2a-payment-zero');
const ordersBefore = sink.orders.length;
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
L(`B après confirm → ${page.url().replace(BASE, '')}`);
const cashNumB = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2000 }).catch(() => 'ABSENT');
const cashAmtB = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2000 }).catch(() => 'ABSENT');
L(`B CASH: numero="${cashNumB}" montant="${cashAmtB}" — POSTs: ${JSON.stringify(sink.orders.slice(ordersBefore))}`);
await snap(page, sink, 'r2b-after-confirm-zero');
const ok2 = page.locator('button:has-text("J\'AI COMPRIS")').first();
if (await ok2.isVisible().catch(() => false)) { await ok2.click(); await page.waitForTimeout(1500); }

// ============ MISSION C — allergènes Tiramisu (seul item câblé) ============
await startFlow(page);
await page.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
const gridBadge = await page.evaluate(() => {
  const b = document.querySelector('[data-testid="kiosk-product-allergens-51"]');
  return b ? { exists: true, visible: !!(b.offsetWidth || b.offsetHeight), text: b.innerText.replace(/\n/g, ' ') } : { exists: false };
});
L(`C grid badge Tiramisu(51): ${JSON.stringify(gridBadge)}`);
const tiramisuCardText = await page.evaluate(() => document.querySelector('[data-testid="kiosk-product-add-51"]')?.closest('.kiosk-product-card, [class*=product]')?.innerText?.replace(/\n/g, ' | ').slice(0, 300) || 'CARD INTROUVABLE');
L(`C carte Tiramisu: ${tiramisuCardText}`);
await snap(page, sink, 'r3a-desserts-grid-allergens');
r = await addProduct(page, 9, 51); L(`C add Tiramisu: ${JSON.stringify(r).slice(0, 200)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
const cartBadges = await page.evaluate(() => [...document.querySelectorAll('[data-testid^="kiosk-cart-item-allergens-"]')].map(b => ({ visible: !!(b.offsetWidth || b.offsetHeight), text: b.innerText.replace(/\n/g, ' ') })));
L(`C badges allergènes panier: ${JSON.stringify(cartBadges)}`);
const cartDomHasAllergen = await page.evaluate(() => /gluten|oeufs|lait|allerg/i.test(document.body.innerText));
L(`C texte allergène visible quelque part dans le panier ? ${cartDomHasAllergen}`);
await snap(page, sink, 'r3b-cart-tiramisu-allergens');
await page.locator('button:has-text("Vider le panier")').first().click().catch(() => {});
await page.waitForTimeout(800);
await page.locator('button:has-text("Vider"), button:has-text("Oui"), button:has-text("Confirmer")').first().click().catch(() => {});
await page.waitForTimeout(800);

// ============ MISSION D+E — inactivité payment + double-tap ralenti ============
// timers courts via kioskSettings persisté
await page.evaluate(() => {
  const v = JSON.parse(localStorage.vuex || '{}');
  v.kioskSettings = { ...(v.kioskSettings || {}), idleMs: 12000, confirmMs: 4000 };
  localStorage.vuex = JSON.stringify(v);
});
await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await startFlow(page);
r = await addProduct(page, 10, 52); L(`D add Coca: ${JSON.stringify(r)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1000);
// 1) prouver que le timer court marche sur CART (overlay après ~8-12s)
let overlaySeenCart = false;
for (let i = 0; i < 14; i++) {
  await page.waitForTimeout(1000);
  overlaySeenCart = await page.evaluate(() => /toujours là|still|continuer ma commande/i.test(document.body.innerText) || !!document.querySelector('.kiosk-idle-warn, [data-testid*="still"], [class*=inactivity]'));
  if (overlaySeenCart) break;
}
L(`D overlay inactivité sur CART (idleMs=12s): ${overlaySeenCart}`);
// dissiper l'overlay
await page.mouse.click(540, 960); await page.waitForTimeout(800);
await page.mouse.click(540, 960); await page.waitForTimeout(800);
// si reset → re-add
if (!(await page.url()).includes('cart')) {
  await startFlow(page);
  r = await addProduct(page, 10, 52); L(`D re-add Coca après reset: ${JSON.stringify(r)}`);
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
}
// 2) aller sur PAYMENT et attendre 25s — overlay/redirect attendu: AUCUN (noTimerRoutes)
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2000);
if (page.url().includes('/loyalty')) { await page.locator('.kiosk-loyalty-skip').first().click().catch(() => {}); await page.waitForTimeout(1400); }
if (page.url().includes('/upsell')) { await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {}); await page.waitForTimeout(1600); }
L(`D arrivée payment: ${page.url().replace(BASE, '')}`);
let overlaySeenPay = false;
for (let i = 0; i < 25; i++) {
  await page.waitForTimeout(1000);
  overlaySeenPay = await page.evaluate(() => /toujours là|continuer ma commande/i.test(document.body.innerText));
  if (overlaySeenPay) break;
}
L(`D payment après 25s (idleMs=12s): overlay=${overlaySeenPay} url=${page.url().replace(BASE, '')}`);
await snap(page, sink, 'r4-payment-25s-sans-timer');

// 3) MISSION E — double-tap avec latence réseau 1500ms (CDP)
const cdp = await context.newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 1500, downloadThroughput: 200 * 1024, uploadThroughput: 200 * 1024 });
const ordersBeforeE = sink.orders.length;
const dbl = await page.evaluate(() => {
  const btn = document.querySelector('[data-testid="kiosk-payment-counter-confirm"]');
  if (!btn) return 'BTN ABSENT';
  btn.click(); btn.click(); btn.click();
  return 'triple-click dispatché';
});
L(`E ${dbl}`);
await page.waitForTimeout(9000);
const postsE = sink.orders.slice(ordersBeforeE);
L(`E POSTs order après triple-click: ${JSON.stringify(postsE)}`);
L(`E url finale: ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'r5-after-doubletap');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 }).catch(() => {});

fs.writeFileSync(OUT + '_d1red-orders.json', JSON.stringify(sink.orders, null, 2));
L('RED run done');
await browser.close();
